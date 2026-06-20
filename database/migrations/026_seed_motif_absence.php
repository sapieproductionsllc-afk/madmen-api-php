<?php
// database/migrations/026_seed_motif_absence.php
return [
    // Insertion idempotente des motifs imposés par la spec (INSERT IGNORE sur la
    // clé UNIQUE uq_motif_libelle). Rejouable sans créer de doublon.
    'up' => "INSERT IGNORE INTO motif_absence (libelle) VALUES
        ('réunion'),
        ('pause café'),
        ('pause toilette'),
        ('intervention technique'),
        ('appel professionnel'),
        ('déplacement interne'),
        ('autre')",
    'down' => "DELETE FROM motif_absence WHERE libelle IN
        ('réunion','pause café','pause toilette','intervention technique',
         'appel professionnel','déplacement interne','autre')",
];
