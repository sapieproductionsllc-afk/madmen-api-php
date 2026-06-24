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
        $presents = 0;
        $planningCache = [];
        foreach ($agents as &$agent) {
            $statut = (string) $agent['statut'];
            $eid = (int) $agent['id'];
            if (($statut === 'present' || $statut === 'retard') && $agent['arrivee'] !== null) {
                $planningCache[$eid] ??= Presence::planning($db, $eid);
                $fenetre = Presence::fenetreJour($planningCache[$eid], $today);
                if (Presence::estAutoParti($statut, $fenetre)) {
                    $agent['statut'] = 'parti';
                    $statut = 'parti';
                }
            }
            // Compte des présents : entrée pointée et pas (auto-)parti.
            if ($agent['arrivee'] !== null && $statut !== 'parti') {
                $presents++;
            }
        }
        unset($agent);

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
