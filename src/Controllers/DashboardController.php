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

        $enConge = (int) $db->query(
            "SELECT COUNT(*) FROM employe WHERE statut = 'conge'"
        )->fetchColumn();

        $totalActifs = (int) $db->query(
            "SELECT COUNT(*) FROM employe WHERE statut = 'actif'"
        )->fetchColumn();

        $actifs = (int) $db->query(
            "SELECT COUNT(DISTINCT employe_id) FROM session_travail WHERE statut = 'ouverte'"
        )->fetchColumn();

        $inactifs = (int) $db->query(
            "SELECT COUNT(DISTINCT employe_id) FROM session_travail WHERE statut = 'verrouillee'"
        )->fetchColumn();

        // Détail PAR AGENT (additif) pour le tableau de présence du dashboard.
        $agents = $db->query(
            "SELECT e.id, e.matricule, TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
                    d.nom AS departement_nom, p.intitule AS poste_libelle,
                    COALESCE(pt.statut, IF(e.statut = 'conge', 'conge', 'absent')) AS statut,
                    pt.heure_entree AS arrivee,
                    he.pause_debut, he.pause_fin
             FROM employe e
             LEFT JOIN departement d      ON d.id = e.departement_id
             LEFT JOIN poste p            ON p.id = e.poste_id
             LEFT JOIN pointage pt        ON pt.employe_id = e.id AND pt.date = CURDATE()
             LEFT JOIN horaire_employe he ON he.employe_id = e.id
             WHERE e.statut <> 'suspendu'
             ORDER BY e.nom, e.prenom"
        )->fetchAll();

        // ÉTAT LIVE par agent : En activité / En pause / Parti / Absent / Congé.
        // Règle : au bureau => présent ; ressorti pendant la pause déjeuner => EN PAUSE ;
        // ressorti hors de cette fenêtre => PARTI ; jamais pointé => ABSENT.
        // Données en UN SEUL lot de requêtes (pas de N+1, cf. note de perf historique).
        //
        // 1) Dernier passage K40 du jour par employé -> est-il ACTUELLEMENT au bureau ?
        $dernierPassage = [];
        foreach ($db->query(
            "SELECT employe_id, type FROM (
                SELECT employe_id, type,
                       ROW_NUMBER() OVER (PARTITION BY employe_id ORDER BY horodatage DESC, id DESC) rn
                FROM pointage_passage WHERE date = CURDATE()
             ) t WHERE rn = 1"
        )->fetchAll() as $row) {
            $dernierPassage[(int) $row['employe_id']] = $row['type'];
        }

        // 2) Application de la règle (Presence::etatLive) + comptage des présents.
        $now = date('Y-m-d H:i:s');
        $presents = 0;
        foreach ($agents as &$agent) {
            $type = $dernierPassage[(int) $agent['id']] ?? null;
            $aPointe = $agent['arrivee'] !== null;
            // Au bureau si le dernier passage est une entrée ; repli sur le statut si aucun passage détaillé.
            $presentMaintenant = $type !== null
                ? ($type === 'entree')
                : ((string) $agent['statut'] !== 'parti');
            // Fenêtre de pause de l'employé ; null => fenêtre globale (config 12:30–14:00).
            $h = ($agent['pause_debut'] !== null && $agent['pause_fin'] !== null)
                ? [
                    'dejeuner_debut' => substr((string) $agent['pause_debut'], 0, 5),
                    'dejeuner_fin'   => substr((string) $agent['pause_fin'], 0, 5),
                ]
                : null;

            $agent['statut'] = Presence::etatLive((string) $agent['statut'], $aPointe, $presentMaintenant, $now, $h);
            unset($agent['pause_debut'], $agent['pause_fin']); // colonnes internes : ne pas exposer

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
