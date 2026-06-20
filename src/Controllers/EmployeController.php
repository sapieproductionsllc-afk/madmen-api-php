<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;

final class EmployeController
{
    /** Colonnes renvoyées (jamais code_pin_hash). */
    private const COLUMNS = 'id, matricule, nom, prenom, photo_url, poste_id, departement_id,
        superieur_id, telephone, adresse, contact_urgence_nom, contact_urgence_tel,
        salaire, statut, created_at';

    private const FILLABLE = [
        'matricule', 'nom', 'prenom', 'photo_url', 'poste_id', 'departement_id',
        'superieur_id', 'telephone', 'adresse', 'contact_urgence_nom',
        'contact_urgence_tel', 'salaire', 'statut',
    ];

    public function index(): void
    {
        $db = Database::connection();
        $sql = 'SELECT ' . self::COLUMNS . ' FROM employe WHERE 1=1';
        $params = [];

        if (($dep = Request::query('departement_id')) !== null) {
            $sql .= ' AND departement_id = :dep';
            $params['dep'] = $dep;
        }
        if (($statut = Request::query('statut')) !== null) {
            $sql .= ' AND statut = :statut';
            $params['statut'] = $statut;
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    public function show(array $params): void
    {
        $employe = $this->find((int) $params['id']);
        if ($employe === null) {
            Response::error('Employé introuvable', 404);
        }
        Response::json($employe);
    }

    public function store(): void
    {
        $body = Request::body();

        foreach (['matricule', 'nom', 'prenom', 'code_pin'] as $required) {
            if (empty($body[$required])) {
                Response::error("Le champ '$required' est obligatoire", 422);
            }
        }

        // Le PIN doit être composé de 4 à 8 chiffres.
        if (!preg_match('/^\d{4,8}$/', (string) $body['code_pin'])) {
            Response::error("Le champ 'code_pin' doit contenir entre 4 et 8 chiffres", 422);
        }

        $data = $this->filterFillable($body);
        $data['code_pin_hash'] = password_hash((string) $body['code_pin'], PASSWORD_BCRYPT);

        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ":$c", $cols);

        $sql = 'INSERT INTO employe (' . implode(', ', $cols) . ')
                VALUES (' . implode(', ', $placeholders) . ')';

        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($data);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Ce matricule existe déjà', 422);
            }
            throw $e;
        }

        $id = (int) Database::connection()->lastInsertId();
        Response::json($this->find($id) ?? [], 201);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        if ($this->find($id) === null) {
            Response::error('Employé introuvable', 404);
        }

        $body = Request::body();
        $data = $this->filterFillable($body);

        if (!empty($body['code_pin'])) {
            $data['code_pin_hash'] = password_hash((string) $body['code_pin'], PASSWORD_BCRYPT);
        }
        if ($data === []) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $data['id'] = $id;

        $stmt = Database::connection()->prepare("UPDATE employe SET $set WHERE id = :id");
        $stmt->execute($data);

        Response::json($this->find($id) ?? []);
    }

    public function destroy(array $params): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM employe WHERE id = :id');
        $stmt->execute(['id' => (int) $params['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Employé introuvable', 404);
        }
        Response::noContent();
    }

    private function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM employe WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    private function filterFillable(array $body): array
    {
        return array_intersect_key($body, array_flip(self::FILLABLE));
    }
}
