<?php
return [
    'up' => "CREATE TABLE tentative_connexion (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NULL,
        poste_travail_id BIGINT UNSIGNED NOT NULL,
        horodatage DATETIME NOT NULL,
        methode ENUM('pin','empreinte','rfid','facial') NULL,
        resultat ENUM('succes','echec') NOT NULL,
        raison_echec VARCHAR(120) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tentative_employe (employe_id),
        KEY idx_tentative_poste_travail (poste_travail_id),
        KEY idx_tentative_resultat (resultat),
        CONSTRAINT fk_tentative_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE SET NULL,
        CONSTRAINT fk_tentative_poste_travail FOREIGN KEY (poste_travail_id)
            REFERENCES poste_travail(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS tentative_connexion",
];
