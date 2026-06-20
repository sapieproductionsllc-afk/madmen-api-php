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

        // Dérivation HKDF (RFC 5869) plutôt qu'un simple hash : meilleure
        // séparation de domaine et résistance. 32 octets pour AES-256.
        return hash_hkdf('sha256', (string) $cfg['key'], 32, 'madmen-biometrie-template');
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

        if ($plain === false) {
            error_log('Crypto::decrypt a échoué (gabarit illisible : clé APP_KEY changée ou données corrompues ?).');

            return '';
        }

        return $plain;
    }
}
