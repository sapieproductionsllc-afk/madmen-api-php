<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class MotifController
{
    /**
     * Liste des motifs d'absence (pour le sélecteur du kiosque au déverrouillage).
     * Renvoie un tableau d'objets { id, libelle } trié par libellé.
     */
    public function index(): void
    {
        $stmt = Database::connection()->query(
            'SELECT id, libelle FROM motif_absence ORDER BY libelle'
        );

        // Normalise les types (id en int) pour un contrat JSON stable côté front.
        $motifs = array_map(static fn (array $m): array => [
            'id'      => (int) $m['id'],
            'libelle' => $m['libelle'],
        ], $stmt->fetchAll());

        Response::json($motifs);
    }
}
