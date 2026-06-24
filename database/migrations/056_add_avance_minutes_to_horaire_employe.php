<?php
return [
    // Pendant « en avance » de tolerance_minutes (qui borne le RETARD toléré).
    // avance_minutes = combien de temps AVANT l'heure d'arrivée prévue un employé
    // peut pointer son ENTRÉE pour que ça compte (fenêtre [debut - avance, fin)).
    // Utilisé UNIQUEMENT pour filtrer les pointages K40 (le pointage manuel admin
    // n'est jamais filtré). Défaut 30 min ; bornes applicatives 0–240.
    'up' => "ALTER TABLE horaire_employe
        ADD COLUMN avance_minutes INT NOT NULL DEFAULT 30 AFTER tolerance_minutes",
    'down' => "ALTER TABLE horaire_employe DROP COLUMN avance_minutes",
];
