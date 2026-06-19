<?php
return [
    'up' => "CREATE TABLE session_travail (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        poste_travail_id BIGINT UNSIGNED NOT NULL,
        heure_debut DATETIME NOT NULL,
        heure_fin DATETIME NULL,
        methode_auth ENUM('pin','empreinte','pin+empreinte') NULL,
        autorisation_ok TINYINT(1) NULL,
        statut ENUM('ouverte','verrouillee','fermee') NOT NULL DEFAULT 'ouverte',
        duree_active_sec INT NOT NULL DEFAULT 0,
        duree_inactive_sec INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_session_employe (employe_id),
        KEY idx_session_poste_travail (poste_travail_id),
        CONSTRAINT fk_session_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_session_poste_travail FOREIGN KEY (poste_travail_id)
            REFERENCES poste_travail(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS session_travail",
];
