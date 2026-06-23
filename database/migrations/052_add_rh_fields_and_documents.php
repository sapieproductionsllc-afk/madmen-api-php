<?php
return [
    // 1) Champs RH additionnels sur l'employé (tous NULLABLE, sans impact kiosque/sync).
    //    Affichés/édités depuis la fiche profil du dashboard (ProfilDetails.jsx).
    // 2) Complète la table document_rh existante (cf. 044) pour le CRUD documents :
    //    description (TEXT), ajoute_par (FK employe), chemin (fichier stocké hors /public),
    //    mime (type réel détecté). La colonne `url` existante reste l'URL d'accès exposée.
    'up' => "
        ALTER TABLE employe
            ADD COLUMN sexe          VARCHAR(16) NULL AFTER prenom,
            ADD COLUMN date_naissance DATE       NULL AFTER sexe,
            ADD COLUMN nationalite   VARCHAR(80) NULL AFTER date_naissance,
            ADD COLUMN etat_civil    VARCHAR(24) NULL AFTER nationalite,
            ADD COLUMN date_embauche DATE        NULL AFTER etat_civil,
            ADD COLUMN type_contrat  VARCHAR(32) NULL AFTER date_embauche,
            ADD COLUMN notes_admin   TEXT        NULL AFTER type_contrat;

        ALTER TABLE document_rh
            ADD COLUMN description TEXT           NULL AFTER type,
            ADD COLUMN chemin      VARCHAR(255)   NULL AFTER url,
            ADD COLUMN mime        VARCHAR(120)   NULL AFTER chemin,
            ADD COLUMN ajoute_par  BIGINT UNSIGNED NULL AFTER taille_octets,
            ADD KEY idx_document_rh_ajoute_par (ajoute_par),
            ADD CONSTRAINT fk_document_rh_ajoute_par FOREIGN KEY (ajoute_par)
                REFERENCES employe(id) ON DELETE SET NULL;
    ",
    'down' => "
        ALTER TABLE document_rh
            DROP FOREIGN KEY fk_document_rh_ajoute_par,
            DROP COLUMN ajoute_par,
            DROP COLUMN mime,
            DROP COLUMN chemin,
            DROP COLUMN description;

        ALTER TABLE employe
            DROP COLUMN notes_admin,
            DROP COLUMN type_contrat,
            DROP COLUMN date_embauche,
            DROP COLUMN etat_civil,
            DROP COLUMN nationalite,
            DROP COLUMN date_naissance,
            DROP COLUMN sexe;
    ",
];
