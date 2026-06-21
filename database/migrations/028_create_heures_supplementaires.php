<?php
return [
    'up' => "CREATE TABLE heures_supplementaires (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        heure_debut DATETIME NULL,
        heure_fin DATETIME NULL,
        duree_minutes INT NOT NULL DEFAULT 0,
        source ENUM('k40','session') NOT NULL DEFAULT 'k40',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_heures_sup_employe_date (employe_id, date),
        KEY idx_heures_sup_employe (employe_id),
        CONSTRAINT fk_heures_sup_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS heures_supplementaires",
];
