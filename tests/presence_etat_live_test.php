<?php
declare(strict_types=1);

/**
 * Test AUTONOME de Presence::etatLive (règle « En activité / En pause / Parti / Absent »).
 * Lancer : php tests/presence_etat_live_test.php
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

// Fenêtre de pause déjeuner 12:30–14:00 (config réelle MADMEN).
$h = ['debut' => '08:30', 'fin' => '18:00', 'dejeuner_debut' => '12:30', 'dejeuner_fin' => '14:00', 'tolerance' => 0];
$d = '2026-06-25 '; // jour de référence

echo "== Presence::etatLive ==\n";

// Congé : prioritaire (même sans pointage).
check('conge -> conge', Presence::etatLive('conge', false, false, $d . '13:00:00', $h), 'conge');

// Absent : aucune entrée pointée.
check('jamais pointe -> absent', Presence::etatLive('absent', false, false, $d . '10:00:00', $h), 'absent');

// Au bureau (dernier passage = entrée) : on garde present/retard.
check('au bureau, present -> present', Presence::etatLive('present', true, true, $d . '10:00:00', $h), 'present');
check('au bureau, retard -> retard', Presence::etatLive('retard', true, true, $d . '10:00:00', $h), 'retard');
check('au bureau a l heure de pause -> present (pas sorti)', Presence::etatLive('present', true, true, $d . '13:00:00', $h), 'present');

// Ressorti PENDANT la pause -> en pause (bornes incluses).
check('sorti 13:00 -> pause', Presence::etatLive('present', true, false, $d . '13:00:00', $h), 'pause');
check('sorti 12:30 (debut) -> pause', Presence::etatLive('present', true, false, $d . '12:30:00', $h), 'pause');
check('sorti 14:00 (fin) -> pause', Presence::etatLive('present', true, false, $d . '14:00:00', $h), 'pause');

// Ressorti HORS de la pause -> parti.
check('sorti 11:00 (avant pause) -> parti', Presence::etatLive('present', true, false, $d . '11:00:00', $h), 'parti');
check('sorti 14:01 (apres pause) -> parti', Presence::etatLive('present', true, false, $d . '14:01:00', $h), 'parti');
check('sorti 17:30 (fin de journee) -> parti', Presence::etatLive('present', true, false, $d . '17:30:00', $h), 'parti');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
