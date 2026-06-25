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
     * Enregistre un « punch » K40. Renvoie :
     *   - 'traite'  : rattaché à un employé ET enregistré (entrée/sortie) ;
     *   - 'ignore'  : rattaché à un employé mais FILTRÉ par les règles d'horaire
     *                 (jour de repos, trop tôt, après le départ sans arrivée, déjà
     *                 parti) -> aucun pointage créé. Décision DÉFINITIVE : le curseur
     *                 de synchro peut avancer (inutile de relire ce punch) ;
     *   - 'inconnu' : identifiant terminal NON MAPPÉ -> punch jamais enregistré ;
     *                 le curseur ne doit pas dépasser ce punch (il sera relu et
     *                 enregistré une fois l'employé mappé).
     */
    public static function record(PDO $db, string $deviceUserId, string $timestamp, string $heureLimite): string
    {
        // Garde-fou observabilité : un horodatage non parseable = ligne corrompue du
        // terminal (jamais un vrai punch, toujours bien daté). On la trace pour
        // investigation au lieu de la laisser disparaître silencieusement.
        if (strtotime($timestamp) === false) {
            error_log("K40: horodatage non parseable (dev={$deviceUserId}, ts={$timestamp})");
        }

        // 0) JOURNAL BRUT — on persiste TOUT punch AVANT toute résolution/filtrage.
        //    Garantie anti-perte : même non mappé, filtré par l'horaire, ou daté dans
        //    le futur, le punch reste durablement enregistré et rejouable.
        $brutUuid = self::brutUuid($deviceUserId, $timestamp);
        self::insererBrut($db, $deviceUserId, $timestamp, $brutUuid);

        // 1) Punch daté dans le FUTUR (horloge K40 déréglée) : conservé au brut, mais
        //    NON injecté dans les données dérivées. Avec le clamp du curseur (runSync),
        //    il ne peut pas empoisonner la synchro et faire sauter les punchs réels.
        if ((int) strtotime($timestamp) > time() + 300) {
            self::majBrut($db, $brutUuid, null, 'futur');
            return 'ignore';
        }

        // 2) Résolution stricte de l'employé.
        $employeId = self::resolveEmploye($db, $deviceUserId);
        if ($employeId === null) {
            self::majBrut($db, $brutUuid, null, 'inconnu');
            return 'inconnu';
        }

        // 3) Filtrage des règles d'horaire + enregistrement du passage (source K40).
        $decision = self::enregistrer($db, $employeId, self::appareilId($db), $timestamp, $heureLimite);
        self::majBrut($db, $brutUuid, $employeId, $decision);

        return $decision;
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
        // source='manuel' => JAMAIS filtré : l'admin a toujours raison (jour de repos,
        // hors fenêtre, après le départ... tout est accepté tel quel).
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
     * @return string 'traite' si un passage a été enregistré ; 'ignore' si filtré (K40
     *               hors fenêtre / jour de repos / après le départ). Le pointage MANUEL
     *               n'est jamais filtré -> renvoie toujours 'traite'.
     */
    private static function enregistrer(PDO $db, int $employeId, int $appareilId, string $ts, string $heureLimite, string $source = 'k40', ?string $forceType = null): string
    {
        $date = substr($ts, 0, 10);
        // Horaire PROPRE à l'employé (ou global par défaut) : référence des calculs.
        $horaire = Presence::horaire($db, $employeId);
        // Fenêtre du JOUR (emploi du temps par jour) pour le retard ; null = repos.
        $fenetre = Presence::fenetreJour(Presence::planning($db, $employeId), $date);

        // 0) FILTRAGE K40 conscient de l'horaire (UNIQUEMENT source='k40' ; le manuel
        //    n'est JAMAIS filtré). On force éventuellement un type 'sortie' (départ).
        if ($source === 'k40') {
            // a) Jour de REPOS (aucune fenêtre prévue) -> on ignore tout.
            if ($fenetre === null) {
                return 'ignore';
            }
            // b) Arrivée EN AVANCE (avant debut - avance) : on NE l'ignore PLUS. La
            //    personne EST présente -> on enregistre son punch avec son heure réelle.
            //    Le retard reste calculé par Presence (une avance = aucun retard).
            //    Avant : 'ignore' -> l'employé apparaissait ABSENT malgré son pointage K40
            //    (ex. Yohann pointé 07:52 pour une prise à 08:30 -> perdu).
            // c) À/APRÈS l'heure de DÉPART prévue : la personne est censée être partie.
            if (Presence::estApresFin($ts, $fenetre)) {
                [$aArrivee, $aSortie] = self::etatJourK40($db, $employeId, $date);
                if (!$aArrivee || $aSortie) {
                    // Aucune arrivée ce jour (1er punch après fin) OU déjà partie
                    // (sortie déjà enregistrée) -> on ignore (reste 'parti').
                    return 'ignore';
                }
                // Arrivée présente sans sortie -> ce punch est le DÉPART.
                $forceType = 'sortie';
            }
            // d) Sinon (dans [debut - avance, fin)) -> enregistrement NORMAL ci-dessous.
        }

        // 1) Type : forcé (saisie manuelle explicite OU départ K40 imposé ci-dessus)
        //    sinon à BASCULE d'après le nombre de passages du jour (0,2,4...=entrée ;
        //    1,3,5...=sortie).
        $stmt = $db->prepare('SELECT COUNT(*) FROM pointage_passage WHERE employe_id = ? AND date = ?');
        $stmt->execute([$employeId, $date]);
        $type = $forceType ?? ((((int) $stmt->fetchColumn()) % 2 === 0) ? 'entree' : 'sortie');

        // 2) Enregistre le passage de façon IDEMPOTENTE (source : 'k40' ou 'manuel').
        // Le client_uuid est déterministe (employé + horodatage) : un même punch
        // renvoyé/rejoué par le terminal (handshake Stamp=0, reconnexion ADMS, double
        // traitement pull/push) produit le MÊME uuid -> INSERT IGNORE le rejette via
        // l'index UNIQUE uq_pp_client_uuid -> aucun doublon, aucune inversion de parité.
        $clientUuid = self::clientUuid($employeId, $ts);

        // ATOMICITÉ (anti-incohérence) : le passage ET le résumé du jour sont écrits dans
        // UNE seule transaction -> un crash entre les deux ne peut PAS laisser un résumé
        // périmé (tout ou rien ; le punch sera relu, le device n'étant jamais purgé).
        // inTransaction() : on ne gère la transaction que si aucune n'est déjà ouverte
        // (évite une transaction PDO imbriquée si l'appel vient d'un flux transactionnel).
        $gereTx = !$db->inTransaction();
        if ($gereTx) {
            $db->beginTransaction();
        }
        try {
        $ins = $db->prepare(
            'INSERT IGNORE INTO pointage_passage
                (employe_id, date, type, horodatage, appareil_id, source, client_uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$employeId, $date, $type, $ts, $appareilId, $source, $clientUuid]);
        if ($ins->rowCount() === 0) {
            // Doublon : passage déjà enregistré AVEC son résumé (écrits atomiquement
            // ensemble) -> rien à recalculer. Opération sûre à rejouer indéfiniment.
            if ($gereTx) {
                $db->commit();
            }
            return 'traite';
        }

        // 3) Recharge tous les passages du jour et recalcule le résumé.
        $stmt = $db->prepare(
            'SELECT type, horodatage FROM pointage_passage WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$employeId, $date]);
        $passages = $stmt->fetchAll();
        $resume = self::resumeJournee($passages, $horaire);

        // Compteurs du jour (jour de repos -> 0). retard_dejeuner = retour de pause après
        // l'heure fixe de fin ; temps_manquant = prévu net − travaillé (solde fin de journée).
        $retardDej = $fenetre ? Presence::retardRetourDejeuner($passages, $horaire) : 0;
        $tempsManq = $fenetre ? Presence::tempsManquant($resume['present'], $horaire) : 0;

        // 4) Upsert du résumé quotidien (table pointage).
        $stmt = $db->prepare('SELECT id FROM pointage WHERE employe_id = ? AND date = ? AND appareil_id = ?');
        $stmt->execute([$employeId, $date, $appareilId]);
        $pid = $stmt->fetchColumn();

        // Le DERNIER passage du jour décide l'état courant : 'sortie' => la personne
        // est PARTIE ; 'entree' => présente (ou en retard si l'arrivée était tardive).
        $estParti = ($type === 'sortie');

        if (!$pid) {
            // Retard vs la fenêtre du jour (au-delà de la tolérance). Repos -> 0.
            $retard = $fenetre ? Presence::retardDansFenetre($resume['entree'], $fenetre) : 0;
            $statut = $estParti ? 'parti' : ($retard > 0 ? 'retard' : 'present');
            $db->prepare(
                'INSERT INTO pointage
                    (employe_id, appareil_id, date, heure_entree, heure_sortie, methode,
                     retard_minutes, retard_dejeuner_minutes, temps_manquant_minutes,
                     temps_present_minutes, temps_pause_minutes, nb_pauses, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $employeId, $appareilId, $date, $resume['entree'], $resume['sortie'], 'empreinte',
                $retard, $retardDej, $tempsManq,
                $resume['present'], $resume['pause'], $resume['nb_pauses'], $statut,
            ]);
        } else {
            // Met à jour la sortie ET le statut : 'parti' si dernier passage = sortie,
            // sinon retour à 'present' (en conservant 'retard' si l'arrivée était tardive).
            $db->prepare(
                "UPDATE pointage SET heure_sortie = ?, retard_dejeuner_minutes = ?,
                    temps_manquant_minutes = ?, temps_present_minutes = ?,
                    temps_pause_minutes = ?, nb_pauses = ?,
                    statut = CASE WHEN ? = 1 THEN 'parti'
                                  WHEN statut = 'retard' THEN 'retard'
                                  ELSE 'present' END
                 WHERE id = ?"
            )->execute([
                $resume['sortie'], $retardDej, $tempsManq, $resume['present'], $resume['pause'], $resume['nb_pauses'],
                $estParti ? 1 : 0, (int) $pid,
            ]);
        }

        // 5) Une SORTIE (pause OU départ) met à jour les heures sup. Le K40 NE
        // touche PAS au kiosque (PC) : les deux systèmes sont 100% indépendants
        // (le PC ne se verrouille que par inactivité ou déconnexion).
        if ($type === 'sortie') {
            self::enregistrerHeuresSup($db, $employeId, $date, $resume['sortie'], $horaire);
        }

            if ($gereTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            // Échec d'écriture : on annule TOUT (passage + résumé). Le device n'étant
            // jamais purgé, le punch sera relu et re-traité à la prochaine synchro.
            if ($gereTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return 'traite';
    }

    /**
     * État K40 d'un jour pour le filtrage : [aArrivee, aSortie].
     *  - aArrivee : au moins un passage 'entree' existe ce jour ;
     *  - aSortie  : le DERNIER passage du jour est une 'sortie' (la personne est
     *               actuellement repartie). Sert à décider, pour un punch >= fin, si
     *               on l'enregistre comme départ ou si on l'ignore (déjà parti).
     *
     * @return array{0:bool,1:bool}
     */
    private static function etatJourK40(PDO $db, int $employeId, string $date): array
    {
        $stmt = $db->prepare(
            'SELECT type FROM pointage_passage WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$employeId, $date]);
        $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($types === []) {
            return [false, false];
        }
        $aArrivee = in_array('entree', $types, true);
        $aSortie = ($types[count($types) - 1] === 'sortie');

        return [$aArrivee, $aSortie];
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

    /**
     * UUID déterministe du JOURNAL BRUT, basé sur (device_user_id + horodatage) —
     * INDÉPENDANT de la source. Le MÊME punch physique reçu par pull ET par push
     * produit le même uuid -> une seule ligne brute (dédup correcte entre chemins).
     */
    private static function brutUuid(string $deviceUserId, string $ts): string
    {
        $t = strtotime($ts);
        $norm = $t !== false ? date('Y-m-d H:i:s', $t) : $ts;
        $h = md5('brut|' . $deviceUserId . '|' . $norm);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12)
        );
    }

    /** Écrit le punch au journal brut (append-only, idempotent via uq_pb_client_uuid). */
    private static function insererBrut(PDO $db, string $deviceUserId, string $ts, string $uuid): void
    {
        $t = strtotime($ts);
        $norm = $t !== false ? date('Y-m-d H:i:s', $t) : $ts;
        $db->prepare(
            'INSERT IGNORE INTO k40_punch_brut (device_user_id, horodatage, source, client_uuid)
             VALUES (?, ?, ?, ?)'
        )->execute([$deviceUserId, $norm, 'k40', $uuid]);
    }

    /** Annote le punch brut (employé résolu + décision) — observabilité et reprise. */
    private static function majBrut(PDO $db, string $uuid, ?int $employeId, string $decision): void
    {
        $db->prepare(
            'UPDATE k40_punch_brut SET employe_id = ?, decision = ? WHERE client_uuid = ?'
        )->execute([$employeId, $decision, $uuid]);
    }

    /**
     * Journalise EN BLOC tout un lot de punchs bruts (pull), AVANT tout filtrage par
     * curseur. Garantit qu'un punch que le curseur sauterait (ex. horloge K40 reculée
     * -> ts < curseur, ou tout autre saut) reste DURABLEMENT enregistré et rejouable.
     * Idempotent (INSERT IGNORE sur client_uuid). Inséré par paquets de 500.
     *
     * @param array<int,array{id?:mixed,timestamp?:mixed}> $logs
     */
    public static function journaliserLot(PDO $db, array $logs): void
    {
        $batch = [];
        foreach ($logs as $log) {
            $dev = (string) ($log['id'] ?? '');
            $ts  = (string) ($log['timestamp'] ?? '');
            if ($dev === '' || $ts === '') {
                continue;
            }
            $t = strtotime($ts);
            $norm = $t !== false ? date('Y-m-d H:i:s', $t) : $ts;
            $batch[] = [$dev, $norm, 'k40', self::brutUuid($dev, $ts)];
        }
        foreach (array_chunk($batch, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
            }
            $db->prepare(
                "INSERT IGNORE INTO k40_punch_brut (device_user_id, horodatage, source, client_uuid) VALUES {$ph}"
            )->execute($params);
        }
    }
}
