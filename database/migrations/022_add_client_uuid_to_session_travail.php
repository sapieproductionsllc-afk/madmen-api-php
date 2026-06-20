<?php
return [
    // Identifiant généré côté client (PC) pour les sessions créées HORS-LIGNE.
    // Permet une synchronisation montante IDEMPOTENTE : rejouer la même session
    // n'insère pas de doublon (clé UNIQUE). NULL pour les sessions ouvertes en
    // ligne (plusieurs NULL autorisés par MySQL sur un index UNIQUE).
    'up' => "ALTER TABLE session_travail
        ADD COLUMN client_uuid CHAR(36) NULL AFTER id,
        ADD UNIQUE KEY uq_session_client_uuid (client_uuid)",
    'down' => "ALTER TABLE session_travail
        DROP KEY uq_session_client_uuid,
        DROP COLUMN client_uuid",
];
