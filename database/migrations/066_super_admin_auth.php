<?php
// Projet A — Authentification SUPER-ADMIN par identifiant + mot de passe.
//  - username / mot_de_passe_hash / doit_changer_mdp ajoutés à `employe`
//    (utilisés UNIQUEMENT par les super-admins ; les autres gardent le PIN).
//  - Amorçage idempotent : garantit un super-admin « Admin / 0000 » avec
//    changement de mot de passe forcé à la 1re connexion.
//      * backfill du super-admin existant (prod : compte ADMIN) s'il n'a pas d'identifiant ;
//      * sinon (DB vierge / réinstallation) insertion du compte Admin.
$hash = password_hash('0000', PASSWORD_BCRYPT); // hash bcrypt frais à chaque migrate

return [
    'up' => "
        ALTER TABLE employe
            ADD COLUMN username VARCHAR(60) NULL AFTER matricule,
            ADD COLUMN mot_de_passe_hash VARCHAR(255) NULL AFTER code_pin_hash,
            ADD COLUMN doit_changer_mdp TINYINT(1) NOT NULL DEFAULT 0,
            ADD UNIQUE KEY uq_employe_username (username);

        UPDATE employe
           SET username = 'Admin', mot_de_passe_hash = '$hash', doit_changer_mdp = 1
         WHERE role = 'super_admin' AND (username IS NULL OR username = '')
         ORDER BY id
         LIMIT 1;

        INSERT INTO employe
              (matricule, username, nom, prenom, code_pin_hash, role, statut, mot_de_passe_hash, doit_changer_mdp)
        SELECT 'ADMIN', 'Admin', 'Admin', 'Système', '', 'super_admin', 'actif', '$hash', 1
          FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM employe WHERE role = 'super_admin' LIMIT 1) AS sa);
    ",
    'down' => "
        ALTER TABLE employe
            DROP KEY uq_employe_username,
            DROP COLUMN username,
            DROP COLUMN mot_de_passe_hash,
            DROP COLUMN doit_changer_mdp;
    ",
];
