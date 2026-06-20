<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class MotifController
{
    /** Liste des motifs d'absence (pour le menu de déverrouillage > 20 min). */
    public function index(): void
    {
        $stmt = Database::connection()->query(
            'SELECT id, libelle FROM motif_absence ORDER BY id'
        );

        Response::json($stmt->fetchAll());
    }
}
