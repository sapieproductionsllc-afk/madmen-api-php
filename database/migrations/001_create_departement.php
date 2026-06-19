<?php
return [
    'up' => "CREATE TABLE departement (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        code VARCHAR(20) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_departement_nom (nom),
        UNIQUE KEY uq_departement_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS departement",
];
