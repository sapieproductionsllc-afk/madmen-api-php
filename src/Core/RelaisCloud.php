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
        $employes = $db->query(
            "SELECT matricule, nom, prenom, email, statut FROM employe WHERE statut <> 'suspendu'"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Pointages modifiés depuis le curseur.
        $st = $db->prepare(
            "SELECT e.matricule, p.date, p.heure_entree, p.heure_sortie, p.retard_minutes,
                    p.temps_present_minutes, p.temps_pause_minutes, p.nb_pauses, p.statut, p.updated_at
             FROM pointage p JOIN employe e ON e.id = p.employe_id
             WHERE p.updated_at > ? ORDER BY p.updated_at"
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
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) {
            // Curseur NON avancé -> ces données seront re-poussées au prochain passage.
            return [
                'ok' => false, 'employes' => count($employes), 'pointages' => count($pointages),
                'erreur' => $err !== '' ? $err : ('HTTP ' . $code),
            ];
        }

        $db->prepare(
            'INSERT INTO relais_state (id, last_push_at) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE last_push_at = VALUES(last_push_at)'
        )->execute([$maxUpd]);

        return ['ok' => true, 'employes' => count($employes), 'pointages' => count($pointages)];
    }
}
