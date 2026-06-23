<?php
declare(strict_types=1);

namespace MadMen\Core;

use RuntimeException;

/**
 * Pont PHP -> Python (pyzk) pour l'écriture/suppression des gabarits d'empreinte
 * sur le K40. La lib rats/zkteco ne sait pas écrire les empreintes.
 *
 * Sécurité : proc_open avec un TABLEAU d'arguments (aucun shell, aucune injection),
 * gabarit transmis en base64 sur STDIN (jamais en argv ni fichier temp), ip/port
 * pris EXCLUSIVEMENT de la config serveur. Timeout dur + proc_terminate.
 */
final class K40Template
{
    /** Mapping doigt (chaîne FR) -> fid ZK (0-9). */
    private const FINGER_MAP = [
        'pouce_gauche'       => 0,
        'index_gauche'       => 1,
        'majeur_gauche'      => 2,
        'annulaire_gauche'   => 3,
        'auriculaire_gauche' => 4,
        'pouce_droit'        => 5,
        'index_droit'        => 6,
        'majeur_droit'       => 7,
        'annulaire_droit'    => 8,
        'auriculaire_droit'  => 9,
    ];

    private const FALLBACK_FID = 6; // index droit, si doigt inconnu/NULL

    /** Traduit un libellé de doigt en fid ZK (0-9). */
    public static function fid(?string $doigt): int
    {
        if ($doigt === null) {
            return self::FALLBACK_FID;
        }
        $key = strtolower(trim($doigt));

        return self::FINGER_MAP[$key] ?? self::FALLBACK_FID;
    }

    /**
     * Pousse des gabarits sur le K40.
     * @param array<int,array{uid:int,user_id:string,name:string,fingers:array<int,array{fid:int,template_b64:string}>}> $users
     * @return array<string,mixed> Résultat décodé du script Python.
     */
    public static function push(array $users): array
    {
        return self::run('push', $users);
    }

    /**
     * Retire des gabarits (fingers précis) ou l'utilisateur entier (fingers vide).
     * @param array<int,array{uid:int,user_id:string,fingers:array<int,array{fid:int}>}> $users
     * @return array<string,mixed>
     */
    public static function remove(array $users): array
    {
        return self::run('remove', $users);
    }

    /**
     * Lit les pointages (attendance) du K40 via pyzk — la lib PHP rats/zkteco étant
     * instable sur getAttendance. @return array{ok:bool,attendance:array<int,array>}
     */
    public static function attendance(): array
    {
        return self::run('attendance', []);
    }

    /**
     * @param array<int,mixed> $users
     * @return array<string,mixed>
     */
    private static function run(string $action, array $users): array
    {
        $cfg = K40::config();
        if (empty($cfg['enabled'])) {
            throw new RuntimeException('K40 désactivé (K40_ENABLED).');
        }

        // LOCAL-ONLY : l'écriture de gabarit exige proc_open (pont Python pyzk),
        // souvent désactivé en hébergement mutualisé. Cette opération n'a de sens
        // que sur la passerelle locale (PC admin sur le LAN du K40).
        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                "Provisioning d'empreintes indisponible sur cet hôte (proc_open désactivé) : "
                . 'opération réservée à la passerelle locale K40.'
            );
        }

        $ip = (string) ($cfg['ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('IP K40 invalide dans la config.');
        }

        $python = (string) $cfg['python_bin'];
        $script = (string) $cfg['push_script'];
        if (!is_file($script)) {
            throw new RuntimeException("Script pont introuvable : {$script}");
        }

        $payload = json_encode([
            'ip'       => $ip,
            'port'     => (int) ($cfg['port'] ?? 4370),
            'password' => (int) ($cfg['password'] ?? 0),
            'timeout'  => 15,
            'action'   => $action,
            'users'    => array_values($users),
        ], JSON_UNESCAPED_UNICODE);

        $descriptors = [
            0 => ['pipe', 'r'], // STDIN  (JSON + gabarit base64)
            1 => ['pipe', 'w'], // STDOUT (JSON résultat)
            2 => ['pipe', 'w'], // STDERR (logs)
        ];

        // TABLEAU d'arguments -> pas de shell, pas d'injection, pas de quoting.
        $proc = proc_open([$python, $script], $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Impossible de lancer le pont Python.');
        }

        fwrite($pipes[0], (string) $payload);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + (int) ($cfg['python_timeout'] ?? 60);
        $stdout = '';
        $stderr = '';

        do {
            $status = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException('Pont Python : timeout dépassé.');
            }
            usleep(50000); // 50 ms
        } while (true);

        // Drainer le reste.
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($stderr !== '') {
            // Ne JAMAIS logger le gabarit : seul le stderr du script (pas de PII).
            error_log('K40Template stderr: ' . trim($stderr));
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Pont Python : sortie illisible (exit ' . $exit . ').'
            );
        }

        return $decoded;
    }
}
