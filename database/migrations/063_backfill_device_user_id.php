<?php
return [
    // Mapping K40 manquant pour les employés créés CÔTÉ CLOUD (jamais poussés par la
    // passerelle locale) : sans device_user_id, leur pointage revient « inconnu »
    // (K40Pointage::resolveEmploye est STRICT — aucun repli sur employe.id) -> l'employé
    // est ABSENT malgré son doigt sur le K40. Le pont empreintes pousse leur gabarit au
    // slot K40 = employe.id, donc device_user_id = id ferme la boucle de RETOUR.
    // Backfill idempotent (re-jouable : ne touche que les NULL/'').
    'up' => "UPDATE employe SET device_user_id = id
             WHERE device_user_id IS NULL OR device_user_id = ''",
    // Backfill de données : pas de rollback réel (on ne sait plus lesquels étaient vides).
    'down' => "SELECT 1",
];
