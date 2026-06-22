<?php
return [
    // Demandes génériques de l'employé (app self-service) : avance sur salaire,
    // congé/permission, formation, attestation, autre. Un seul modèle pour tous les
    // types ; champs optionnels selon le type (montant pour avance, dates pour congé).
    // NB : système de SOLDE de congés (solde_conge/demande_conge du collègue) séparé ;
    // intégration éventuelle plus tard.
    'up' => "CREATE TABLE demande (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        type ENUM('avance','conge','formation','attestation','autre') NOT NULL,
        objet VARCHAR(160) NOT NULL,
        details TEXT NULL,
        montant DECIMAL(12,2) NULL,
        date_debut DATE NULL,
        date_fin DATE NULL,
        statut ENUM('en_attente','approuve','refuse','annule') NOT NULL DEFAULT 'en_attente',
        motif_refus VARCHAR(255) NULL,
        valide_par BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_demande_emp (employe_id, statut),
        CONSTRAINT fk_demande_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_demande_valideur FOREIGN KEY (valide_par) REFERENCES employe(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS demande",
];
