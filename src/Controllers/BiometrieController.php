<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Crypto;
use MadMen\Core\Database;
use MadMen\Core\K40;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;
use Throwable;

final class BiometrieController
{
    /** Lister les biométries enrôlées d'un employé (sans le gabarit). */
    public function index(array $params): void
    {
        $employeId = $this->resoudreEmploye($params['id']);
        $this->assertEmploye($employeId);

        $stmt = Database::connection()->prepare(
            'SELECT id, employe_id, type, doigt, badge_rfid, actif, created_at
             FROM employe_biometrie WHERE employe_id = ? ORDER BY id'
        );
        $stmt->execute([$employeId]);

        Response::json($stmt->fetchAll());
    }

    /**
     * Exporter les gabarits d'empreinte (déchiffrés, base64) d'un employé pour
     * sauvegarde/téléchargement (côté admin, JWT). Inverse de l'import via store().
     */
    public function export(array $params): void
    {
        $employeId = $this->resoudreEmploye($params['id']);
        $this->assertEmploye($employeId);

        $db   = Database::connection();
        $stmt = $db->prepare('SELECT nom, prenom, matricule FROM employe WHERE id = ?');
        $stmt->execute([$employeId]);
        $emp = $stmt->fetch() ?: [];

        $stmt = $db->prepare(
            'SELECT type, doigt, template, badge_rfid, created_at
             FROM employe_biometrie WHERE employe_id = ? AND actif = 1 ORDER BY id'
        );
        $stmt->execute([$employeId]);

        $biometries = [];
        foreach ($stmt->fetchAll() as $r) {
            $item = [
                'type'       => $r['type'],
                'doigt'      => $r['doigt'],
                'badge_rfid' => $r['badge_rfid'],
                'created_at' => $r['created_at'],
            ];
            if ($r['type'] !== 'rfid' && $r['template'] !== null) {
                $blob = is_resource($r['template']) ? stream_get_contents($r['template']) : (string) $r['template'];
                $raw  = Crypto::decrypt($blob);
                if (strlen($raw) < 100) {
                    continue; // gabarit illisible -> omis
                }
                $item['template_b64'] = base64_encode($raw);
            }
            $biometries[] = $item;
        }

        Response::json([
            'format'     => 'madmen-biometrie/v1',
            'employe'    => [
                'matricule' => $emp['matricule'] ?? null,
                'nom'       => trim(($emp['prenom'] ?? '') . ' ' . ($emp['nom'] ?? '')),
            ],
            'count'      => count($biometries),
            'biometries' => $biometries,
        ]);
    }

