<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\K40;
use MadMen\Core\K40Pointage;
use MadMen\Core\Response;
use Throwable;

/**
 * Pointeuse ZKTeco K40 : statut, synchronisation des pointages (arrivée/départ
 * par empreinte), gestion des utilisateurs du terminal.
 */
final class K40Controller
{
    /** GET /api/k40/status — teste la connexion au terminal. */
    public function status(): void
    {
        $cfg = K40::config();
        try {
            $zk = K40::connect();
            $version = @$zk->version();
            @$zk->disconnect();
            Response::json([
                'connected' => true,
                'ip'        => $cfg['ip'],
                'port'      => $cfg['port'],
                'version'   => $version,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'connected' => false,
                'ip'        => $cfg['ip'],
                'port'      => $cfg['port'],
                'enabled'   => $cfg['enabled'],
                'error'     => $this->messagePublic('Connexion au terminal impossible', $e),
            ]);
        }
    }

    /** POST /api/k40/sync — récupère les pointages du K40 et les enregistre. */
    public function sync(): void
    {
        try {
            $resume = $this->runSync();
            Response::json($resume);
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Synchronisation K40 échouée', $e), 502);
        }
    }

    /** GET /api/k40/users — liste les utilisateurs enregistrés sur le terminal. */
    public function users(): void
    {
        @set_time_limit(0); // lecture des users lente sur le terminal
        try {
            $zk = K40::connect();
            $users = $zk->getUser();
            @$zk->disconnect();
            Response::json($users);
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Lecture des utilisateurs K40 échouée', $e), 502);
        }
    }

    /** POST /api/k40/push-user/{id} — pousse un employé vers le terminal. */
    public function pushUser(array $params): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, matricule, device_user_id, nom, prenom FROM employe WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $emp = $stmt->fetch();
        if (!$emp) {
            Response::error('Employé introuvable', 404);
        }

        $deviceUserId = $emp['device_user_id'] ?: (string) $emp['id'];
        $name = mb_substr($emp['prenom'] . ' ' . $emp['nom'], 0, 24);

        try {
            $zk = K40::connect();
            // setUser($uid, $userid, $name, $password, role, cardno)
            $zk->setUser((int) $emp['id'], $deviceUserId, $name, '');
            @$zk->disconnect();
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Envoi vers le K40 échoué', $e), 502);
        }

        // Mémorise le mapping si absent.
        if (!$emp['device_user_id']) {
            $db->prepare('UPDATE employe SET device_user_id = ? WHERE id = ?')
               ->execute([$deviceUserId, (int) $emp['id']]);
        }

