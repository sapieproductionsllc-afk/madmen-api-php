<?php
return [
    'up' => "CREATE TABLE autorisation_poste (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        poste_travail_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_autorisation_poste (employe_id, poste_travail_id),
        KEY idx_autorisation_poste_travail (poste_travail_id),
        CONSTRAINT fk_autorisation_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_autorisation_poste_travail FOREIGN KEY (poste_travail_id)
            REFERENCES poste_travail(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS autorisation_poste",
];
