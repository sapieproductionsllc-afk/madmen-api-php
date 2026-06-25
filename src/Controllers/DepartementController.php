<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class DepartementController
{
    /** GET /api/departements — liste des départements (id, nom) pour les sélecteurs. */
    public function index(): void
    {
        $rows = Database::connection()
            ->query('SELECT id, nom FROM departement ORDER BY nom')
            ->fetchAll();

        Response::json($rows);
    }
}
