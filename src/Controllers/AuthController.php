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
    /** Durée de vie d'un refresh token : 60 jours. */
    private const REFRESH_TTL = 5184000;

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
        $refresh = $this->emettreRefresh((int) $e['id']);

        Response::json([
            'token'         => $token,
            'refresh_token' => $refresh,
            'employe' => [
                'id'        => (int) $e['id'],
                'matricule' => $e['matricule'],
                'nom'       => $e['nom'],
                'prenom'    => $e['prenom'],
                'role'      => $e['role'],
            ],
        ]);
    }

    /**
     * POST /api/auth/login-pin — { code_pin } → { token, employe }.
     *
     * Connexion par PIN SEUL (app employé) : le PIN identifie l'employé parmi TOUS
     * les comptes. GARDE-FOU de sécurité : si le PIN correspond à PLUSIEURS employés
     * (collision), on REFUSE (409) — impossible de savoir qui c'est. Throttling par
     * IP (anti-brute-force) toujours actif. ⚠️ Moins sûr qu'un matricule+PIN : à
     * réserver à des PIN suffisamment longs/uniques.
     */
    public function loginPin(): void
    {
        $body = Request::body();
        if (empty($body['code_pin'])) {
            Response::error("Le champ 'code_pin' est obligatoire", 422);
        }
        $pin = (string) $body['code_pin'];
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        // Anti-brute-force par IP (pas de matricule ici). Toujours actif.
        if ($this->tropDeTentatives($ip, $ip)) {
            Response::error('Trop de tentatives, réessayez plus tard', 429);
        }

        $rows = Database::connection()
            ->query("SELECT id, matricule, nom, prenom, role, code_pin_hash FROM employe WHERE statut <> 'suspendu'")
            ->fetchAll();

        $matches = [];
        foreach ($rows as $e) {
            if (password_verify($pin, (string) $e['code_pin_hash'])) {
                $matches[] = $e;
                if (count($matches) > 1) {
                    break; // collision détectée : inutile de continuer
                }
            }
        }

        if (count($matches) === 0) {
            $this->logTentative($ip, $ip, 'echec');
            Response::error('PIN invalide', 401);
        }
        if (count($matches) > 1) {
            // Plusieurs comptes partagent ce PIN : connexion par PIN seul impossible.
            $this->logTentative($ip, $ip, 'echec');
            Response::error(
                'Ce PIN est utilisé par plusieurs comptes : connexion par PIN seul impossible. Contactez l\'administrateur.',
                409
            );
        }

        $e = $matches[0];
        $this->logTentative($ip, $ip, 'succes');
        $token = Jwt::encode([
            'sub'       => (int) $e['id'],
            'matricule' => $e['matricule'],
            'role'      => $e['role'],
        ]);
        $refresh = $this->emettreRefresh((int) $e['id']);

        Response::json([
            'token'         => $token,
            'refresh_token' => $refresh,
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
     * POST /api/auth/refresh — { refresh_token } → { token, refresh_token }.
     * Échange un refresh token valide contre un NOUVEAU JWT (8 h) + un nouveau
     * refresh token (rotation : l'ancien est révoqué). Permet de rester connecté
     * longtemps sans ressaisir le PIN.
     */
    public function refresh(): void
    {
        $token = (string) (Request::body()['refresh_token'] ?? '');
        if ($token === '') {
            Response::error("Le champ 'refresh_token' est obligatoire", 422);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT rt.id, rt.employe_id, rt.expires_at, rt.revoked,
                    e.matricule, e.role, e.statut
             FROM refresh_token rt
             JOIN employe e ON e.id = rt.employe_id
             WHERE rt.token_hash = ?"
        );
        $stmt->execute([hash('sha256', $token)]);
        $rt = $stmt->fetch();

        if (
            !$rt
            || (int) $rt['revoked'] === 1
            || strtotime((string) $rt['expires_at']) < time()
            || $rt['statut'] === 'suspendu'
        ) {
            Response::error('Refresh token invalide ou expiré', 401);
        }

        // Rotation : on révoque l'ancien, on en émet un nouveau.
        $db->prepare('UPDATE refresh_token SET revoked = 1, last_used_at = ? WHERE id = ?')
           ->execute([date('Y-m-d H:i:s'), (int) $rt['id']]);
        $nouveau = $this->emettreRefresh((int) $rt['employe_id']);

        Response::json([
            'token'         => Jwt::encode([
                'sub'       => (int) $rt['employe_id'],
                'matricule' => $rt['matricule'],
                'role'      => $rt['role'],
            ]),
            'refresh_token' => $nouveau,
        ]);
    }

    /** POST /api/auth/logout — { refresh_token } → 204. Révoque le refresh token. */
    public function logout(): void
    {
        $token = (string) (Request::body()['refresh_token'] ?? '');
        if ($token !== '') {
            Database::connection()
                ->prepare('UPDATE refresh_token SET revoked = 1 WHERE token_hash = ?')
                ->execute([hash('sha256', $token)]);
        }
        Response::noContent();
    }

    /**
     * Émet un refresh token (opaque, aléatoire) pour un employé : stocke son HASH
     * + son expiration, renvoie le jeton EN CLAIR (à donner au client, une seule fois).
     */
    private function emettreRefresh(int $employeId): string
    {
        $token = bin2hex(random_bytes(32)); // 64 caractères hexadécimaux
        Database::connection()->prepare(
            'INSERT INTO refresh_token (employe_id, token_hash, expires_at) VALUES (?, ?, ?)'
        )->execute([
            $employeId,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::REFRESH_TTL),
        ]);

        return $token;
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
