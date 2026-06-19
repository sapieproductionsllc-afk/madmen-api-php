<?php
return [
    'up' => "CREATE TABLE productivite_jour (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        temps_presence_min INT NULL,
        temps_travaille_min INT NULL,
        temps_inactivite_min INT NULL,
        nb_arrets INT NOT NULL DEFAULT 0,
        retard_minutes INT NOT NULL DEFAULT 0,
        taux_productivite DECIMAL(5,2) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_productivite_employe_date (employe_id, date),
        CONSTRAINT fk_productivite_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS productivite_jour",
];
