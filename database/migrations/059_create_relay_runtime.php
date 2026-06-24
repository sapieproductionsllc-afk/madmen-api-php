<?php
return [
    // Coordination du "reporter distribué" : chaque PC du bureau (app MADMEN User)
    // peut relayer les pointages du K40 vers le cloud, mais UN SEUL de garde à la fois.
    // Ligne singleton (id=1), créée à la volée par le 1er claim :
    //  - holder / lease_until : bail atomique (qui est de garde, jusqu'à quand).
    //  - last_relay_at        : dernier check-in d'un reporter -> détection "bureau silencieux".
    'up' => "CREATE TABLE IF NOT EXISTS relay_runtime (
        id TINYINT UNSIGNED NOT NULL,
        holder VARCHAR(64) NULL,
        lease_until DATETIME NULL,
        last_relay_at DATETIME NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'down' => "DROP TABLE IF EXISTS relay_runtime",
];
