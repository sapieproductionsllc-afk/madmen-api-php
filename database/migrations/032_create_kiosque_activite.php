<?php
return [
    // Journal d'activité du KIOSQUE (surveillance) — une ligne par session.
    // Enregistre TOUT le temps : actif (la personne travaille) ET inactif
    // (verrouillé / ne travaille pas), + login/logout + motifs.
    //
    // IMPORTANT : table de SURVEILLANCE uniquement. Elle n'est JAMAIS lue par la
    // paie (PaieController ne lit que `pointage`/K40). Aucun impact sur le salaire.
    'up' => "CREATE TABLE kiosque_activite (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NULL,
        employe_id BIGINT UNSIGNED NOT NULL,
        poste_travail_id BIGINT UNSIGNED NULL,
        date DATE NOT NULL,
        connexion_at DATETIME NOT NULL,
        deconnexion_at DATETIME NULL,
        temps_total_sec INT NOT NULL DEFAULT 0,
        temps_actif_sec INT NOT NULL DEFAULT 0,
        temps_inactif_sec INT NOT NULL DEFAULT 0,
        nb_verrouillages INT NOT NULL DEFAULT 0,
        motifs JSON NULL,
        methode_auth VARCHAR(20) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ka_emp_date (employe_id, date),
        KEY idx_ka_session (session_id),
        CONSTRAINT fk_ka_emp FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS kiosque_activite",
];
