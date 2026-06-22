<?php
return [
    // Canal de DIFFUSION "Tout le personnel" (#8) : 3e type de conversation. Additif
    // (valeur d'enum ajoutee, aucune valeur existante retiree). Le kiosque n'utilise
    // PAS la messagerie (login/lock/activite/sync uniquement) -> aucun impact.
    'up' => "ALTER TABLE conversation
        MODIFY type ENUM('direct','groupe','annonce') NOT NULL DEFAULT 'direct'",
    'down' => "ALTER TABLE conversation
        MODIFY type ENUM('direct','groupe') NOT NULL DEFAULT 'direct'",
];
