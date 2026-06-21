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
    /** Fenêtre glissante (minutes) pour le comptage des échecs. */
    private const FENETRE_MINUTES = 15;
    /** Échecs max pour UN matricule sur la fenêtre avant blocage temporaire. */
    private const MAX_ECHEC_MATRICULE = 5;
    /** Échecs max depuis UNE IP sur la fenêtre (anti-balayage de plusieurs comptes). */
    private const MAX_ECHEC_IP = 30;

    /** POST /api/auth/login — { matricule, code_pin } → { token, employe } */
    public function login(): void
    {
        $body = Request::body();
        foreach (['matricule', 'code_pin'] as $champ) {
            if (empty($body[$champ])) {
                Response::error("Le champ '$champ' est obligatoire", 422);
            }
        }

        $matricule = (string) $body['matricule'];
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        // Anti-brute-force : cet endpoint délivre des jetons porteurs de RÔLE
        // (jusqu'à super_admin). Le throttling est TOUJOURS actif ici — il n'est
        // PAS désactivable par BRUTE_FORCE_ENABLED (contrairement au PIN kiosque).
        if ($this->tropDeTentatives($matricule, $ip)) {
            Response::error('Trop de tentatives, réessayez plus tard', 429);
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, matricule, nom, prenom, role, statut, code_pin_hash FROM employe WHERE matricule = ?'
        );
        $stmt->execute([$matricule]);
        $e = $stmt->fetch();

        if (!$e || !password_verify((string) $body['code_pin'], (string) $e['code_pin_hash'])) {
            $this->logTentative($matricule, $ip, 'echec');
            Response::error('Identifiants invalides', 401);
        }
        if ($e['statut'] === 'suspendu') {
            Response::error('Compte suspendu', 403);
        }

        $this->logTentative($matricule, $ip, 'succes');

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

    /**
     * Vrai si trop d'échecs récents pour ce matricule (lockout par compte) OU
     * depuis cette IP (anti-balayage). Fenêtre glissante, requêtes préparées.
     */
    private function tropDeTentatives(string $matricule, string $ip): bool
    {
        $depuis = date('Y-m-d H:i:s', time() - self::FENETRE_MINUTES * 60);
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM tentative_login
             WHERE identifiant = ? AND resultat = 'echec' AND horodatage >= ?"
        );
        $stmt->execute([$matricule, $depuis]);
        if ((int) $stmt->fetchColumn() >= self::MAX_ECHEC_MATRICULE) {
            return true;
        }

        if ($ip !== '') {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM tentative_login
                 WHERE ip = ? AND resultat = 'echec' AND horodatage >= ?"
            );
            $stmt->execute([$ip, $depuis]);
            if ((int) $stmt->fetchColumn() >= self::MAX_ECHEC_IP) {
                return true;
            }
        }

        return false;
    }

    /** Journalise une tentative (succès/échec) pour l'anti-brute-force. */
    private function logTentative(string $matricule, string $ip, string $resultat): void
    {
        Database::connection()->prepare(
            'INSERT INTO tentative_login (identifiant, ip, resultat, horodatage) VALUES (?, ?, ?, ?)'
        )->execute([$matricule, $ip !== '' ? $ip : null, $resultat, date('Y-m-d H:i:s')]);
    }
}
