<?php
return [
    // Historique du SALAIRE FIXE (de base) par employé, avec date d'application.
    // La paie utilise, pour un mois donné, la dernière entrée dont date_application <= fin de mois.
    // `employe.salaire` reste synchronisé sur le montant ACTUEL (cache pour les vues existantes).
    'up' => "CREATE TABLE salaire_fixe (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        devise VARCHAR(8) NOT NULL DEFAULT 'FCFA',
        date_application DATE NOT NULL,
        commentaire VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_salaire_fixe_emp (employe_id, date_application),
        CONSTRAINT fk_salaire_fixe_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS salaire_fixe",
];
