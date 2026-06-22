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
    private function donnees(): array
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

        return [
            'presence' => $presence,
            'donut' => [
                'presents' => $presents,
                'retards'  => $retards,
                'absents'  => $absents,
                'conges'   => $conges,
            ],
            'tendance' => $tendance,
            'tempsEcranMoyen' => $tempsEcranMoyen,
        ];
    }

    /** GET /api/rapports/synthese — agrégats JSON. */
    public function synthese(): void
    {
        Response::json($this->donnees());
    }

    /**
     * GET /api/rapports/export — page HTML prête à imprimer (Enregistrer en PDF côté
     * navigateur). Sans dépendance serveur ; ne touche pas le front. Une vraie sortie
     * PDF « brandée » nécessiterait dompdf (cf. docs/INTEGRATION-FRONT.md §4.9).
     */
    public function export(): void
    {
        $d = $this->donnees();
        $g = $d['donut'];
        $date = date('d/m/Y H:i');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<title>MadMen - Rapport de synthese</title>'
            . '<style>@page{size:A4;margin:18mm}body{font-family:Arial,Helvetica,sans-serif;color:#1a1a1a}'
            . 'h1{font-size:20px;margin:0 0 4px}.sub{color:#666;font-size:12px;margin:0 0 18px}'
            . 'table{border-collapse:collapse;width:100%;margin:10px 0}td,th{border:1px solid #ccc;padding:8px 10px;text-align:left;font-size:13px}'
            . 'th{background:#f2f2f2}.kpi{font-size:28px;font-weight:700;margin:0}.muted{color:#777;font-size:11px;margin:0 0 14px}'
            . '@media print{button{display:none}}</style></head><body>'
            . '<h1>MadMen &mdash; Rapport de synthese</h1>'
            . '<p class="sub">Genere le ' . $date . '</p>'
            . '<p class="kpi">' . (int) $d['presence'] . ' %</p><p class="muted">Taux de presence (hors conges)</p>'
            . '<table><tr><th>Presents</th><th>Retards</th><th>Absents</th><th>Conges</th><th>Temps ecran moyen</th></tr>'
            . '<tr><td>' . (int) $g['presents'] . '</td><td>' . (int) $g['retards'] . '</td><td>' . (int) $g['absents'] . '</td><td>' . (int) $g['conges'] . '</td><td>' . (int) $d['tempsEcranMoyen'] . ' min</td></tr></table>'
            . '<button onclick="window.print()">Imprimer / Enregistrer en PDF</button>'
            . '<script>window.onload=function(){setTimeout(function(){window.print();},300);};</script>'
            . '</body></html>';
        exit;
    }
}
