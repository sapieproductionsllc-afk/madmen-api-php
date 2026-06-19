<?php
return [
    'up' => "CREATE TABLE jour_ferie (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        libelle VARCHAR(100) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_jour_ferie_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS jour_ferie",
];
