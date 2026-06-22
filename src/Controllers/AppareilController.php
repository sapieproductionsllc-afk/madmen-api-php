<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class AppareilController
{
    /**
     * Projection des colonnes réelles de `appareil_biometrique` vers la forme
     * attendue par le front {id, name, type, agence, status, lastSync}.
     * - name     <- nom
     * - agence   <- emplacement (pas de colonne « agence » : emplacement est le plus proche)
     * - status   <- statut
     * - lastSync <- updated_at (pas de colonne de synchro dédiée : updated_at fait foi)
     */
    private const COLUMNS =
        'id,
         nom AS name,
         type,
         emplacement AS agence,
         statut AS status,
         updated_at AS lastSync';

    /** Liste des appareils biométriques. */
    public function index(): void
    {
        $stmt = Database::connection()->query(
            'SELECT ' . self::COLUMNS . ' FROM appareil_biometrique ORDER BY id DESC'
        );

        Response::json($stmt->fetchAll());
    }

    /** Détail d'un appareil biométrique. */
    public function show(array $params): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM appareil_biometrique WHERE id = :id'
        );
        $stmt->execute(['id' => (int) $params['id']]);

        $appareil = $stmt->fetch() ?: null;
        if ($appareil === null) {
            Response::error('Appareil introuvable', 404);
        }

        Response::json($appareil);
    }
}
