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
     * Pointage MANUEL (saisi par l'admin quand le K40 est injoignable). Même logique
     * de BASCULE et même appareil logique que le K40 : un pointage manuel compte
     * comme un passage, donc le pointage K40 suivant enchaîne correctement (ex.
     * arrivée saisie à la main -> le doigt sur le K40 ensuite = DÉPART, pas arrivée).
     *
     * @param string|null $type 'entree'|'sortie' pour forcer ; null = bascule auto.
     */
    public static function recordManuel(PDO $db, int $employeId, string $ts, ?string $type = null): void
    {
        self::enregistrer($db, $employeId, self::appareilId($db), $ts, '', 'manuel', $type);
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
    private static function enregistrer(PDO $db, int $employeId, int $appareilId, string $ts, string $heureLimite, string $source = 'k40', ?string $forceType = null): void
    {
        $date = substr($ts, 0, 10);
        // Horaire PROPRE à l'employé (ou global par défaut) : référence des calculs.
        $horaire = Presence::horaire($db, $employeId);
        // Fenêtre du JOUR (emploi du temps par jour) pour le retard ; null = repos.
        $fenetre = Presence::fenetreJour(Presence::planning($db, $employeId), $date);

        // 1) Type : forcé (saisie manuelle explicite) sinon à BASCULE d'après le
        //    nombre de passages du jour (0,2,4...=entrée ; 1,3,5...=sortie).
        $stmt = $db->prepare('SELECT COUNT(*) FROM pointage_passage WHERE employe_id = ? AND date = ?');
        $stmt->execute([$employeId, $date]);
        $type = $forceType ?? ((((int) $stmt->fetchColumn()) % 2 === 0) ? 'entree' : 'sortie');

        // 2) Enregistre le passage de façon IDEMPOTENTE (source : 'k40' ou 'manuel').
        // Le client_uuid est déterministe (employé + horodatage) : un même punch
        // renvoyé/rejoué par le terminal (handshake Stamp=0, reconnexion ADMS, double
        // traitement pull/push) produit le MÊME uuid -> INSERT IGNORE le rejette via
        // l'index UNIQUE uq_pp_client_uuid -> aucun doublon, aucune inversion de parité.
        $clientUuid = self::clientUuid($employeId, $ts);
        $ins = $db->prepare(
            'INSERT IGNORE INTO pointage_passage
                (employe_id, date, type, horodatage, appareil_id, source, client_uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$employeId, $date, $type, $ts, $appareilId, $source, $clientUuid]);
        if ($ins->rowCount() === 0) {
            // Doublon : ce punch est déjà enregistré. Le résumé du jour est déjà
            // correct -> rien à recalculer (opération sûre à rejouer indéfiniment).
            return;
        }

        // 3) Recharge tous les passages du jour et recalcule le résumé.
        $stmt = $db->prepare(
            'SELECT type, horodatage FROM pointage_passage WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$employeId, $date]);
        $resume = self::resumeJournee($stmt->fetchAll(), $horaire);

        // 4) Upsert du résumé quotidien (table pointage).
        $stmt = $db->prepare('SELECT id FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?');
        $stmt->execute([$employeId, $date, $appareilId]);
        $pid = $stmt->fetchColumn();

        if (!$pid) {
            // Retard vs la fenêtre du jour (au-delà de la tolérance). Repos -> 0.
            $retard = $fenetre ? Presence::retardDansFenetre($resume['entree'], $fenetre) : 0;
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

        // 5) Une SORTIE (pause OU départ) met à jour les heures sup. Le K40 NE
        // touche PAS au kiosque (PC) : les deux systèmes sont 100% indépendants
        // (le PC ne se verrouille que par inactivité ou déconnexion).
        if ($type === 'sortie') {
            self::enregistrerHeuresSup($db, $employeId, $date, $resume['sortie'], $horaire);
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
    private static function resumeJournee(array $passages, ?array $horaire = null): array
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
                $present += Presence::presenceMinutes($a['horodatage'], $b['horodatage'], $horaire);
            } elseif ($a['type'] === 'sortie' && $b['type'] === 'entree') {
                $pause += (int) max(0, (strtotime($b['horodatage']) - strtotime($a['horodatage'])) / 60);
                $nbPauses++;
            }
        }

        return ['entree' => $entree, 'sortie' => $sortie, 'present' => $present, 'pause' => $pause, 'nb_pauses' => $nbPauses];
    }

    /**
     * Enregistre (upsert) les heures supplémentaires si le départ dépasse 18:00.
     * Clé unique : employe_id + date.
     */
    private static function enregistrerHeuresSup(PDO $db, int $employeId, string $date, string $ts, ?array $horaire = null): void
    {
        $dureeSup = Presence::heuresSupMinutes($ts, $horaire);
        if ($dureeSup <= 0) {
            return;
        }

        // Début des heures sup = heure de fin prévue de l'employé (repli : défaut global).
        $fin = (($horaire ?? Presence::defaultHoraire())['fin']) . ':00';

        $db->prepare(
            "INSERT INTO heures_supplementaires
                (employe_id, date, heure_debut, heure_fin, duree_minutes, source)
             VALUES (?, ?, ?, ?, ?, 'k40')
             ON DUPLICATE KEY UPDATE heure_debut = VALUES(heure_debut),
                heure_fin = VALUES(heure_fin), duree_minutes = VALUES(duree_minutes)"
        )->execute([$employeId, $date, $date . ' ' . $fin, $ts, $dureeSup]);
    }

    /**
     * UUID déterministe (36 caractères, forme UUID) pour un punch donné. Le même
     * punch (même employé + même horodatage) produit toujours le même uuid, ce qui
     * permet la déduplication via l'index UNIQUE pointage_passage.client_uuid.
     */
    private static function clientUuid(int $employeId, string $ts): string
    {
        // Canonicalise l'horodatage avant le hachage : le MÊME punch produit le même
        // uuid quel que soit le format reçu (pull vs push, variante firmware/epoch),
        // pour que la déduplication fonctionne aussi BIEN entre les deux chemins.
        $t = strtotime($ts);
        $norm = $t !== false ? date('Y-m-d H:i:s', $t) : $ts;
        $h = md5('k40|' . $employeId . '|' . $norm); // 32 caractères hexadécimaux
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 12, 4),
            substr($h, 16, 4),
            substr($h, 20, 12)
        );
    }
}
