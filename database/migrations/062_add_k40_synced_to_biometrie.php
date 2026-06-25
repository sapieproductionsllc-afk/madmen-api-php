<?php
return [
    // Suivi de la synchro des empreintes vers le K40 (pont descendant cloud -> K40).
    // NULL = en attente de poussée sur le device ; le reporter de garde la pousse puis
    // horodate ici. Défaut NULL => au 1er passage, le reporter (re)pousse TOUS les
    // gabarits actifs sur le K40 (idempotent, auto-réparation), puis ne re-pousse que
    // les nouveaux enrôlements.
    'up' => "ALTER TABLE employe_biometrie
        ADD COLUMN k40_synced_at TIMESTAMP NULL DEFAULT NULL AFTER actif",
    'down' => "ALTER TABLE employe_biometrie
        DROP COLUMN k40_synced_at",
];
