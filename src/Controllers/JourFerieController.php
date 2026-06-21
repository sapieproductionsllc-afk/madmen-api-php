<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;

/**
 * Jours fériés (journées payées). Un jour férié n'est pas compté comme une
 * absence dans la paie (voir PaieController). CRUD réservé à l'administration.
 */
final class JourFerieController
{
    /** GET /api/jours-feries?annee=YYYY (ou ?from=YYYY-MM-DD&to=YYYY-MM-DD). */
    public function index(): void
    {
        $db = Database::connection();
        $sql = 'SELECT id, date, libelle FROM jour_ferie WHERE 1=1';
        $params = [];

        $annee = Request::query('annee');
        if (is_string($annee) && preg_match('/^\d{4}$/', $annee) === 1) {
            $sql .= ' AND date BETWEEN :d1 AND :d2';
            $params['d1'] = "$annee-01-01";
            $params['d2'] = "$annee-12-31";
        }
        $from = Request::query('from');
        $to = Request::query('to');
        if (is_string($from) && is_string($to) && $this->estDate($from) && $this->estDate($to)) {
            $sql .= ' AND date BETWEEN :from AND :to';
            $params['from'] = $from;
            $params['to'] = $to;
        }
        $sql .= ' ORDER BY date';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    /** POST /api/jours-feries — { date: YYYY-MM-DD, libelle }. */
    public function store(): void
    {
        $body = Request::body();
        $date = $body['date'] ?? null;
        $libelle = trim((string) ($body['libelle'] ?? ''));

        if (!is_string($date) || !$this->estDate($date)) {
            Response::error("Le champ 'date' est requis (format YYYY-MM-DD)", 422);
        }
        if ($libelle === '') {
            Response::error("Le champ 'libelle' est obligatoire", 422);
        }

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO jour_ferie (date, libelle) VALUES (?, ?)'
            );
            $stmt->execute([$date, $libelle]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Ce jour férié existe déjà', 422);
            }
            throw $e;
        }

        $id = (int) Database::connection()->lastInsertId();
        Response::json(['id' => $id, 'date' => $date, 'libelle' => $libelle], 201);
    }

    /** DELETE /api/jours-feries/{id}. */
    public function destroy(array $params): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM jour_ferie WHERE id = ?');
        $stmt->execute([(int) $params['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Jour férié introuvable', 404);
        }
        Response::noContent();
    }

    /** Charge les fériés d'une période en map [Y-m-d => libelle]. Utilitaire paie. */
    public static function map(\PDO $db, string $dateDebut, string $dateFin): array
    {
        $stmt = $db->prepare('SELECT date, libelle FROM jour_ferie WHERE date BETWEEN ? AND ?');
        $stmt->execute([$dateDebut, $dateFin]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['date']] = (string) $r['libelle'];
        }

        return $out;
    }

    private function estDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
    }
}
