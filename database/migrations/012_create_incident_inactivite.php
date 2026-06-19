<?php
return [
    'up' => "CREATE TABLE incident_inactivite (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL,
        employe_id BIGINT UNSIGNED NOT NULL,
        poste_travail_id BIGINT UNSIGNED NOT NULL,
        heure_verrouillage DATETIME NOT NULL,
        heure_reprise DATETIME NULL,
        duree_minutes INT NULL,
        motif_id BIGINT UNSIGNED NULL,
        justification VARCHAR(255) NULL,
        statut ENUM('ouvert','justifie','clos') NOT NULL DEFAULT 'ouvert',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_incident_session (session_id),
        KEY idx_incident_employe (employe_id),
        KEY idx_incident_poste_travail (poste_travail_id),
        KEY idx_incident_motif (motif_id),
        CONSTRAINT fk_incident_session FOREIGN KEY (session_id)
            REFERENCES session_travail(id) ON DELETE CASCADE,
        CONSTRAINT fk_incident_employe FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_incident_poste_travail FOREIGN KEY (poste_travail_id)
            REFERENCES poste_travail(id) ON DELETE CASCADE,
        CONSTRAINT fk_incident_motif FOREIGN KEY (motif_id)
            REFERENCES motif_absence(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS incident_inactivite",
];
