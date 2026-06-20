<?php
declare(strict_types=1);

/**
 * Configuration de la pointeuse ZKTeco K40 (terminal de pointage réseau).
 * Lit le .env via Env::load.
 */

use MadMen\Core\Env;

require_once dirname(__DIR__) . '/src/Core/Env.php';

$env = Env::load();

return [
    // Mettre à true une fois le K40 branché et configuré sur le réseau.
    'enabled'        => Env::bool('K40_ENABLED', false),
    'ip'             => $env['K40_IP'] ?? '192.168.1.201',
    'port'           => (int) ($env['K40_PORT'] ?? 4370),
    // Clé de communication du terminal (0 = aucune). Pour info/doc.
    'password'       => (int) ($env['K40_PASSWORD'] ?? 0),
    // Heure limite d'arrivée : au-delà => retard.
    'heure_limite'   => $env['K40_HEURE_LIMITE'] ?? '08:15',
    // Mode de communication : 'pull' (l'API interroge le K40 sur le LAN),
    // 'push' (le K40 envoie vers l'API via /iclock — ADMS), ou 'both'.
    'mode'           => strtolower((string) ($env['K40_MODE'] ?? 'pull')),
];
