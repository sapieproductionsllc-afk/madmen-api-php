<?php
return [
    'up' => "CREATE TABLE appareil_biometrique (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        type ENUM('empreinte','rfid','facial') NOT NULL,
        emplacement VARCHAR(150) NULL,
        adresse_ip VARCHAR(45) NULL,
        numero_serie VARCHAR(80) NULL,
        statut ENUM('en_ligne','hors_ligne','maintenance') NOT NULL DEFAULT 'en_ligne',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_appareil_numero_serie (numero_serie)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS appareil_biometrique",
];
