<?php
declare(strict_types=1);

namespace MadMen\Core;

use PDO;

/**
 * Règles métier de présence : retard, pause déjeuner, temps de présence effectif,
 * heures supplémentaires et jours travaillés.
 *
 * LIMITE : horaires de MÊME JOUR uniquement (heure_depart > heure_arrivee). Les
 * horaires de nuit traversant minuit (fin <= début) NE sont PAS supportés ; ils
 * sont d'ailleurs refusés à la configuration (HoraireController::upsert).
 *
 * Horaire PAR EMPLOYÉ (table horaire_employe) avec repli sur l'horaire GLOBAL
 * (config/presence.php) quand l'employé n'a pas d'horaire défini. Toutes les
 * méthodes de calcul acceptent un horaire ($h) ; null => horaire global par défaut.
 *
 * Forme d'un horaire ($h) :
 *   [ 'debut'=>'08:30', 'fin'=>'18:00', 'dejeuner_debut'=>'12:30'|null,
 *     'dejeuner_fin'=>'13:30'|null, 'tolerance'=>0, 'jours'=>'1,2,3,4,5' ]
 */
final class Presence
{
    public static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/presence.php';
    }

    /**
     * Vrai si l'employé est ACTUELLEMENT au bureau (pointé présent) à l'instant $ts :
     * son DERNIER passage K40 du jour (horodatage <= $ts) est une « entrée » — donc
     * il est entré et pas encore ressorti. Sert à n'autoriser l'ouverture d'un poste
     * qu'aux personnes physiquement présentes (pointage K40 d'arrivée requis).
     */
    public static function presentAt(PDO $db, int $employeId, string $ts): bool
    {
        $jour = substr($ts, 0, 10); // AAAA-MM-JJ
        $stmt = $db->prepare(
            'SELECT type FROM pointage_passage
             WHERE employe_id = ? AND date = ? AND horodatage <= ?
             ORDER BY horodatage DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$employeId, $jour, $ts]);

        return $stmt->fetchColumn() === 'entree';
    }

    /** Avance (minutes) par défaut quand l'employé n'a pas d'horaire défini. */
    public const AVANCE_DEFAUT = 30;

    /** Horaire global par défaut (config + tolérance 0 + lundi→vendredi). */
    public static function defaultHoraire(): array
    {
        $cfg = self::config();

        return [
            'debut'          => $cfg['debut'] ?? '08:30',
            'fin'            => $cfg['fin'] ?? '18:00',
            'dejeuner_debut' => $cfg['dejeuner_debut'] ?? '12:30',
            'dejeuner_fin'   => $cfg['dejeuner_fin'] ?? '13:30',
            'tolerance'      => 0,
            // Avance autorisée avant l'heure d'arrivée (pendant « en avance » de la
            // tolérance de retard). Utilisée pour borner la fenêtre des punchs K40.
            'avance'         => self::AVANCE_DEFAUT,
            'jours'          => '1,2,3,4,5',
        ];
    }

    /**
     * Horaire effectif d'un employé : sa ligne horaire_employe si elle existe,
     * sinon l'horaire global par défaut.
     */
    public static function horaire(PDO $db, int $employeId): array
    {
        $stmt = $db->prepare(
            'SELECT heure_arrivee, heure_depart, pause_debut, pause_fin,
                    tolerance_minutes, avance_minutes, jours_travailles
             FROM horaire_employe WHERE employe_id = ?'
        );
        $stmt->execute([$employeId]);
        $row = $stmt->fetch();
        if (!$row) {
            return self::defaultHoraire();
        }

        return [
            'debut'          => substr((string) $row['heure_arrivee'], 0, 5),
            'fin'            => substr((string) $row['heure_depart'], 0, 5),
            'dejeuner_debut' => $row['pause_debut'] !== null ? substr((string) $row['pause_debut'], 0, 5) : null,
            'dejeuner_fin'   => $row['pause_fin'] !== null ? substr((string) $row['pause_fin'], 0, 5) : null,
            'tolerance'      => (int) $row['tolerance_minutes'],
            'avance'         => (int) $row['avance_minutes'],
            'jours'          => (string) $row['jours_travailles'],
        ];
    }

    /**
     * Emploi du temps PAR JOUR de l'employé, normalisé : retourne
     *   [ 'jours' => [ 1 => ['debut'=>'08:00','fin'=>'18:00'], ..., 6 => [...] ],
     *     'tolerance' => 0 ]
     * où la clé jour = ISO (1=lundi..7=dimanche). Un jour absent = repos.
     * Source : colonne `planning` (JSON) si présente ; sinon repli sur l'horaire
     * unique (heure_arrivee/heure_depart répliqué sur jours_travailles).
     */
    public static function planning(PDO $db, int $employeId): array
    {
        $stmt = $db->prepare(
            'SELECT heure_arrivee, heure_depart, tolerance_minutes, avance_minutes, jours_travailles, planning
             FROM horaire_employe WHERE employe_id = ?'
        );
        $stmt->execute([$employeId]);
        $row = $stmt->fetch();

        // 1) Planning par jour (JSON) prioritaire.
        if ($row && !empty($row['planning'])) {
            $plan = json_decode((string) $row['planning'], true);
            $jours = [];
            if (is_array($plan)) {
                foreach ($plan as $k => $v) {
                    $j = (int) $k;
                    if ($j < 1 || $j > 7 || !is_array($v)) {
                        continue;
                    }
                    // Défense en profondeur : on re-valide le format HH:MM lu en base
                    // (même si l'écriture est déjà validée) pour ne jamais alimenter
                    // les calculs avec une heure corrompue (sinon paie faussée).
                    $debut = isset($v['debut']) ? substr((string) $v['debut'], 0, 5) : '';
                    $fin = isset($v['fin']) ? substr((string) $v['fin'], 0, 5) : '';
                    $re = '/^([01]\d|2[0-3]):([0-5]\d)$/';
                    if (preg_match($re, $debut) === 1 && preg_match($re, $fin) === 1 && $fin > $debut) {
                        $jours[$j] = ['debut' => $debut, 'fin' => $fin];
                    } else {
                        error_log("horaire_employe.planning invalide (employe $employeId, jour $j) : ignoré");
                    }
                }
            }

            return [
                'jours'     => $jours,
                'tolerance' => (int) $row['tolerance_minutes'],
                'avance'    => (int) $row['avance_minutes'],
            ];
        }

        // 2) Repli : horaire unique répliqué sur chaque jour travaillé.
        $h = self::horaire($db, $employeId);
        $liste = array_filter(array_map('trim', explode(',', (string) $h['jours'])), 'strlen');
        if ($liste === []) {
            $liste = ['1', '2', '3', '4', '5', '6', '7'];
        }
        $jours = [];
        foreach ($liste as $j) {
            $jours[(int) $j] = ['debut' => $h['debut'], 'fin' => $h['fin']];
        }

        return [
            'jours'     => $jours,
            'tolerance' => (int) $h['tolerance'],
            'avance'    => (int) ($h['avance'] ?? self::AVANCE_DEFAUT),
        ];
    }

    /**
     * Fenêtre de travail prévue pour une DATE donnée selon le planning, ou null si
     * c'est un jour de repos (non saisi). Forme : ['debut','fin','tolerance','avance'].
     * 'avance' = minutes autorisées AVANT 'debut' pour pointer son arrivée (K40).
     */
    public static function fenetreJour(array $planning, string $date): ?array
    {
        $j = (int) date('N', strtotime($date));
        if (!isset($planning['jours'][$j])) {
            return null; // repos
        }

        return $planning['jours'][$j] + [
            'tolerance' => $planning['tolerance'] ?? 0,
            'avance'    => $planning['avance'] ?? self::AVANCE_DEFAUT,
        ];
    }

    /**
     * Instant (timestamp Unix) borne BASSE d'acceptation d'un punch K40 pour la
     * fenêtre d'un jour : (debut - avance_minutes). Avant cet instant, le punch est
     * « trop tôt » et doit être ignoré.
     */
    public static function debutAvecAvance(string $date, array $fenetre): int
    {
        $debut = strtotime($date . ' ' . $fenetre['debut']);

        return $debut - ((int) ($fenetre['avance'] ?? self::AVANCE_DEFAUT)) * 60;
    }

    /** Instant (timestamp Unix) de FIN prévue du jour pour une fenêtre. */
    public static function finJour(string $date, array $fenetre): int
    {
        return strtotime($date . ' ' . $fenetre['fin']);
    }

    /**
     * Vrai si l'instant $ts est AVANT (debut - avance_minutes) de sa fenêtre de jour :
     * l'employé pointe trop en avance -> le punch K40 doit être ignoré.
     */
    public static function estTropTot(string $ts, array $fenetre): bool
    {
        $t = strtotime($ts);

        return $t < self::debutAvecAvance(date('Y-m-d', $t), $fenetre);
    }

    /**
     * Vrai si l'instant $ts est À/APRÈS l'heure de FIN prévue du jour : la personne
     * est censée être partie. Un punch K40 à ce moment vaut un DÉPART (ou est ignoré
     * s'il n'y a pas d'arrivée / si elle est déjà partie).
     */
    public static function estApresFin(string $ts, array $fenetre): bool
    {
        return strtotime($ts) >= self::finJour(date('Y-m-d', strtotime($ts)), $fenetre);
    }

    /**
     * AUTO-PARTI (à l'AFFICHAGE seulement, sans pointage de sortie) : un employé
     * dont le statut du jour est 'present'/'retard' et dont l'heure de fin prévue du
     * jour est dépassée (maintenant >= fin) est considéré « parti ».
     *
     * @param string      $statut Statut du jour issu de la table pointage.
     * @param array|null  $fenetre Fenêtre du jour (Presence::fenetreJour) ; null = repos.
     * @param string|null $now    Instant de référence (défaut : maintenant).
     */
    public static function estAutoParti(string $statut, ?array $fenetre, ?string $now = null): bool
    {
        // POLITIQUE (juin 2026) : on NE bascule PLUS automatiquement en « parti » à l'heure
        // de fin prévue. Un employé reste PRÉSENT tant qu'il n'a pas POINTÉ SA SORTIE (un
        // pointage de départ met déjà statut='parti' dans K40Pointage). Le marquer « parti »
        // alors qu'il est encore au travail (il n'a juste pas encore pointé) n'a aucun sens.
        // Le DÉCOMPTE du temps de travail, lui, reste borné à la fenêtre par presenceMinutes
        // (on arrête de compter à l'heure de fin, sans heures sup automatiques).
        unset($statut, $fenetre, $now); // conservés pour compat. d'appel ; logique désactivée
        return false;
    }

    /**
     * Retard (minutes) vs une FENÊTRE de jour donnée (déjà résolue : le jour est
     * travaillé). Compte uniquement au-delà de la tolérance. Voir retardMinutes.
     */
    public static function retardDansFenetre(string $ts, array $fenetre): int
    {
        $arrivee = strtotime($ts);
        $debut = strtotime(date('Y-m-d', $arrivee) . ' ' . $fenetre['debut']);
        // Plancher de grâce système (5 min) ; une tolérance d'horaire plus large prime.
        $grace = $debut + max((int) ($fenetre['tolerance'] ?? 0), self::GRACE_MINUTES) * 60;

        return $arrivee <= $grace ? 0 : (int) (($arrivee - $grace) / 60);
    }

    /** Vrai si la date de $ts est un jour travaillé selon l'horaire. */
    public static function estJourTravaille(string $ts, ?array $h = null): bool
    {
        $h = $h ?? self::defaultHoraire();
        $jours = array_filter(array_map('trim', explode(',', (string) ($h['jours'] ?? '1,2,3,4,5'))), 'strlen');
        if ($jours === []) {
            return true; // aucune restriction => tous les jours
        }

        return in_array(date('N', strtotime($ts)), $jours, true);
    }

    /** Grâce de ponctualité MINIMALE appliquée à tout le système (kiosque, dashboard,
     *  pointage K40, paie, rapports) : une arrivée en retard de <= 5 min compte « à
     *  l'heure ». Une tolérance d'horaire SUPÉRIEURE prime (5 min = plancher). */
    public const GRACE_MINUTES = 5;

    /**
     * Minutes de retard. Règle : le retard ne compte QUE ce qui dépasse la marge
     * de tolérance. Tant que l'arrivée est dans (début + tolérance), retard = 0 ;
     * au-delà, retard = minutes écoulées depuis la FIN de la tolérance (pas de saut
     * brutal). Retard = 0 aussi en avance ou si le jour n'est pas travaillé.
     * La marge est d'au moins GRACE_MINUTES (5) min, partout dans le système.
     * Ex. début 08:30, tolérance 15 : arrivée 08:46 -> 1 min ; 09:00 -> 15 min.
     */
    public static function retardMinutes(string $ts, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        if (!self::estJourTravaille($ts, $h)) {
            return 0;
        }
        $arrivee = strtotime($ts);
        $debut = strtotime(date('Y-m-d', $arrivee) . ' ' . $h['debut']);
        // Plancher de grâce système (5 min) ; une tolérance d'horaire plus large prime.
        $grace = $debut + max((int) ($h['tolerance'] ?? 0), self::GRACE_MINUTES) * 60;

        if ($arrivee <= $grace) {
            return 0;
        }

        return (int) (($arrivee - $grace) / 60);
    }

    /** Vrai si l'heure de $ts tombe dans la pause déjeuner [debut, fin] de l'horaire. */
    public static function estPauseDejeuner(string $ts, ?array $h = null): bool
    {
        $h = $h ?? self::defaultHoraire();
        if (empty($h['dejeuner_debut']) || empty($h['dejeuner_fin'])) {
            return false;
        }
        $instant = strtotime($ts);
        $jour = date('Y-m-d', $instant);

        return $instant >= strtotime($jour . ' ' . $h['dejeuner_debut'])
            && $instant <= strtotime($jour . ' ' . $h['dejeuner_fin']);
    }

    /**
     * Tolérance VISUELLE (minutes) après la fin de pause avant de basculer le libellé
     * « pas_revenu_pause » -> « jamais_revenu_pause » sur le dashboard. PUREMENT cosmétique :
     * n'affecte ni le retard du rapport ni la paie (qui restent stricts). Défaut 30.
     */
    public static function graceRetourPause(): int
    {
        $g = (int) (self::config()['grace_retour_pause'] ?? 30);

        return $g > 0 ? $g : 30;
    }

    /**
     * État LIVE d'un agent pour le tableau « Agents présents aujourd'hui » (temps réel).
     * Libellés de SUIVI (visuels) — la logique stricte (retard, paie) est calculée ailleurs.
     *
     * Au bureau => present/retard. Jamais pointé => absent. Sinon (ressorti) :
     *   - si la DERNIÈRE sortie n'était PAS pendant la pause déjeuner (départ anticipé ou de
     *     fin de journée) => 'parti' ;
     *   - si elle était pendant la pause et qu'il n'est pas revenu, progression selon l'heure :
     *       pendant la pause                         => 'pause'
     *       fin de pause .. +grace (def. 30 min)     => 'pas_revenu_pause'
     *       au-delà de la grace, avant la fin de jour=> 'jamais_revenu_pause'
     *       après l'heure de fin prévue              => 'parti'
     *
     * @param string      $statutJour        statut du jour (table pointage) : present|retard|parti|absent|conge
     * @param bool        $aPointe           a une entrée pointée aujourd'hui (heure_entree non nulle)
     * @param bool        $presentMaintenant son DERNIER passage du jour est une « entrée » (actuellement au bureau)
     * @param string      $now               instant d'évaluation 'AAAA-MM-JJ HH:MM:SS'
     * @param array|null  $h                 horaire de l'employé (fenêtres pause + fin de jour) ; null => global
     * @param string|null $sortieTs          horodatage de la dernière sortie ('AAAA-MM-JJ HH:MM:SS') si ressorti
     * @return string conge|absent|present|retard|pause|pas_revenu_pause|jamais_revenu_pause|parti
     */
    public static function etatLive(
        string $statutJour,
        bool $aPointe,
        bool $presentMaintenant,
        string $now,
        ?array $h = null,
        ?string $sortieTs = null
    ): string {
        if ($statutJour === 'conge') {
            return 'conge';
        }
        if (!$aPointe) {
            return 'absent';
        }
        if ($presentMaintenant) {
            // Au bureau : on conserve present/retard ; tout statut résiduel => present.
            return in_array($statutJour, ['present', 'retard'], true) ? $statutJour : 'present';
        }

        // Ressorti. Si la dernière sortie n'était pas pendant la pause déjeuner (départ
        // anticipé avant la pause, ou départ après être revenu), c'est un simple « parti ».
        if ($sortieTs === null || !self::estPauseDejeuner($sortieTs, $h)) {
            return 'parti';
        }

        // Sortie pendant la pause, pas (encore) revenu : progression visuelle selon l'heure.
        $h = $h ?? self::defaultHoraire();
        $jour     = substr($now, 0, 10);
        $tsNow    = strtotime($now);
        $finPause = strtotime($jour . ' ' . ($h['dejeuner_fin'] ?? '14:00'));
        $finGrace = $finPause + self::graceRetourPause() * 60;
        $finJour  = strtotime($jour . ' ' . ($h['fin'] ?? '18:00'));

        if ($tsNow <= $finPause) {
            return 'pause';
        }
        if ($tsNow <= $finGrace) {
            return 'pas_revenu_pause';
        }
        if ($tsNow < $finJour) {
            return 'jamais_revenu_pause';
        }

        return 'parti';
    }

    /**
     * Temps de présence effectif en minutes : durée bornée à [debut, fin] de
     * l'horaire, moins le chevauchement avec la pause déjeuner.
     */
    public static function presenceMinutes(string $entree, string $sortie, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        $tsEntree = strtotime($entree);
        $tsSortie = strtotime($sortie);
        $jour = date('Y-m-d', $tsEntree);

        $deb = max($tsEntree, strtotime($jour . ' ' . $h['debut']));
        $sor = min($tsSortie, strtotime($jour . ' ' . $h['fin']));
        if ($sor <= $deb) {
            return 0;
        }
        $minutes = (int) (($sor - $deb) / 60);

        if (!empty($h['dejeuner_debut']) && !empty($h['dejeuner_fin'])) {
            $chevDeb = max($deb, strtotime($jour . ' ' . $h['dejeuner_debut']));
            $chevFin = min($sor, strtotime($jour . ' ' . $h['dejeuner_fin']));
            if ($chevFin > $chevDeb) {
                $minutes -= (int) (($chevFin - $chevDeb) / 60);
            }
        }

        return max(0, $minutes);
    }

    /**
     * Minutes d'heures supplémentaires : durée entre l'heure de fin prévue de
     * l'horaire et $sortie si le départ est postérieur ; 0 sinon.
     */
    public static function heuresSupMinutes(string $sortie, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        $tsSortie = strtotime($sortie);
        $fin = strtotime(date('Y-m-d', $tsSortie) . ' ' . $h['fin']);

        if ($tsSortie <= $fin) {
            return 0;
        }

        return (int) (($tsSortie - $fin) / 60);
    }

    /**
     * Temps de travail MANQUANT (minutes) sur la journée : ce que l'employé était
     * censé travailler (durée prévue − pause déjeuner) MOINS ce qu'il a réellement
     * travaillé ; 0 si à jour ou au-delà. Base début/fin = celle de presenceMinutes
     * (pour que travaillé + manquant se réconcilient). C'est un solde de FIN DE JOURNÉE.
     */
    public static function tempsManquant(int $presentMinutes, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        $debut = (int) strtotime('2000-01-01 ' . $h['debut']);
        $fin   = (int) strtotime('2000-01-01 ' . $h['fin']);
        $spanMin = max(0, (int) (($fin - $debut) / 60));

        $lunchMin = 0;
        if (!empty($h['dejeuner_debut']) && !empty($h['dejeuner_fin'])) {
            $ld = (int) strtotime('2000-01-01 ' . $h['dejeuner_debut']);
            $lf = (int) strtotime('2000-01-01 ' . $h['dejeuner_fin']);
            $lunchMin = max(0, (int) (($lf - $ld) / 60));
        }

        $prevuNet = max(0, $spanMin - $lunchMin);

        return max(0, $prevuNet - $presentMinutes);
    }

    /**
     * Retard de RETOUR de pause déjeuner (minutes). La pause est une fenêtre FIXE
     * [dejeuner_debut, dejeuner_fin] : quelle que soit l'heure de DÉPART, il faut être
     * repointé À/AVANT dejeuner_fin. Si, à dejeuner_fin, le dernier passage est une
     * SORTIE (l'employé est dehors) et qu'il repointe une ENTRÉE après dejeuner_fin,
     * retard = floor((retour − dejeuner_fin)/60). Sinon 0 (à l'heure ; traversée sans
     * pause ; jamais revenu — ce dernier cas relève de tempsManquant).
     *
     * @param array<int,array{type:string,horodatage:string}> $passages triés ascendant
     */
    public static function retardRetourDejeuner(array $passages, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        if (empty($h['dejeuner_debut']) || empty($h['dejeuner_fin']) || $passages === []) {
            return 0;
        }
        $date  = substr((string) $passages[0]['horodatage'], 0, 10);
        $finTs = (int) strtotime($date . ' ' . $h['dejeuner_fin']);

        // Dernier passage à/avant dejeuner_fin : décide si l'employé est DEHORS à 14:00.
        $avant = null;
        foreach ($passages as $p) {
            if ((int) strtotime((string) $p['horodatage']) <= $finTs) {
                $avant = $p;
            }
        }
        if ($avant === null || $avant['type'] !== 'sortie') {
            return 0; // pas dehors à dejeuner_fin (pointé entrée = à l'heure / sans pause)
        }

        // Première ENTRÉE après dejeuner_fin = le retour tardif.
        foreach ($passages as $p) {
            if ($p['type'] === 'entree' && (int) strtotime((string) $p['horodatage']) > $finTs) {
                return (int) floor(((int) strtotime((string) $p['horodatage']) - $finTs) / 60);
            }
        }

        return 0; // jamais revenu
    }
}
