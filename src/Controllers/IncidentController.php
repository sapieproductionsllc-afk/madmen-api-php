<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Consultation des incidents d'inactivité (absences pendant une session) avec
 * leur MOTIF et leur justification : « où était l'employé et ce qu'il faisait ».
 * Lecture seule, destinée aux superviseurs/managers.
 */
final class IncidentController
{
    /** GET /api/incidents?employe_id=&statut=&from=YYYY-MM-DD&to=YYYY-MM-DD&limit= */
    public function index(): void
    {
        $sql = "SELECT i.id, i.session_id, i.employe_id,
                       CONCAT(e.prenom, ' ', e.nom) AS employe,
                       i.poste_travail_id, i.heure_verrouillage, i.heure_reprise,
                       i.duree_minutes, i.motif_id, m.libelle AS motif,
                       i.justification, i.statut
                FROM incident_inactivite i
                LEFT JOIN employe e ON e.id = i.employe_id
                LEFT JOIN motif_absence m ON m.id = i.motif_id
                WHERE 1=1";
        $params = [];

        if (($emp = Request::query('employe_id')) !== null && ctype_digit((string) $emp)) {
            $sql .= ' AND i.employe_id = :emp';
            $params['emp'] = (int) $emp;
        }
        if (($statut = Request::query('statut')) !== null && is_string($statut)) {
            $sql .= ' AND i.statut = :statut';
            $params['statut'] = $statut;
        }
        $from = Request::query('from');
        $to = Request::query('to');
        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $sql .= ' AND i.heure_verrouillage >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $sql .= ' AND i.heure_verrouillage <= :to';
            $params['to'] = $to . ' 23:59:59';
        }

        $limit = max(1, min(500, (int) (Request::query('limit', 100))));
        $sql .= ' ORDER BY i.id DESC LIMIT ' . $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }
}
