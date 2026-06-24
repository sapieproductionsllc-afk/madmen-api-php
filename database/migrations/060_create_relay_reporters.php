<?php
return [
    // Flotte des reporters : un PC du bureau (app MADMEN User) par ligne. Mis à jour à
    // chaque "claim" (tous les PC claim à chaque tour, qu'ils obtiennent la garde ou non)
    // -> permet de lister TOUS les PC qui relaient et de repérer ceux qui se taisent.
    'up' => "CREATE TABLE IF NOT EXISTS relay_reporters (
        hostname VARCHAR(64) NOT NULL,
        last_seen DATETIME NOT NULL,
        PRIMARY KEY (hostname),
        KEY idx_last_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'down' => "DROP TABLE IF EXISTS relay_reporters",
];
