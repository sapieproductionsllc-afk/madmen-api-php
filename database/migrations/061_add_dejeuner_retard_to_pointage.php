<?php
return [
    // Deux compteurs quotidiens supplémentaires du résumé pointage :
    //  - retard_dejeuner_minutes : retour de pause déjeuner APRÈS l'heure fixe de fin.
    //  - temps_manquant_minutes  : (durée prévue − déjeuner) − temps réellement travaillé.
    'up' => "ALTER TABLE pointage
        ADD COLUMN retard_dejeuner_minutes INT NOT NULL DEFAULT 0 AFTER retard_minutes,
        ADD COLUMN temps_manquant_minutes INT NOT NULL DEFAULT 0 AFTER retard_dejeuner_minutes",
    'down' => "ALTER TABLE pointage
        DROP COLUMN temps_manquant_minutes,
        DROP COLUMN retard_dejeuner_minutes",
];