    /**
     * Enrôler une donnée biométrique.
     * - empreinte / facial : body.template (base64 du gabarit) [+ doigt]
     * - rfid               : body.badge_rfid
     */
    public function store(array $params): void
    {
        $employeId = $this->resoudreEmploye($params['id']);
        $this->assertEmploye($employeId);

        $body = Request::body();
        $type = $body['type'] ?? null;

        if (!in_array($type, ['empreinte', 'rfid', 'facial'], true)) {
            Response::error("Le champ 'type' doit être : empreinte, rfid ou facial", 422);
        }

        $doigt = $body['doigt'] ?? null;
        $badge = null;
        $template = null;

        if ($type === 'rfid') {
            if (empty($body['badge_rfid'])) {
                Response::error("Le champ 'badge_rfid' est obligatoire pour le type rfid", 422);
            }
            $badge = (string) $body['badge_rfid'];
        } else {
            if (empty($body['template'])) {
                Response::error("Le champ 'template' (base64) est obligatoire pour empreinte/facial", 422);
            }
            $raw = base64_decode((string) $body['template'], true);
            if ($raw === false) {
                Response::error("Le 'template' n'est pas un base64 valide", 422);
            }
            // Garde-fou qualité/format : un gabarit exploitable fait >= 100 octets (même
            // plancher que le push K40 et l'export) et tient dans la colonne BLOB. Un
            // gabarit minuscule serait stocké « enrôlé » mais inutilisable (jamais poussé).
            $taille = strlen($raw);
            if ($taille < 100 || $taille > 65000) {
                Response::error("Gabarit d'empreinte invalide ou de qualité insuffisante", 422);
            }
            // Chiffrement du gabarit au repos (échec = on n'enregistre RIEN de corrompu).
            try {
                $template = Crypto::encrypt($raw);
            } catch (Throwable $e) {
                error_log('Enrôlement empreinte (employé #' . $employeId . ') : ' . $e->getMessage());
                Response::error("Échec du chiffrement du gabarit — réessayez", 500);
            }
        }

        // Ré-enrôlement d'un doigt = REMPLACEMENT propre (pas de doublons qui s'accumulent) :
        // on retire l'ancien gabarit du même (employé, type, doigt) avant d'insérer le neuf.
        // Le push K40 ci-dessous réécrit de toute façon le même emplacement. Rend aussi
        // l'opération IDEMPOTENTE (un ré-essai après un push lent ne crée pas de doublon).
        if ($type !== 'rfid' && $doigt !== null && $doigt !== '') {
            Database::connection()
                ->prepare('DELETE FROM employe_biometrie WHERE employe_id = ? AND type = ? AND doigt = ?')
                ->execute([$employeId, $type, $doigt]);
        }

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO employe_biometrie (employe_id, type, doigt, template, badge_rfid, actif)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->bindValue(1, $employeId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $type);
            $stmt->bindValue(3, $doigt);
            $stmt->bindValue(4, $template, $template === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $stmt->bindValue(5, $badge);
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Ce badge RFID est déjà enrôlé', 422);
            }
            throw $e;
        }

        $id = (int) Database::connection()->lastInsertId();

        // Filet de sécurité : s'assure que l'employé existe (identité) sur le K40 au
        // moment de l'enrôlement. Best-effort (K40 off/injoignable -> ignoré).
        // NB : le GABARIT d'empreinte n'est PAS poussé — la lib rats/zkteco ne sait
        // pas écrire les empreintes (setFingerprint non fonctionnel). Pour pointer au
        // doigt sur le K40, enrôler l'empreinte DIRECTEMENT sur le terminal.
        $this->pushK40Identite($employeId);

        // Pour une empreinte : on pousse AUSSI le gabarit au K40 (identité d'abord,
        // car save_user_template exige un User existant). Best-effort, non bloquant.
        // On réutilise $raw (gabarit EN CLAIR déjà en mémoire) -> pas de re-déchiffrement.
        $note = 'Identité synchronisée au K40 si disponible.';
        if ($type === 'empreinte' && isset($raw) && is_string($raw)) {
            $note = $this->pushK40Template($employeId, $doigt, $raw)
                ? 'Identité + empreinte synchronisées au K40.'
                : 'Identité synchronisée ; empreinte non poussée (K40 indisponible, rattrapage via /api/k40/push-fingerprints).';
        }

