<?php
return [
    // Index composite servant TOUS les JOIN chauds du dashboard
    // (ON pt.employe_id = e.id AND pt.date = CURDATE()) + les scans de plage paie/feuille.
    // Non-unique (sûr même s'il existe d'anciens doublons employe+date).
    'up' => "ALTER TABLE pointage ADD KEY idx_pointage_employe_date (employe_id, date)",
    'down' => "ALTER TABLE pointage DROP KEY idx_pointage_employe_date",
];
