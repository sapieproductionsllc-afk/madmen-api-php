<?php
return [
    // BUGFIX : à une SORTIE, K40Pointage écrit statut='parti', mais l'ENUM ne le
    // contenait pas -> « Data truncated for column statut » -> la sortie plantait
    // (la personne n'était jamais marquée « partie »). On ajoute 'parti' à l'ENUM.
    'up' => "ALTER TABLE pointage
        MODIFY statut ENUM('present','absent','retard','conge','parti') NULL",
    'down' => "ALTER TABLE pointage
        MODIFY statut ENUM('present','absent','retard','conge') NULL",
];
