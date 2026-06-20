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

        $expected = Env::get('API_KEY');
        $provided = self::bearerToken();

        if ($expected === '' || $provided === null || !hash_equals($expected, $provided)) {
            Response::json(['error' => 'Non autorisé'], 401);
        }
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
