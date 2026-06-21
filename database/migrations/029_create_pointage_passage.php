<?php
return [
    // Chaque « doigt » sur le K40 = un passage. Type à bascule :
    // 1er du jour = entree (arrivée), puis sortie (pause/départ), entree (retour)...
    // Permet de gérer PLUSIEURS pauses dans la journée (chaque sortie->entree = absence).
    'up' => "CREATE TABLE pointage_passage (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        type ENUM('entree','sortie') NOT NULL,
        horodatage DATETIME NOT NULL,
        appareil_id BIGINT UNSIGNED NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'k40',
        client_uuid CHAR(36) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pp_emp_date (employe_id, date),
        UNIQUE KEY uq_pp_client_uuid (client_uuid),
        CONSTRAINT fk_pp_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_pp_appareil FOREIGN KEY (appareil_id)
            REFERENCES appareil_biometrique(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS pointage_passage",
];
