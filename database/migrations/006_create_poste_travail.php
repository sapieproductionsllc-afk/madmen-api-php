<?php
return [
    'up' => "CREATE TABLE poste_travail (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NOT NULL,
        nom VARCHAR(100) NULL,
        departement_id BIGINT UNSIGNED NULL,
        adresse_ip VARCHAR(45) NULL,
        adresse_mac VARCHAR(40) NULL,
        statut ENUM('libre','occupe','verrouille','hors_ligne') NOT NULL DEFAULT 'libre',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_poste_travail_code (code),
        KEY idx_poste_travail_departement (departement_id),
        CONSTRAINT fk_poste_travail_departement FOREIGN KEY (departement_id)
            REFERENCES departement(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS poste_travail",
];
