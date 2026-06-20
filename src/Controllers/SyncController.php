<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Env;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;
use PDOException;
use Throwable;

/**
 * Synchronisation montante (offline-first). Un client (agent poste, APK, web)
 * qui a bufferisé des événements hors-ligne les renvoie ici quand il reconnecte.
 *
 * Idempotence : chaque événement porte un `client_uuid` (clé UNIQUE). Rejouer
 * le même événement (réseau instable) n'insère pas de doublon.
 *
 * POST /api/sync
 * Body : { "events": [ {type, client_uuid, ...}, ... ] }
 *   type = session | pointage | activite | incident
 * Ordre de traitement : sessions d'abord (pour résoudre les références), puis
 * pointages, puis activités/incidents (qui pointent une session via
 * `session_client_uuid`).
 */
final class SyncController
{
    public function sync(): void
    {
        $body = Request::body();
        $events = $body['events'] ?? null;
        if (!is_array($events)) {
            Response::error("Le champ 'events' (tableau) est obligatoire", 422);
        }

        $db = Database::connection();
        $acceptes = [];
        $doublons = [];
        $erreurs = [];
        $sessionMap = []; // client_uuid -> id serveur

        $db->beginTransaction();
        try {
            // 1) Sessions d'abord.
            foreach ($events as $i => $e) {
                if (($e['type'] ?? '') !== 'session') {
                    continue;
                }
                [$status, $id] = $this->upsertSession($db, $e);
                $this->tally($e, $status, $i, $acceptes, $doublons, $erreurs);
                if ($id !== null && !empty($e['client_uuid'])) {
                    $sessionMap[$e['client_uuid']] = $id;
                }
            }
            // 2) Pointages.
            foreach ($events as $i => $e) {
                if (($e['type'] ?? '') !== 'pointage') {
                    continue;
                }
                $this->tally($e, $this->upsertPointage($db, $e), $i, $acceptes, $doublons, $erreurs);
            }
            // 3) Activités & incidents (référencent une session).
            foreach ($events as $i => $e) {
                $type = $e['type'] ?? '';
                if ($type === 'activite') {
                    $this->tally($e, $this->upsertActivite($db, $e, $sessionMap), $i, $acceptes, $doublons, $erreurs);
                } elseif ($type === 'incident') {
                    $this->tally($e, $this->upsertIncident($db, $e, $sessionMap), $i, $acceptes, $doublons, $erreurs);
                }
            }
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            if (Env::bool('APP_DEBUG', false)) {
                Response::error('Synchronisation échouée : ' . $ex->getMessage(), 500);
            }
            error_log('Sync: ' . $ex->getMessage());
            Response::error('Synchronisation échouée', 500);
        }

        Response::json([
            'recus'          => count($events),
            'acceptes'       => count($acceptes),
            'doublons'       => count($doublons),
            'erreurs'        => $erreurs,
            'accepted_uuids' => $acceptes,
        ]);
    }

    // ---------------------------------------------------------------- upserts

