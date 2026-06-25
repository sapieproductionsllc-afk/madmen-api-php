<?php
return [
    // Statut 'archive' : employé sorti des effectifs (données conservées), distinct de
    // 'suspendu' (désactivation temporaire réversible). Le burger de la fiche propose
    // Archiver ; l'index employés masque les archivés par défaut.
    'up' => "ALTER TABLE employe
        MODIFY statut ENUM('actif','suspendu','conge','archive') NOT NULL DEFAULT 'actif'",
    'down' => "ALTER TABLE employe
        MODIFY statut ENUM('actif','suspendu','conge') NOT NULL DEFAULT 'actif'",
];
