<?php
return [
    // #7.1 : aligne les types de demande sur le front -> ajoute 'permission' et 'absence'.
    // Additif : valeurs d'enum AJOUTEES, aucune retiree (aucune ligne existante invalidee).
    'up' => "ALTER TABLE demande
        MODIFY type ENUM('avance','conge','permission','absence','formation','attestation','autre') NOT NULL",
    'down' => "ALTER TABLE demande
        MODIFY type ENUM('avance','conge','formation','attestation','autre') NOT NULL",
];
