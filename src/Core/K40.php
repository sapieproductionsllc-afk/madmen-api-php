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
        // Pré-check de joignabilité RAPIDE (≈1,5 s) AVANT la lib rats/zkteco : celle-ci
        // n'a AUCUN timeout de réception et HANG ~30 s si le K40 est injoignable, ce qui
        // bloque TOUT le serveur PHP mono-thread (statut, sync, et même les autres apps).
        if (!self::joignable((string) $cfg['ip'], (int) $cfg['port'])) {
            throw new RuntimeException("K40 injoignable à {$cfg['ip']}:{$cfg['port']}.");
        }

        $zk = new ZKTeco($cfg['ip'], $cfg['port']);
        if (!$zk->connect()) {
            throw new RuntimeException("K40 injoignable à {$cfg['ip']}:{$cfg['port']}.");
        }

        return $zk;
    }

    /**
     * Sonde UDP RAPIDE (1,5 s) : envoie le CMD_CONNECT ZKTeco et attend une réponse.
     * Évite le hang ~30 s de rats/zkteco (socket sans timeout de réception) quand le
     * terminal est absent. true = réponse reçue ; false = injoignable (échec rapide).
     */
    private static function joignable(string $ip, int $port): bool
    {
        if (!function_exists('socket_create')) {
            return true; // extension sockets absente -> on laisse la lib tenter
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return true;
        }
        @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 500000]);
        $buf = \Rats\Zkteco\Lib\Helper\Util::createHeader(
            \Rats\Zkteco\Lib\Helper\Util::CMD_CONNECT,
            0,
            0,
            -1 + \Rats\Zkteco\Lib\Helper\Util::USHRT_MAX,
            ''
        );
        @socket_sendto($sock, $buf, strlen($buf), 0, $ip, $port);
        $recv = '';
        $from = '';
        $fromPort = 0;
        $n = @socket_recvfrom($sock, $recv, 1024, 0, $from, $fromPort);
        @socket_close($sock);

        return $n !== false && strlen((string) $recv) > 0;
    }
}
