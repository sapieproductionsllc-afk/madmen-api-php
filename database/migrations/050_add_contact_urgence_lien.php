<?php
return [
    // Lien de parenté du contact d'urgence (ex. « Conjoint », « Mère ») — affiché sur la
    // fiche profil de l'employé (dashboard). Additif : colonne NULLABLE, sans impact
    // kiosque/sync. Non utilisé pour l'authentification.
    'up' => "ALTER TABLE employe
        ADD COLUMN contact_urgence_lien VARCHAR(60) NULL AFTER contact_urgence_tel",
    'down' => "ALTER TABLE employe
        DROP COLUMN contact_urgence_lien",
];
