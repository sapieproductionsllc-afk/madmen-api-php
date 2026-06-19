<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Response;

final class ConfigController
{
    /** Config biométrie destinée au front (valeurs non sensibles uniquement). */
    public function biometrie(): void
    {
        $cfg = require dirname(__DIR__, 2) . '/config/biometrie.php';

        Response::json([
            'device'          => $cfg['device'],
            'bridge_url'      => $cfg['bridge_url'],
            'samples'         => $cfg['samples'],
            'threshold'       => $cfg['threshold'],
            'template_format' => $cfg['template_format'],
            'simulation'      => $cfg['simulation'],
        ]);
    }
}
