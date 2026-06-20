<?php
declare(strict_types=1);

/**
 * Configuration de connexion MySQL (lit le .env via Env::load).
 */

use MadMen\Core\Env;

require_once dirname(__DIR__) . '/src/Core/Env.php';

$env = Env::load();

return [
    'host'     => $env['DB_HOST'] ?? '127.0.0.1',
    'port'     => $env['DB_PORT'] ?? '3306',
    'database' => $env['DB_NAME'] ?? 'madmen',
    'username' => $env['DB_USER'] ?? 'root',
    'password' => $env['DB_PASS'] ?? '',
    'charset'  => 'utf8mb4',
];
