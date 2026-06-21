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
    // Rôle de l'instance : 'cloud' (API de gestion, hébergement distant — ne parle
    // PAS au K40) ou 'gateway' (passerelle locale sur le LAN du bureau — exécute
    // le PULL + le pont pyzk). Les routes PULL K40 ne sont montées qu'en 'gateway'.
    'role'           => strtolower((string) ($env['K40_ROLE'] ?? 'cloud')),
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
    // Liste blanche des numéros de série (SN) autorisés en mode Push.
    // Lue depuis K40_PUSH_SN (.env, valeurs séparées par des virgules).
    // Tableau vide = accepte tout terminal (dev) ; à renseigner en prod.
    // C'est l'agent K40 (K40PushController) qui exploite cette liste.
    'push_sn'        => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ($env['K40_PUSH_SN'] ?? ''))
    ), static fn (string $sn): bool => $sn !== '')),

    // --- Pont Python+pyzk pour l'écriture des gabarits d'empreinte ---
    // La lib PHP rats/zkteco ne sait pas écrire les empreintes (setFingerprint
    // cassé) ; l'upload de gabarit passe par un script Python (pyzk).
    'python_bin'     => $env['K40_PYTHON_BIN'] ?? 'python',
    'push_script'    => $env['K40_PUSH_SCRIPT']
        ?? (dirname(__DIR__) . '/scripts/k40_push_template.py'),
    // Timeout dur (s) du sous-processus Python côté PHP.
    'python_timeout' => (int) ($env['K40_PYTHON_TIMEOUT'] ?? 60),
];
