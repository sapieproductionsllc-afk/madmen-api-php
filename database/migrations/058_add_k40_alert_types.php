<?php
return [
    // Types d'alerte K40 (early-warning anti-perte) :
    //  - k40_saturation : buffer du terminal proche du plein -> risque de débordement
    //                     (FIFO écrase les plus vieux) si on ne synchronise/vide pas.
    //  - k40_horloge    : pointage daté dans le futur -> horloge du K40 déréglée.
    'up' => "ALTER TABLE alerte MODIFY type
        ENUM('inactivite','retard','absence','deconnexion','connexion_refusee','k40_saturation','k40_horloge') NOT NULL",
    'down' => "ALTER TABLE alerte MODIFY type
        ENUM('inactivite','retard','absence','deconnexion','connexion_refusee') NOT NULL",
];
