<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Règles métier de présence : retard, pause déjeuner, temps de présence
 * effectif et heures supplémentaires.
 *
 * Fenêtre de présence 08:30–18:00 ; retard si arrivée après 08:30 ; pause
 * déjeuner 12:30–13:30 (1h non comptée, exclue du temps de présence) ; tout
 * travail après 18:00 = heures supplémentaires.
 */
final class Presence
{
    public static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/presence.php';
    }

    /**
     * Minutes de retard : durée entre le début de présence (08:30) du jour de
     * $ts et $ts si l'arrivée est postérieure ; 0 si à l'heure ou en avance.
     */
    public static function retardMinutes(string $ts): int
    {
        $cfg = self::config();
        $arrivee = strtotime($ts);
        $debut = strtotime(date('Y-m-d', $arrivee) . ' ' . $cfg['debut']);

        if ($arrivee <= $debut) {
            return 0;
        }

        return (int) (($arrivee - $debut) / 60);
    }

    /**
     * Vrai si l'heure de $ts tombe dans la pause déjeuner [debut, fin].
     */
    public static function estPauseDejeuner(string $ts): bool
    {
        $cfg = self::config();
        $instant = strtotime($ts);
        $jour = date('Y-m-d', $instant);
        $debut = strtotime($jour . ' ' . $cfg['dejeuner_debut']);
        $fin = strtotime($jour . ' ' . $cfg['dejeuner_fin']);

        return $instant >= $debut && $instant <= $fin;
    }

    /**
     * Temps de présence effectif en minutes : durée bornée à la fenêtre
     * [debut, fin] du jour, moins le chevauchement avec la pause déjeuner.
     */
    public static function presenceMinutes(string $entree, string $sortie): int
    {
        $cfg = self::config();
        $tsEntree = strtotime($entree);
        $tsSortie = strtotime($sortie);
        $jour = date('Y-m-d', $tsEntree);

        $debut = strtotime($jour . ' ' . $cfg['debut']);
        $fin = strtotime($jour . ' ' . $cfg['fin']);

        // Borne l'intervalle à la fenêtre de présence.
        $deb = max($tsEntree, $debut);
        $sor = min($tsSortie, $fin);

        if ($sor <= $deb) {
            return 0;
        }

        $minutes = (int) (($sor - $deb) / 60);

        // Retire le chevauchement avec la pause déjeuner.
        $dejDebut = strtotime($jour . ' ' . $cfg['dejeuner_debut']);
        $dejFin = strtotime($jour . ' ' . $cfg['dejeuner_fin']);
        $chevDeb = max($deb, $dejDebut);
        $chevFin = min($sor, $dejFin);
        if ($chevFin > $chevDeb) {
            $minutes -= (int) (($chevFin - $chevDeb) / 60);
        }

        return $minutes;
    }

    /**
     * Minutes d'heures supplémentaires : durée entre la fin de présence
     * (18:00) du jour de $sortie et $sortie si le départ est postérieur ;
     * 0 sinon.
     */
    public static function heuresSupMinutes(string $sortie): int
    {
        $cfg = self::config();
        $tsSortie = strtotime($sortie);
        $fin = strtotime(date('Y-m-d', $tsSortie) . ' ' . $cfg['fin']);

        if ($tsSortie <= $fin) {
            return 0;
        }

        return (int) (($tsSortie - $fin) / 60);
    }
}
