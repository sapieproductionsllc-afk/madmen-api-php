<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Dépenses de la société (Finance.jsx). Saisie et suivi des frais généraux,
 * consultés par mois. CRUD réservé à l'administration.
 *  - GET    /api/depenses?periode=YYYY-MM  (défaut : mois courant)
 *  - POST   /api/depenses                  ({ libelle, categorie, montant, date, note? })
 *  - DELETE /api/depenses/{id}
 */
final class DepenseController
{
    /** GET /api/depenses?periode=YYYY-MM — dépenses du mois (défaut : mois courant). */
    public function index(): void
    {
        $periode = Request::query('periode');
        if (!is_string($periode) || preg_match('/^\d{4}-\d{2}$/', $periode) !== 1) {
            $periode = date('Y-m');
        }

        $debut = "$periode-01";
        $fin = date('Y-m-t', strtotime($debut));

        $stmt = Database::connection()->prepare(
            'SELECT id, libelle, categorie, montant, date, note, created_at
             FROM depense WHERE date BETWEEN :debut AND :fin
             ORDER BY date DESC, id DESC'
        );
        $stmt->execute(['debut' => $debut, 'fin' => $fin]);

        Response::json(array_map([$this, 'formate'], $stmt->fetchAll()));
    }

    /** POST /api/depenses — { libelle, categorie, montant, date, note? }. */
    public function store(): void
    {
        $body = Request::body();

        $libelle = trim((string) ($body['libelle'] ?? ''));
        $categorie = trim((string) ($body['categorie'] ?? ''));
        $date = $body['date'] ?? null;

        if ($libelle === '') {
            Response::error("Le champ 'libelle' est obligatoire", 422);
        }
        if ($categorie === '') {
            Response::error("Le champ 'categorie' est obligatoire", 422);
        }
        if (!isset($body['montant']) || !is_numeric($body['montant']) || (float) $body['montant'] <= 0) {
            Response::error("Le champ 'montant' (> 0) est requis", 422);
        }
        if (!is_string($date) || !$this->estDate($date)) {
            Response::error("Le champ 'date' est requis (format YYYY-MM-DD)", 422);
        }

        $montant = round((float) $body['montant'], 2);
        $note = isset($body['note']) ? trim((string) $body['note']) : null;

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO depense (libelle, categorie, montant, date, note)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$libelle, $categorie, $montant, $date, $note !== '' ? $note : null]);

        $stmt = $db->prepare(
            'SELECT id, libelle, categorie, montant, date, note, created_at
             FROM depense WHERE id = ?'
        );
        $stmt->execute([(int) $db->lastInsertId()]);

        Response::json($this->formate($stmt->fetch()), 201);
    }

    /** DELETE /api/depenses/{id}. */
    public function destroy(array $params): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM depense WHERE id = ?');
        $stmt->execute([(int) $params['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Dépense introuvable', 404);
        }
        Response::noContent();
    }

    // ---------------------------------------------------------------- helpers

    private function formate(array $d): array
    {
        return [
            'id'         => (int) $d['id'],
            'libelle'    => $d['libelle'],
            'categorie'  => $d['categorie'],
            'montant'    => (float) $d['montant'],
            'date'       => $d['date'],
            'note'       => $d['note'],
            'created_at' => $d['created_at'] ?? null,
        ];
    }

    private function estDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
    }
}
