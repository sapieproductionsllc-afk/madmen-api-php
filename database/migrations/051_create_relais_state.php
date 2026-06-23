<?php
return [
    // Curseur du relais cloud (bureau -> cloud). Modèle de k40_state : une seule ligne (id=1).
    // last_push_at = horodatage du dernier pointage déjà remonté au cloud.
    'up' => "CREATE TABLE IF NOT EXISTS relais_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        last_push_at DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'down' => "DROP TABLE IF EXISTS relais_state",
];
