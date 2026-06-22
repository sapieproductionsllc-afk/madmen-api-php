<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\K40;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;
use Throwable;

final class EmployeController
{
    /** Colonnes renvoyées (jamais code_pin_hash). */
    private const COLUMNS = 'id, matricule, nom, prenom, photo_url, poste_id, departement_id,
        superieur_id, telephone, adresse, contact_urgence_nom, contact_urgence_tel,
        salaire, statut, created_at';

    /**
     * Lecture ENRICHIE (additive) pour le dashboard : colonnes employé (préfixées e.)
     * + libellés résolus (poste/département/manager) + `name` concaténé + `role`.
     * N'enlève AUCUN champ existant. Voir docs/INTEGRATION-FRONT.md §3.A.
     */
    private const SELECT_ENRICHED = "e.id, e.matricule, e.nom, e.prenom, e.photo_url,
        e.poste_id, e.departement_id, e.superieur_id, e.telephone, e.email, e.adresse,
        e.contact_urgence_nom, e.contact_urgence_tel, e.salaire, e.statut, e.role, e.created_at,
        TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
        p.intitule AS poste_libelle,
        d.nom AS departement_nom,
        CONCAT(s.prenom, ' ', s.nom) AS manager_nom,
        pt.statut AS today_statut, pt.heure_entree AS today_arrivee, pt.retard_minutes AS today_retard";

    private const FROM_JOINS = "FROM employe e
        LEFT JOIN poste p       ON p.id = e.poste_id
        LEFT JOIN departement d ON d.id = e.departement_id
        LEFT JOIN employe s     ON s.id = e.superieur_id
        LEFT JOIN pointage pt   ON pt.employe_id = e.id AND pt.date = CURDATE()";

    private const FILLABLE = [
        'matricule', 'nom', 'prenom', 'photo_url', 'poste_id', 'departement_id',
        'superieur_id', 'telephone', 'email', 'adresse', 'contact_urgence_nom',
        'contact_urgence_tel', 'salaire', 'statut',
    ];

    public function index(): void
    {
        $db = Database::connection();
        $sql = 'SELECT ' . self::SELECT_ENRICHED . ' ' . self::FROM_JOINS . ' WHERE 1=1';
        $params = [];

        if (($dep = Request::query('departement_id')) !== null) {
            $sql .= ' AND e.departement_id = :dep';
            $params['dep'] = $dep;
        }
        if (($statut = Request::query('statut')) !== null) {
            $sql .= ' AND e.statut = :statut';
            $params['statut'] = $statut;
        }
        $sql .= ' ORDER BY e.id DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Response::json(array_map([self::class, 'withToday'], $stmt->fetchAll()));
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

        // Auto-push de l'identité vers le K40 (best-effort : ne fait jamais échouer
        // la création si le terminal est désactivé/injoignable).
        $this->pushK40Silencieux($id, (string) $body['nom'], (string) $body['prenom']);

        Response::json($this->find($id) ?? [], 201);
    }

    /**
     * Pousse l'identité (uid = id employé, nom) vers le K40. Silencieux : toute
     * erreur (K40 off/injoignable) est ignorée, l'employé reste créé.
     */
    private function pushK40Silencieux(int $id, string $nom, string $prenom): void
    {
        @set_time_limit(0);
        try {
            $zk = K40::connect();
            $name = mb_substr($prenom . ' ' . $nom, 0, 24);
            $zk->setUser($id, (string) $id, $name, '');
            @$zk->disconnect();
            Database::connection()
                ->prepare('UPDATE employe SET device_user_id = ? WHERE id = ?')
                ->execute([(string) $id, $id]);
        } catch (Throwable $e) {
            error_log('Auto-push K40 (création employé #' . $id . ') ignoré : ' . $e->getMessage());
        }
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
        $stmt = Database::connection()->prepare('SELECT ' . self::SELECT_ENRICHED . ' ' . self::FROM_JOINS . ' WHERE e.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? self::withToday($row) : null;
    }

    /** Imbrique le pointage du jour dans un objet `today` (additif, attendu par le dashboard). */
    private static function withToday(array $row): array
    {
        $row['today'] = [
            'statut'         => $row['today_statut'] ?? null,
            'arrivee'        => $row['today_arrivee'] ?? null,
            'retard_minutes' => isset($row['today_retard']) ? (int) $row['today_retard'] : null,
        ];
        unset($row['today_statut'], $row['today_arrivee'], $row['today_retard']);

        return $row;
    }

    private function filterFillable(array $body): array
    {
        return array_intersect_key($body, array_flip(self::FILLABLE));
    }
}
