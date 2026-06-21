<?php
declare(strict_types=1);

namespace MadMen\Core;

use PDO;

/**
 * Logique d'enregistrement des pointages issus du K40, partagée par les deux
 * modes : Pull (l'API interroge le terminal) et Push/ADMS (le terminal envoie).
 *
 * Règle : 1er pointage du jour = arrivée (heure_entree, retard si > heure limite),
 * pointage suivant = départ (heure_sortie mise à jour).
 */
final class K40Pointage
{
    /**
     * Enregistre un « punch ». Renvoie 'traite' si rattaché à un employé,
     * 'ignore' si l'identifiant terminal est inconnu.
     */
    public static function record(PDO $db, string $deviceUserId, string $timestamp, string $heureLimite): string
    {
        $employeId = self::resolveEmploye($db, $deviceUserId);
        if ($employeId === null) {
            return 'ignore';
        }

        self::enregistrer($db, $employeId, self::appareilId($db), $timestamp, $heureLimite);

        return 'traite';
    }

    /**
     * Résout un identifiant terminal vers un employé.
     *
     * Priorité au mapping explicite via `device_user_id`. Le repli sur
     * `employe.id` (quand l'identifiant est numérique) est volontairement borné :
     * il ne sert qu'à l'amorçage, AVANT que le mapping ne soit fait. Dès qu'au
     * moins un employé possède un `device_user_id` renseigné, on considère le
     * mapping en place et on n'autorise plus le repli numérique — sinon un
     * terminal forgé pourrait viser n'importe quel employé par son id.
     */
    public static function resolveEmploye(PDO $db, string $deviceUserId): ?int
    {
        if ($deviceUserId === '') {
            return null;
        }
        $stmt = $db->prepare('SELECT id FROM employe WHERE device_user_id = ?');
        $stmt->execute([$deviceUserId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        // Repli numérique uniquement si AUCUN mapping device_user_id n'existe en base.
        if (ctype_digit($deviceUserId) && !self::mappingExiste($db)) {
            $stmt = $db->prepare('SELECT id FROM employe WHERE id = ?');
            $stmt->execute([(int) $deviceUserId]);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    /** Indique si au moins un employé a un device_user_id renseigné (mapping fait). */
    private static function mappingExiste(PDO $db): bool
    {
        $stmt = $db->query(
            "SELECT 1 FROM employe WHERE device_user_id IS NOT NULL AND device_user_id <> '' LIMIT 1"
        );

        return $stmt->fetchColumn() !== false;
    }

    /** Trouve ou crée l'appareil représentant le K40. */
    public static function appareilId(PDO $db): int
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

    /** 1er punch du jour = arrivée ; suivant = départ. */
    private static function enregistrer(PDO $db, int $employeId, int $appareilId, string $ts, string $heureLimite): void
    {
        $date = substr($ts, 0, 10);

        $stmt = $db->prepare(
            'SELECT id, heure_entree FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?'
        );
        $stmt->execute([$employeId, $date, $appareilId]);
        $pointage = $stmt->fetch();

        if (!$pointage) {
            // Arrivée : le retard est calculé par Presence (référence 08:30),
            // indépendamment de l'ancien paramètre $heureLimite.
            $retard = Presence::retardMinutes($ts);
            $statut = $retard > 0 ? 'retard' : 'present';

            $db->prepare(
                'INSERT INTO pointage (employe_id, appareil_id, date, heure_entree, methode, retard_minutes, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$employeId, $appareilId, $date, $ts, 'empreinte', $retard, $statut]);
        } else {
            // Départ : on met à jour l'heure de sortie...
            $db->prepare('UPDATE pointage SET heure_sortie = ? WHERE id = ?')
               ->execute([$ts, (int) $pointage['id']]);

            // ...puis on verrouille le poste et on enregistre les heures sup.
            self::verrouillerSessions($db, $employeId, $ts);
            self::enregistrerHeuresSup($db, $employeId, $date, $ts);
        }
    }

    /**
     * Départ K40 : verrouille toutes les sessions ouvertes de l'employé, met les
     * postes concernés en 'verrouille' et ouvre un incident d'inactivité par session.
     */
    private static function verrouillerSessions(PDO $db, int $employeId, string $ts): void
    {
        // Récupère les sessions ouvertes AVANT verrouillage (pour leurs ids/postes).
        $stmt = $db->prepare(
            "SELECT id, poste_travail_id FROM session_travail WHERE employe_id = ? AND statut = 'ouverte'"
        );
        $stmt->execute([$employeId]);
        $sessions = $stmt->fetchAll();

        if (!$sessions) {
            return;
        }

        $db->prepare(
            "UPDATE session_travail SET statut = 'verrouillee' WHERE employe_id = ? AND statut = 'ouverte'"
        )->execute([$employeId]);

        $verrouillePoste = $db->prepare(
            "UPDATE poste_travail SET statut = 'verrouille' WHERE id = ?"
        );
        $ouvreIncident = $db->prepare(
            'INSERT INTO incident_inactivite
                (session_id, employe_id, poste_travail_id, heure_verrouillage, justification, statut)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($sessions as $session) {
            $posteId = $session['poste_travail_id'] !== null ? (int) $session['poste_travail_id'] : null;
            if ($posteId !== null) {
                $verrouillePoste->execute([$posteId]);
            }
            $ouvreIncident->execute([
                (int) $session['id'],
                $employeId,
                $posteId,
                $ts,
                'Parti du bureau (pointage K40)',
                'ouvert',
            ]);
        }
    }

    /**
     * Enregistre (upsert) les heures supplémentaires si le départ dépasse 18:00.
     * Clé unique : employe_id + date.
     */
    private static function enregistrerHeuresSup(PDO $db, int $employeId, string $date, string $ts): void
    {
        $dureeSup = Presence::heuresSupMinutes($ts);
        if ($dureeSup <= 0) {
            return;
        }

        $db->prepare(
            "INSERT INTO heures_supplementaires
                (employe_id, date, heure_debut, heure_fin, duree_minutes, source)
             VALUES (?, ?, ?, ?, ?, 'k40')
             ON DUPLICATE KEY UPDATE heure_fin = VALUES(heure_fin), duree_minutes = VALUES(duree_minutes)"
        )->execute([$employeId, $date, $date . ' 18:00:00', $ts, $dureeSup]);
    }
}
