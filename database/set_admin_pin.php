<?php
declare(strict_types=1);

/**
 * Définit le code PIN du compte ADMIN-001 (défaut '1234', ou via env ADMIN_PIN).
 * Le hash bcrypt est généré ICI, côté serveur — jamais collé à la main, donc
 * aucune corruption possible. À lancer dans le conteneur api :
 *   docker compose -f deploy/docker-compose.yml --env-file .env exec -T api php database/set_admin_pin.php
 */
require __DIR__ . '/../vendor/autoload.php';

use MadMen\Core\Database;

$pin = getenv('ADMIN_PIN') ?: '1234';
$db  = Database::connection();

$stmt = $db->prepare("UPDATE employe SET code_pin_hash = ? WHERE matricule = 'ADMIN-001'");
$stmt->execute([password_hash($pin, PASSWORD_BCRYPT)]);

echo "PIN de ADMIN-001 défini à '{$pin}' — {$stmt->rowCount()} ligne(s) modifiée(s).\n";
