<?php
return [
    'up' => "CREATE TABLE employe (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        matricule VARCHAR(20) NOT NULL,
        nom VARCHAR(80) NOT NULL,
        prenom VARCHAR(80) NOT NULL,
        photo_url VARCHAR(255) NULL,
        poste_id BIGINT UNSIGNED NULL,
        departement_id BIGINT UNSIGNED NULL,
        superieur_id BIGINT UNSIGNED NULL,
        telephone VARCHAR(30) NULL,
        adresse VARCHAR(255) NULL,
        contact_urgence_nom VARCHAR(120) NULL,
        contact_urgence_tel VARCHAR(30) NULL,
        salaire DECIMAL(10,2) NULL,
        code_pin_hash VARCHAR(255) NOT NULL,
        statut ENUM('actif','suspendu','conge') NOT NULL DEFAULT 'actif',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_employe_matricule (matricule),
        KEY idx_employe_poste (poste_id),
        KEY idx_employe_departement (departement_id),
        KEY idx_employe_superieur (superieur_id),
        CONSTRAINT fk_employe_poste FOREIGN KEY (poste_id)
            REFERENCES poste(id) ON DELETE SET NULL,
        CONSTRAINT fk_employe_departement FOREIGN KEY (departement_id)
            REFERENCES departement(id) ON DELETE SET NULL,
        CONSTRAINT fk_employe_superieur FOREIGN KEY (superieur_id)
            REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS employe",
];
