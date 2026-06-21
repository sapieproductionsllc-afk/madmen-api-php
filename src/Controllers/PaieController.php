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
            if (empty($p['heure_entree'])) {
                continue; // pointage sans entrée exploitable -> jour traité comme absent
            }
            $present[$date] = true;
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

        // Garde-fou : si le temps théorique ou le salaire est invalide, la valeur
        // horaire serait nulle -> on NE produit PAS de montants (sinon l'employé
        // serait silencieusement payé plein sans déductions). On signale au lieu.
        $calculable = $tempsTheoriqueMensuelSec > 0 && $salaire > 0;
        $avertissement = $calculable ? null
            : "Paie incalculable : horaire (temps théorique nul) ou salaire de l'employé invalide.";

        // Montants (arrondis au FCFA entier ; valeurs unitaires à 2 décimales).
        $deductionRetard = $calculable ? round($totalRetardSec * $valeurSeconde) : null;
        $deductionAbsence = $calculable ? round($joursAbsent * $tempsJournalierSec * $valeurSeconde) : null;
        // Heures sup : NON incluses dans le salaire. Enregistrées à part ; cette
        // valeur est seulement INDICATIVE (ce que coûterait leur paiement) si
        // l'employeur décide d'accorder un bonus. Elle n'entre PAS dans le net.
        $heuresSupValeurIndic = $calculable ? round($totalSupSec * $valeurSeconde) : null;
        $salaireNet = $calculable
            ? round($salaire - $deductionRetard - $deductionAbsence)
            : null;

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
            'paie_calculable'             => $calculable,
            'avertissement'               => $avertissement,
            'salaire_brut'                => round($salaire),
            'deduction_retard'            => $deductionRetard,
            'deduction_absence'           => $deductionAbsence,
            'heures_sup_incluses_net'      => false,
            'heures_sup_valeur_indicative' => $heuresSupValeurIndic,
            'salaire_net'                 => $salaireNet,
            'detail'                      => array_values($detail),
        ];
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
