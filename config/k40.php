<?php
declare(strict_types=1);

/**
 * Configuration de la pointeuse ZKTeco K40 (terminal de pointage réseau).
 * Lit le .env.
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

$bool = static fn (string $k, bool $def): bool =>
    isset($env[$k]) ? in_array(strtolower($env[$k]), ['1', 'true', 'yes', 'on'], true) : $def;

return [
    // Mettre à true une fois le K40 branché et configuré sur le réseau.
    'enabled'        => $bool('K40_ENABLED', false),
    'ip'             => $env['K40_IP'] ?? '192.168.1.201',
    'port'           => (int) ($env['K40_PORT'] ?? 4370),
    // Clé de communication du terminal (0 = aucune). Pour info/doc.
    'password'       => (int) ($env['K40_PASSWORD'] ?? 0),
    // Heure limite d'arrivée : au-delà => retard.
    'heure_limite'   => $env['K40_HEURE_LIMITE'] ?? '08:15',
];
