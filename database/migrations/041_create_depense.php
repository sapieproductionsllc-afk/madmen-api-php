<?php
return [
    // Dépenses de la société (frais généraux, achats, charges...). Saisie libre
    // par l'administration ; consultées par mois (filtre sur `date`).
    'up' => "CREATE TABLE depense (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        libelle VARCHAR(160) NOT NULL,
        categorie VARCHAR(80) NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        date DATE NOT NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_depense_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS depense",
];
