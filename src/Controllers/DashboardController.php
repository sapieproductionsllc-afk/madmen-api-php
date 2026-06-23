<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class DashboardController
{
    /** Résumé présence du jour. */
    public function presence(): void
    {
        $db = Database::connection();

        // Présents = ont pointé une ENTRÉE aujourd'hui ET ne sont pas repartis (statut <> 'parti').
        $presents = (int) $db->query(
            "SELECT COUNT(DISTINCT employe_id) FROM pointage
             WHERE date = CURDATE() AND heure_entree IS NOT NULL AND statut <> 'parti'"
        )->fetchColumn();

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
