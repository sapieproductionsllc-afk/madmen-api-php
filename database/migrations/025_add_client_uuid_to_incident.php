<?php
return [
    'up' => "ALTER TABLE incident_inactivite
        ADD COLUMN client_uuid CHAR(36) NULL AFTER id,
        ADD UNIQUE KEY uq_incident_client_uuid (client_uuid)",
    'down' => "ALTER TABLE incident_inactivite
        DROP KEY uq_incident_client_uuid,
        DROP COLUMN client_uuid",
];
