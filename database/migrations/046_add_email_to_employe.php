<?php
return [
    // Champ email de l'employe (affiche par le dashboard : annuaire, profil, comptes).
    // Additif : colonne NULLABLE, n'impacte ni le kiosque ni la sync. Non utilise pour
    // l'authentification (login = matricule + PIN).
    'up' => "ALTER TABLE employe
        ADD COLUMN email VARCHAR(160) NULL AFTER telephone,
        ADD UNIQUE KEY uq_employe_email (email)",
    'down' => "ALTER TABLE employe
        DROP INDEX uq_employe_email,
        DROP COLUMN email",
];
