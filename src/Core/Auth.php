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

    /**
     * Vérifie l'authentification pour l'URI demandée. Si AUTH_ENABLED=true et que
     * la route n'est pas publique, exige un Bearer valide ; renvoie un 401 JSON
     * (et termine la requête) sinon.
     */
    public static function enforce(string $uri): void
    {
        if (!Env::bool('AUTH_ENABLED', false)) {
            return; // Auth désactivée (démo) : on laisse passer.
        }

        if (in_array($uri, self::PUBLIC_PATHS, true)) {
            return; // Route publique (liste blanche).
        }

        // Protocole ADMS/iclock du K40 : le terminal ne peut pas envoyer de clé.
        // Ces routes sont authentifiées par le numéro de série (SN) du terminal.
        if (str_starts_with($uri, '/iclock/')) {
            return;
        }

        $expectedKeys = self::expectedKeys();
        $provided = self::bearerToken();

        // m2 : auth activée mais aucune clé configurée => tout est refusé (401).
        // On l'enregistre une fois par requête avant de renvoyer l'erreur.
        if ($expectedKeys === []) {
            error_log('AVERTISSEMENT: AUTH_ENABLED=true mais aucune API_KEY configurée -> tout est refusé (401).');
            Response::json(['error' => 'Non autorisé'], 401);
        }

        // M3 : compare le jeton fourni à chacune des clés autorisées avec
        // hash_equals (comparaison à temps constant, anti-timing).
        if ($provided !== null) {
            foreach ($expectedKeys as $key) {
                if (hash_equals($key, $provided)) {
                    return; // Clé valide.
                }
            }
        }

        Response::json(['error' => 'Non autorisé'], 401);
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
