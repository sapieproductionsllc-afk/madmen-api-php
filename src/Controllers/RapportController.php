<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Presence;
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
     * GET /api/rapports/feuille-temps?periode=semaine|mois&date=YYYY-MM-DD
     * Feuille de temps DÉTAILLÉE par employé pour la période : matrice employé × jour
     * (état + arrivée/départ/heures/retard) + totaux par employé + KPI globaux.
     * Source : table pointage (statut/heures/retard) + jours fériés. Jour sans pointage :
     * futur (à venir), férié, repos (sam/dim) ou absent (jour ouvré passé).
     */
    public function feuilleTemps(): void
    {
        $db = Database::connection();

        $periode = Request::query('periode') === 'mois' ? 'mois' : 'semaine';
        $ref = Request::query('date');
        if (!is_string($ref) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) !== 1) {
            $ref = date('Y-m-d');
        }
        $d = new \DateTime($ref);
        if ($periode === 'mois') {
            $debut = (clone $d)->modify('first day of this month');
            $fin   = (clone $d)->modify('last day of this month');
        } else {
            $dow = (int) $d->format('N'); // 1=lundi .. 7=dimanche
            $debut = (clone $d)->modify('-' . ($dow - 1) . ' days');
            $fin   = (clone $debut)->modify('+6 days');
        }
        $debutStr = $debut->format('Y-m-d');
        $finStr   = $fin->format('Y-m-d');

        $jours = [];
        for ($c = clone $debut; $c <= $fin; $c->modify('+1 day')) {
            $jours[] = $c->format('Y-m-d');
        }

        $ferieStmt = $db->prepare('SELECT date FROM jour_ferie WHERE date BETWEEN ? AND ?');
        $ferieStmt->execute([$debutStr, $finStr]);
        $feries = array_fill_keys(array_column($ferieStmt->fetchAll(), 'date'), true);

        $employes = $db->query(
            "SELECT e.id, e.matricule, TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
                    d.nom AS departement, p.intitule AS poste,
                    DATE(e.created_at) AS cree_le, e.date_embauche
             FROM employe e
             LEFT JOIN departement d ON d.id = e.departement_id
             LEFT JOIN poste p       ON p.id = e.poste_id
             WHERE e.statut <> 'archive'
               AND COALESCE(e.role, '') <> 'super_admin'  -- les comptes admin ne pointent pas
             ORDER BY e.nom, e.prenom"
        )->fetchAll();

        $ptStmt = $db->prepare(
            "SELECT employe_id, date,
                    TIME_FORMAT(heure_entree, '%H:%i') AS arrivee,
                    TIME_FORMAT(heure_sortie, '%H:%i') AS depart,
                    CASE WHEN heure_entree IS NOT NULL AND heure_sortie IS NOT NULL
                         THEN TIMESTAMPDIFF(MINUTE, heure_entree, heure_sortie) END AS minutes,
                    retard_minutes, statut
             FROM pointage
             WHERE date BETWEEN ? AND ?"
        );
        $ptStmt->execute([$debutStr, $finStr]);
        $parEmpJour = [];
        foreach ($ptStmt->fetchAll() as $r) {
            $parEmpJour[$r['employe_id'] . '|' . $r['date']] = $r;
        }

        $today = date('Y-m-d');
        $workers = [];
        $kpi = ['ponctuels' => 0, 'retards' => 0, 'absents' => 0, 'conges' => 0, 'retard_total_min' => 0, 'heures_total_min' => 0];

        foreach ($employes as $emp) {
            $cells = [];
            $tPresents = 0; $tRetards = 0; $tRetardMin = 0; $tHeuresMin = 0; $tAbsents = 0; $tConges = 0;
            // Début de suivi : avant cette date l'employé n'existait pas dans le système
            // (enregistré le `cree_le`) ou n'avait pas encore commencé (date_embauche future).
            // Les jours antérieurs sont 'na' (aucune donnée), JAMAIS 'absent'.
            $debutSuivi = (string) ($emp['cree_le'] ?? '');
            $embauche = $emp['date_embauche'] ? substr((string) $emp['date_embauche'], 0, 10) : null;
            if ($embauche !== null && ($debutSuivi === '' || $embauche > $debutSuivi)) {
                $debutSuivi = $embauche;
            }
            // Planning PROPRE à l'employé -> repos vs absent selon SES jours travaillés
            // (et non un week-end Sam/Dim codé en dur qui contredit le calendrier/paie).
            $planningEmp = Presence::planning($db, (int) $emp['id']);
            foreach ($jours as $jour) {
                $cell = ['date' => $jour];
                $row = $parEmpJour[$emp['id'] . '|' . $jour] ?? null;
                if ($row !== null) {
                    $st = $row['statut'];
                    if ($st === 'conge') {
                        $cell['etat'] = 'conge';
                        $tConges++;
                    } elseif ($st === 'absent') {
                        $cell['etat'] = 'absent';
                        $tAbsents++;
                    } else { // present / retard / parti = jour travaillé
                        $retard = (int) $row['retard_minutes'];
                        // retard_minutes est DÉJÀ net de la grâce (appliquée au stockage) : une
                        // valeur > 0 = déjà au-delà des 5 min. Ne PAS réappliquer la grâce ici
                        // (double grâce -> retards sous-comptés / ponctuels sur-comptés).
                        $enRetard = $retard > 0;
                        $cell['etat'] = $enRetard ? 'retard' : 'present';
                        $cell['arrivee'] = $row['arrivee'];
                        $cell['retard_min'] = $enRetard ? $retard : 0;
                        $mins = $row['minutes'] !== null ? (int) $row['minutes'] : null;
                        // Sortie <= entrée => journée INCOMPLÈTE (encore présent OU pointage de
                        // sortie manquant). Pas de départ réel ni d'heures -> évite le faux
                        // « 08:49 -> 08:49 = 0h00 ». Le statut (présent/retard) reste valable.
                        if ($mins !== null && $mins > 0) {
                            $cell['depart'] = $row['depart'];
                            $cell['minutes'] = $mins;
                            $tHeuresMin += $mins;
                        } else {
                            $cell['depart'] = null;
                            $cell['minutes'] = null;
                            $cell['en_cours'] = true;
                        }
                        $tPresents++;
                        if ($enRetard) { $tRetards++; $tRetardMin += $retard; }
                    }
                } elseif ($jour > $today) {
                    $cell['etat'] = 'futur';
                } elseif ($debutSuivi !== '' && $jour <= $debutSuivi) {
                    // Jour d'enregistrement/embauche OU avant : aucune donnée encore -> 'na',
                    // JAMAIS 'absent' (on ne peut pas être absent le jour même où on est créé).
                    $cell['etat'] = 'na';
                } elseif (isset($feries[$jour])) {
                    $cell['etat'] = 'ferie';
                } elseif (Presence::fenetreJour($planningEmp, $jour) === null) {
                    $cell['etat'] = 'repos'; // jour NON travaillé selon le planning de l'employé
                } else {
                    $cell['etat'] = 'absent';
                    $tAbsents++;
                }
                $cells[] = $cell;
            }
            $workers[] = [
                'id'          => (int) $emp['id'],
                'matricule'   => $emp['matricule'],
                'name'        => $emp['name'],
                'departement' => $emp['departement'],
                'poste'       => $emp['poste'],
                'jours'       => $cells,
                'totaux'      => [
                    'jours_presents' => $tPresents,
                    'retards'        => $tRetards,
                    'retard_min'     => $tRetardMin,
                    'heures_min'     => $tHeuresMin,
                    'absents'        => $tAbsents,
                    'conges'         => $tConges,
                ],
            ];
            $kpi['ponctuels']        += $tPresents - $tRetards;
            $kpi['retards']          += $tRetards;
            $kpi['absents']          += $tAbsents;
            $kpi['conges']           += $tConges;
            $kpi['retard_total_min'] += $tRetardMin;
            $kpi['heures_total_min'] += $tHeuresMin;
        }

        $baseP = $kpi['ponctuels'] + $kpi['retards'] + $kpi['absents'];
        $ponctualite = $baseP > 0 ? (int) round($kpi['ponctuels'] / $baseP * 100) : 0;

        Response::json([
            'periode'    => $periode,
            'date_debut' => $debutStr,
            'date_fin'   => $finStr,
            'jours'      => $jours,
            'effectif'   => count($employes),
            'kpi'        => [
                'ponctualite'      => $ponctualite,
                'retards'          => $kpi['retards'],
                'retard_total_min' => $kpi['retard_total_min'],
                'absents'          => $kpi['absents'],
                'conges'           => $kpi['conges'],
                'heures_total_min' => $kpi['heures_total_min'],
            ],
            'workers'    => $workers,
        ]);
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
