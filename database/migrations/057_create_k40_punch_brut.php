<?php
return [
    // Journal BRUT append-only de TOUS les punchs K40 (pull ET push). Chaque punch y
    // est écrit AVANT toute résolution d'employé ou filtrage horaire -> garantit
    // qu'AUCUN punch n'est jamais perdu (non mappé, filtré par horaire, ou daté futur).
    // Source de vérité brute, rejouable/réparable. Dédup idempotente par client_uuid.
    'up' => "CREATE TABLE IF NOT EXISTS k40_punch_brut (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        device_user_id VARCHAR(20) NOT NULL,
        horodatage DATETIME NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'k40',
        employe_id BIGINT UNSIGNED NULL,
        decision VARCHAR(24) NULL,
        client_uuid CHAR(36) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pb_client_uuid (client_uuid),
        KEY idx_pb_device (device_user_id, horodatage),
        KEY idx_pb_decision (decision),
        KEY idx_pb_employe (employe_id, horodatage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'down' => "DROP TABLE IF EXISTS k40_punch_brut",
];
