<?php
return [
    'up' => "ALTER TABLE activite_echantillon
        ADD COLUMN client_uuid CHAR(36) NULL AFTER id,
        ADD UNIQUE KEY uq_activite_client_uuid (client_uuid)",
    'down' => "ALTER TABLE activite_echantillon
        DROP KEY uq_activite_client_uuid,
        DROP COLUMN client_uuid",
];
