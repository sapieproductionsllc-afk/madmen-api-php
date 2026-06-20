<?php
return [
    // Rôle hiérarchique pour le contrôle d'accès (RBAC).
    // super_admin > directeur > superviseur > employe (défaut).
    'up' => "ALTER TABLE employe
        ADD COLUMN role ENUM('super_admin','directeur','superviseur','employe')
        NOT NULL DEFAULT 'employe' AFTER statut",
    'down' => "ALTER TABLE employe DROP COLUMN role",
];
