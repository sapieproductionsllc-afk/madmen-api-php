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
                    tolerance_minutes, jours_travailles
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
            'jours'          => (string) $row['jours_travailles'],
        ];
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

    /**
     * Minutes de retard. Règle : le retard ne compte QUE ce qui dépasse la marge
     * de tolérance. Tant que l'arrivée est dans (début + tolérance), retard = 0 ;
     * au-delà, retard = minutes écoulées depuis la FIN de la tolérance (pas de saut
     * brutal). Retard = 0 aussi en avance ou si le jour n'est pas travaillé.
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
        $grace = $debut + ((int) ($h['tolerance'] ?? 0)) * 60;

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
}
