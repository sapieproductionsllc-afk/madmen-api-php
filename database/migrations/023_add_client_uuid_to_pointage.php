<?php
return [
    // Idempotence de la synchro montante (événements créés hors-ligne).
    'up' => "ALTER TABLE pointage
        ADD COLUMN client_uuid CHAR(36) NULL AFTER id,
        ADD UNIQUE KEY uq_pointage_client_uuid (client_uuid)",
    'down' => "ALTER TABLE pointage
        DROP KEY uq_pointage_client_uuid,
        DROP COLUMN client_uuid",
];
