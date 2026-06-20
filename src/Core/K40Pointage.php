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

    /** Résout un identifiant terminal vers un employé (device_user_id, sinon id). */
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
            'SELECT id FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?'
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
            $db->prepare('UPDATE pointage SET heure_sortie = ? WHERE id = ?')
               ->execute([$ts, (int) $pointage['id']]);
        }
    }
}
