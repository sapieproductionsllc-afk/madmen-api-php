<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Env;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;

/**
 * Réception PASSERELLE (côté cloud) : la passerelle locale du bureau pousse ici ses
 * employés + pointages. Sens UNIQUE local -> cloud. Auth par GATEWAY_TOKEN (jeton dédié,
 * la route est exemptée de l'auth JWT globale dans Auth::enforce).
 */
final class GatewayController
{
    public function sync(): void
    {
        // --- Auth jeton passerelle (comparaison à temps constant) ---
        $attendu = trim((string) Env::get('GATEWAY_TOKEN', ''));
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $recu = (stripos($header, 'Bearer ') === 0) ? substr($header, 7) : '';
        if ($attendu === '' || !hash_equals($attendu, $recu)) {
            Response::error('Jeton passerelle invalide', 401);
        }

        $body = Request::body();
        $employes  = is_array($body['employes'] ?? null)  ? $body['employes']  : [];
        $pointages = is_array($body['pointages'] ?? null) ? $body['pointages'] : [];

        $db = Database::connection();
        $db->beginTransaction(); // application ATOMIQUE : tout ou rien (sinon rollback + 500)
        try {

        // 1) Upsert employés par matricule. Le hash du PIN du bureau (source de vérité)
        //    est synchronisé -> même code en local ET en ligne. Le role cloud n'est
        //    jamais écrasé (un employé reste 'employe' à la création, modifiable côté cloud).
        $empApp = 0;
        foreach ($employes as $e) {
            $mat = trim((string) ($e['matricule'] ?? ''));
            if ($mat === '') {
                continue;
            }
            // Hash du PIN poussé par le bureau (si fourni). Absent -> on ne touche pas au PIN cloud.
            $hash = trim((string) ($e['code_pin_hash'] ?? ''));
            $st = $db->prepare('SELECT id FROM employe WHERE matricule = ?');
            $st->execute([$mat]);
            $id = $st->fetchColumn();
            if ($id) {
                if ($hash !== '') {
                    $db->prepare('UPDATE employe SET nom = ?, prenom = ?, email = ?, statut = ?, code_pin_hash = ? WHERE id = ?')
                       ->execute([$e['nom'] ?? '', $e['prenom'] ?? '', $e['email'] ?? null, $e['statut'] ?? 'actif', $hash, (int) $id]);
                } else {
                    $db->prepare('UPDATE employe SET nom = ?, prenom = ?, email = ?, statut = ? WHERE id = ?')
                       ->execute([$e['nom'] ?? '', $e['prenom'] ?? '', $e['email'] ?? null, $e['statut'] ?? 'actif', (int) $id]);
                }
            } else {
                // Nouveau : hash du bureau si fourni, sinon PIN provisoire aléatoire ; role 'employe'.
                $pin = $hash !== '' ? $hash : password_hash(bin2hex(random_bytes(4)), PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO employe (matricule, nom, prenom, email, code_pin_hash, statut, role)
                              VALUES (?, ?, ?, ?, ?, ?, 'employe')")
                   ->execute([$mat, $e['nom'] ?? '', $e['prenom'] ?? '', $e['email'] ?? null, $pin, $e['statut'] ?? 'actif']);
            }
            $empApp++;
        }

        // 2) Appareil « passerelle » côté cloud (créé une fois).
        $appId = $this->appareilPasserelle($db);

        // 3) Upsert pointages quotidiens par (matricule -> employe_id, date, appareil_id).
        //    Insert DIRECT des champs déjà calculés au bureau (aucun recalcul ici).
        $ptApp = 0;
        foreach ($pointages as $p) {
            $mat = trim((string) ($p['matricule'] ?? ''));
            if ($mat === '' || empty($p['date'])) {
                continue;
            }
            $st = $db->prepare('SELECT id FROM employe WHERE matricule = ?');
            $st->execute([$mat]);
            $eid = $st->fetchColumn();
            if (!$eid) {
                continue;
            }

            $sel = $db->prepare('SELECT id FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?');
            $sel->execute([(int) $eid, $p['date'], $appId]);
            $pid = $sel->fetchColumn();
            if ($pid) {
                $db->prepare('UPDATE pointage SET heure_entree = ?, heure_sortie = ?, retard_minutes = ?,
                                 temps_present_minutes = ?, temps_pause_minutes = ?, nb_pauses = ?, statut = ? WHERE id = ?')
                   ->execute([
                       $p['heure_entree'] ?? null, $p['heure_sortie'] ?? null, (int) ($p['retard_minutes'] ?? 0),
                       (int) ($p['temps_present_minutes'] ?? 0), (int) ($p['temps_pause_minutes'] ?? 0),
                       (int) ($p['nb_pauses'] ?? 0), $p['statut'] ?? 'present', (int) $pid,
                   ]);
            } else {
                $db->prepare("INSERT INTO pointage (employe_id, appareil_id, date, heure_entree, heure_sortie, methode,
                                 retard_minutes, temps_present_minutes, temps_pause_minutes, nb_pauses, statut)
                              VALUES (?, ?, ?, ?, ?, 'empreinte', ?, ?, ?, ?, ?)")
                   ->execute([
                       (int) $eid, $appId, $p['date'], $p['heure_entree'] ?? null, $p['heure_sortie'] ?? null,
                       (int) ($p['retard_minutes'] ?? 0), (int) ($p['temps_present_minutes'] ?? 0),
                       (int) ($p['temps_pause_minutes'] ?? 0), (int) ($p['nb_pauses'] ?? 0), $p['statut'] ?? 'present',
                   ]);
            }
            $ptApp++;
        }

        $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Non-200 -> la passerelle locale NE marque PAS le lot transmis et le re-pousse.
            Response::error('Echec application passerelle', 500);
            return;
        }

        Response::json([
            'employes_recus'    => count($employes),
            'employes_appliques' => $empApp,
            'pointages_recus'   => count($pointages),
            'pointages_appliques' => $ptApp,
        ]);
    }

    /** Id de l'appareil « passerelle » côté cloud (le crée au besoin). */
    private function appareilPasserelle(PDO $db): int
    {
        $id = $db->query("SELECT id FROM appareil_biometrique WHERE numero_serie = 'K40-POINTEUSE' LIMIT 1")->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $db->prepare("INSERT INTO appareil_biometrique (nom, type, emplacement, numero_serie, statut)
                      VALUES ('K40 Pointeuse', 'empreinte', '', 'K40-POINTEUSE', 'en_ligne')")->execute();
        return (int) $db->lastInsertId();
    }
}
