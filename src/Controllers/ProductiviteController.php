<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class ProductiviteController
{
    private const PERIODES = ['mois' => 30, 'trimestre' => 90, 'semestre' => 180];

    /**
     * Classement des employés par taux de productivité moyen.
     * Query : periode (mois|trimestre|semestre|tout), from, to, departement_id, limit, ordre (asc|desc).
     */
    public function classement(): void
    {
        $db = Database::connection();
        [$where, $params] = $this->periodeWhere('p.date');

        if (($dep = Request::query('departement_id')) !== null) {
            $where[] = 'e.departement_id = :dep';
            $params['dep'] = (int) $dep;
        }

        $ordre = strtolower((string) Request::query('ordre', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min(100, (int) Request::query('limit', 20)));
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
                    e.id,
                    e.matricule,
                    CONCAT(e.prenom, ' ', e.nom) AS nom,
                    dep.nom AS departement,
                    ROUND(AVG(p.taux_productivite), 1) AS taux_moyen,
                    ROUND(SUM(p.temps_travaille_min) / 60, 1) AS heures_travaillees,
                    ROUND(SUM(p.temps_inactivite_min) / 60, 1) AS heures_inactivite,
                    SUM(p.retard_minutes) AS retard_total_min,
                    SUM(p.nb_arrets) AS total_arrets,
                    COUNT(*) AS jours_travailles
                FROM productivite_jour p
                JOIN employe e ON e.id = p.employe_id
                LEFT JOIN departement dep ON dep.id = e.departement_id
                $whereSql
                GROUP BY e.id, e.matricule, nom, dep.nom
                ORDER BY taux_moyen $ordre, heures_travaillees $ordre
                LIMIT $limit";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Ajout du rang
        $rang = 1;
        foreach ($rows as &$row) {
            $row['rang'] = $rang++;
            $row['taux_moyen'] = (float) $row['taux_moyen'];
            $row['heures_travaillees'] = (float) $row['heures_travaillees'];
            $row['heures_inactivite'] = (float) $row['heures_inactivite'];
            $row['retard_total_min'] = (int) $row['retard_total_min'];
            $row['total_arrets'] = (int) $row['total_arrets'];
            $row['jours_travailles'] = (int) $row['jours_travailles'];
        }
        unset($row);

        Response::json([
            'periode'    => Request::query('periode', 'tout'),
            'classement' => $rows,
        ]);
    }

    /** Détail de productivité d'un employé : agrégats + série quotidienne. */
    public function show(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];

        $stmt = $db->prepare("SELECT id, matricule, CONCAT(prenom, ' ', nom) AS nom FROM employe WHERE id = ?");
        $stmt->execute([$id]);
        $employe = $stmt->fetch();
        if (!$employe) {
            Response::error('Employé introuvable', 404);
        }

        [$where, $qp] = $this->periodeWhere('date');
        $where[] = 'employe_id = :id';
        $qp['id'] = $id;
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $agg = $db->prepare(
            "SELECT
                ROUND(AVG(taux_productivite), 1) AS taux_moyen,
                ROUND(SUM(temps_travaille_min) / 60, 1) AS heures_travaillees,
                ROUND(SUM(temps_inactivite_min) / 60, 1) AS heures_inactivite,
                SUM(retard_minutes) AS retard_total_min,
                SUM(nb_arrets) AS total_arrets,
                COUNT(*) AS jours_travailles
             FROM productivite_jour $whereSql"
        );
        $agg->execute($qp);
        $resume = $agg->fetch();

        $serieStmt = $db->prepare(
            "SELECT date, temps_presence_min, temps_travaille_min, temps_inactivite_min,
                    nb_arrets, retard_minutes, taux_productivite
             FROM productivite_jour $whereSql ORDER BY date ASC"
        );
        $serieStmt->execute($qp);

        // Rang de l'employé parmi tous (même période) + effectif classé.
        [$gWhere, $gp] = $this->periodeWhere('date');
        $gWhereSql = $gWhere ? 'WHERE ' . implode(' AND ', $gWhere) : '';

        $totalStmt = $db->prepare("SELECT COUNT(DISTINCT employe_id) FROM productivite_jour $gWhereSql");
        $totalStmt->execute($gp);
        $total = (int) $totalStmt->fetchColumn();

        $rangStmt = $db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT employe_id FROM productivite_jour $gWhereSql
                GROUP BY employe_id HAVING AVG(taux_productivite) > :mt
             ) sup"
        );
        $rgp = $gp;
        $rgp['mt'] = (float) ($resume['taux_moyen'] ?? 0);
        $rangStmt->execute($rgp);
        $rang = $total > 0 ? ((int) $rangStmt->fetchColumn()) + 1 : 0;

        // Tendance : moyenne 7 derniers jours vs les 7 précédents (%), pour cet employé.
        $tStmt = $db->prepare(
            "SELECT
                AVG(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN taux_productivite END) AS cur,
                AVG(CASE WHEN date <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          AND date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN taux_productivite END) AS prev
             FROM productivite_jour WHERE employe_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)"
        );
        $tStmt->execute([$id]);
        $t = $tStmt->fetch() ?: [];
        $tcur = (float) ($t['cur'] ?? 0);
        $tprev = (float) ($t['prev'] ?? 0);
        $tendance = $tprev > 0 ? round(($tcur - $tprev) / $tprev * 100, 1) : 0.0;

        Response::json([
            'employe'  => $employe,
            'periode'  => Request::query('periode', 'tout'),
            'resume'   => $resume,
            'rang'     => $rang,
            'total'    => $total,
            'tendance' => $tendance,
            'serie'    => $serieStmt->fetchAll(),
        ]);
    }

    /**
     * GET /api/productivite/global — agrégat ENTREPRISE (bloc haut du dashboard) :
     * { value, series (12 jours), weeklyGrowth, tempsTravailleMoyen, inactiviteMoyenne }.
     */
    public function globale(): void
    {
        $db = Database::connection();

        $resume = $db->query(
            "SELECT ROUND(AVG(taux_productivite), 1) AS value,
                    ROUND(AVG(temps_travaille_min))  AS temps_travaille_moyen,
                    ROUND(AVG(temps_inactivite_min)) AS inactivite_moyenne
             FROM productivite_jour WHERE date >= DATE_SUB(CURDATE(), INTERVAL 12 DAY)"
        )->fetch() ?: [];

        $serie = $db->query(
            "SELECT ROUND(AVG(taux_productivite), 1) AS taux
             FROM productivite_jour WHERE date >= DATE_SUB(CURDATE(), INTERVAL 12 DAY)
             GROUP BY date ORDER BY date ASC"
        )->fetchAll();
        $series = array_map(static fn (array $r): float => (float) $r['taux'], $serie);

        $w = $db->query(
            "SELECT
                AVG(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN taux_productivite END) AS cur,
                AVG(CASE WHEN date <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          AND date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN taux_productivite END) AS prev
             FROM productivite_jour WHERE date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)"
        )->fetch() ?: [];
        $cur = (float) ($w['cur'] ?? 0);
        $prev = (float) ($w['prev'] ?? 0);
        $weeklyGrowth = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : 0.0;

        Response::json([
            'value'               => (float) ($resume['value'] ?? 0),
            'series'              => $series,
            'weeklyGrowth'        => $weeklyGrowth,
            'tempsTravailleMoyen' => (int) ($resume['temps_travaille_moyen'] ?? 0),
            'inactiviteMoyenne'   => (int) ($resume['inactivite_moyenne'] ?? 0),
        ]);
    }

    /**
     * Construit la clause de période à partir de periode|from|to.
     * @return array{0: string[], 1: array<string,mixed>}
     */
    private function periodeWhere(string $col): array
    {
        $where = [];
        $params = [];

        $from = Request::query('from');
        $to = Request::query('to');
        $periode = Request::query('periode');

        if ($from !== null && $to !== null) {
            $where[] = "$col BETWEEN :from AND :to";
            $params['from'] = $from;
            $params['to'] = $to;
        } elseif ($periode !== null && isset(self::PERIODES[$periode])) {
            $where[] = "$col >= DATE_SUB(CURDATE(), INTERVAL " . self::PERIODES[$periode] . ' DAY)';
        }
        // periode=tout ou rien -> aucun filtre de date

        return [$where, $params];
    }
}
