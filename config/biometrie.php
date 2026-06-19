<?php
declare(strict_types=1);

/**
 * Configuration de l'intégration biométrique (lecteur d'empreintes).
 * Cible : ZKTeco via le service local ZKFinger WebSDK / ZKBioOnline.
 *
 * Lit le .env. Les valeurs BIO_* pilotent à la fois le backend et le front
 * (via l'endpoint GET /api/config/biometrie).
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
    // Marque du lecteur : zkteco | digitalpersona | secugen
    'device'          => $env['BIO_DEVICE'] ?? 'zkteco',
    // URL du service local installé sur le poste (ZKFinger WebSDK / ZKBioOnline)
    'bridge_url'      => $env['BIO_BRIDGE_URL'] ?? 'http://127.0.0.1:8080',
    // Nombre de captures du même doigt pour construire le gabarit d'enrôlement
    'samples'         => (int) ($env['BIO_SAMPLES'] ?? 3),
    // Seuil de correspondance au login (0-100). Plus haut = plus strict.
    'threshold'       => (int) ($env['BIO_THRESHOLD'] ?? 55),
    // Format du gabarit : ansi | iso | zk
    'template_format' => $env['BIO_TEMPLATE_FORMAT'] ?? 'ansi',
    // Mode simulation : true tant qu'aucun lecteur réel n'est branché
    'simulation'      => $bool('BIO_SIMULATION', true),
];
