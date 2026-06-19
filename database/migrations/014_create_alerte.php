<?php
return [
    'up' => "CREATE TABLE alerte (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type ENUM('inactivite','retard','absence','deconnexion','connexion_refusee') NOT NULL,
        employe_id BIGINT UNSIGNED NULL,
        poste_travail_id BIGINT UNSIGNED NULL,
        destinataire_id BIGINT UNSIGNED NULL,
        message VARCHAR(255) NULL,
        horodatage DATETIME NOT NULL,
        lu TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_alerte_employe (employe_id),
        KEY idx_alerte_destinataire (destinataire_id),
        KEY idx_alerte_type (type),
        CONSTRAINT fk_alerte_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_alerte_poste_travail FOREIGN KEY (poste_travail_id)
            REFERENCES poste_travail(id) ON DELETE SET NULL,
        CONSTRAINT fk_alerte_destinataire FOREIGN KEY (destinataire_id)
            REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS alerte",
];
