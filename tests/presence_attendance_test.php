<?php
declare(strict_types=1);

/**
 * Test AUTONOME (composer/PHPUnit indisponible dans cet environnement) des deux
 * nouvelles règles de présence. Lancer : php tests/presence_attendance_test.php
 * Sortie : liste OK/FAIL + code retour 0 (tout vert) ou 1 (au moins un échec).
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

/** @return array{type:string,horodatage:string} */
function pp(string $type, string $time): array
{
    return ['type' => $type, 'horodatage' => '2026-06-25 ' . $time];
}

// 08:00–18:00, déjeuner 12:30–14:00 → prévu net 8h30 = 510 min.
$h = ['debut' => '08:00', 'fin' => '18:00', 'dejeuner_debut' => '12:30', 'dejeuner_fin' => '14:00', 'tolerance' => 0];
$hNoLunch = ['debut' => '08:00', 'fin' => '12:00', 'dejeuner_debut' => null, 'dejeuner_fin' => null];

echo "== tempsManquant ==\n";
check('full day worked 510 -> 0', Presence::tempsManquant(510, $h), 0);
check('worked 270 (4h30) -> 240 (4h00)', Presence::tempsManquant(270, $h), 240);
check('worked 600 -> 0 (clamp)', Presence::tempsManquant(600, $h), 0);
check('no lunch: prevu 240, worked 180 -> 60', Presence::tempsManquant(180, $hNoLunch), 60);

echo "== retardRetourDejeuner ==\n";
check('back 13:55 -> 0', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:40:00'), pp('entree', '13:55:00')], $h), 0);
check('back 14:00 -> 0 (on time)', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:40:00'), pp('entree', '14:00:00')], $h), 0);
check('back 14:01 -> 1', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:40:00'), pp('entree', '14:01:00')], $h), 1);
check('back 14:05 -> 5', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:40:00'), pp('entree', '14:05:00')], $h), 5);
check('left 12:50 back 14:05 -> 5 (deadline not extended)', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:50:00'), pp('entree', '14:05:00')], $h), 5);
check('worked through (no lunch sortie) -> 0', Presence::retardRetourDejeuner([pp('entree', '08:00:00')], $h), 0);
check('never returned -> 0', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:40:00')], $h), 0);
check('no lunch window -> 0', Presence::retardRetourDejeuner([pp('entree', '08:00:00'), pp('sortie', '12:40:00'), pp('entree', '14:05:00')], $hNoLunch), 0);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
