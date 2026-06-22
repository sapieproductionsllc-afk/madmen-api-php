<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class RapportController
{
    /**
     * Synthèse agrégée pour la page Rapports & Analyses.
     * Query : from (YYYY-MM-DD), to (YYYY-MM-DD), service (nom du département).
     * Sans from/to -> 7 derniers jours (cohérent avec l'UI « 7 derniers jours »).
     * Lecture seule, agrégats SQL.
     *
     * Réponse : {
     *   presence: int (% présence hors congés),
     *   donut: { presents, retards, absents, conges },
     *   tendance: float[] (taux de productivité moyen, un point par jour, 7 jours),
     *   tempsEcranMoyen: int (minutes : travail - inactivité, plancher 0)
     * }
     */
    public function synthese(): void
    {
        $db = Database::connection();

        // --- Bornes de période (défaut : 7 derniers jours) ---
        $from = Request::query('from');
        $to = Request::query('to');
        if ($from === null || $to === null || $from === '' || $to === '') {
            $from = null;
            $to = null;
        }

        // --- Filtre service (nom de département) ---
        $service = Request::query('service');
        if ($service === '' || $service === 'Tous services' || $service === 'Tous') {
            $service = null;
        }

        // === Donut : répartition des pointages par statut ===
        $pointWhere = [];
        $pointParams = [];
        if ($from !== null && $to !== null) {
            $pointWhere[] = 'pt.date BETWEEN :from AND :to';
            $pointParams['from'] = $from;
            $pointParams['to'] = $to;
        } else {
            $pointWhere[] = 'pt.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        }
        if ($service !== null) {
            $pointWhere[] = 'dep.nom = :service';
            $pointParams['service'] = $service;
        }
        $pointWhereSql = 'WHERE ' . implode(' AND ', $pointWhere);

        $donutStmt = $db->prepare(
            "SELECT
                SUM(pt.statut = 'present') AS presents,
                SUM(pt.statut = 'retard')  AS retards,
                SUM(pt.statut = 'absent')  AS absents,
                SUM(pt.statut = 'conge')   AS conges
             FROM pointage pt
             JOIN employe e ON e.id = pt.employe_id
             LEFT JOIN departement dep ON dep.id = e.departement_id
             $pointWhereSql"
        );
        $donutStmt->execute($pointParams);
        $donutRow = $donutStmt->fetch() ?: [];

        $presents = (int) ($donutRow['presents'] ?? 0);
        $retards = (int) ($donutRow['retards'] ?? 0);
        $absents = (int) ($donutRow['absents'] ?? 0);
        $conges = (int) ($donutRow['conges'] ?? 0);

        // Taux de présence (hors congés) : (présents + retards) / (présents + retards + absents).
        $base = $presents + $retards + $absents;
        $presence = $base > 0 ? (int) round(($presents + $retards) / $base * 100) : 0;

        // === Productivité (tendance + temps écran) sur productivite_jour ===
        $prodWhere = [];
        $prodParams = [];
        if ($from !== null && $to !== null) {
            $prodWhere[] = 'pj.date BETWEEN :from AND :to';
            $prodParams['from'] = $from;
            $prodParams['to'] = $to;
        } else {
            $prodWhere[] = 'pj.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        }
        if ($service !== null) {
            $prodWhere[] = 'dep.nom = :service';
            $prodParams['service'] = $service;
        }
        $prodWhereSql = 'WHERE ' . implode(' AND ', $prodWhere);

        // Tendance : taux de productivité moyen par jour (ordre chronologique).
        $tendanceStmt = $db->prepare(
            "SELECT pj.date AS jour, ROUND(AVG(pj.taux_productivite), 1) AS taux
             FROM productivite_jour pj
             JOIN employe e ON e.id = pj.employe_id
             LEFT JOIN departement dep ON dep.id = e.departement_id
             $prodWhereSql
             GROUP BY pj.date
             ORDER BY pj.date ASC"
        );
        $tendanceStmt->execute($prodParams);
        $tendance = array_map(
            static fn (array $r): float => (float) $r['taux'],
            $tendanceStmt->fetchAll()
        );

        // Temps écran moyen : moyenne de (travail - inactivité) en minutes, plancher 0.
        $ecranStmt = $db->prepare(
            "SELECT ROUND(AVG(GREATEST(
                        COALESCE(pj.temps_travaille_min, 0) - COALESCE(pj.temps_inactivite_min, 0),
                        0))) AS ecran
             FROM productivite_jour pj
             JOIN employe e ON e.id = pj.employe_id
             LEFT JOIN departement dep ON dep.id = e.departement_id
             $prodWhereSql"
        );
        $ecranStmt->execute($prodParams);
        $tempsEcranMoyen = (int) ($ecranStmt->fetchColumn() ?: 0);

        Response::json([
            'presence' => $presence,
            'donut' => [
                'presents' => $presents,
                'retards'  => $retards,
                'absents'  => $absents,
                'conges'   => $conges,
            ],
            'tendance' => $tendance,
            'tempsEcranMoyen' => $tempsEcranMoyen,
        ]);
    }
}
