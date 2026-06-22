<?php
return [
    // Paramètres globaux de l'application (clé/valeur, valeur stockée en JSON).
    'up' => "CREATE TABLE parametre (
        cle VARCHAR(120) PRIMARY KEY,
        valeur TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS parametre",
];
