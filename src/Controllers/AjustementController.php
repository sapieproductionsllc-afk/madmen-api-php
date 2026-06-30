<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Primes / retenues manuelles d'un employe pour un mois (composition du salaire, #4).
 * Lues par PaieController::calculer pour ajuster le net. Voir docs/INTEGRATION-FRONT.md.
 */
final class AjustementController
{
    private const PERIODE = '/^\d{4}-(0[1-9]|1[0-2])$/';
    private const COLS = 'id, employe_id, periode, type, libelle, montant, created_at';

    /** GET /api/employes/{id}/ajustements?periode=YYYY-MM */
    public function index(array $params): void
    {
        $sql = 'SELECT ' . self::COLS . ' FROM paie_ajustement WHERE employe_id = :id';
        $args = ['id' => \MadMen\Core\Employe::resolveId($params['id'])];

        $periode = Request::query('periode');
        if (is_string($periode) && preg_match(self::PERIODE, $periode) === 1) {
            $sql .= ' AND periode = :periode';
            $args['periode'] = $periode;
        }
        $sql .= ' ORDER BY periode DESC, id DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);

        Response::json($stmt->fetchAll());
    }

    /** POST /api/employes/{id}/ajustements {periode, type, libelle, montant} */
    public function store(array $params): void
    {
        $body = Request::body();
        $periode = (string) ($body['periode'] ?? '');
        $type = in_array($body['type'] ?? '', ['prime', 'retenue'], true) ? $body['type'] : '';
        $libelle = trim((string) ($body['libelle'] ?? ''));
        $montant = $body['montant'] ?? null;

        if (preg_match(self::PERIODE, $periode) !== 1) {
            Response::error("Champ 'periode' invalide (format YYYY-MM)", 422);
        }
        if ($type === '') {
            Response::error("Champ 'type' invalide ('prime' ou 'retenue')", 422);
        }
        if ($libelle === '') {
            Response::error("Champ 'libelle' obligatoire", 422);
        }
        if (!is_numeric($montant) || (float) $montant <= 0) {
            Response::error("Champ 'montant' invalide (doit etre > 0)", 422);
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO paie_ajustement (employe_id, periode, type, libelle, montant)
             VALUES (:emp, :periode, :type, :libelle, :montant)'
        );
        $stmt->execute([
            'emp'     => \MadMen\Core\Employe::resolveId($params['id']),
            'periode' => $periode,
            'type'    => $type,
            'libelle' => mb_substr($libelle, 0, 160),
            'montant' => round((float) $montant, 2),
        ]);

        $id = (int) Database::connection()->lastInsertId();
        $row = Database::connection()->prepare('SELECT ' . self::COLS . ' FROM paie_ajustement WHERE id = ?');
        $row->execute([$id]);

        Response::json($row->fetch() ?: [], 201);
    }

    /** DELETE /api/ajustements/{id} */
    public function destroy(array $params): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM paie_ajustement WHERE id = :id');
        $stmt->execute(['id' => (int) $params['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Ajustement introuvable', 404);
        }
        Response::noContent();
    }
}
