<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Crypto;
use MadMen\Core\Database;
use MadMen\Core\K40;
use MadMen\Core\K40Pointage;
use MadMen\Core\K40Template;
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

    /**
     * POST /api/k40/push-fingerprints — pousse TOUS les gabarits actifs au K40
     * en UNE session (déchiffrement PHP -> pont Python). Calqué sur pushAll.
     */
    public function pushFingerprints(): void
    {
        @set_time_limit(0);

        $db = Database::connection();
        $rows = $db->query(
            "SELECT b.employe_id, b.doigt, b.template,
                    e.nom, e.prenom, e.device_user_id
             FROM employe_biometrie b
             JOIN employe e ON e.id = b.employe_id
             WHERE b.type = 'empreinte' AND b.actif = 1 AND e.statut = 'actif'
             ORDER BY b.employe_id, b.id"
        )->fetchAll();

        $users = [];
        $skipped = 0;
        foreach ($rows as $r) {
            $eid = (int) $r['employe_id'];
            $blob = is_resource($r['template']) ? stream_get_contents($r['template']) : (string) $r['template'];
            $raw = Crypto::decrypt($blob);
            if (strlen($raw) < 100) { // decrypt échoué ou seed bidon
                $skipped++;
                continue;
            }
            if (!isset($users[$eid])) {
                $users[$eid] = [
                    'uid'     => $eid,
                    'user_id' => (string) ($r['device_user_id'] ?: $eid),
                    'name'    => mb_substr($r['prenom'] . ' ' . $r['nom'], 0, 24),
                    'fingers' => [],
                ];
            }
            $users[$eid]['fingers'][] = [
                'fid'          => K40Template::fid($r['doigt']),
                'template_b64' => base64_encode($raw),
            ];
        }

        if (!$users) {
            Response::json(['message' => 'Aucune empreinte exploitable', 'skipped' => $skipped, 'synced' => 0]);
        }

        try {
            $res = K40Template::push(array_values($users));
        } catch (Throwable $e) {
            Response::error($this->messagePublic('Push des empreintes K40 échoué', $e), 502);
        }

        Response::json([
            'message' => 'Empreintes poussées au K40',
            'users'   => count($users),
            'synced'  => $res['synced'] ?? 0,
            'failed'  => $res['failed'] ?? 0,
            'skipped' => $skipped,
            'results' => $res['results'] ?? [],
        ]);
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

        // try/finally : quoi qu'il arrive (même si getAttendance lève), on RÉ-ACTIVE
        // le terminal et on se déconnecte. Sinon une lecture qui échoue laisserait le
        // K40 « désactivé » et plus PERSONNE ne pourrait pointer à l'entrée.
        $zk = K40::connect();
        try {
            @$zk->disableDevice();
            $logs = $zk->getAttendance();
        } finally {
            @$zk->enableDevice();
            @$zk->disconnect();
        }

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
        // Dès qu'un punch NON MAPPÉ est rencontré, on n'avance plus le curseur
        // au-delà : il sera relu à la prochaine synchro puis enregistré une fois
        // l'employé mappé (plus de perte définitive). La déduplication par
        // client_uuid rend ce ré-traitement totalement sûr (aucun doublon créé).
        $bloque = false;

        foreach ($logs as $log) {
            $recus++;
            $ts = (string) ($log['timestamp'] ?? '');
            $devId = (string) ($log['id'] ?? '');
            // '<' (et non '<=') : un punch distinct à la même seconde que le curseur
            // n'est plus perdu ; un éventuel re-traitement est neutralisé par la dédup.
            if ($ts === '' || $ts < $lastSync) {
                continue;
            }

            if (K40Pointage::record($db, $devId, $ts, $cfg['heure_limite']) === 'traite') {
                $traites++;
                if (!$bloque && $ts > $maxTs) {
                    $maxTs = $ts;
                }
            } else {
                $ignores++;
                $inconnus[$devId] = true;
                $bloque = true; // ne pas dépasser ce punch non mappé
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
