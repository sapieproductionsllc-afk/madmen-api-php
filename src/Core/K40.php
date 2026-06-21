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

        // La lib rats/zkteco appelle utf8_encode()/utf8_decode() (dépréciés en
        // PHP 8.2+). On masque E_DEPRECATED pour que l'avertissement ne pollue pas
        // les réponses JSON de l'API (sinon du HTML d'erreur précède le JSON).
        error_reporting(error_reporting() & ~E_DEPRECATED);

        if (!$cfg['enabled']) {
            throw new RuntimeException('K40 désactivé (mettre K40_ENABLED=true dans .env).');
        }

        // TODO(m3) : la lib rats/zkteco ne gère PAS la clé de communication du
        // terminal. Son constructeur est ZKTeco($ip, $port) et connect() envoie
        // un CMD_CONNECT sans payload d'authentification. Tant que K40_PASSWORD
        // vaut 0 (aucune clé), la connexion fonctionne. Si une clé de comm est
        // configurée sur le terminal (config['password'] != 0), il faudra une
        // lib qui implémente CMD_AUTH, ou retirer la clé côté terminal.
        $zk = new ZKTeco($cfg['ip'], $cfg['port']);
        if (!$zk->connect()) {
            throw new RuntimeException("K40 injoignable à {$cfg['ip']}:{$cfg['port']}.");
        }

        return $zk;
    }
}
