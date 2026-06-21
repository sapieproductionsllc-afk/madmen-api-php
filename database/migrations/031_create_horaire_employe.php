<?php
return [
    // Horaire de travail PAR EMPLOYÉ (1 ligne par employé). Sert de référence pour
    // calculer retard / présence / heures sup / absence — au lieu d'un horaire
    // global. Absence de ligne => on retombe sur les valeurs globales (config/presence.php).
    // jours_travailles : numéros ISO du jour (1=lundi .. 7=dimanche), séparés par des virgules.
    'up' => "CREATE TABLE horaire_employe (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        heure_arrivee TIME NOT NULL DEFAULT '08:30:00',
        heure_depart TIME NOT NULL DEFAULT '18:00:00',
        pause_debut TIME NULL,
        pause_fin TIME NULL,
        tolerance_minutes INT NOT NULL DEFAULT 0,
        jours_travailles VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_horaire_employe (employe_id),
        CONSTRAINT fk_horaire_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS horaire_employe",
];
