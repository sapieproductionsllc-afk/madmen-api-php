<?php
declare(strict_types=1);

namespace MadMen\Core;

final class Request
{
    /** Paramètre de query string (?cle=valeur). */
    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Corps JSON de la requête sous forme de tableau associatif. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
