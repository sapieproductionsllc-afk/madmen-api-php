<?php
declare(strict_types=1);

namespace MadMen\Core;

use Rats\Zkteco\Lib\ZKTeco;
use RuntimeException;

/**
 * Accès à la pointeuse ZKTeco K40 (terminal de pointage réseau, port 4370).
 */
final class K40
{
    public static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/k40.php';
    }

    /**
     * Ouvre une connexion au K40. Lève une exception si désactivé ou injoignable.
     */
    public static function connect(): ZKTeco
    {
        $cfg = self::config();

        if (!$cfg['enabled']) {
            throw new RuntimeException('K40 désactivé (mettre K40_ENABLED=true dans .env).');
        }

        $zk = new ZKTeco($cfg['ip'], $cfg['port']);
        if (!$zk->connect()) {
            throw new RuntimeException("K40 injoignable à {$cfg['ip']}:{$cfg['port']}.");
        }

        return $zk;
    }
}
