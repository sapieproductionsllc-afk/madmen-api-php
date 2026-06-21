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
     * Résout un identifiant terminal vers un employé — UNIQUEMENT via le mapping
     * explicite `device_user_id` (renseigné quand l'employé a été poussé au K40).
     *
     * STRICT : aucun repli sur `employe.id`. Un identifiant que le terminal ne
     * « connaît » pas (non mappé) renvoie null → le pointage est IGNORÉ (jamais
     * enregistré). Cela évite d'enregistrer des inconnus et tout risque d'usurpation.
     */
    public static function resolveEmploye(PDO $db, string $deviceUserId): ?int
    {
        if ($deviceUserId === '') {
            return null;
        }
        $stmt = $db->prepare('SELECT id FROM employe WHERE device_user_id = ?');
        $stmt->execute([$deviceUserId]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
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

    /**
     * Pointage à BASCULE (multi-pauses).
     *
     * Chaque doigt = un « passage ». Le type alterne selon le nombre de passages
     * déjà enregistrés ce jour : 0,2,4… = entree (arrivée / retour) ; 1,3,5… =
     * sortie (pause / départ). On recalcule ensuite le résumé du jour (arrivée,
     * dernier mouvement, temps réellement présent, temps de pause, nb de pauses).
     * Une sortie verrouille le PC et met à jour les heures sup.
     *
     * @param string $heureLimite conservé pour compat ; le retard est calculé par Presence.
     */
    private static function enregistrer(PDO $db, int $employeId, int $appareilId, string $ts, string $heureLimite): void
    {
        $date = substr($ts, 0, 10);

        // 1) Type à bascule d'après le nombre de passages du jour.
        $stmt = $db->prepare('SELECT COUNT(*) FROM pointage_passage WHERE employe_id = ? AND date = ?');
        $stmt->execute([$employeId, $date]);
        $type = (((int) $stmt->fetchColumn()) % 2 === 0) ? 'entree' : 'sortie';

        // 2) Enregistre le passage.
        $db->prepare(
            'INSERT INTO pointage_passage (employe_id, date, type, horodatage, appareil_id, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$employeId, $date, $type, $ts, $appareilId, 'k40']);

        // 3) Recharge tous les passages du jour et recalcule le résumé.
        $stmt = $db->prepare(
            'SELECT type, horodatage FROM pointage_passage WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$employeId, $date]);
        $resume = self::resumeJournee($stmt->fetchAll());

        // 4) Upsert du résumé quotidien (table pointage).
        $stmt = $db->prepare('SELECT id FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?');
        $stmt->execute([$employeId, $date, $appareilId]);
        $pid = $stmt->fetchColumn();

        if (!$pid) {
            $retard = Presence::retardMinutes($resume['entree']);
            $statut = $retard > 0 ? 'retard' : 'present';
            $db->prepare(
                'INSERT INTO pointage
                    (employe_id, appareil_id, date, heure_entree, heure_sortie, methode,
                     retard_minutes, temps_present_minutes, temps_pause_minutes, nb_pauses, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $employeId, $appareilId, $date, $resume['entree'], $resume['sortie'], 'empreinte',
                $retard, $resume['present'], $resume['pause'], $resume['nb_pauses'], $statut,
            ]);
        } else {
            $db->prepare(
                'UPDATE pointage SET heure_sortie = ?, temps_present_minutes = ?,
                    temps_pause_minutes = ?, nb_pauses = ? WHERE id = ?'
            )->execute([$resume['sortie'], $resume['present'], $resume['pause'], $resume['nb_pauses'], (int) $pid]);
        }

        // 5) Une SORTIE (pause OU départ) verrouille le PC et met à jour les heures sup.
        if ($type === 'sortie') {
            self::verrouillerSessions($db, $employeId, $ts);
            self::enregistrerHeuresSup($db, $employeId, $date, $resume['sortie']);
        }
    }

    /**
     * Résumé d'une journée depuis les passages triés (alternance entree/sortie).
     *  - présence = somme des intervalles entree->sortie (bornés 08:30–18:00, déjeuner exclu)
     *  - pause    = somme des intervalles sortie->entree (temps réellement absent)
     *
     * @param array<int,array{type:string,horodatage:string}> $passages
     * @return array{entree:string,sortie:string,present:int,pause:int,nb_pauses:int}
     */
    private static function resumeJournee(array $passages): array
    {
        $entree = $passages[0]['horodatage'];
        $sortie = $passages[count($passages) - 1]['horodatage'];
        $present = 0;
        $pause = 0;
        $nbPauses = 0;

        for ($i = 0, $n = count($passages); $i + 1 < $n; $i++) {
            $a = $passages[$i];
            $b = $passages[$i + 1];
            if ($a['type'] === 'entree' && $b['type'] === 'sortie') {
                $present += Presence::presenceMinutes($a['horodatage'], $b['horodatage']);
            } elseif ($a['type'] === 'sortie' && $b['type'] === 'entree') {
                $pause += (int) max(0, (strtotime($b['horodatage']) - strtotime($a['horodatage'])) / 60);
                $nbPauses++;
            }
        }

        return ['entree' => $entree, 'sortie' => $sortie, 'present' => $present, 'pause' => $pause, 'nb_pauses' => $nbPauses];
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
