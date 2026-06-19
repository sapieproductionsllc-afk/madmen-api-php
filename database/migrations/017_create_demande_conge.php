<?php
return [
    'up' => "CREATE TABLE demande_conge (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        type_conge_id BIGINT UNSIGNED NOT NULL,
        date_debut DATE NOT NULL,
        date_fin DATE NOT NULL,
        nb_jours DECIMAL(5,2) NULL,
        statut ENUM('en_attente','approuve','refuse','annule') NOT NULL DEFAULT 'en_attente',
        valide_par BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_demande_conge_employe (employe_id),
        KEY idx_demande_conge_type (type_conge_id),
        KEY idx_demande_conge_valide_par (valide_par),
        CONSTRAINT fk_demande_conge_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_demande_conge_type FOREIGN KEY (type_conge_id)
            REFERENCES type_conge(id) ON DELETE CASCADE,
        CONSTRAINT fk_demande_conge_valide_par FOREIGN KEY (valide_par)
            REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS demande_conge",
];
