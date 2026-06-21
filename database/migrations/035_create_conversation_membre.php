<?php
return [
    // Membres d'une conversation. last_read_message_id = curseur de lecture
    // (sert aux accusés de lecture ✓✓ et au compteur de non-lus). Pas de FK vers
    // message pour éviter une dépendance circulaire (message référence conversation).
    'up' => "CREATE TABLE conversation_membre (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        employe_id BIGINT UNSIGNED NOT NULL,
        role ENUM('admin','membre') NOT NULL DEFAULT 'membre',
        last_read_message_id BIGINT UNSIGNED NULL,
        joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_conv_emp (conversation_id, employe_id),
        KEY idx_cm_emp (employe_id),
        CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id)
            REFERENCES conversation(id) ON DELETE CASCADE,
        CONSTRAINT fk_cm_emp FOREIGN KEY (employe_id)
            REFERENCES employe(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS conversation_membre",
];
