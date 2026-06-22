<?php
return [
    // Journal des paiements de paie : une ligne par (employé, période) marquée
    // PAYÉE depuis l'écran de paie (Paiement.jsx « marquer payé »). Sert d'historique
    // des versements et alimente la synthèse Finance (mouvements + total par période).
    // La contrainte UNIQUE (employe_id, periode) garantit l'idempotence : un même
    // employé ne peut être payé qu'une fois pour une période donnée.
    'up' => "CREATE TABLE paie_paiement (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        periode CHAR(7) NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        statut ENUM('paye') NOT NULL DEFAULT 'paye',
        paye_le DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_paie_paiement (employe_id, periode),
        CONSTRAINT fk_paie_paiement_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS paie_paiement",
];
