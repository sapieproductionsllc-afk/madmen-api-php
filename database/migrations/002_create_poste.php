<?php
return [
    'up' => "CREATE TABLE poste (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        intitule VARCHAR(100) NOT NULL,
        departement_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_poste_departement (departement_id),
        CONSTRAINT fk_poste_departement FOREIGN KEY (departement_id)
            REFERENCES departement(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS poste",
];
