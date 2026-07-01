<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Presence;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Vue présence : statut temps réel par agent (dérivé des sessions de travail) et
 * calendrier de présence mensuel d'un employé (dérivé des pointages + fériés).
 *
 * Lecture seule : aucune écriture en base. Les états « live » sont calculés à la
 * volée à partir de session_travail.statut (ouverte/verrouillee) et de
 * employe.statut ('conge'). Le calendrier dérive chaque jour de pointage.statut,
 * des jours fériés (jour_ferie) et du planning de l'employé (jours travaillés).
 */
final class PresenceController
{
    /**
     * GET /api/presence/temps-reel
     *
     * État instantané de chaque employé actif (non suspendu) :
     *   [{ employe_id, matricule, name, live, detail, depuis }]
     * où live ∈ { 'En activite', 'En pause', 'Absent', 'Conge' } :
     *   - 'Conge'       : employe.statut = 'conge' (prioritaire sur la session) ;
     *   - 'En activite' : une session_travail 'ouverte' en cours ;
     *   - 'En pause'    : une session_travail 'verrouillee' (poste verrouillé) ;
     *   - 'Absent'      : aucune session ouverte/verrouillée en cours.
     * depuis = heure_debut de la session en cours (null si absent/congé sans session).
     */
    public function tempsReel(): void
    {
        $db = Database::connection();

        // On part de TOUS les employés non suspendus, puis on rattache leur session
        // « en cours » (statut ouverte/verrouillee). LEFT JOIN : un employé sans
        // session reste présent dans le résultat (Absent ou Conge). On joint aussi le
        // pointage du jour pour exposer le statut journalier (avec AUTO-PARTI).
        $stmt = $db->prepare(
            "SELECT e.id AS employe_id,
                    e.matricule,
                    TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
                    e.statut AS employe_statut,
                    s.statut AS session_statut,
                    s.heure_debut AS depuis,
                    pt.nom AS poste,
                    po.statut AS pointage_statut,
                    po.heure_entree AS arrivee
             FROM employe e
             LEFT JOIN session_travail s
                    ON s.id = (
                        SELECT s2.id FROM session_travail s2
                        WHERE s2.employe_id = e.id
                          AND s2.statut IN ('ouverte','verrouillee')
                        ORDER BY s2.id DESC
                        LIMIT 1
                    )
             LEFT JOIN poste_travail pt ON pt.id = s.poste_travail_id
             LEFT JOIN pointage po ON po.employe_id = e.id AND po.date = CURDATE()
             WHERE e.statut NOT IN ('suspendu', 'archive')
             ORDER BY e.matricule"
        );
        $stmt->execute();

        $today = date('Y-m-d');
        $planningCache = [];
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            [$live, $detail] = $this->etatLive(
                (string) $row['employe_statut'],
                $row['session_statut'] !== null ? (string) $row['session_statut'] : null,
                $row['poste'] !== null ? (string) $row['poste'] : null
            );

            // Statut JOURNALIER (cohérent avec l'ENUM pointage.statut), AUTO-PARTI
            // inclus : un agent 'present'/'retard' dont la fin du jour est dépassée
            // est exposé 'parti'. Additif : 'live' (sessions) reste inchangé.
            $statut = $this->statutJourAuto(
                $db,
                (int) $row['employe_id'],
                (string) $row['employe_statut'],
                $row['pointage_statut'] !== null ? (string) $row['pointage_statut'] : null,
                $today,
                $planningCache
            );

            $out[] = [
                'employe_id' => (int) $row['employe_id'],
                'matricule'  => (string) $row['matricule'],
                'name'       => (string) $row['name'],
                'live'       => $live,
                'statut'     => $statut,
                'detail'     => $detail,
                'depuis'     => $live === 'Absent' ? null : ($row['depuis'] ?? null),
            ];
        }

