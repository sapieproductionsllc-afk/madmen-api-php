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

        $presents = (int) $db->query(
            "SELECT COUNT(DISTINCT employe_id) FROM pointage
             WHERE date = CURDATE() AND heure_entree IS NOT NULL"
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

        Response::json([
            'presents' => $presents,
            'absents'  => max(0, $totalActifs - $presents),
            'en_conge' => $enConge,
            'actifs'   => $actifs,
            'inactifs' => $inactifs,
        ]);
    }
}
