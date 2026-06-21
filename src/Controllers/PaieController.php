<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Paie;
use MadMen\Core\Presence;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;

/**
 * Paie mensuelle : bulletin par employé et liste pour la paie de tous les employés.
 * S'appuie sur l'horaire de l'employé (horaire_employe) + ses pointages (pointage)
 * + son salaire (employe.salaire). Calculs purs délégués à Core\Paie.
 */
final class PaieController
{
    /** GET /api/employes/{id}/paie?mois=YYYY-MM — bulletin détaillé d'un employé. */
    public function bulletin(array $params): void
    {
        $db = Database::connection();
        $mois = $this->moisValide(Request::query('mois'));

        $stmt = $db->prepare('SELECT id, matricule, nom, prenom, salaire FROM employe WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $employe = $stmt->fetch();
        if (!$employe) {
            Response::error('Employé introuvable', 404);
        }

        Response::json($this->calcul($db, $employe, $mois));
    }

    /** GET /api/paie?mois=YYYY-MM — synthèse de paie de tous les employés actifs. */
    public function liste(): void
    {
        $db = Database::connection();
        $mois = $this->moisValide(Request::query('mois'));

        $stmt = $db->query("SELECT id, matricule, nom, prenom, salaire FROM employe WHERE statut = 'actif' ORDER BY nom, prenom");
        $out = [];
        foreach ($stmt->fetchAll() as $employe) {
            $bulletin = $this->calcul($db, $employe, $mois);
            unset($bulletin['detail']); // synthèse : pas le détail journalier
            $out[] = $bulletin;
        }

        Response::json(['mois' => $mois, 'paie' => $out]);
    }

    /**
     * Calcule le bulletin de paie d'un employé pour un mois (YYYY-MM).
     * @return array<string,mixed>
     */
    private function calcul(PDO $db, array $employe, string $mois): array
    {
        $id = (int) $employe['id'];
        $salaire = (float) $employe['salaire'];

        $dateDebut = $mois . '-01';
        $dateFin = date('Y-m-t', strtotime($dateDebut));
        $today = date('Y-m-d');
        $borneAbsence = min($dateFin, $today); // on ne marque pas absent un jour futur

        // Emploi du temps PAR JOUR de l'employé (ou repli horaire unique).
        $planning = Presence::planning($db, $id);
        // Jours fériés du mois : payés -> jamais comptés comme absence.
        $feries = JourFerieController::map($db, $dateDebut, $dateFin);

        // Base théorique = somme des fenêtres de CHAQUE jour planifié du mois.
        $tempsTheoriqueMensuelSec = 0;
        $joursOuvres = 0;
        for ($t = strtotime($dateDebut), $tf = strtotime($dateFin); $t <= $tf; $t = strtotime('+1 day', $t)) {
            $w = Presence::fenetreJour($planning, $d = date('Y-m-d', $t));
            if ($w !== null) {
                $tempsTheoriqueMensuelSec += self::dureeFenetre($d, $w);
                $joursOuvres++;
            }
        }
        $valeurSeconde = Paie::valeurSeconde($salaire, $tempsTheoriqueMensuelSec);

        // Pointages du mois (jours réellement pointés).
        $stmt = $db->prepare(
            'SELECT date, heure_entree, heure_sortie, retard_minutes
             FROM pointage WHERE employe_id = ? AND date BETWEEN ? AND ? ORDER BY date'
        );
        $stmt->execute([$id, $dateDebut, $dateFin]);

        $detail = [];
        $present = [];
        $totalTravailleSec = 0;
        $totalRetardSec = 0;
        $nbRetards = 0;

        foreach ($stmt->fetchAll() as $p) {
            $date = (string) $p['date'];
            if (empty($p['heure_entree'])) {
                continue; // pointage sans entrée exploitable -> jour traité comme absent
            }
            $present[$date] = true;
            $w = Presence::fenetreJour($planning, $date);
            $workStart = strtotime((string) $p['heure_entree']);
            $workEnd = !empty($p['heure_sortie']) ? strtotime((string) $p['heure_sortie']) : $workStart;
            $worked = max(0, $workEnd - $workStart);

            if ($w === null) {
                // Jour NON planifié : temps enregistré (trace) mais NON compté.
                $normal = 0;
                $late = 0;
                $status = 'EXTRA';
            } else {
                // Jour planifié : NORMAL = temps DANS la fenêtre saisie ; le temps
                // hors fenêtre n'est tout simplement pas compté (pas d'heures sup).
                $winStart = strtotime("$date " . $w['debut']);
                $winEnd = strtotime("$date " . $w['fin']);
                $normal = max(0, min($workEnd, $winEnd) - max($workStart, $winStart));
                $late = max(0, (int) $p['retard_minutes']) * 60;
                $status = $late > 0 ? 'LATE' : 'PRESENT';
                if ($late > 0) {
                    $nbRetards++;
                }
            }

            $totalTravailleSec += $worked;
            $totalRetardSec += $late;

            $detail[$date] = [
                'date'           => $date,
                'check_in'       => $p['heure_entree'],
                'check_out'      => $p['heure_sortie'],
                'worked_seconds' => $worked,
                'normal_seconds' => $normal,
                'late_seconds'   => $late,
                'status'         => $status,
            ];
        }

        // Jours travaillés sans pointage (jusqu'à aujourd'hui) : férié = PAYÉ (pas
        // d'absence, pas de déduction) ; sinon ABSENT (déduit).
        $joursAbsent = 0;
        $joursFeries = 0;
        $absenceSec = 0;
        for ($t = strtotime($dateDebut), $tb = strtotime($borneAbsence); $t <= $tb; $t = strtotime('+1 day', $t)) {
            $date = date('Y-m-d', $t);
            $w = Presence::fenetreJour($planning, $date);
            if ($w === null || isset($present[$date])) {
                continue; // repos, ou déjà pointé
            }
            if (isset($feries[$date])) {
                $joursFeries++;
                $detail[$date] = [
                    'date' => $date, 'check_in' => null, 'check_out' => null,
                    'worked_seconds' => 0, 'normal_seconds' => 0, 'late_seconds' => 0,
                    'status' => 'FERIE', 'libelle' => $feries[$date],
                ];
            } else {
                $joursAbsent++;
                $absenceSec += self::dureeFenetre($date, $w);
                $detail[$date] = [
                    'date' => $date, 'check_in' => null, 'check_out' => null,
                    'worked_seconds' => 0, 'normal_seconds' => 0, 'late_seconds' => 0,
                    'status' => 'ABSENT',
                ];
            }
        }
        ksort($detail);

        // Garde-fou : si le temps théorique ou le salaire est invalide, la valeur
        // horaire serait nulle -> on NE produit PAS de montants (sinon l'employé
        // serait silencieusement payé plein sans déductions). On signale au lieu.
        $calculable = $tempsTheoriqueMensuelSec > 0 && $salaire > 0;
        $avertissement = $calculable ? null
            : "Paie incalculable : horaire (temps théorique nul) ou salaire de l'employé invalide.";

        // Montants (arrondis au FCFA entier ; valeurs unitaires à 2 décimales).
        $deductionRetard = $calculable ? round($totalRetardSec * $valeurSeconde) : null;
        // Absence déduite selon la durée RÉELLE de chaque jour manqué (sa fenêtre).
        $deductionAbsence = $calculable ? round($absenceSec * $valeurSeconde) : null;
        $salaireNet = $calculable
            ? round($salaire - $deductionRetard - $deductionAbsence)
            : null;

        return [
            'employe' => [
                'id' => $id, 'matricule' => $employe['matricule'],
                'nom' => $employe['nom'], 'prenom' => $employe['prenom'],
            ],
            'mois' => $mois,
            'planning'                    => $planning['jours'],
            'tolerance_minutes'           => $planning['tolerance'],
            'jours_ouvres_mois'           => $joursOuvres,
            'temps_theorique_mensuel_sec' => $tempsTheoriqueMensuelSec,
            'temps_theorique_mensuel'     => Paie::formatHM($tempsTheoriqueMensuelSec),
            'valeur_heure'                => round($valeurSeconde * 3600, 2),
            'valeur_minute'               => round($valeurSeconde * 60, 2),
            'valeur_seconde'              => round($valeurSeconde, 4),
            'jours_presents'              => count(array_filter($detail, static fn ($d) => in_array($d['status'], ['PRESENT', 'LATE'], true))),
            'jours_feries'                => $joursFeries,
            'jours_extra'                 => count(array_filter($detail, static fn ($d) => $d['status'] === 'EXTRA')),
            'jours_absents'               => $joursAbsent,
            'nb_retards'                  => $nbRetards,
            'temps_total_travaille_sec'   => $totalTravailleSec,
            'temps_total_travaille'       => Paie::formatHM($totalTravailleSec),
            'temps_total_retard_sec'      => $totalRetardSec,
            'temps_total_retard'          => Paie::formatHM($totalRetardSec),
            'paie_calculable'             => $calculable,
            'avertissement'               => $avertissement,
            'salaire_brut'                => round($salaire),
            'deduction_retard'            => $deductionRetard,
            'deduction_absence'           => $deductionAbsence,
            'salaire_net'                 => $salaireNet,
            'detail'                      => array_values($detail),
        ];
    }

    /** Durée (secondes) d'une fenêtre de jour [debut, fin] à la date donnée. */
    private static function dureeFenetre(string $date, array $w): int
    {
        return max(0, strtotime("$date " . $w['fin']) - strtotime("$date " . $w['debut']));
    }

    /** Valide et normalise le mois (YYYY-MM) ; défaut = mois courant. Tolère un type non-string (?mois[]=) -> défaut. */
    private function moisValide($mois): string
    {
        $mois = is_string($mois) ? $mois : date('Y-m');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) !== 1) {
            Response::error("Paramètre 'mois' invalide (format attendu : YYYY-MM)", 422);
        }

        return $mois;
    }
}
