<?php
return [
    'up' => "CREATE TABLE pointage (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        appareil_id BIGINT UNSIGNED NULL,
        date DATE NOT NULL,
        heure_entree DATETIME NULL,
        heure_sortie DATETIME NULL,
        methode ENUM('empreinte','rfid','facial','pin') NULL,
        retard_minutes INT NOT NULL DEFAULT 0,
        statut ENUM('present','absent','retard','conge') NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pointage_employe (employe_id),
        KEY idx_pointage_appareil (appareil_id),
        KEY idx_pointage_date (date),
        CONSTRAINT fk_pointage_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_pointage_appareil FOREIGN KEY (appareil_id)
            REFERENCES appareil_biometrique(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS pointage",
];
