<?php
return [
    // Anti-brute-force du login dashboard (POST /api/auth/login) qui délivre des
    // jetons JWT porteurs de RÔLE (jusqu'à super_admin). Distincte de
    // tentative_connexion (qui est liée à un poste_travail, pour le PIN kiosque).
    // Comptage par matricule tenté (lockout de compte) et par IP (anti-balayage).
    'up' => "CREATE TABLE tentative_login (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        identifiant VARCHAR(120) NOT NULL,
        ip VARCHAR(45) NULL,
        resultat ENUM('succes','echec') NOT NULL,
        horodatage DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tl_identifiant (identifiant),
        KEY idx_tl_ip (ip),
        KEY idx_tl_horodatage (horodatage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS tentative_login",
];
