<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class HeuresSupController
{
    /** Liste des heures supplémentaires, jointes à l'employé (prénom + nom). */
    public function index(): void
    {
        $sql = 'SELECT h.id, h.employe_id, CONCAT(e.prenom, \' \', e.nom) AS employe,
                       h.date, h.heure_debut, h.heure_fin, h.duree_minutes, h.source
                FROM heures_supplementaires h
                JOIN employe e ON e.id = h.employe_id
                WHERE 1=1';
        $params = [];

        if (($emp = Request::query('employe_id')) !== null) {
            $sql .= ' AND h.employe_id = :emp';
            $params['emp'] = $emp;
        }
        if (($date = Request::query('date')) !== null) {
            $sql .= ' AND h.date = :date';
            $params['date'] = $date;
        }
        $sql .= ' ORDER BY h.date DESC, h.id DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }
}
