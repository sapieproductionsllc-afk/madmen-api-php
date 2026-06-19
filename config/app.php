<?php
declare(strict_types=1);

/**
 * Configuration applicative (lit le .env). APP_KEY sert au chiffrement
 * des gabarits biométriques au repos.
 */

$root = dirname(__DIR__);
$envFile = $root . '/.env';
$env = [];

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $env[trim($key)] = trim($value);
    }
}

return [
    'key' => $env['APP_KEY'] ?? 'dev-insecure-key-change-me',
];
