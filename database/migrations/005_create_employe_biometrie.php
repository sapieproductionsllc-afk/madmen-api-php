<?php
return [
    'up' => "CREATE TABLE employe_biometrie (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        type ENUM('empreinte','rfid','facial') NOT NULL,
        doigt VARCHAR(20) NULL,
        template BLOB NULL,
        badge_rfid VARCHAR(50) NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_biometrie_badge_rfid (badge_rfid),
        KEY idx_biometrie_employe (employe_id),
        CONSTRAINT fk_biometrie_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS employe_biometrie",
];
