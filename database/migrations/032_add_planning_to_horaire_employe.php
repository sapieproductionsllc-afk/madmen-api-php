<?php
return [
    // Emploi du temps PAR JOUR (optionnel). JSON : { "1":{"debut":"08:00","fin":"18:00"},
    // ..., "6":{"debut":"08:00","fin":"12:00"} } où la clé = jour ISO (1=lundi..7=dimanche).
    // Un jour absent du planning = repos. Si planning est NULL, on retombe sur l'horaire
    // unique (heure_arrivee/heure_depart + jours_travailles) — rétro-compatible.
    'up' => "ALTER TABLE horaire_employe ADD COLUMN planning JSON NULL AFTER jours_travailles",
    'down' => "ALTER TABLE horaire_employe DROP COLUMN planning",
];
