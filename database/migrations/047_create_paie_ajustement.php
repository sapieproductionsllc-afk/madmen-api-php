<?php
return [
    // Composition du salaire (#4) : primes / retenues MANUELLES par mois, en plus des
    // deductions automatiques (retard/absence) deja calculees. Additif. Le kiosque ne
    // touche pas a la paie -> aucun impact. Les AVANCES sont gerees via la table `pret`.
    'up' => "CREATE TABLE paie_ajustement (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        periode CHAR(7) NOT NULL,
        type ENUM('prime','retenue') NOT NULL,
        libelle VARCHAR(160) NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_paie_ajustement_emp (employe_id, periode),
        CONSTRAINT fk_paie_ajustement_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS paie_ajustement",
];
