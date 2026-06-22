<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Objectifs / évolution professionnelle de l'employé connecté (app self-service).
 * Toutes les routes /api/me/objectifs sont scopées au jeton (rang 1, anti-IDOR).
 */
final class ObjectifController
{
    private const CATEGORIES = ['competences', 'carriere', 'formation', 'performance', 'perso'];
    private const STATUTS = ['en_cours', 'atteint', 'abandonne'];

    /** GET /api/me/objectifs — mes objectifs. */
    public function index(): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            'SELECT id, categorie, titre, description, echeance, progression, statut, created_at
             FROM objectif WHERE employe_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$id]);

        Response::json(array_map([$this, 'formate'], $stmt->fetchAll()));
    }

    /** POST /api/me/objectifs — { titre, categorie, echeance?, description?, progression? }. */
    public function creer(): void
    {
        $id = $this->employeId();
        $body = Request::body();

        $titre = trim((string) ($body['titre'] ?? ''));
        if ($titre === '') {
            Response::error("Le champ 'titre' est obligatoire", 422);
        }
        $categorie = $body['categorie'] ?? 'competences';
        if (!in_array($categorie, self::CATEGORIES, true)) {
            Response::error("'categorie' invalide (" . implode(', ', self::CATEGORIES) . ')', 422);
        }
        $progression = $this->normProgression($body['progression'] ?? 0);
        if ($progression === null) {
            Response::error("'progression' doit être un entier entre 0 et 100", 422);
        }
        $echeance = $this->normEcheance($body['echeance'] ?? null);
        $description = isset($body['description']) ? trim((string) $body['description']) : null;

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO objectif (employe_id, categorie, titre, description, echeance, progression, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $categorie, $titre, $description ?: null, $echeance, $progression,
            $progression >= 100 ? 'atteint' : 'en_cours']);

        Response::json($this->find($db, (int) $db->lastInsertId(), $id), 201);
    }

    /** PUT /api/me/objectifs/{id} — met à jour mes champs (partiel). */
    public function update(array $params): void
    {
        $emp = $this->employeId();
        $db = Database::connection();
        $oid = (int) $params['id'];
        if ($this->find($db, $oid, $emp) === null) {
            Response::error('Objectif introuvable', 404);
        }

        $body = Request::body();
        $set = [];
        $vals = [];

        if (isset($body['titre'])) {
            $t = trim((string) $body['titre']);
            if ($t === '') {
                Response::error("'titre' ne peut pas être vide", 422);
            }
            $set[] = 'titre = ?';
            $vals[] = $t;
        }
        if (isset($body['categorie'])) {
            if (!in_array($body['categorie'], self::CATEGORIES, true)) {
                Response::error("'categorie' invalide", 422);
            }
            $set[] = 'categorie = ?';
            $vals[] = $body['categorie'];
        }
        if (array_key_exists('description', $body)) {
            $set[] = 'description = ?';
            $vals[] = $body['description'] !== null ? trim((string) $body['description']) : null;
        }
        if (array_key_exists('echeance', $body)) {
            $set[] = 'echeance = ?';
            $vals[] = $this->normEcheance($body['echeance']);
        }
        if (isset($body['progression'])) {
            $p = $this->normProgression($body['progression']);
            if ($p === null) {
                Response::error("'progression' doit être un entier entre 0 et 100", 422);
            }
            $set[] = 'progression = ?';
            $vals[] = $p;
        }
        if (isset($body['statut'])) {
            if (!in_array($body['statut'], self::STATUTS, true)) {
                Response::error("'statut' invalide (" . implode(', ', self::STATUTS) . ')', 422);
            }
            $set[] = 'statut = ?';
            $vals[] = $body['statut'];
        }

        if ($set === []) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $vals[] = $oid;
        $vals[] = $emp;
        $db->prepare('UPDATE objectif SET ' . implode(', ', $set) . ' WHERE id = ? AND employe_id = ?')
           ->execute($vals);

        Response::json($this->find($db, $oid, $emp) ?? []);
    }

    /** DELETE /api/me/objectifs/{id}. */
    public function destroy(array $params): void
    {
        $emp = $this->employeId();
        $stmt = Database::connection()->prepare('DELETE FROM objectif WHERE id = ? AND employe_id = ?');
        $stmt->execute([(int) $params['id'], $emp]);
        if ($stmt->rowCount() === 0) {
            Response::error('Objectif introuvable', 404);
        }

        Response::noContent();
    }

    // ---------------------------------------------------------------- helpers

    private function find(\PDO $db, int $oid, int $emp): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, categorie, titre, description, echeance, progression, statut, created_at
             FROM objectif WHERE id = ? AND employe_id = ?'
        );
        $stmt->execute([$oid, $emp]);
        $row = $stmt->fetch();

        return $row ? $this->formate($row) : null;
    }

    private function formate(array $o): array
    {
        return [
            'id'           => (int) $o['id'],
            'categorie'    => $o['categorie'],
            'titre'        => $o['titre'],
            'description'  => $o['description'],
            'echeance'     => $o['echeance'],
            'progression'  => (int) $o['progression'],
            'statut'       => $o['statut'],
            'created_at'   => $o['created_at'] ?? null,
        ];
    }

    /** 0-100 entier, ou null si invalide. */
    private function normProgression($v): ?int
    {
        if (!is_numeric($v)) {
            return null;
        }
        $p = (int) $v;

        return ($p < 0 || $p > 100) ? null : $p;
    }

    /** 'YYYY-MM' -> 'YYYY-MM-01' ; 'YYYY-MM-DD' tel quel ; null sinon. */
    private function normEcheance($v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}$/', $v) === 1) {
            return $v . '-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            return $v;
        }

        return null;
    }

    private function employeId(): int
    {
        $user = Auth::currentUser();
        $id = $user['sub'] ?? null;
        if (!$id) {
            Response::error('Non authentifié', 401);
        }

        return (int) $id;
    }
}
