<?php
declare(strict_types=1);

/**
 * Règles de présence (horaires de travail, pause déjeuner, heures sup).
 * Lit le .env via Env::load.
 *
 * Présence comptée de 08:30 à 18:00 ; retard si arrivée après 08:30 ;
 * pause déjeuner 12:30–13:30 (1h, NON comptée, exclue du temps de présence) ;
 * tout travail après 18:00 = heures supplémentaires.
 */

use MadMen\Core\Env;

require_once dirname(__DIR__) . '/src/Core/Env.php';

$env = Env::load();

return [
    // Début de la fenêtre de présence : au-delà à l'arrivée => retard.
    'debut'          => $env['PRESENCE_DEBUT'] ?? '08:30',
    // Fin de la fenêtre de présence : au-delà au départ => heures sup.
    'fin'            => $env['PRESENCE_FIN'] ?? '18:00',
    // Pause déjeuner : exclue du temps de présence, ni absence ni retard.
    'dejeuner_debut' => $env['DEJEUNER_DEBUT'] ?? '12:30',
    'dejeuner_fin'   => $env['DEJEUNER_FIN'] ?? '13:30',
];
