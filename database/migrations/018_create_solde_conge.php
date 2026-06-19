<?php
return [
    'up' => "CREATE TABLE solde_conge (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        type_conge_id BIGINT UNSIGNED NOT NULL,
        annee YEAR NOT NULL,
        jours_acquis DECIMAL(5,2) NULL,
        jours_pris DECIMAL(5,2) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_solde_conge (employe_id, type_conge_id, annee),
        KEY idx_solde_conge_type (type_conge_id),
        CONSTRAINT fk_solde_conge_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_solde_conge_type FOREIGN KEY (type_conge_id)
            REFERENCES type_conge(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS solde_conge",
];
