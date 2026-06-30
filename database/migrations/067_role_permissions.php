<?php
// Projet B — Permissions configurables PAR RÔLE (chefs d'équipe).
// niveau par domaine : none (caché) < voir (lecture) < gerer (lecture + écriture).
// super_admin = toujours tout (jamais stocké). employe = aucun accès dashboard.
// Seed idempotent : ON DUPLICATE KEY UPDATE niveau=niveau -> ne réécrit jamais une
// valeur déjà définie par le super-admin.
return [
    'up' => "
        CREATE TABLE IF NOT EXISTS role_permission (
            role   VARCHAR(20) NOT NULL,
            area   VARCHAR(20) NOT NULL,
            niveau ENUM('none','voir','gerer') NOT NULL DEFAULT 'none',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role, area)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        INSERT INTO role_permission (role, area, niveau) VALUES
            ('directeur','presence','voir'),('directeur','employes','voir'),('directeur','pointages','gerer'),
            ('directeur','paie','voir'),('directeur','rapports','voir'),('directeur','demandes','gerer'),
            ('directeur','communication','gerer'),('directeur','administration','none'),
            ('superviseur','presence','voir'),('superviseur','employes','voir'),('superviseur','pointages','gerer'),
            ('superviseur','paie','none'),('superviseur','rapports','voir'),('superviseur','demandes','gerer'),
            ('superviseur','communication','voir'),('superviseur','administration','none')
        ON DUPLICATE KEY UPDATE niveau = niveau;
    ",
    'down' => "DROP TABLE IF EXISTS role_permission",
];
