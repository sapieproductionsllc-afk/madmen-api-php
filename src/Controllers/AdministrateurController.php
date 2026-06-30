<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;

/**
 * Gestion des comptes SUPER-ADMIN (Administration → Administrateurs).
 * Réservé au super_admin (cf. Auth : /api/administrateurs => rang 4).
 * Les super-admins se connectent par identifiant + mot de passe, ne pointent pas,
 * n'ont pas de biométrie et n'apparaissent dans aucune vue « employé ».
 */
final class AdministrateurController
{
    /** GET /api/administrateurs — liste des super-admins (sans le hash du mot de passe). */
    public function index(): void
    {
        $rows = Database::connection()->query(
            "SELECT id, username, TRIM(CONCAT(prenom, ' ', nom)) AS name, statut, doit_changer_mdp, created_at
             FROM employe
             WHERE role = 'super_admin'
             ORDER BY id"
        )->fetchAll();

        Response::json(array_map(static function (array $r): array {
            $r['doit_changer_mdp'] = (int) $r['doit_changer_mdp'] === 1;
            return $r;
        }, $rows));
    }

    /** POST /api/administrateurs — { nom, username, mot_de_passe } → crée un super-admin (doit changer à la 1re connexion). */
    public function store(): void
    {
        $body = Request::body();
        $nom = trim((string) ($body['nom'] ?? ''));
        $username = trim((string) ($body['username'] ?? ''));
        $motDePasse = (string) ($body['mot_de_passe'] ?? '');

        if ($nom === '' || $username === '') {
            Response::error("Les champs 'nom' et 'username' sont obligatoires", 422);
        }
        if (strlen($motDePasse) < 8) {
            Response::error('Le mot de passe temporaire doit contenir au moins 8 caractères', 422);
        }

        // matricule technique unique : les super-admins ne pointent pas, mais
        // `employe.matricule` est NOT NULL UNIQUE.
        $matricule = 'ADM-' . strtoupper(bin2hex(random_bytes(3)));

        try {
            Database::connection()->prepare(
                "INSERT INTO employe (matricule, username, nom, prenom, code_pin_hash, role, statut, mot_de_passe_hash, doit_changer_mdp)
                 VALUES (?, ?, ?, '', '', 'super_admin', 'actif', ?, 1)"
            )->execute([$matricule, $username, $nom, password_hash($motDePasse, PASSWORD_BCRYPT)]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                Response::error('Cet identifiant est déjà utilisé', 422);
            }
            throw $e;
        }

        Response::json([
            'id'       => (int) Database::connection()->lastInsertId(),
            'username' => $username,
            'message'  => 'Administrateur créé',
        ], 201);
    }

    /** POST /api/administrateurs/{id}/reset-password — { mot_de_passe } → réinitialise + force le changement. */
    public function resetPassword(array $params): void
    {
        $motDePasse = (string) (Request::body()['mot_de_passe'] ?? '');
        if (strlen($motDePasse) < 8) {
            Response::error('Le mot de passe temporaire doit contenir au moins 8 caractères', 422);
        }

        $stmt = Database::connection()->prepare(
            "UPDATE employe SET mot_de_passe_hash = ?, doit_changer_mdp = 1 WHERE id = ? AND role = 'super_admin'"
        );
        $stmt->execute([password_hash($motDePasse, PASSWORD_BCRYPT), (int) $params['id']]);
        if ($stmt->rowCount() === 0) {
            Response::error('Administrateur introuvable', 404);
        }
        Response::json(['message' => 'Mot de passe réinitialisé']);
    }

    /** DELETE /api/administrateurs/{id} — supprime un super-admin ; JAMAIS le dernier. */
    public function destroy(array $params): void
    {
        $db = Database::connection();
        $total = (int) $db->query("SELECT COUNT(*) FROM employe WHERE role = 'super_admin'")->fetchColumn();
        if ($total <= 1) {
            Response::error('Impossible de supprimer le dernier administrateur', 409);
        }
        $stmt = $db->prepare("DELETE FROM employe WHERE id = ? AND role = 'super_admin'");
        $stmt->execute([(int) $params['id']]);
        if ($stmt->rowCount() === 0) {
            Response::error('Administrateur introuvable', 404);
        }
        Response::noContent();
    }
}