    /** @return array{0:string,1:?int} statut + id serveur de la session */
    private function upsertSession(PDO $db, array $e): array
    {
        $uuid = $e['client_uuid'] ?? null;
        if (!$uuid) {
            return ['erreur:client_uuid manquant', null];
        }
        if (empty($e['heure_debut'])) {
            return ['erreur:heure_debut manquante', null];
        }
        $posteId = $this->posteId($db, $e['poste_travail_code'] ?? null);
        if ($posteId === null) {
            return ['erreur:poste inconnu', null];
        }
        $empId = (int) ($e['employe_id'] ?? 0);
        if (!$this->employeExiste($db, $empId)) {
            return ['erreur:employe inconnu', null];
        }
        if (!$this->estAutorise($db, $empId, $posteId)) {
            return ['erreur:non autorisé sur ce poste', null];
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO session_travail
                    (client_uuid, employe_id, poste_travail_id, heure_debut, heure_fin,
                     methode_auth, autorisation_ok, statut, duree_active_sec, duree_inactive_sec)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([
                $uuid, $empId, $posteId, $e['heure_debut'], $e['heure_fin'] ?? null,
                $e['methode_auth'] ?? 'pin', $e['statut'] ?? 'fermee',
                (int) ($e['duree_active_sec'] ?? 0), (int) ($e['duree_inactive_sec'] ?? 0),
            ]);

            return ['accepte', (int) $db->lastInsertId()];
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                return ['doublon', $this->sessionIdByUuid($db, $uuid)];
            }
            throw $ex;
        }
    }

    private function upsertPointage(PDO $db, array $e): string
    {
        $uuid = $e['client_uuid'] ?? null;
        if (!$uuid) {
            return 'erreur:client_uuid manquant';
        }
        $empId = (int) ($e['employe_id'] ?? 0);
        if (!$this->employeExiste($db, $empId)) {
            return 'erreur:employe inconnu';
        }
        $date = $e['date'] ?? substr((string) ($e['heure_entree'] ?? ''), 0, 10);
        if ($date === '') {
            return 'erreur:date manquante';
        }

        try {
            $db->prepare(
                'INSERT INTO pointage (client_uuid, employe_id, date, heure_entree, heure_sortie, methode, retard_minutes, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $uuid, $empId, $date, $e['heure_entree'] ?? null, $e['heure_sortie'] ?? null,
                $e['methode'] ?? 'empreinte', (int) ($e['retard_minutes'] ?? 0), $e['statut'] ?? 'present',
            ]);

            return 'accepte';
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                return 'doublon';
            }
            throw $ex;
        }
    }

    private function upsertActivite(PDO $db, array $e, array $sessionMap): string
    {
        $uuid = $e['client_uuid'] ?? null;
        if (!$uuid) {
            return 'erreur:client_uuid manquant';
        }
        $sessionId = $this->resolveSession($db, $e, $sessionMap);
        if ($sessionId === null) {
            return 'erreur:session introuvable';
        }

        try {
            $db->prepare(
                'INSERT INTO activite_echantillon (client_uuid, session_id, horodatage, mouvements_souris, frappes_clavier, app_active, niveau_activite)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $uuid, $sessionId, $e['horodatage'] ?? null,
                (int) ($e['mouvements_souris'] ?? 0), (int) ($e['frappes_clavier'] ?? 0),
                $e['app_active'] ?? null, $e['niveau_activite'] ?? 'actif',
            ]);

            return 'accepte';
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                return 'doublon';
            }
            throw $ex;
        }
    }

    private function upsertIncident(PDO $db, array $e, array $sessionMap): string
    {
        $uuid = $e['client_uuid'] ?? null;
        if (!$uuid) {
            return 'erreur:client_uuid manquant';
        }
        $sessionId = $this->resolveSession($db, $e, $sessionMap);
        if ($sessionId === null) {
            return 'erreur:session introuvable';
        }
        $empId = (int) ($e['employe_id'] ?? 0);
        $posteId = $this->posteId($db, $e['poste_travail_code'] ?? null);
        if (!$this->employeExiste($db, $empId) || $posteId === null) {
            return 'erreur:employe/poste inconnu';
        }

        try {
            $db->prepare(
                'INSERT INTO incident_inactivite
                    (client_uuid, session_id, employe_id, poste_travail_id, heure_verrouillage, heure_reprise, duree_minutes, motif_id, justification, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $uuid, $sessionId, $empId, $posteId, $e['heure_verrouillage'] ?? null, $e['heure_reprise'] ?? null,
                isset($e['duree_minutes']) ? (int) $e['duree_minutes'] : null,
                $e['motif_id'] ?? null, $e['justification'] ?? null, $e['statut'] ?? 'justifie',
            ]);

            return 'accepte';
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                return 'doublon';
            }
            throw $ex;
        }
    }

    // ---------------------------------------------------------------- helpers

    private function resolveSession(PDO $db, array $e, array $sessionMap): ?int
    {
        $u = $e['session_client_uuid'] ?? null;
        if ($u !== null && isset($sessionMap[$u])) {
            return $sessionMap[$u];
        }
        if ($u !== null) {
            $id = $this->sessionIdByUuid($db, (string) $u);
            if ($id !== null) {
                return $id;
            }
        }
        if (!empty($e['session_id'])) {
            return (int) $e['session_id'];
        }

        return null;
    }

    private function sessionIdByUuid(PDO $db, string $uuid): ?int
    {
        $stmt = $db->prepare('SELECT id FROM session_travail WHERE client_uuid = ?');
        $stmt->execute([$uuid]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    private function posteId(PDO $db, ?string $code): ?int
    {
        if (!$code) {
            return null;
        }
        $stmt = $db->prepare('SELECT id FROM poste_travail WHERE code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    private function employeExiste(PDO $db, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $db->prepare('SELECT 1 FROM employe WHERE id = ?');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    private function estAutorise(PDO $db, int $empId, int $posteId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM autorisation_poste WHERE employe_id = ? AND poste_travail_id = ?');
        $stmt->execute([$empId, $posteId]);

        return (bool) $stmt->fetchColumn();
    }

    private function tally(array $e, string $status, int $index, array &$acceptes, array &$doublons, array &$erreurs): void
    {
        $uuid = $e['client_uuid'] ?? ('#' . $index);
        if ($status === 'accepte') {
            $acceptes[] = $uuid;
        } elseif ($status === 'doublon') {
            $doublons[] = $uuid;
        } else {
            $erreurs[] = ['uuid' => $uuid, 'index' => $index, 'raison' => substr($status, 7)];
        }
    }
}
