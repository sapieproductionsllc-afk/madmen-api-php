<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Moteur de calcul de la paie mensuelle à partir des pointages et de l'horaire
 * de chaque employé. Fonctions PURES (sans accès base) pour être testables.
 *
 * Décisions métier (validées) :
 *  - Temps travaillé = BRUT (sortie - entrée), pause déjeuner incluse.
 *  - Temps journalier théorique = BRUT (départ prévu - arrivée prévue).
 *  - Retard déduit = uniquement au-delà de la tolérance (déjà calculé dans
 *    pointage.retard_minutes par Presence::retardMinutes).
 *  - Jours travaillés du mois = jours ouvrés RÉELS du calendrier (selon les
 *    jours travaillés de l'employé), pas un nombre fixe.
 *  - Heures sup d'un jour = max(0, temps travaillé - temps journalier théorique).
 */
final class Paie
{
    /** Secondes de travail théoriques par jour (brut : départ - arrivée). */
    public static function tempsJournalierSecondes(array $horaire): int
    {
        $debut = strtotime('1970-01-01 ' . $horaire['debut']);
        $fin = strtotime('1970-01-01 ' . $horaire['fin']);

        return max(0, $fin - $debut);
    }

    /**
     * Nombre de jours travaillés (jour ISO ∈ $jours, ex. "1,2,3,4,5") dans
     * l'intervalle [dateDebut, dateFin] inclus.
     */
    public static function compterJoursTravailles(string $dateDebut, string $dateFin, string $jours): int
    {
        $set = array_filter(array_map('trim', explode(',', $jours)), 'strlen');
        if ($set === []) {
            $set = ['1', '2', '3', '4', '5', '6', '7'];
        }
        $cur = strtotime($dateDebut);
        $end = strtotime($dateFin);
        $n = 0;
        while ($cur <= $end) {
            if (in_array(date('N', $cur), $set, true)) {
                $n++;
            }
            $cur = strtotime('+1 day', $cur);
        }

        return $n;
    }

    /** Vrai si $date (Y-m-d) est un jour travaillé selon $jours. */
    public static function estJourTravaille(string $date, string $jours): bool
    {
        $set = array_filter(array_map('trim', explode(',', $jours)), 'strlen');
        if ($set === []) {
            return true;
        }

        return in_array(date('N', strtotime($date)), $set, true);
    }

    /** Valeur d'UNE seconde de travail = salaire mensuel / temps théorique mensuel (s). */
    public static function valeurSeconde(float $salaireMensuel, int $tempsTheoriqueMensuelSec): float
    {
        if ($tempsTheoriqueMensuelSec <= 0) {
            return 0.0;
        }

        return $salaireMensuel / $tempsTheoriqueMensuelSec;
    }

    /** Temps travaillé d'un jour en secondes (brut). 0 si entrée ou sortie absente. */
    public static function tempsTravailleSecondes(?string $entree, ?string $sortie): int
    {
        if (empty($entree) || empty($sortie)) {
            return 0;
        }

        return max(0, strtotime($sortie) - strtotime($entree));
    }

    /** Heures sup d'un jour (s) = ce qui dépasse le temps journalier théorique. */
    public static function heuresSupSecondes(int $tempsTravailleSec, int $tempsJournalierSec): int
    {
        return max(0, $tempsTravailleSec - $tempsJournalierSec);
    }

    /** Formate des secondes en « 9h57min » (lisible dans les rapports). */
    public static function formatHM(int $secondes): string
    {
        $secondes = max(0, $secondes);
        $h = intdiv($secondes, 3600);
        $m = intdiv($secondes % 3600, 60);

        return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'min';
    }
}
