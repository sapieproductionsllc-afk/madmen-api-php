<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Jetons JWT signés (HS256) pour l'authentification des utilisateurs du
 * dashboard. Sans état (le rôle voyage dans le jeton, vérifié par signature
 * HMAC avec APP_KEY). Distinct de la clé API statique (clients machine).
 */
final class Jwt
{
    /** Durée de vie par défaut : 8 h. */
    public const TTL = 28800;

    private static function secret(): string
    {
        $cfg = require dirname(__DIR__, 2) . '/config/app.php';

        return (string) $cfg['key'];
    }

    /** @param array<string,mixed> $payload */
    public static function encode(array $payload, int $ttl = self::TTL): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;

        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));

        return "$h.$p.$sig";
    }

    /** @return array<string,mixed>|null payload validé, ou null si invalide/expiré */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $sig] = $parts;

        $expected = self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode(self::unb64($p), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && time() > (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
