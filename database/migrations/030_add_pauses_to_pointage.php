<?php
return [
    // Résumé quotidien recalculé depuis les passages : temps réellement présent
    // (hors pauses + hors déjeuner), temps total de pause, nombre de pauses.
    'up' => "ALTER TABLE pointage
        ADD COLUMN temps_present_minutes INT NOT NULL DEFAULT 0 AFTER retard_minutes,
        ADD COLUMN temps_pause_minutes INT NOT NULL DEFAULT 0 AFTER temps_present_minutes,
        ADD COLUMN nb_pauses INT NOT NULL DEFAULT 0 AFTER temps_pause_minutes",
    'down' => "ALTER TABLE pointage
        DROP COLUMN temps_present_minutes,
        DROP COLUMN temps_pause_minutes,
        DROP COLUMN nb_pauses",
];
