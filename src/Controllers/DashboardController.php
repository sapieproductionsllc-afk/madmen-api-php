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
                    pt.heure_entree AS arrivee
             FROM employe e
             LEFT JOIN departement d ON d.id = e.departement_id
             LEFT JOIN poste p       ON p.id = e.poste_id
             LEFT JOIN pointage pt   ON pt.employe_id = e.id AND pt.date = CURDATE()
             WHERE e.statut <> 'suspendu'
             ORDER BY e.nom, e.prenom"
        )->fetchAll();

        // AUTO-PARTI (affichage) : un agent 'present'/'retard' dont l'heure de fin
        // prévue du jour est dépassée est affiché 'parti'. Calcul en PHP via le
        // planning de chaque employé. Mémo planning pour éviter les requêtes redondantes.
        // Présents = ENTRÉE pointée aujourd'hui ET ni 'parti' ni AUTO-parti.
        // Présents = entrée pointée aujourd'hui ET statut non 'parti'.
        // (AUTO-PARTI par planning retiré : Presence::estAutoParti renvoie toujours false —
        //  on évitait ici une requête planning PAR employé à chaque appel dashboard.)
        $presents = 0;
        foreach ($agents as $agent) {
            if ($agent['arrivee'] !== null && (string) $agent['statut'] !== 'parti') {
                $presents++;
            }
        }

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
