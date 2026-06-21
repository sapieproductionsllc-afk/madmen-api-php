<?php
declare(strict_types=1);

/**
 * Seuils de surveillance des postes de travail (kiosque).
 *
 * Ces valeurs sont consommées par l'app kiosque via GET /api/config/postes
 * (tauri-bridge -> orchestrateur). Lit le .env via Env::load.
 */

use MadMen\Core\Env;

require_once dirname(__DIR__) . '/src/Core/Env.php';

$env = Env::load();

return [
    // Minutes d'inactivité avant verrouillage automatique du poste.
    'inactivite_lock_minutes' => (int) ($env['POSTE_LOCK_MINUTES'] ?? 7),
    // Minutes d'absence au-delà desquelles un motif est exigé à la reprise.
    'justification_minutes'   => (int) ($env['POSTE_JUSTIF_MINUTES'] ?? 20),
    // Période (secondes) des heartbeats d'activité envoyés par le kiosque.
    'heartbeat_seconds'       => (int) ($env['POSTE_HEARTBEAT_SECONDS'] ?? 60),
];
