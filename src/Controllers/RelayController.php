<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Crypto;
use MadMen\Core\Database;
use MadMen\Core\Env;
use MadMen\Core\K40Template;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;

/**
 * Reporter distribué : chaque PC du bureau (app MADMEN User) peut relayer les pointages
 * du K40 vers le cloud, mais UN SEUL de garde à la fois.
 *
 * - POST /api/relay/claim  : un reporter (ré)attribue le tour de garde. Auth GATEWAY_TOKEN
 *   (route exemptée de l'auth JWT globale dans Auth::enforce). Bail atomique de 60 s.
 * - GET  /api/relay/health : état du flux (silence) pour la bannière "bureau silencieux".
 *   Auth JWT normale (consommée par le dashboard).
 *
 * Les POINTAGES eux-mêmes ne passent PAS par ici : le reporter de garde les pousse au
 * récepteur PUSH existant (/iclock/cdata, HTTPS), qui dédoublonne déjà via client_uuid
 * et journalise dans k40_punch_brut (zéro perte). Ici on ne fait QUE coordonner.
 */
final class RelayController
{
    private const LEASE_SECONDS  = 60;   // durée du bail "de garde"
    private const QUIET_SECONDS  = 600;  // > 10 min sans aucun check-in => bureau silencieux

    /** POST /api/relay/claim — demande/renouvelle le tour de garde. */
    public function claim(): void
    {
        // --- Auth jeton relais (comparaison à temps constant) ---
        $attendu = trim((string) Env::get('GATEWAY_TOKEN', ''));
        $header  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $recu    = (stripos($header, 'Bearer ') === 0) ? substr($header, 7) : '';
        if ($attendu === '' || !hash_equals($attendu, $recu)) {
            Response::error('Jeton relais invalide', 401);
        }

        $body   = Request::body();
        $holder = substr(trim((string) ($body['holder'] ?? '')), 0, 64);
        if ($holder === '') {
            Response::error('holder requis', 422);
        }

        $db = Database::connection();
        // Bail ATOMIQUE : on (ré)attribue le tour UNIQUEMENT s'il est libre, expiré, ou
        // déjà à nous. Sinon le titulaire en cours est conservé. Une seule requête ->
        // MySQL sérialise les claims concurrents sur la ligne id=1 : un seul gagnant.
        $st = $db->prepare(
            "INSERT INTO relay_runtime (id, holder, lease_until, last_relay_at)
             VALUES (1, :h, NOW() + INTERVAL " . self::LEASE_SECONDS . " SECOND, NOW())
             ON DUPLICATE KEY UPDATE
               holder        = IF(lease_until IS NULL OR lease_until < NOW() OR holder = VALUES(holder),
                                  VALUES(holder), holder),
               lease_until   = IF(lease_until IS NULL OR lease_until < NOW() OR holder = VALUES(holder),
                                  VALUES(lease_until), lease_until),
               last_relay_at = IF(lease_until IS NULL OR lease_until < NOW() OR holder = VALUES(holder),
                                  NOW(), last_relay_at)"
        );
        $st->bindValue(':h', $holder);
        $st->execute();

        $row     = $db->query("SELECT holder, lease_until FROM relay_runtime WHERE id = 1")
            ->fetch(PDO::FETCH_ASSOC);
        $granted = is_array($row) && ($row['holder'] ?? null) === $holder;

        // Suivi de FLOTTE : ce reporter est "vu" maintenant (chaque PC claim à chaque
        // tour) -> on peut lister tous les PC qui relaient et repérer les absents.
        $db->prepare(
            "INSERT INTO relay_reporters (hostname, last_seen) VALUES (:h, NOW())
             ON DUPLICATE KEY UPDATE last_seen = NOW()"
        )->execute([':h' => $holder]);

        Response::json([
            'granted'     => $granted,
            'lease_until' => $granted ? ($row['lease_until'] ?? null) : null,
            'holder'      => $row['holder'] ?? null,   // qui est de garde (info)
        ]);
    }

    /** GET /api/relay/health — y a-t-il eu un check-in reporter récemment ? */
    public function health(): void
    {
        $db  = Database::connection();
        $row = $db->query(
            "SELECT last_relay_at, holder,
                    TIMESTAMPDIFF(SECOND, last_relay_at, NOW()) AS silence_seconds
             FROM relay_runtime WHERE id = 1"
        )->fetch(PDO::FETCH_ASSOC);

        $silence = $row && $row['silence_seconds'] !== null ? (int) $row['silence_seconds'] : null;

        // Nombre de PC ayant relayé dans les 2 dernières minutes (flotte en ligne).
        $online = (int) $db->query(
            "SELECT COUNT(*) FROM relay_reporters WHERE last_seen > NOW() - INTERVAL 120 SECOND"
        )->fetchColumn();

        Response::json([
            'last_relay_at'    => $row['last_relay_at'] ?? null,
            'holder'           => $row['holder'] ?? null,
            'silence_seconds'  => $silence,
            'quiet'            => $silence === null ? true : ($silence > self::QUIET_SECONDS),
            'reporters_online' => $online,
        ]);
    }

