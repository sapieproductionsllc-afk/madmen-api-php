<?php
declare(strict_types=1);

/**
 * Synchronisation CLI des pointages depuis la pointeuse K40.
 * Usage : php database/k40_sync.php
 * À planifier (Planificateur de tâches Windows) toutes les X minutes.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MadMen\Controllers\K40Controller;

try {
    $resume = (new K40Controller())->runSync();
    echo "Synchro K40 OK\n";
    echo '  reçus    : ' . $resume['recus'] . "\n";
    echo '  traités  : ' . $resume['traites'] . "\n";
    echo '  ignorés  : ' . $resume['ignores'] . "\n";
    if (!empty($resume['employes_inconnus'])) {
        echo '  inconnus : ' . implode(', ', $resume['employes_inconnus']) . "\n";
    }
    echo '  dernière : ' . $resume['derniere_synchro'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur K40 : ' . $e->getMessage() . "\n");
    exit(1);
}
