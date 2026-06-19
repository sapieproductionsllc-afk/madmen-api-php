<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class AlerteController
{
    public function index(): void
    {
        $sql = 'SELECT * FROM alerte WHERE 1=1';
        $params = [];

        if (($dest = Request::query('destinataire_id')) !== null) {
            $sql .= ' AND destinataire_id = :dest';
            $params['dest'] = $dest;
        }
        if (($lu = Request::query('lu')) !== null) {
            $sql .= ' AND lu = :lu';
            $params['lu'] = filter_var($lu, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $limit = max(1, min(200, (int) Request::query('limit', 50)));
        $sql .= " ORDER BY horodatage DESC LIMIT $limit";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }
}
