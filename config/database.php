<?php
declare(strict_types=1);

/**
 * Chargement simple des variables depuis le fichier .env (si present),
 * sans dependance externe. Retourne la configuration de connexion MySQL.
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
    'host'     => $env['DB_HOST'] ?? '127.0.0.1',
    'port'     => $env['DB_PORT'] ?? '3306',
    'database' => $env['DB_NAME'] ?? 'madmen',
    'username' => $env['DB_USER'] ?? 'root',
    'password' => $env['DB_PASS'] ?? '',
    'charset'  => 'utf8mb4',
];
