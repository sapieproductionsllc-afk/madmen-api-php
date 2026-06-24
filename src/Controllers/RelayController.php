<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Env;
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
        Response::json([
            'last_relay_at'   => $row['last_relay_at'] ?? null,
            'holder'          => $row['holder'] ?? null,
            'silence_seconds' => $silence,
            'quiet'           => $silence === null ? true : ($silence > self::QUIET_SECONDS),
        ]);
    }
}
