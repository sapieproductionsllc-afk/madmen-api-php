<?php
return [
    // Messages d'une conversation : texte et/ou pièce jointe (image, audio, document).
    // client_uuid = idempotence (offline-first, anti-doublon à l'envoi).
    'up' => "CREATE TABLE message (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        expediteur_id BIGINT UNSIGNED NOT NULL,
        type ENUM('texte','image','audio','document','fichier') NOT NULL DEFAULT 'texte',
        contenu TEXT NULL,
        fichier_id BIGINT UNSIGNED NULL,
        client_uuid CHAR(36) NULL,
        supprime TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_msg_client_uuid (client_uuid),
        KEY idx_msg_conv (conversation_id, id),
        CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id)
            REFERENCES conversation(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_exp FOREIGN KEY (expediteur_id)
            REFERENCES employe(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_fichier FOREIGN KEY (fichier_id)
            REFERENCES fichier(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'down' => "DROP TABLE IF EXISTS message",
];
