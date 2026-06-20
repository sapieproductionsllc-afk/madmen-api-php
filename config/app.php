<?php
declare(strict_types=1);

/**
 * Configuration applicative (lit le .env via Env::load). APP_KEY sert au
 * chiffrement des gabarits biométriques au repos.
 */

use MadMen\Core\Env;

require_once dirname(__DIR__) . '/src/Core/Env.php';

$env = Env::load();

return [
    'key'   => $env['APP_KEY'] ?? 'dev-insecure-key-change-me',
    'debug' => Env::bool('APP_DEBUG', false),
    'env'   => $env['APP_ENV'] ?? 'production',
];
