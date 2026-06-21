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

        $horaire = Presence::horaire($db, $id);
        $jours = (string) $horaire['jours'];

        // Bases théoriques (mois complet).
        $tempsJournalierSec = Paie::tempsJournalierSecondes($horaire);
        $joursOuvres = Paie::compterJoursTravailles($dateDebut, $dateFin, $jours);
        $tempsTheoriqueMensuelSec = $tempsJournalierSec * $joursOuvres;
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
        $totalSupSec = 0;
        $nbRetards = 0;

        foreach ($stmt->fetchAll() as $p) {
            $date = (string) $p['date'];
            $present[$date] = true;
            if (empty($p['heure_entree'])) {
                continue;
            }
            $worked = Paie::tempsTravailleSecondes($p['heure_entree'], $p['heure_sortie']);
            $late = max(0, (int) $p['retard_minutes']) * 60;
            $sup = Paie::heuresSupSecondes($worked, $tempsJournalierSec);

            $totalTravailleSec += $worked;
            $totalRetardSec += $late;
            $totalSupSec += $sup;
            if ($late > 0) {
                $nbRetards++;
            }

            $detail[$date] = [
                'date'            => $date,
                'check_in'        => $p['heure_entree'],
                'check_out'       => $p['heure_sortie'],
                'worked_seconds'  => $worked,
                'late_seconds'    => $late,
                'overtime_seconds' => $sup,
                'status'          => $late > 0 ? 'LATE' : 'PRESENT',
            ];
        }

        // Absences : jours travaillés sans pointage, jusqu'à aujourd'hui.
        $joursAbsent = 0;
        $cur = strtotime($dateDebut);
        $end = strtotime($borneAbsence);
        while ($cur <= $end) {
            $date = date('Y-m-d', $cur);
            if (Paie::estJourTravaille($date, $jours) && !isset($present[$date])) {
                $joursAbsent++;
                $detail[$date] = [
                    'date' => $date, 'check_in' => null, 'check_out' => null,
                    'worked_seconds' => 0, 'late_seconds' => 0, 'overtime_seconds' => 0,
                    'status' => 'ABSENT',
                ];
            }
            $cur = strtotime('+1 day', $cur);
        }
        ksort($detail);

        // Montants (arrondis au FCFA entier ; valeurs unitaires à 2 décimales).
        $deductionRetard = round($totalRetardSec * $valeurSeconde);
        $deductionAbsence = round($joursAbsent * $tempsJournalierSec * $valeurSeconde);
        $montantSup = round($totalSupSec * $valeurSeconde);
        $salaireNet = round($salaire - $deductionRetard - $deductionAbsence + $montantSup);

        return [
            'employe' => [
                'id' => $id, 'matricule' => $employe['matricule'],
                'nom' => $employe['nom'], 'prenom' => $employe['prenom'],
            ],
            'mois' => $mois,
            'horaire' => ['arrivee' => $horaire['debut'], 'depart' => $horaire['fin'], 'jours' => $jours],
            'temps_journalier_sec'        => $tempsJournalierSec,
            'temps_journalier'            => Paie::formatHM($tempsJournalierSec),
            'jours_ouvres_mois'           => $joursOuvres,
            'temps_theorique_mensuel_sec' => $tempsTheoriqueMensuelSec,
            'temps_theorique_mensuel'     => Paie::formatHM($tempsTheoriqueMensuelSec),
            'valeur_heure'                => round($valeurSeconde * 3600, 2),
            'valeur_minute'               => round($valeurSeconde * 60, 2),
            'valeur_seconde'              => round($valeurSeconde, 4),
            'jours_presents'              => count(array_filter($detail, static fn ($d) => $d['status'] !== 'ABSENT')),
            'jours_absents'               => $joursAbsent,
            'nb_retards'                  => $nbRetards,
            'temps_total_travaille_sec'   => $totalTravailleSec,
            'temps_total_travaille'       => Paie::formatHM($totalTravailleSec),
            'temps_total_retard_sec'      => $totalRetardSec,
            'temps_total_retard'          => Paie::formatHM($totalRetardSec),
            'temps_total_heures_sup_sec'  => $totalSupSec,
            'temps_total_heures_sup'      => Paie::formatHM($totalSupSec),
            'salaire_brut'                => round($salaire),
            'deduction_retard'            => $deductionRetard,
            'deduction_absence'           => $deductionAbsence,
            'montant_heures_sup'          => $montantSup,
            'salaire_net'                 => $salaireNet,
            'detail'                      => array_values($detail),
        ];
    }

    /** Valide et normalise le mois (YYYY-MM) ; défaut = mois courant. */
    private function moisValide(?string $mois): string
    {
        $mois = $mois ?? date('Y-m');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) !== 1) {
            Response::error("Paramètre 'mois' invalide (format attendu : YYYY-MM)", 422);
        }

        return $mois;
    }
}
