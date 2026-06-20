<?php
// config/postes.php — paramètres de contrôle des postes (non sensibles, exposés au client).
return [
    // Inactivité tolérée avant verrouillage automatique (spec §3).
    'inactivite_lock_minutes' => 7,
    // Au-delà de cette absence, un motif est requis au déverrouillage (spec §5).
    'justification_minutes'   => 20,
    // Fréquence d'envoi des heartbeats d'activité par le client.
    'heartbeat_seconds'       => 60,
];
