<?php
return [
    'up' => "CREATE TABLE activite_echantillon (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL,
        horodatage DATETIME NOT NULL,
        mouvements_souris INT NOT NULL DEFAULT 0,
        frappes_clavier INT NOT NULL DEFAULT 0,
        app_active VARCHAR(150) NULL,
        niveau_activite ENUM('actif','inactif') NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_activite_session (session_id),
        KEY idx_activite_horodatage (horodatage),
        CONSTRAINT fk_activite_session FOREIGN KEY (session_id)
            REFERENCES session_travail(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS activite_echantillon",
];