    /** GET /api/relay/reporters — liste de la flotte (tous les PC qui relaient). */
    public function reporters(): void
    {
        $db   = Database::connection();
        $rows = $db->query(
            "SELECT hostname, last_seen,
                    TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age_seconds
             FROM relay_reporters ORDER BY last_seen DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $online = 0;
        foreach ($rows as &$r) {
            $r['online'] = ((int) $r['age_seconds']) <= 120; // vu il y a < 2 min
            if ($r['online']) {
                $online++;
            }
        }
        unset($r);
        Response::json(['total' => count($rows), 'online' => $online, 'reporters' => $rows]);
    }

    /**
     * GET /api/relay/pending-fingerprints — gabarits d'empreinte EN ATTENTE de poussée K40.
     * Le reporter de garde (sur le LAN du K40) les tire, les écrit sur le device, puis
     * appelle /api/relay/fingerprints-synced. Sens DESCENDANT cloud -> K40 (le cloud ne
     * peut PAS joindre le K40 ; c'est le PC du bureau qui fait le pont). Auth GATEWAY_TOKEN.
     */
    public function pendingFingerprints(): void
    {
        $this->authReporter();

        $db   = Database::connection();
        $rows = $db->query(
            "SELECT b.id, b.employe_id, b.doigt, b.template,
                    e.nom, e.prenom, e.device_user_id
             FROM employe_biometrie b
             JOIN employe e ON e.id = b.employe_id
             WHERE b.type = 'empreinte' AND b.actif = 1 AND b.k40_synced_at IS NULL
               AND e.statut = 'actif'
             ORDER BY b.employe_id, b.id
             LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);

        $users  = [];
        $bioIds = [];
        foreach ($rows as $r) {
            $blob = is_resource($r['template']) ? stream_get_contents($r['template']) : (string) $r['template'];
            $raw  = Crypto::decrypt($blob);
            if (strlen($raw) < 100) {
                // Déchiffrement KO ou seed bidon : on le marque fait pour ne pas boucler dessus.
                $db->prepare("UPDATE employe_biometrie SET k40_synced_at = NOW() WHERE id = ?")
                   ->execute([(int) $r['id']]);
                continue;
            }
            $eid = (int) $r['employe_id'];
            if (!isset($users[$eid])) {
                // Ferme la boucle de RETOUR : un employé créé côté cloud n'a pas de
                // device_user_id -> son pointage reviendrait « inconnu » (resolveEmploye
                // strict). On le mappe au slot K40 qu'on s'apprête à utiliser (= employe.id),
                // comme le font déjà K40Controller et BiometrieController.
                $duid = $r['device_user_id'];
                if (!$duid) {
                    $db->prepare(
                        "UPDATE employe SET device_user_id = ?
                         WHERE id = ? AND (device_user_id IS NULL OR device_user_id = '')"
                    )->execute([(string) $eid, $eid]);
                    $duid = (string) $eid;
                }
                $users[$eid] = [
                    'uid'     => $eid,
                    'user_id' => (string) $duid,
                    'name'    => mb_substr(trim($r['prenom'] . ' ' . $r['nom']), 0, 24),
                    'fingers' => [],
                ];
            }
            $users[$eid]['fingers'][] = [
                'fid'          => K40Template::fid($r['doigt']),
                'template_b64' => base64_encode($raw),
            ];
            $bioIds[] = (int) $r['id'];
        }

        Response::json(['users' => array_values($users), 'bio_ids' => $bioIds]);
    }

    /**
     * POST /api/relay/fingerprints-synced — le reporter confirme que des gabarits ont été
     * écrits sur le K40 ; on les horodate pour ne plus les re-pousser. Auth GATEWAY_TOKEN.
     * Body : { "bio_ids": [12, 13, ...] }
     */
    public function fingerprintsSynced(): void
    {
        $this->authReporter();

        $body = Request::body();
        $ids  = array_values(array_filter(
            array_map('intval', (array) ($body['bio_ids'] ?? [])),
            static fn ($x) => $x > 0
        ));
        if ($ids === []) {
            Response::json(['marked' => 0]);
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $st    = Database::connection()->prepare(
            "UPDATE employe_biometrie SET k40_synced_at = NOW() WHERE id IN ($place)"
        );
        $st->execute($ids);

        Response::json(['marked' => $st->rowCount()]);
    }

    /** Auth jeton relais (comparaison à temps constant), partagée par les endpoints reporter. */
    private function authReporter(): void
    {
        $attendu = trim((string) Env::get('GATEWAY_TOKEN', ''));
        $header  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $recu    = (stripos($header, 'Bearer ') === 0) ? substr($header, 7) : '';
        if ($attendu === '' || !hash_equals($attendu, $recu)) {
            Response::error('Jeton relais invalide', 401);
        }
    }
}
