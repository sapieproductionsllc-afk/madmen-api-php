<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use MadMen\Core\Salaire;
use PDO;

/**
 * Salaire fixe (de base) d'un employé, avec HISTORIQUE daté.
 * - GET    /api/employes/{id}/salaire  : montant actuel + historique (récent -> ancien).
 * - POST   /api/employes/{id}/salaire  : ajoute un salaire daté.
 * - PUT    /api/salaire/{id}           : modifie une entrée d'historique.
 * - DELETE /api/salaire/{id}           : supprime une entrée.
 * À chaque écriture, `employe.salaire` est resynchronisé sur le montant ACTUEL.
 */
final class SalaireController
{
    private const COLS = 'id, employe_id, montant, devise, date_application, commentaire, created_at';

    public function index(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];

        $stmt = $db->prepare('SELECT ' . self::COLS . ' FROM salaire_fixe WHERE employe_id = ? ORDER BY date_application DESC, id DESC');
        $stmt->execute([$id]);

        Response::json([
            'employe_id' => $id,
            'actuel'     => Salaire::actuel($db, $id),
            'historique' => $stmt->fetchAll(),
        ]);
    }

    public function store(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];

        $exists = $db->prepare('SELECT 1 FROM employe WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            Response::error('Employé introuvable', 404);
        }

        $body = Request::body();
        [$montant, $date, $devise, $commentaire] = $this->valider($body, true);

        $db->prepare('INSERT INTO salaire_fixe (employe_id, montant, devise, date_application, commentaire) VALUES (?, ?, ?, ?, ?)')
           ->execute([$id, $montant, $devise, $date, $commentaire]);
        $newId = (int) $db->lastInsertId();
        $this->resync($db, $id);

        Response::json($this->find($db, $newId), 201);
    }

    public function update(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];

        $stmt = $db->prepare('SELECT employe_id FROM salaire_fixe WHERE id = ?');
        $stmt->execute([$id]);
        $empId = $stmt->fetchColumn();
        if ($empId === false) {
            Response::error('Entrée de salaire introuvable', 404);
        }

        $body = Request::body();
        $set = [];
        $vals = [];
        if (array_key_exists('montant', $body)) {
            if (!is_numeric($body['montant']) || (float) $body['montant'] < 0) {
                Response::error("Le champ 'montant' doit être un nombre positif", 422);
            }
            $set[] = 'montant = ?';
            $vals[] = (float) $body['montant'];
        }
        if (array_key_exists('devise', $body)) {
            $set[] = 'devise = ?';
            $vals[] = (trim((string) $body['devise']) ?: 'FCFA');
        }
        if (array_key_exists('date_application', $body)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body['date_application'])) {
                Response::error("Le champ 'date_application' doit être au format AAAA-MM-JJ", 422);
            }
            $set[] = 'date_application = ?';
            $vals[] = (string) $body['date_application'];
        }
        if (array_key_exists('commentaire', $body)) {
            $set[] = 'commentaire = ?';
            $vals[] = $body['commentaire'] !== null ? trim((string) $body['commentaire']) : null;
        }
        if ($set === []) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $vals[] = $id;
        $db->prepare('UPDATE salaire_fixe SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        $this->resync($db, (int) $empId);

        Response::json($this->find($db, $id));
    }

    public function destroy(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];

        $stmt = $db->prepare('SELECT employe_id FROM salaire_fixe WHERE id = ?');
        $stmt->execute([$id]);
        $empId = $stmt->fetchColumn();
        if ($empId === false) {
            Response::error('Entrée de salaire introuvable', 404);
        }

        $db->prepare('DELETE FROM salaire_fixe WHERE id = ?')->execute([$id]);
        $this->resync($db, (int) $empId);

        Response::noContent();
    }

    /** @return array{0:float,1:string,2:string,3:?string} montant, date, devise, commentaire */
    private function valider(array $body, bool $requis): array
    {
        $montant = $body['montant'] ?? null;
        if (!is_numeric($montant) || (float) $montant < 0) {
            Response::error("Le champ 'montant' doit être un nombre positif", 422);
        }
        $date = trim((string) ($body['date_application'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error("Le champ 'date_application' (AAAA-MM-JJ) est obligatoire", 422);
        }
        $devise = trim((string) ($body['devise'] ?? 'FCFA')) ?: 'FCFA';
        $commentaire = isset($body['commentaire']) && trim((string) $body['commentaire']) !== ''
            ? trim((string) $body['commentaire'])
            : null;

        return [(float) $montant, $date, $devise, $commentaire];
    }

    private function find(PDO $db, int $id): array
    {
        $stmt = $db->prepare('SELECT ' . self::COLS . ' FROM salaire_fixe WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: [];
    }

    /** employe.salaire = salaire fixe actuel (cache pour les vues qui lisent encore cette colonne). */
    private function resync(PDO $db, int $empId): void
    {
        $actuel = Salaire::actuel($db, $empId);
        if ($actuel !== null) {
            $db->prepare('UPDATE employe SET salaire = ? WHERE id = ?')->execute([$actuel, $empId]);
        }
    }
}
