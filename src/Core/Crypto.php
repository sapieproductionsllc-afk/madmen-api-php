<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Chiffrement symétrique (AES-256-GCM) pour les données sensibles au repos,
 * notamment les gabarits biométriques. Format stocké : iv(12) . tag(16) . cipher.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $cfg = require dirname(__DIR__, 2) . '/config/app.php';

        return hash('sha256', (string) $cfg['key'], true); // 32 octets
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $iv . $tag . $cipher;
    }

    public static function decrypt(string $blob): string
    {
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $cipher = substr($blob, 28);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }
}
