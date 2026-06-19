<?php
return [
    'up' => "CREATE TABLE type_conge (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        libelle VARCHAR(50) NOT NULL,
        paye TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_type_conge_libelle (libelle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS type_conge",
];