        Response::json(['message' => 'Employé envoyé au K40', 'device_user_id' => $deviceUserId]);
    }

    /** DELETE /api/k40/users/{id} — retire un employé du terminal (uid = employe.id). */
    public function removeUser(array $params): void
    {
        try {
            $zk = K40::connect();
            $zk->removeUser((int) $params['id']);
            @$zk->disconnect();
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Suppression sur le K40 échouée', $e), 502);
        }

        Response::json(['message' => 'Employé retiré du K40', 'uid' => (int) $params['id']]);
    }

    /** POST /api/k40/clear-users — efface TOUS les utilisateurs du terminal. */
    public function clearUsers(): void
    {
        try {
            $zk = K40::connect();
            $zk->clearUsers();
            @$zk->disconnect();
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Effacement des utilisateurs K40 échoué', $e), 502);
        }

        Response::json(['message' => 'Tous les utilisateurs ont été retirés du K40']);
    }

    /**
     * POST /api/k40/push-all — pousse TOUS les employés actifs en UNE connexion.
     * Bien plus fiable que 17 connect/disconnect en rafale (le terminal ne gère
     * qu'une session UDP à la fois).
     */
    public function pushAll(): void
    {
        // Chaque setUser est lent (~4 s) sur le K40 ; un batch dépasse les 30 s
        // par défaut de PHP. On lève la limite le temps de l'opération.
        @set_time_limit(0);

        $db = Database::connection();
        $emps = $db->query(
            "SELECT id, device_user_id, nom, prenom FROM employe WHERE statut = 'actif' ORDER BY id"
        )->fetchAll();

        $pushed = 0;
        try {
            $zk = K40::connect();
            foreach ($emps as $emp) {
                $deviceUserId = $emp['device_user_id'] ?: (string) $emp['id'];
                $name = mb_substr($emp['prenom'] . ' ' . $emp['nom'], 0, 24);
                $zk->setUser((int) $emp['id'], $deviceUserId, $name, '');
                if (!$emp['device_user_id']) {
                    $db->prepare('UPDATE employe SET device_user_id = ? WHERE id = ?')
                       ->execute([$deviceUserId, (int) $emp['id']]);
                }
                $pushed++;
            }
            @$zk->disconnect();
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Push global K40 échoué', $e), 502);
        }

        Response::json(['message' => 'Employés poussés au K40', 'count' => $pushed, 'total' => count($emps)]);
    }

    // ----------------------------------------------------------------- sécurité

    /**
     * Message d'erreur public : générique en production, détaillé seulement si
     * APP_DEBUG est actif (le détail fuit l'IP/la structure interne sinon).
     */
    private function messagePublic(string $generique, Throwable $e): string
    {
        return $this->appDebug() ? $generique . ' : ' . $e->getMessage() : $generique;
    }

    /** Lit APP_DEBUG depuis le .env (lecture seule). Défaut : false. */
    private function appDebug(): bool
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!is_file($envFile)) {
            return false;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, 'APP_DEBUG') !== 0) {
                continue;
            }
            [, $value] = array_pad(explode('=', $line, 2), 2, '');
            $value = strtolower(trim($value));

            return in_array($value, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    // ------------------------------------------------------------------ logique

    /**
     * Cœur de la synchro (réutilisable en CLI). Renvoie un résumé.
     * @return array<string,mixed>
     */
    public function runSync(): array
    {
        @set_time_limit(0); // getAttendance peut être long sur le terminal

        $cfg = K40::config();
        $db = Database::connection();

        $zk = K40::connect();
        @$zk->disableDevice();
        $logs = $zk->getAttendance();
        @$zk->enableDevice();
        @$zk->disconnect();

        if (!is_array($logs)) {
            $logs = [];
        }

        // Tri chronologique.
        usort($logs, static fn ($a, $b) => strcmp((string) $a['timestamp'], (string) $b['timestamp']));

        // Dernière synchro.
        $lastSync = $db->query('SELECT last_sync_at FROM k40_state WHERE id = 1')->fetchColumn();
        $lastSync = $lastSync ?: '0000-00-00 00:00:00';

        $recus = 0;
        $traites = 0;
        $ignores = 0;
        $inconnus = [];
        $maxTs = $lastSync;

        foreach ($logs as $log) {
            $recus++;
            $ts = (string) ($log['timestamp'] ?? '');
            $devId = (string) ($log['id'] ?? '');
            if ($ts === '' || $ts <= $lastSync) {
                continue; // déjà traité
            }
            if ($ts > $maxTs) {
                $maxTs = $ts;
            }

            if (K40Pointage::record($db, $devId, $ts, $cfg['heure_limite']) === 'traite') {
                $traites++;
            } else {
                $ignores++;
                $inconnus[$devId] = true;
            }
        }

        // Met à jour l'état.
        $db->prepare(
            'INSERT INTO k40_state (id, last_sync_at) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE last_sync_at = VALUES(last_sync_at)'
        )->execute([$maxTs]);

        return [
            'recus'              => $recus,
            'traites'            => $traites,
            'ignores'            => $ignores,
            'employes_inconnus'  => array_keys($inconnus),
            'derniere_synchro'   => $maxTs,
        ];
    }

}
