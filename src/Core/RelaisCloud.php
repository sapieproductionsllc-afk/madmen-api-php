<?php
declare(strict_types=1);

namespace MadMen\Core;

use PDO;

/**
 * Relais à SENS UNIQUE (bureau -> cloud) : pousse les employés + les pointages nouveaux
 * (depuis le curseur relais_state) vers l'API cloud, via le jeton GATEWAY_TOKEN.
 * Best-effort : si le cloud est injoignable, le curseur n'avance pas -> re-tenté plus tard
 * (rien n'est perdu). Tourne UNIQUEMENT sur la passerelle locale (RELAIS_ENABLED=true).
 */
final class RelaisCloud
{
    /** @return array{ok:bool,employes:int,pointages:int,erreur?:string,desactive?:bool} */
    public static function pousser(): array
    {
        if (!Env::bool('RELAIS_ENABLED', false)) {
            return ['ok' => true, 'employes' => 0, 'pointages' => 0, 'desactive' => true];
        }

        $url   = rtrim((string) Env::get('RELAIS_CLOUD_URL', ''), '/'); // ex: https://api-madmen.ssmanager.uk
        $token = (string) Env::get('GATEWAY_TOKEN', '');
        if ($url === '' || $token === '') {
            return ['ok' => false, 'employes' => 0, 'pointages' => 0, 'erreur' => 'config incomplète (RELAIS_CLOUD_URL / GATEWAY_TOKEN)'];
        }

        $db = Database::connection();
        $depuis = $db->query('SELECT last_push_at FROM relais_state WHERE id = 1')->fetchColumn() ?: '2000-01-01 00:00:00';

        // Employés (upsert idempotent côté cloud par matricule).
        // code_pin_hash inclus : le cloud l'applique pour que le MÊME code marche en
        // ligne (le hash bcrypt voyage, jamais le PIN en clair).
        $employes = $db->query(
            "SELECT matricule, nom, prenom, email, statut, code_pin_hash FROM employe WHERE statut <> 'suspendu'"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Pointages modifiés depuis le curseur.
        $st = $db->prepare(
            "SELECT e.matricule, p.date, p.heure_entree, p.heure_sortie, p.retard_minutes,
                    p.temps_present_minutes, p.temps_pause_minutes, p.nb_pauses, p.statut, p.updated_at
             FROM pointage p JOIN employe e ON e.id = p.employe_id
             WHERE p.updated_at > ? AND e.statut <> 'suspendu' ORDER BY p.updated_at"
        );
        $st->execute([$depuis]);
        $pointages = $st->fetchAll(PDO::FETCH_ASSOC);

        // Curseur = le plus récent updated_at vu (on l'avance seulement si le push réussit).
        $maxUpd = $depuis;
        foreach ($pointages as $p) {
            if (($p['updated_at'] ?? '') > $maxUpd) {
                $maxUpd = (string) $p['updated_at'];
            }
        }
        // updated_at est interne au curseur : on le retire du corps envoyé.
        $pointagesEnvoi = array_map(static function (array $p): array {
            unset($p['updated_at']);
            return $p;
        }, $pointages);

        $payload = json_encode(['employes' => $employes, 'pointages' => $pointagesEnvoi], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url . '/api/gateway/sync');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        // Bundle CA embarqué : PHP/Windows n'a pas de magasin de certificats configuré
        // par défaut -> sans ça, curl échoue sur « unable to get local issuer certificate ».
        $cacert = dirname(__DIR__, 2) . '/certs/cacert.pem';
        if (is_file($cacert)) {
            curl_setopt($ch, CURLOPT_CAINFO, $cacert);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // ANTI-PERTE : on n'avance le curseur QUE si le cloud confirme avoir reçu le lot
        // COMPLET (pointages_recus == nombre envoyé). Un HTTP 200 ne suffit PAS : un corps
        // tronqué/coupé en transit fait que le cloud décode 0 pointage et répond quand même
        // 200 avec pointages_recus=0 -> sans cette vérif, le curseur avancerait et ces
        // pointages n'atteindraient JAMAIS le cloud. Si le lot n'est pas confirmé complet,
        // le curseur reste en place -> re-poussé au prochain passage.
        // On exige que le cloud ait APPLIQUÉ (pas seulement reçu) EXACTEMENT le nombre de
        // pointages envoyés. Tous les pointages envoyés référencent un employé NON suspendu
        // -> poussé dans le MÊME lot -> présent côté cloud -> aucun n'est sauté, donc
        // appliqués == envoyés. Si le cloud en applique moins (corps tronqué, skip), on
        // n'avance PAS le curseur -> re-poussé au prochain passage. Aucune perte côté cloud.
        $rep       = is_string($resp) ? json_decode($resp, true) : null;
        $appliques = is_array($rep) && isset($rep['pointages_appliques']) ? (int) $rep['pointages_appliques'] : null;
        $lotComplet = $code === 200 && $appliques !== null && $appliques === count($pointagesEnvoi);

        if (!$lotComplet) {
            // Curseur NON avancé -> ces données seront re-poussées au prochain passage.
            return [
                'ok' => false, 'employes' => count($employes), 'pointages' => count($pointages),
                'erreur' => $err !== '' ? $err
                    : ('HTTP ' . $code . ' — lot incomplet (cloud a appliqué ' . var_export($appliques, true)
                       . ' / ' . count($pointagesEnvoi) . ' envoyés)'),
            ];
        }

        $db->prepare(
            'INSERT INTO relais_state (id, last_push_at) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE last_push_at = VALUES(last_push_at)'
        )->execute([$maxUpd]);

        return ['ok' => true, 'employes' => count($employes), 'pointages' => count($pointages)];
    }
}
