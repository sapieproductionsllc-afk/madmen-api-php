<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Identite;
use MadMen\Core\K40;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;
use RuntimeException;
use Throwable;

final class EmployeController
{
    /** Colonnes renvoyées (jamais code_pin_hash). */
    private const COLUMNS = 'id, matricule, nom, prenom, photo_url, poste_id, departement_id,
        superieur_id, telephone, adresse, contact_urgence_nom, contact_urgence_tel,
        contact_urgence_lien, salaire, statut, created_at';

    /**
     * Lecture ENRICHIE (additive) pour le dashboard : colonnes employé (préfixées e.)
     * + libellés résolus (poste/département/manager) + `name` concaténé + `role`.
     * N'enlève AUCUN champ existant. Voir docs/INTEGRATION-FRONT.md §3.A.
     */
    private const SELECT_ENRICHED = "e.id, e.matricule, e.nom, e.prenom, e.photo_url,
        e.poste_id, e.departement_id, e.superieur_id, e.telephone, e.email, e.adresse,
        e.contact_urgence_nom, e.contact_urgence_tel, e.contact_urgence_lien,
        e.salaire, e.statut, e.role,
        e.sexe, e.date_naissance, e.nationalite, e.etat_civil,
        e.date_embauche, e.type_contrat, e.notes_admin,
        e.created_at,
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
        'contact_urgence_tel', 'contact_urgence_lien', 'salaire', 'statut', 'role',
        // Champs RH additionnels (migration 052) — tous NULLABLE, éditables via PUT.
        'sexe', 'date_naissance', 'nationalite', 'etat_civil',
        'date_embauche', 'type_contrat', 'notes_admin',
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

        // Seuls nom + prénom sont requis : le matricule est auto-généré (sauf si fourni),
        // et le PIN est TOUJOURS généré automatiquement (un 'code_pin' entrant est ignoré).
        foreach (['nom', 'prenom'] as $required) {
            if (empty($body[$required])) {
                Response::error("Le champ '$required' est obligatoire", 422);
            }
        }

        $db = Database::connection();

        // PIN unique généré côté serveur, renvoyé EN CLAIR une seule fois.
        try {
            $pin = Identite::genererPinUnique($db);
        } catch (RuntimeException $e) {
            Response::error('Impossible de générer un PIN unique (espace saturé)', 507);
        }

