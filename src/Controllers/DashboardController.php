<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Presence;
use MadMen\Core\Response;

final class DashboardController
{
    /** Résumé présence du jour. */
    public function presence(): void
    {
        $db = Database::connection();
        $today = date('Y-m-d');

        // En congé AUJOURD'HUI : statut administratif 'conge' OU un jour de congé posé
        // sur une plage (pointage.statut='conge' du jour). Couvre les deux mécanismes.
        $enConge = (int) $db->query(
            "SELECT COUNT(*) FROM employe e
             WHERE e.statut = 'conge'
                OR EXISTS (SELECT 1 FROM pointage p
                           WHERE p.employe_id = e.id AND p.date = CURDATE() AND p.statut = 'conge')"
        )->fetchColumn();

        $totalActifs = (int) $db->query(
            "SELECT COUNT(*) FROM employe WHERE statut = 'actif' AND COALESCE(role, '') <> 'super_admin'"
        )->fetchColumn();

        $actifs = (int) $db->query(
            "SELECT COUNT(DISTINCT employe_id) FROM session_travail WHERE statut = 'ouverte'"
        )->fetchColumn();

        $inactifs = (int) $db->query(
            "SELECT COUNT(DISTINCT employe_id) FROM session_travail WHERE statut = 'verrouillee'"
        )->fetchColumn();

        // Détail PAR AGENT (additif) pour le tableau de présence du dashboard.
        // On récupère aussi l'horaire de l'employé (fenêtre de pause + heure de fin de jour).
        $agents = $db->query(
            "SELECT e.id, e.matricule, TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
                    d.nom AS departement_nom, p.intitule AS poste_libelle,
                    COALESCE(pt.statut, IF(e.statut = 'conge', 'conge', 'absent')) AS statut,
                    pt.heure_entree AS arrivee,
                    he.heure_arrivee, he.heure_depart, he.pause_debut, he.pause_fin
             FROM employe e
             LEFT JOIN departement d      ON d.id = e.departement_id
             LEFT JOIN poste p            ON p.id = e.poste_id
             LEFT JOIN pointage pt        ON pt.employe_id = e.id AND pt.date = CURDATE()
             LEFT JOIN horaire_employe he ON he.employe_id = e.id
             WHERE e.statut <> 'suspendu' AND COALESCE(e.role, '') <> 'super_admin'
             ORDER BY e.nom, e.prenom"
        )->fetchAll();

        // ÉTAT LIVE par agent : En activité / En pause / Pas revenu de pause /
        // Jamais revenu de pause / Parti / Absent / Congé (cf. Presence::etatLive).
        // Données en UN SEUL lot de requêtes (pas de N+1, cf. note de perf historique).
        //
        // 1) Dernier passage K40 du jour par employé -> au bureau ? + horodatage de la sortie.
        $dernierPassage = [];
        foreach ($db->query(
            "SELECT employe_id, type, horodatage FROM (
                SELECT employe_id, type, horodatage,
                       ROW_NUMBER() OVER (PARTITION BY employe_id ORDER BY horodatage DESC, id DESC) rn
                FROM pointage_passage WHERE date = CURDATE()
             ) t WHERE rn = 1"
        )->fetchAll() as $row) {
            $dernierPassage[(int) $row['employe_id']] = [
                'type' => $row['type'],
                'ts'   => (string) $row['horodatage'],
            ];
        }

        // 2) Application de la règle (Presence::etatLive) + comptage des présents.
        $now = date('Y-m-d H:i:s');
        $def = Presence::defaultHoraire(); // horaire global de repli (une seule fois)
        $presents = 0;
        foreach ($agents as &$agent) {
            $dp = $dernierPassage[(int) $agent['id']] ?? null;
            $type = $dp['type'] ?? null;
            $aPointe = $agent['arrivee'] !== null;
            // Au bureau si le dernier passage est une entrée ; repli sur le statut si aucun passage détaillé.
            $presentMaintenant = $type !== null
                ? ($type === 'entree')
                : ((string) $agent['statut'] !== 'parti');
            // Horodatage de la dernière sortie (sert à savoir si c'était une sortie de pause).
            $sortieTs = $type === 'sortie' ? ($dp['ts'] ?? null) : null;
            // Horaire effectif de l'employé (repli sur l'horaire global quand non défini).
            $h = [
                'debut'          => $agent['heure_arrivee'] !== null ? substr((string) $agent['heure_arrivee'], 0, 5) : $def['debut'],
                'fin'            => $agent['heure_depart'] !== null ? substr((string) $agent['heure_depart'], 0, 5) : $def['fin'],
                'dejeuner_debut' => $agent['pause_debut'] !== null ? substr((string) $agent['pause_debut'], 0, 5) : $def['dejeuner_debut'],
                'dejeuner_fin'   => $agent['pause_fin'] !== null ? substr((string) $agent['pause_fin'], 0, 5) : $def['dejeuner_fin'],
            ];

            $agent['statut'] = Presence::etatLive((string) $agent['statut'], $aPointe, $presentMaintenant, $now, $h, $sortieTs);
            // Colonnes internes : ne pas exposer dans la réponse.
            unset($agent['heure_arrivee'], $agent['heure_depart'], $agent['pause_debut'], $agent['pause_fin']);

            if (in_array($agent['statut'], ['present', 'retard', 'pause'], true)) {
                $presents++; // « présents » = au bureau OU en pause (cohérent avec le front)
            }
        }
        unset($agent); // casse la référence du foreach

        Response::json([
            'presents' => $presents,
            'absents'  => max(0, $totalActifs - $presents),
            'en_conge' => $enConge,
            'actifs'   => $actifs,
            'inactifs' => $inactifs,
            'agents'   => $agents,
        ]);
    }
}
