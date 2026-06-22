<?php
return [
    // Prêts / avances ACCORDÉS à l'employé (suivi du remboursement). À ne pas
    // confondre avec la DEMANDE d'avance (table `demande`, type 'avance') : le prêt
    // est créé par l'admin une fois la demande approuvée, pour suivre le solde.
    'up' => "CREATE TABLE pret (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        montant_rembourse DECIMAL(12,2) NOT NULL DEFAULT 0,
        mensualite DECIMAL(12,2) NULL,
        prochaine_echeance DATE NULL,
        motif VARCHAR(160) NULL,
        statut ENUM('en_cours','solde') NOT NULL DEFAULT 'en_cours',
        accorde_par BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pret_emp (employe_id, statut),
        CONSTRAINT fk_pret_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_pret_accordeur FOREIGN KEY (accorde_par) REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS pret",
];
