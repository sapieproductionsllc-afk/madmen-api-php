<?php
return [
    'up' => "CREATE TABLE motif_absence (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        libelle VARCHAR(80) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_motif_libelle (libelle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS motif_absence",
];
