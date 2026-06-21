<?php
return [
    // Messagerie : une conversation = un fil direct (1-à-1) ou un groupe (3+).
    'up' => "CREATE TABLE conversation (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type ENUM('direct','groupe') NOT NULL DEFAULT 'direct',
        nom VARCHAR(120) NULL,
        cree_par BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_conv_updated (updated_at),
        CONSTRAINT fk_conv_creator FOREIGN KEY (cree_par)
            REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS conversation",
];
