<?php
return [
    // Objectifs / évolution professionnelle de l'employé (app self-service).
    'up' => "CREATE TABLE objectif (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        categorie ENUM('competences','carriere','formation','performance','perso') NOT NULL DEFAULT 'competences',
        titre VARCHAR(160) NOT NULL,
        description TEXT NULL,
        echeance DATE NULL,
        progression TINYINT UNSIGNED NOT NULL DEFAULT 0,
        statut ENUM('en_cours','atteint','abandonne') NOT NULL DEFAULT 'en_cours',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_objectif_emp (employe_id),
        CONSTRAINT fk_objectif_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS objectif",
];
