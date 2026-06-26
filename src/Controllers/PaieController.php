<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Paie;
use MadMen\Core\Presence;
use MadMen\Core\Request;
use MadMen\Core\Response;
use MadMen\Core\Salaire;
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

        // Accepte l'id numérique OU le matricule (le profil tire le bulletin par matricule).
        $idParam = trim((string) $params['id']);
        $colonne = ctype_digit($idParam) ? 'id' : 'matricule';
        $stmt = $db->prepare("SELECT id, matricule, nom, prenom, salaire FROM employe WHERE $colonne = ?");
        $stmt->execute([$idParam]);
        $employe = $stmt->fetch();
        if (!$employe) {
            Response::error('Employé introuvable', 404);
        }

        Response::json(self::calculer($db, $employe, $mois));
    }

    /** GET /api/paie?mois=YYYY-MM — synthèse de paie de tous les employés actifs. */
    public function liste(): void
    {
        $db = Database::connection();
        $mois = $this->moisValide(Request::query('mois'));

        $stmt = $db->query("SELECT id, matricule, nom, prenom, salaire FROM employe WHERE statut = 'actif' ORDER BY nom, prenom");
        $out = [];
        foreach ($stmt->fetchAll() as $employe) {
            $bulletin = self::calculer($db, $employe, $mois);
            unset($bulletin['detail']); // synthèse : pas le détail journalier
            $out[] = $bulletin;
        }

        Response::json(['mois' => $mois, 'paie' => $out]);
    }

    /**
     * Calcule le bulletin de paie d'un employé pour un mois (YYYY-MM).
     * @return array<string,mixed>
     */
    public static function calculer(PDO $db, array $employe, string $mois): array
    {
        $id = (int) $employe['id'];
        // Salaire EFFECTIF au mois (historique salaire_fixe) ; repli sur employe.salaire.
        $salaire = Salaire::effectif($db, $id, $mois) ?? (float) $employe['salaire'];

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

        // Tous les passages (entrées/sorties) du mois, groupés par jour -> affichés tels
        // quels sur la carte du jour (calendrier). ADDITIF : n'entre PAS dans le calcul de
        // paie, lequel reste basé sur heure_entree/heure_sortie de la table pointage.
        $passagesParJour = [];
        $stmtPp = $db->prepare(
            "SELECT date, type, TIME_FORMAT(horodatage, '%H:%i') AS heure
             FROM pointage_passage
             WHERE employe_id = ? AND date BETWEEN ? AND ?
             ORDER BY date, horodatage, id"
        );
        $stmtPp->execute([$id, $dateDebut, $dateFin]);
        foreach ($stmtPp->fetchAll() as $pp) {
            $passagesParJour[(string) $pp['date']][] = [
                'type'  => $pp['type'],   // 'entree' | 'sortie'
                'heure' => $pp['heure'],  // 'HH:MM'
            ];
        }

        $detail = [];
        $present = [];
        $totalTravailleSec = 0;
        $totalNormalSec = 0;
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
            $totalNormalSec += $normal;
            $totalRetardSec += $late;

            $detail[$date] = [
                'date'           => $date,
                'check_in'       => $p['heure_entree'],
                'check_out'      => $p['heure_sortie'],
                'worked_seconds' => $worked,
                'normal_seconds' => $normal,
                'late_seconds'   => $late,
                'status'         => $status,
                'passages'       => $passagesParJour[$date] ?? [],
            ];
        }

        // Congés APPROUVÉS du mois : un jour planifié couvert par un congé approuvé
        // (ou un pointage.statut='conge') est PAYÉ et NON déduit, comme un férié. On
        // récupère l'ensemble des dates couvertes une seule fois (cf. congesMap).
        $conges = self::congesMap($db, $id, $dateDebut, $dateFin);

        // Jours travaillés sans pointage (jusqu'à aujourd'hui) : férié = PAYÉ (pas
        // d'absence, pas de déduction) ; congé approuvé = PAYÉ aussi ; sinon ABSENT
        // (déduit).
        $joursAbsent = 0;
        $joursFeries = 0;
        $joursConge = 0;
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
            } elseif (isset($conges[$date])) {
                // Congé approuvé : payé, NON déduit (pas ajouté à $absenceSec).
                $joursConge++;
                $detail[$date] = [
                    'date' => $date, 'check_in' => null, 'check_out' => null,
                    'worked_seconds' => 0, 'normal_seconds' => 0, 'late_seconds' => 0,
                    'status' => 'CONGE', 'libelle' => $conges[$date],
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

        // Composition du salaire (#4, additif) : primes/retenues MANUELLES du mois
        // (paie_ajustement) + avances = mensualités des prêts EN COURS (pret). Le kiosque
        // ne touche pas à la paie -> sûr. Quand il n'y a rien, ces montants valent 0 et le
        // net reste identique à l'ancien calcul.
        $stmtAj = $db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type = 'prime'   THEN montant END), 0) AS primes,
                    COALESCE(SUM(CASE WHEN type = 'retenue' THEN montant END), 0) AS retenues
             FROM paie_ajustement WHERE employe_id = ? AND periode = ?"
        );
        $stmtAj->execute([$id, $mois]);
        $aj = $stmtAj->fetch() ?: [];
        $primes = (float) ($aj['primes'] ?? 0);
        $retenues = (float) ($aj['retenues'] ?? 0);

        $stmtAv = $db->prepare("SELECT COALESCE(SUM(mensualite), 0) FROM pret WHERE employe_id = ? AND statut = 'en_cours'");
        $stmtAv->execute([$id]);
        $avances = (float) ($stmtAv->fetchColumn() ?: 0);

        // Heures supplémentaires du mois (#2) : la table heures_supplementaires est
        // alimentée par le K40 (durée en minutes au-delà de l'horaire). On la SOMME
        // pour le récap ; elle est INFORMATIVE (non payée) car le modèle horaire ne
        // compte volontairement pas le temps hors fenêtre comme du travail payé.
        // try/catch défensif (comme les sources de congés) : une table absente/modifiée
        // ne doit jamais faire échouer le bulletin entier.
        $heuresSupSec = 0;
        try {
            $stmtHs = $db->prepare(
                'SELECT COALESCE(SUM(duree_minutes), 0) FROM heures_supplementaires
                 WHERE employe_id = ? AND date BETWEEN ? AND ?'
            );
            $stmtHs->execute([$id, $dateDebut, $dateFin]);
            $heuresSupSec = (int) $stmtHs->fetchColumn() * 60;
        } catch (\Throwable $e) {
            $heuresSupSec = 0;
        }
        // Valeur indicative des HS au taux horaire normal (NON ajoutée au net).
        $heuresSupValeur = $calculable ? round($heuresSupSec * $valeurSeconde) : null;

        // % de travail (#10) = temps réellement travaillé / temps théorique du mois.
        $pourcentageTravail = $tempsTheoriqueMensuelSec > 0
            ? round($totalTravailleSec / $tempsTheoriqueMensuelSec * 100, 1)
            : null;

        $salaireNet = $calculable
            ? round($salaire - $deductionRetard - $deductionAbsence + $primes - $retenues - $avances)
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
            'jours_conge'                 => $joursConge,
            'jours_extra'                 => count(array_filter($detail, static fn ($d) => $d['status'] === 'EXTRA')),
            'jours_absents'               => $joursAbsent,
            'nb_retards'                  => $nbRetards,
            'temps_total_travaille_sec'   => $totalTravailleSec,
            'temps_total_travaille'       => Paie::formatHM($totalTravailleSec),
            'temps_total_retard_sec'      => $totalRetardSec,
            'temps_total_retard'          => Paie::formatHM($totalRetardSec),
            // Récap d'heures exploitable par le front (#2/#3) : heures normales / HS /
            // jours par statut. Les HS sont INFORMATIVES (heures_sup_payees=false).
            'recap_heures'                => [
                'heures_normales_sec'  => $totalNormalSec,
                'heures_normales'      => Paie::formatHM($totalNormalSec),
                'heures_sup_sec'       => $heuresSupSec,
                'heures_sup'           => Paie::formatHM($heuresSupSec),
                'heures_sup_payees'    => false,
                'heures_sup_valeur'    => $heuresSupValeur,
                'retard_sec'           => $totalRetardSec,
                'retard'               => Paie::formatHM($totalRetardSec),
                'total_travaille_sec'  => $totalTravailleSec,
                'total_travaille'      => Paie::formatHM($totalTravailleSec),
                'theorique_sec'        => $tempsTheoriqueMensuelSec,
                'theorique'            => Paie::formatHM($tempsTheoriqueMensuelSec),
                // Alias attendus par le front (contrat recap_heures auto-suffisant).
                'heures_theoriques_sec'  => $tempsTheoriqueMensuelSec,
                'heures_travaillees_sec' => $totalTravailleSec,
                'retenues'             => round($deductionRetard + $deductionAbsence + $retenues),
                'jours_present'        => count(array_filter($detail, static fn ($d) => in_array($d['status'], ['PRESENT', 'LATE'], true))),
                'jours_retard'         => $nbRetards,
                'jours_absent'         => $joursAbsent,
                'jours_conge'          => $joursConge,
                'jours_ferie'          => $joursFeries,
                'jours_extra'          => count(array_filter($detail, static fn ($d) => $d['status'] === 'EXTRA')),
            ],
            'paie_calculable'             => $calculable,
            'avertissement'               => $avertissement,
            'salaire_brut'                => round($salaire),
            'deduction_retard'            => $deductionRetard,
            'deduction_absence'           => $deductionAbsence,
            'primes'                      => round($primes),
            'retenues'                    => round($retenues),
            'avances'                     => round($avances),
            'pourcentage_travail'         => $pourcentageTravail,
            'salaire_net'                 => $salaireNet,
            'detail'                      => array_values($detail),
        ];
    }

    /** Durée (secondes) d'une fenêtre de jour [debut, fin] à la date donnée. */
    private static function dureeFenetre(string $date, array $w): int
    {
        return max(0, strtotime("$date " . $w['fin']) - strtotime("$date " . $w['debut']));
    }

    /**
     * Dates (Y-m-d => libellé) du mois couvertes par un congé APPROUVÉ pour
     * l'employé. Trois sources, cumulées :
     *   1. demande_conge (système de solde du collègue) : statut='approuve', chaque
     *      jour entre date_debut et date_fin ; libellé = type_conge.libelle.
     *   2. demande (self-service) : type='conge', statut='approuve', date_debut..date_fin.
     *   3. pointage.statut='conge' (congé saisi directement sur un jour).
     * Robuste si une table/colonne manque (try/catch) : un congé absent ne doit
     * jamais faire échouer le bulletin. Les jours ainsi marqués sont PAYÉS et NON
     * déduits (comme un férié).
     *
     * @return array<string,string> date => libellé
     */
    private static function congesMap(PDO $db, int $employeId, string $dateDebut, string $dateFin): array
    {
        $map = [];

        $marquerIntervalle = static function (string $d1, string $d2, string $libelle) use (&$map, $dateDebut, $dateFin): void {
            $debut = max($d1, $dateDebut);
            $fin = min($d2, $dateFin);
            for ($t = strtotime($debut), $tf = strtotime($fin); $t !== false && $t <= $tf; $t = strtotime('+1 day', $t)) {
                $map[date('Y-m-d', $t)] = $libelle;
            }
        };

        // 1) demande_conge (statut='approuve') chevauchant le mois.
        try {
            $stmt = $db->prepare(
                "SELECT dc.date_debut, dc.date_fin, COALESCE(tc.libelle, 'Congé') AS libelle
                 FROM demande_conge dc
                 LEFT JOIN type_conge tc ON tc.id = dc.type_conge_id
                 WHERE dc.employe_id = ? AND dc.statut = 'approuve'
                   AND dc.date_debut <= ? AND dc.date_fin >= ?"
            );
            $stmt->execute([$employeId, $dateFin, $dateDebut]);
            foreach ($stmt->fetchAll() as $r) {
                $marquerIntervalle((string) $r['date_debut'], (string) $r['date_fin'], (string) $r['libelle']);
            }
        } catch (\Throwable $e) {
            error_log('congesMap demande_conge : ' . $e->getMessage());
        }

        // 2) demande (self-service) type='conge', statut='approuve'.
        try {
            $stmt = $db->prepare(
                "SELECT date_debut, date_fin FROM demande
                 WHERE employe_id = ? AND type = 'conge' AND statut = 'approuve'
                   AND date_debut IS NOT NULL AND date_fin IS NOT NULL
                   AND date_debut <= ? AND date_fin >= ?"
            );
            $stmt->execute([$employeId, $dateFin, $dateDebut]);
            foreach ($stmt->fetchAll() as $r) {
                $marquerIntervalle((string) $r['date_debut'], (string) $r['date_fin'], 'Congé');
            }
        } catch (\Throwable $e) {
            error_log('congesMap demande : ' . $e->getMessage());
        }

        // 3) pointage.statut='conge' (congé posé directement sur un jour).
        try {
            $stmt = $db->prepare(
                "SELECT date FROM pointage
                 WHERE employe_id = ? AND statut = 'conge' AND date BETWEEN ? AND ?"
            );
            $stmt->execute([$employeId, $dateDebut, $dateFin]);
            foreach ($stmt->fetchAll() as $r) {
                $map[(string) $r['date']] = $map[(string) $r['date']] ?? 'Congé';
            }
        } catch (\Throwable $e) {
            error_log('congesMap pointage : ' . $e->getMessage());
        }

        return $map;
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
