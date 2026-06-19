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

        Response::json([
            'employe' => $employe,
            'periode' => Request::query('periode', 'tout'),
            'resume'  => $resume,
            'serie'   => $serieStmt->fetchAll(),
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
