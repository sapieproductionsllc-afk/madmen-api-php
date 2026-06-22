<?php
return [
    // Documents RH rattachés à un employé (contrats, fiches de paie, attestations...).
    // Consultés depuis ProfilDetails.jsx (onglet « Documents RH »). Lecture seule côté API.
    'up' => "CREATE TABLE document_rh (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        titre VARCHAR(160) NOT NULL,
        type VARCHAR(60) NULL,
        url VARCHAR(255) NULL,
        taille_octets BIGINT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_document_rh_emp (employe_id),
        CONSTRAINT fk_document_rh_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS document_rh",
];
