<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Jwt;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Authentification des utilisateurs du dashboard par Matricule + Code PIN.
 * Renvoie un jeton JWT portant le rôle (RBAC).
 */
final class AuthController
{
    /** POST /api/auth/login — { matricule, code_pin } → { token, employe } */
    public function login(): void
    {
        $body = Request::body();
        foreach (['matricule', 'code_pin'] as $champ) {
            if (empty($body[$champ])) {
                Response::error("Le champ '$champ' est obligatoire", 422);
            }
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, matricule, nom, prenom, role, statut, code_pin_hash FROM employe WHERE matricule = ?'
        );
        $stmt->execute([$body['matricule']]);
        $e = $stmt->fetch();

        if (!$e || !password_verify((string) $body['code_pin'], $e['code_pin_hash'])) {
            Response::error('Identifiants invalides', 401);
        }
        if ($e['statut'] === 'suspendu') {
            Response::error('Compte suspendu', 403);
        }

        $token = Jwt::encode([
            'sub'       => (int) $e['id'],
            'matricule' => $e['matricule'],
            'role'      => $e['role'],
        ]);

        Response::json([
            'token'   => $token,
            'employe' => [
                'id'        => (int) $e['id'],
                'matricule' => $e['matricule'],
                'nom'       => $e['nom'],
                'prenom'    => $e['prenom'],
                'role'      => $e['role'],
            ],
        ]);
    }

    /** GET /api/auth/me — renvoie l'utilisateur courant (depuis le jeton). */
    public function me(): void
    {
        $payload = Auth::currentUser();
        if ($payload === null) {
            Response::error('Non authentifié', 401);
        }

        Response::json([
            'id'        => $payload['sub'] ?? null,
            'matricule' => $payload['matricule'] ?? null,
            'role'      => $payload['role'] ?? null,
        ]);
    }
}
