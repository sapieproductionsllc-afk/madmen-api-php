<?php
return [
    // Historique RH d'un employé (événements : embauche, promotion, sanction, congé...).
    // Affiché en frise chronologique dans ProfilDetails.jsx (onglet « Historique RH »).
    'up' => "CREATE TABLE historique_rh (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        evenement VARCHAR(160) NOT NULL,
        detail VARCHAR(255) NULL,
        date DATE NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_historique_rh_emp (employe_id),
        CONSTRAINT fk_historique_rh_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS historique_rh",
];