        Response::json($out);
    }

    /**
     * Statut journalier d'un employé (ENUM pointage.statut), AUTO-PARTI appliqué à
     * l'affichage : un statut 'present'/'retard' bascule 'parti' si l'heure de fin
     * prévue du jour est dépassée. Sans pointage : 'conge' si l'employé est en congé,
     * sinon null. $cache mémorise le planning par employé (passé par référence).
     *
     * @param array<int,array> $cache
     */
    private function statutJourAuto(
        \PDO $db,
        int $employeId,
        string $employeStatut,
        ?string $pointageStatut,
        string $today,
        array &$cache
    ): ?string {
        if ($pointageStatut === null) {
            return $employeStatut === 'conge' ? 'conge' : null;
        }
        // AUTO-PARTI retiré (estAutoParti renvoie toujours false) : on évitait une requête
        // planning PAR employé à chaque appel temps-réel. Le statut journalier brut suffit.
        return $pointageStatut;
    }

    /**
     * GET /api/employes/{id}/presence?mois=YYYY-MM
     *
     * Calendrier de présence d'un employé sur un mois : un état par jour, dérivé
     * de pointage.statut, des jours fériés et du planning (jours travaillés).
     * Réponse :
     *   { employe: {...}, mois: 'YYYY-MM', jours: [{ date, jour_semaine, etat, libelle,
     *     heure_entree, heure_sortie, retard_minutes }] }
     * etat ∈ { 'present','retard','absent','conge','ferie','repos','futur' }.
     */
    public function calendrier(array $params): void
    {
        $db = Database::connection();
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);

        $stmt = $db->prepare(
            "SELECT id, matricule, TRIM(CONCAT(prenom, ' ', nom)) AS name, statut,
                    DATE(created_at) AS cree_le, date_embauche
             FROM employe WHERE id = ?"
        );
        $stmt->execute([$employeId]);
        $employe = $stmt->fetch();
        if (!$employe) {
            Response::error('Employé introuvable', 404);
        }

        // Entrée en service : avant cette date l'employé n'existait pas (créé) ou n'avait
        // pas commencé (embauche) -> ces jours sont 'na', JAMAIS 'absent'.
        $debutService = substr((string) ($employe['cree_le'] ?? ''), 0, 10);
        $emb = !empty($employe['date_embauche']) ? substr((string) $employe['date_embauche'], 0, 10) : null;
        if ($emb !== null && ($debutService === '' || $emb > $debutService)) {
            $debutService = $emb;
        }

        // Mois demandé (par défaut : mois courant). Validé strictement YYYY-MM.
        $mois = Request::query('mois', date('Y-m'));
        if (!is_string($mois) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) !== 1) {
            Response::error("Le paramètre 'mois' doit être au format YYYY-MM", 422);
        }

        $premierJour = $mois . '-01';
        $dernierJour = date('Y-m-t', strtotime($premierJour));
        $nbJours = (int) date('t', strtotime($premierJour));

        // 1) Pointages du mois, indexés par date.
        $stmt = $db->prepare(
            'SELECT date, statut, heure_entree, heure_sortie, retard_minutes
             FROM pointage
             WHERE employe_id = ? AND date BETWEEN ? AND ?'
        );
        $stmt->execute([$employeId, $premierJour, $dernierJour]);
        $pointages = [];
        foreach ($stmt->fetchAll() as $p) {
            $pointages[(string) $p['date']] = $p;
        }

        // 2) Jours fériés du mois : map [Y-m-d => libelle].
        $feries = JourFerieController::map($db, $premierJour, $dernierJour);

        // 3) Planning de l'employé (jours travaillés) pour distinguer repos / absence.
        $planning = Presence::planning($db, $employeId);

        $aujourdhui = date('Y-m-d');
        $jours = [];
        for ($d = 1; $d <= $nbJours; $d++) {
            $date = sprintf('%s-%02d', $mois, $d);
            $jourIso = (int) date('N', strtotime($date));
            $estTravaille = isset($planning['jours'][$jourIso]);
            $pointage = $pointages[$date] ?? null;

            // AUTO-PARTI : seulement pour le JOUR COURANT (les jours passés gardent
            // leur statut historique). Fenêtre du jour pour comparer à l'heure de fin.
            $fenetre = $date === $aujourdhui ? Presence::fenetreJour($planning, $date) : null;

            [$etat, $libelle] = $this->etatJour(
                $date,
                $aujourdhui,
                $pointage,
                isset($feries[$date]) ? (string) $feries[$date] : null,
                $estTravaille,
                (string) $employe['statut'],
                $fenetre,
                $debutService
            );

            $jours[] = [
                'date'           => $date,
                'jour_semaine'   => $jourIso,
                'etat'           => $etat,
                'libelle'        => $libelle,
                'heure_entree'   => $pointage['heure_entree'] ?? null,
                'heure_sortie'   => $pointage['heure_sortie'] ?? null,
                'retard_minutes' => $pointage !== null ? (int) $pointage['retard_minutes'] : 0,
            ];
        }

        Response::json([
            'employe' => [
                'id'        => (int) $employe['id'],
                'matricule' => (string) $employe['matricule'],
                'name'      => (string) $employe['name'],
                'statut'    => (string) $employe['statut'],
            ],
            'mois'  => $mois,
            'jours' => $jours,
        ]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Dérive l'état « live » d'un employé. Le congé prime sur l'état de session :
     * un employé en congé reste 'Conge' même si une session traîne ouverte.
     *
     * @return array{0:string,1:string} [live, detail]
     */
    private function etatLive(string $employeStatut, ?string $sessionStatut, ?string $poste): array
    {
        if ($employeStatut === 'conge') {
            return ['Conge', 'En congé'];
        }

        if ($sessionStatut === 'ouverte') {
            return ['En activite', $poste !== null ? "Sur le poste $poste" : 'Session active'];
        }

        if ($sessionStatut === 'verrouillee') {
            return ['En pause', $poste !== null ? "Poste $poste verrouillé" : 'Poste verrouillé'];
        }

        return ['Absent', 'Aucune session en cours'];
    }

    /**
     * Dérive l'état d'un jour de calendrier. Ordre de priorité :
     *   férié > pointage 'conge'/employé en congé > pointage (present/retard/absent)
     *   > repos (jour non travaillé) > futur (date à venir) > absent (jour passé sans pointage).
     *
     * @param array<string,mixed>|null $pointage Ligne pointage du jour (ou null).
     * @return array{0:string,1:string} [etat, libelle]
     */
    private function etatJour(
        string $date,
        string $aujourdhui,
        ?array $pointage,
        ?string $ferieLibelle,
        bool $estTravaille,
        string $employeStatut,
        ?array $fenetre = null,
        string $debutService = ''
    ): array {
        if ($ferieLibelle !== null) {
            return ['ferie', $ferieLibelle];
        }

        // Pointage marqué congé, ou employé globalement en congé.
        if (($pointage !== null && (string) $pointage['statut'] === 'conge') || $employeStatut === 'conge') {
            return ['conge', 'En congé'];
        }

        if ($pointage !== null) {
            $statut = (string) $pointage['statut'];
            // AUTO-PARTI (jour courant) : present/retard bascule 'parti' si la fin du
            // jour est dépassée. $fenetre n'est fourni que pour le jour courant.
            if ($statut === 'parti'
                || ($fenetre !== null && Presence::estAutoParti($statut, $fenetre))) {
                return ['parti', 'Parti'];
            }
            if ($statut === 'present') {
                return ['present', 'Présent'];
            }
            if ($statut === 'retard') {
                $min = (int) $pointage['retard_minutes'];
                return ['retard', $min > 0 ? "Retard de $min min" : 'En retard'];
            }
            if ($statut === 'absent') {
                return ['absent', 'Absent'];
            }
            // Statut inconnu / null mais pointage présent : on considère présent.
            return ['present', 'Présent'];
        }

        // Aucun pointage : repos si jour non travaillé, sinon futur/absence selon la date.
        if (!$estTravaille) {
            return ['repos', 'Jour de repos'];
        }
        if ($date > $aujourdhui) {
            return ['futur', 'À venir'];
        }
        // Avant l'entrée en service (embauche/création) : aucune donnée -> 'na', jamais 'absent'.
        if ($debutService !== '' && $date <= $debutService) {
            return ['na', 'Non enregistré'];
        }

        return ['absent', 'Absent'];
    }
}
