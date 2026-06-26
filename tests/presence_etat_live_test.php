<?php
declare(strict_types=1);

/**
 * Test AUTONOME de Presence::etatLive (libellés de suivi du dashboard).
 * Lancer : php tests/presence_etat_live_test.php
 *
 * États : present/retard (au bureau), pause (sorti pendant la pause), pas_revenu_pause
 * (fin de pause -> +grace), jamais_revenu_pause (au-delà de la grace, avant la fin de jour),
 * parti (sortie hors pause OU après la fin de jour), absent (jamais pointé), conge.
 */

require __DIR__ . '/../vendor/autoload.php';

use MadMen\Core\Presence;

$pass = 0;
$fail = 0;

function check(string $label, $got, $expected): void
{
    global $pass, $fail;
    if ($got === $expected) {
        $pass++;
        echo "  OK   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label : got " . var_export($got, true) . " | expected " . var_export($expected, true) . "\n";
    }
}

// Horaire : 08:30–18:00, pause 12:30–14:00, grace défaut 30 min -> bascule à 14:30.
$h = ['debut' => '08:30', 'fin' => '18:00', 'dejeuner_debut' => '12:30', 'dejeuner_fin' => '14:00', 'tolerance' => 0];
$d = '2026-06-25 ';

echo "== Presence::etatLive (grace=" . Presence::graceRetourPause() . " min) ==\n";

// Congé / absent / au bureau.
check('conge -> conge', Presence::etatLive('conge', false, false, $d . '13:00:00', $h), 'conge');
check('jamais pointe -> absent', Presence::etatLive('absent', false, false, $d . '10:00:00', $h), 'absent');
check('au bureau, present -> present', Presence::etatLive('present', true, true, $d . '10:00:00', $h), 'present');
check('au bureau, retard -> retard', Presence::etatLive('retard', true, true, $d . '10:00:00', $h), 'retard');

// Sortie PENDANT la pause (13:00), pas revenu -> progression selon l'heure.
check('sortie 13:00, now 13:00 -> pause', Presence::etatLive('parti', true, false, $d . '13:00:00', $h, $d . '13:00:00'), 'pause');
check('sortie 13:00, now 14:00 (fin pause) -> pause', Presence::etatLive('parti', true, false, $d . '14:00:00', $h, $d . '13:00:00'), 'pause');
check('sortie 13:00, now 14:15 -> pas_revenu_pause', Presence::etatLive('parti', true, false, $d . '14:15:00', $h, $d . '13:00:00'), 'pas_revenu_pause');
check('sortie 13:00, now 14:30 (fin grace) -> pas_revenu_pause', Presence::etatLive('parti', true, false, $d . '14:30:00', $h, $d . '13:00:00'), 'pas_revenu_pause');
check('sortie 13:00, now 15:00 -> jamais_revenu_pause', Presence::etatLive('parti', true, false, $d . '15:00:00', $h, $d . '13:00:00'), 'jamais_revenu_pause');
check('sortie 13:00, now 17:59 -> jamais_revenu_pause', Presence::etatLive('parti', true, false, $d . '17:59:00', $h, $d . '13:00:00'), 'jamais_revenu_pause');
check('sortie 13:00, now 18:00 (fin de jour) -> parti', Presence::etatLive('parti', true, false, $d . '18:00:00', $h, $d . '13:00:00'), 'parti');
check('sortie 13:00, now 18:30 -> parti', Presence::etatLive('parti', true, false, $d . '18:30:00', $h, $d . '13:00:00'), 'parti');

// Sortie HORS pause -> simple parti (jamais "pas revenu").
check('sortie 11:00 (avant pause), now 11:30 -> parti', Presence::etatLive('parti', true, false, $d . '11:30:00', $h, $d . '11:00:00'), 'parti');
check('sortie 16:00 (apres pause), now 16:30 -> parti', Presence::etatLive('parti', true, false, $d . '16:30:00', $h, $d . '16:00:00'), 'parti');
check('ressorti sans horodatage -> parti', Presence::etatLive('parti', true, false, $d . '15:00:00', $h, null), 'parti');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
