<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\K40;
use MadMen\Core\Response;
use PDO;
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

        $appareilId = $this->appareilK40($db);

        // Cache des employés par device_user_id (ou id).
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

            $employe = $this->resolveEmploye($db, $devId);
            if ($employe === null) {
                $ignores++;
                $inconnus[$devId] = true;
                continue;
            }

            $this->enregistrerPointage($db, (int) $employe, $appareilId, $ts, $cfg['heure_limite']);
            $traites++;
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

    private function resolveEmploye(PDO $db, string $deviceUserId): ?int
    {
        if ($deviceUserId === '') {
            return null;
        }
        // 1) par device_user_id explicite
        $stmt = $db->prepare('SELECT id FROM employe WHERE device_user_id = ?');
        $stmt->execute([$deviceUserId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        // 2) fallback : device_user_id = id de l'employé
        if (ctype_digit($deviceUserId)) {
            $stmt = $db->prepare('SELECT id FROM employe WHERE id = ?');
            $stmt->execute([(int) $deviceUserId]);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int) $id;
            }
        }
        return null;
    }

    /** Enregistre un punch : 1er du jour = arrivée, dernier = départ. */
    private function enregistrerPointage(PDO $db, int $employeId, int $appareilId, string $ts, string $heureLimite): void
    {
        $date = substr($ts, 0, 10);

        $stmt = $db->prepare(
            'SELECT id, heure_entree FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?'
        );
        $stmt->execute([$employeId, $date, $appareilId]);
        $pointage = $stmt->fetch();

        if (!$pointage) {
            $limite = $date . ' ' . $heureLimite . ':00';
            $retard = max(0, (int) round((strtotime($ts) - strtotime($limite)) / 60));
            $statut = $retard > 0 ? 'retard' : 'present';

            $db->prepare(
                'INSERT INTO pointage (employe_id, appareil_id, date, heure_entree, methode, retard_minutes, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$employeId, $appareilId, $date, $ts, 'empreinte', $retard, $statut]);
        } else {
            // Punch suivant le même jour => mise à jour de l'heure de sortie.
            $db->prepare('UPDATE pointage SET heure_sortie = ? WHERE id = ?')
               ->execute([$ts, (int) $pointage['id']]);
        }
    }

    /** Trouve ou crée l'appareil représentant le K40. */
    private function appareilK40(PDO $db): int
    {
        $id = $db->query("SELECT id FROM appareil_biometrique WHERE numero_serie = 'K40-POINTEUSE'")->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $db->prepare(
            "INSERT INTO appareil_biometrique (nom, type, emplacement, numero_serie, statut)
             VALUES ('K40 Pointeuse', 'empreinte', 'Entrée', 'K40-POINTEUSE', 'en_ligne')"
        )->execute();

        return (int) $db->lastInsertId();
    }
}
