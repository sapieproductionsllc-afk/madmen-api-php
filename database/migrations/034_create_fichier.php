<?php
return [
    // Pièces jointes de la messagerie (images, audio/vocaux, PDF, documents).
    // Le binaire est stocké sur disque (storage/uploads) ; ici on garde les métadonnées.
    'up' => "CREATE TABLE fichier (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nom_original VARCHAR(255) NOT NULL,
        chemin VARCHAR(255) NOT NULL,
        mime VARCHAR(120) NOT NULL,
        taille INT UNSIGNED NOT NULL DEFAULT 0,
        televerse_par BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_fichier_emp FOREIGN KEY (televerse_par)
            REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS fichier",
];
