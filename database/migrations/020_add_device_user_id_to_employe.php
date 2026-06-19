<?php
return [
    'up' => "ALTER TABLE employe
        ADD COLUMN device_user_id VARCHAR(20) NULL AFTER matricule,
        ADD UNIQUE KEY uq_employe_device_user_id (device_user_id)",
    'down' => "ALTER TABLE employe
        DROP INDEX uq_employe_device_user_id,
        DROP COLUMN device_user_id",
];