        $data = $this->filterFillable($body);
        $matriculeFourni = !empty($data['matricule']);
        if (!$matriculeFourni) {
            $data['matricule'] = Identite::prochainMatricule($db);
        }
        $data['code_pin_hash'] = password_hash($pin, PASSWORD_BCRYPT);

        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ":$c", $cols);
        $sql = 'INSERT INTO employe (' . implode(', ', $cols) . ')
                VALUES (' . implode(', ', $placeholders) . ')';

        // Matricule auto : on régénère et on réessaie en cas de collision concurrente.
        $id = null;
        for ($t = 0, $max = $matriculeFourni ? 1 : 5; $t < $max; $t++) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($data);
                $id = (int) $db->lastInsertId();
                break;
            } catch (PDOException $e) {
                $doublonMatricule = ($e->errorInfo[1] ?? null) === 1062
                    && str_contains((string) ($e->errorInfo[2] ?? ''), 'matricule');
                if (!$matriculeFourni && $doublonMatricule) {
                    $data['matricule'] = Identite::prochainMatricule($db);
                    continue;
                }
                self::erreurIntegrite($e); // message précis (doublon vs clé étrangère) ; relance si autre
                throw $e;
            }
        }
        if ($id === null) {
            Response::error("Impossible d'attribuer un matricule unique, réessayez", 409);
        }

        // Auto-push de l'identité vers le K40 (best-effort : ne fait jamais échouer
        // la création si le terminal est désactivé/injoignable).
        $this->pushK40Silencieux($id, (string) $body['nom'], (string) $body['prenom']);

        // Réponse = l'employé créé + le PIN généré (affiché UNE SEULE fois).
        $employe = $this->find($id) ?? [];
        $employe['code_pin_genere'] = $pin;
        Response::json($employe, 201);
    }

    /**
     * POST /api/employes/{id}/regenerer-pin — régénère un PIN unique (cas du PIN oublié).
     * Le PIN étant haché, il ne peut qu'être recréé. Réservé au super_admin (rang 4).
     */
    public function regenererPin(array $params): void
    {
        $id = (int) $params['id'];
        $employe = $this->find($id);
        if ($employe === null) {
            Response::error('Employé introuvable', 404);
        }

        $db = Database::connection();
        try {
            $pin = Identite::genererPinUnique($db);
        } catch (RuntimeException $e) {
            Response::error('Impossible de générer un PIN unique (espace saturé)', 507);
        }

        $db->prepare('UPDATE employe SET code_pin_hash = ? WHERE id = ?')
           ->execute([password_hash($pin, PASSWORD_BCRYPT), $id]);

        Response::json([
            'id'              => $id,
            'matricule'       => $employe['matricule'] ?? null,
            'code_pin_genere' => $pin,
        ]);
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

    /**
     * Traduit une violation d'intégrité (SQLSTATE 23000) en message clair et précis.
     * Distingue un vrai doublon (1062 : matricule / email / device_user_id) d'une clé
     * étrangère invalide (1452 : poste / département / supérieur inexistant). Pour toute
     * autre erreur, ne fait rien : l'appelant relance l'exception (HTTP 500).
     *
     * Indispensable car un poste_id/departement_id/superieur_id pointant vers une ligne
     * inexistante remonte aussi en 23000 et était jadis signalé à tort « matricule existe déjà ».
     */
    private static function erreurIntegrite(PDOException $e): void
    {
        if ($e->getCode() !== '23000') {
            return; // pas une violation d'intégrité -> laisse remonter
        }

        $driverCode = $e->errorInfo[1] ?? null;
        $detail     = (string) ($e->errorInfo[2] ?? '');

        if ($driverCode === 1062) { // Duplicate entry sur une clé UNIQUE
            if (str_contains($detail, 'uq_employe_email')) {
                Response::error('Cet email est déjà utilisé', 422);
            }
            if (str_contains($detail, 'uq_employe_device_user_id')) {
                Response::error("Cet identifiant terminal (device_user_id) est déjà utilisé", 422);
            }
            Response::error('Ce matricule existe déjà', 422);
        }

        if ($driverCode === 1452) { // Foreign key constraint fails : la référence n'existe pas
            if (str_contains($detail, 'fk_employe_poste')) {
                Response::error("Le poste sélectionné n'existe pas", 422);
            }
            if (str_contains($detail, 'fk_employe_departement')) {
                Response::error("Le département sélectionné n'existe pas", 422);
            }
            if (str_contains($detail, 'fk_employe_superieur')) {
                Response::error("Le supérieur sélectionné n'existe pas", 422);
            }
            Response::error('Référence liée invalide (poste, département ou supérieur inexistant)', 422);
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

        // Le PIN ne se modifie PAS ici (sinon risque de doublon) : il passe par
        // POST /api/employes/{id}/regenerer-pin. Un 'code_pin' éventuel est ignoré.
        if ($data === []) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $data['id'] = $id;

        try {
            $stmt = Database::connection()->prepare("UPDATE employe SET $set WHERE id = :id");
            $stmt->execute($data);
        } catch (PDOException $e) {
            self::erreurIntegrite($e); // même traduction d'erreur qu'à la création
            throw $e;
        }

        Response::json($this->find($id) ?? []);
    }

    public function updateIdentifiants(array $params): void
    {
        $id = (int) $params['id'];
        $employe = $this->find($id);
        if ($employe === null) {
            Response::error('Employé introuvable', 404);
        }

        $body = Request::body();
        $data = [];

        if (isset($body['matricule'])) {
            $matricule = trim((string) $body['matricule']);
            if ($matricule === '') {
                Response::error('Le matricule ne peut pas être vide', 422);
            }
            $data['matricule'] = $matricule;
        }

        if (isset($body['code_pin'])) {
            if (!preg_match('/^\d{4}$/', (string) $body['code_pin'])) {
                Response::error("Le code PIN doit contenir exactement 4 chiffres", 422);
            }
            $data['code_pin_hash'] = password_hash((string) $body['code_pin'], PASSWORD_BCRYPT);
        }

        if ($data === []) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $data['id'] = $id;

        try {
            $stmt = Database::connection()->prepare("UPDATE employe SET $set WHERE id = :id");
            $stmt->execute($data);
        } catch (PDOException $e) {
            self::erreurIntegrite($e);
            throw $e;
        }

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