        Response::json([
            'message'    => 'Biométrie enrôlée',
            'id'         => $id,
            'type'       => $type,
            'doigt'      => $doigt,
            'badge_rfid' => $badge,
            'note_k40'   => $note,
        ], 201);
    }

    /**
     * Pousse le gabarit d'empreinte vers le K40 via le pont Python (pyzk).
     * Best-effort, strictement non bloquant. Retourne true si l'upload est confirmé.
     */
    private function pushK40Template(int $employeId, ?string $doigt, string $raw): bool
    {
        @set_time_limit(0);
        try {
            // Garde-fou : gabarit vide (decrypt échoué) ou trop petit (seed bidon).
            if (strlen($raw) < 100) {
                error_log('Push K40 gabarit employé #' . $employeId . ' ignoré : gabarit trop petit (' . strlen($raw) . ' o).');
                return false;
            }

            $stmt = Database::connection()->prepare('SELECT nom, prenom, device_user_id FROM employe WHERE id = ?');
            $stmt->execute([$employeId]);
            $emp = $stmt->fetch();
            if (!$emp) {
                return false;
            }

            $deviceUserId = $emp['device_user_id'] ?: (string) $employeId;
            $name = mb_substr($emp['prenom'] . ' ' . $emp['nom'], 0, 24);
            $fid  = \MadMen\Core\K40Template::fid($doigt);

            $res = \MadMen\Core\K40Template::push([[
                'uid'     => $employeId,
                'user_id' => (string) $deviceUserId,
                'name'    => $name,
                'fingers' => [[
                    'fid'          => $fid,
                    'template_b64' => base64_encode($raw),
                ]],
            ]]);

            return !empty($res['ok']);
        } catch (Throwable $e) {
            error_log('Push K40 gabarit (employé #' . $employeId . ') ignoré : ' . $e->getMessage());
            return false;
        }
    }

    /** Pousse l'identité de l'employé vers le K40 (best-effort, silencieux). */
    private function pushK40Identite(int $employeId): void
    {
        @set_time_limit(0);
        try {
            $stmt = Database::connection()->prepare('SELECT nom, prenom FROM employe WHERE id = ?');
            $stmt->execute([$employeId]);
            $emp = $stmt->fetch();
            if (!$emp) {
                return;
            }
            $zk = K40::connect();
            $name = mb_substr($emp['prenom'] . ' ' . $emp['nom'], 0, 24);
            $zk->setUser($employeId, (string) $employeId, $name, '');
            @$zk->disconnect();
            Database::connection()
                ->prepare('UPDATE employe SET device_user_id = ? WHERE id = ?')
                ->execute([(string) $employeId, $employeId]);
        } catch (Throwable $e) {
            error_log('Push K40 identité (enrôlement employé #' . $employeId . ') ignoré : ' . $e->getMessage());
        }
    }

    /** Supprimer une biométrie enrôlée. Propage au K40 (best-effort) si empreinte. */
    public function destroy(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::connection();

        // Lire la ligne AVANT suppression (employe_id + doigt + type).
        $stmt = $db->prepare('SELECT employe_id, type, doigt FROM employe_biometrie WHERE id = ?');
        $stmt->execute([$id]);
        $bio = $stmt->fetch();
        if (!$bio) {
            Response::error('Biométrie introuvable', 404);
        }

        $db->prepare('DELETE FROM employe_biometrie WHERE id = ?')->execute([$id]);

        if ($bio['type'] === 'empreinte') {
            $this->removeK40Template((int) $bio['employe_id'], $bio['doigt']);
        }

        Response::noContent();
    }

    /** Retire le gabarit (doigt précis) du K40 (best-effort, non bloquant). */
    private function removeK40Template(int $employeId, ?string $doigt): void
    {
        @set_time_limit(0);
        try {
            $fid = \MadMen\Core\K40Template::fid($doigt);
            \MadMen\Core\K40Template::remove([[
                'uid'     => $employeId,
                'user_id' => (string) $employeId,
                'fingers' => [['fid' => $fid]],
            ]]);
        } catch (Throwable $e) {
            error_log('Retrait K40 gabarit (employé #' . $employeId . ', doigt ' . (string) $doigt . ') ignoré : ' . $e->getMessage());
        }
    }

    private function assertEmploye(int $id): void
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM employe WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            Response::error('Employé introuvable', 404);
        }
    }

    /**
     * Résout un identifiant d'URL (id numérique OU matricule) en id numérique d'employé
     * (0 si introuvable). Le front appelle ces routes avec le MATRICULE (ex. EMP-0015),
     * comme les autres endpoints /api/employes/{id} — d'où la résolution cohérente ici.
     */
    private function resoudreEmploye($idParam): int
    {
        return \MadMen\Core\Employe::resolveId($idParam); // source unique (matricule OU id)
    }
}
