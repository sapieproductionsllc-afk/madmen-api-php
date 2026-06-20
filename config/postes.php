<?php
// config/postes.php — paramètres de contrôle des postes (non sensibles, exposés au client).
return [
    // Inactivité tolérée avant verrouillage automatique (spec §3).
    // [TEST] abaissé à 1 min (prod = 7).
    'inactivite_lock_minutes' => 1,
    // Au-delà de cette absence, un motif est requis au déverrouillage (spec §5).
    // [TEST] abaissé à 2 min (prod = 20).
    'justification_minutes'   => 2,
    // Fréquence d'envoi des heartbeats d'activité par le client.
    // [TEST] abaissé à 20 s (prod = 60).
    'heartbeat_seconds'       => 20,
];
