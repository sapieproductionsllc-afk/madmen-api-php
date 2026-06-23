<?php
return [
    // Refresh tokens (sessions longues de l'app employé/mobile). L'access token JWT
    // dure 8 h ; le refresh token (opaque, aléatoire, ~60 j) permet d'en obtenir un
    // nouveau sans re-saisir le PIN. On stocke uniquement le HASH (SHA-256) du jeton
    // — révocable (logout) et à rotation (un nouveau refresh à chaque utilisation).
    'up' => "CREATE TABLE refresh_token (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employe_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rt_hash (token_hash),
        KEY idx_rt_employe (employe_id),
        KEY idx_rt_expires (expires_at),
        CONSTRAINT fk_rt_employe FOREIGN KEY (employe_id) REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS refresh_token",
];
