<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Authentification de l'API par clé statique (en-tête Authorization: Bearer <clé>).
 *
 * Piloté par AUTH_ENABLED (.env). Quand désactivé, toutes les requêtes passent
 * (mode démo). Quand activé, toute route hors liste blanche exige une clé valide.
 */
final class Auth
{
    /** Routes accessibles sans authentification (système & documentation). */
    private const PUBLIC_PATHS = ['/', '/health', '/docs', '/openapi.yaml'];

    /** Hiérarchie des rôles (rang croissant). super_admin peut tout faire. */
    private const RANKS = ['employe' => 1, 'superviseur' => 2, 'directeur' => 3, 'super_admin' => 4];

    /**
     * Vérifie l'authentification pour l'URI demandée. Si AUTH_ENABLED=true et que
     * la route n'est pas publique, exige un Bearer valide ; renvoie un 401 JSON
     * (et termine la requête) sinon.
     */
    public static function enforce(string $method, string $uri): void
    {
        if (!Env::bool('AUTH_ENABLED', false)) {
            return; // Auth désactivée (démo) : on laisse passer.
        }
        if (in_array($uri, self::PUBLIC_PATHS, true)) {
            return; // Route publique (liste blanche).
        }
        // Login public + protocole terminal /iclock (authentifié par SN).
        if ($uri === '/api/auth/login' || str_starts_with($uri, '/iclock/')) {
            return;
        }

        $provided = self::bearerToken();
        if ($provided === null) {
            Response::json(['error' => 'Non autorisé'], 401);
        }

        // 1) Clé API « maître » (clients machine / agents) = accès total.
        $keys = self::expectedKeys();
        if ($keys === []) {
            error_log('AVERTISSEMENT: AUTH_ENABLED=true mais aucune API_KEY configurée.');
        }
        foreach ($keys as $key) {
            if (hash_equals($key, $provided)) {
                return; // Clé maître : tous les droits.
            }
        }

        // 2) Jeton JWT utilisateur (RBAC par rôle).
        $payload = Jwt::decode($provided);
        if ($payload === null) {
            Response::json(['error' => 'Non autorisé'], 401);
        }
        $role = (string) ($payload['role'] ?? 'employe');
        if ($role === 'super_admin') {
            return; // Le super admin peut TOUT faire.
        }
        $rank = self::RANKS[$role] ?? 1;
        if ($rank < self::requiredRank($method, $uri)) {
            Response::json(['error' => 'Accès interdit : rôle insuffisant'], 403);
        }
    }

    /**
     * Garde-fou de démarrage : en PRODUCTION (APP_ENV=production), refuse de servir
     * si la configuration est dangereuse (auth désactivée, secrets par défaut,
     * aucune clé, CORS ouvert). Ne fait RIEN hors production (dev/démo intacts).
     */
    public static function assertProductionSafe(): void
    {
        if (Env::get('APP_ENV') !== 'production') {
            return;
        }

        $fail = static function (string $raison): void {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Configuration de production invalide']);
            error_log('BOOT REFUSÉ (production) : ' . $raison);
            exit;
        };

        if (!Env::bool('AUTH_ENABLED', false)) {
            $fail('AUTH_ENABLED doit valoir true en production.');
        }
        $defauts = ['', 'changez-moi-en-production'];
        if (in_array(trim(Env::get('APP_KEY')), $defauts, true)) {
            $fail('APP_KEY non régénérée (valeur par défaut).');
        }
        if (in_array(trim(Env::get('API_KEY')), $defauts, true) && self::expectedKeys() === []) {
            $fail('Aucune API_KEY valide configurée.');
        }
        if (trim(Env::get('CORS_ORIGIN', '*')) === '*') {
            $fail('CORS_ORIGIN ne doit pas valoir "*" en production.');
        }
    }

    /** Payload du jeton JWT courant (ou null si absent/invalide). */
    public static function currentUser(): ?array
    {
        $token = self::bearerToken();

        return $token === null ? null : Jwt::decode($token);
    }

    /**
     * Rang minimum requis pour (méthode, uri).
     *  - Actions poste/agent (sessions, pointages, sync) : employe (1)
     *  - Écritures de gestion (employés, biométrie, k40, config) : super_admin (4)
     *  - Lectures /api/* : superviseur (2)
     */
    private static function requiredRank(string $method, string $uri): int
    {
        if ($uri === '/api/auth/me') {
            return 1;
        }
        // Messagerie (conversations, messages, pièces jointes) : tout employé authentifié.
        if (preg_match('#^/api/(conversations|messages|fichiers)#', $uri) === 1) {
            return 1;
        }
        // Le kiosque/poste (rang employe) doit pouvoir poller sa propre session
        // pour détecter un verrouillage forcé (statut passé à 'verrouillee').
        if ($method === 'GET' && preg_match('#^/api/sessions/\d+$#', $uri) === 1) {
            return 1;
        }
        if ($method === 'POST') {
            if (in_array($uri, ['/api/sessions/login', '/api/sessions/identifier', '/api/pointages', '/api/sync'], true)) {
                return 1;
            }
            if (preg_match('#^/api/sessions/\d+/(lock|unlock|logout|activite)$#', $uri) === 1) {
                return 1;
            }
        }
        if ($method !== 'GET' && preg_match('#^/api/(employes|biometrie|k40|config)#', $uri) === 1) {
            return 4; // écritures de gestion réservées au super_admin
        }
        // Paie : donnée sensible (salaires) -> directeur minimum, jamais superviseur.
        if ($method === 'GET' && preg_match('#^/api/(paie|employes/\d+/paie)$#', $uri) === 1) {
            return 3;
        }
        if ($method === 'GET' && str_starts_with($uri, '/api/')) {
            return 2; // consultation : superviseur et au-dessus
        }

        return 4; // par défaut : prudence
    }

    /**
     * M3 — Multi-clés : agrège API_KEY (clé unique, rétro-compatible) et
     * API_KEYS (plusieurs clés séparées par des virgules) en une liste de clés
     * autorisées, en éliminant les valeurs vides et les doublons.
     *
     * @return list<string>
     */
    private static function expectedKeys(): array
    {
        $keys = [];

        $single = trim(Env::get('API_KEY'));
        if ($single !== '') {
            $keys[] = $single;
        }

        foreach (explode(',', Env::get('API_KEYS')) as $key) {
            $key = trim($key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Extrait le jeton de l'en-tête Authorization: Bearer <clé>.
     */
    private static function bearerToken(): ?string
    {
        $header = self::authorizationHeader();
        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Récupère l'en-tête Authorization (le serveur intégré PHP ne le place pas
     * toujours dans $_SERVER['HTTP_AUTHORIZATION']).
     */
    private static function authorizationHeader(): ?string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }

        return null;
    }
}
