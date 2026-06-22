<?php
declare(strict_types=1);

/**
 * Crée UN compte super_admin si aucun n'existe encore (idempotent).
 * Variables d'env optionnelles : ADMIN_MATRICULE, ADMIN_NOM, ADMIN_PRENOM, ADMIN_PIN.
 * Lancé pendant le déploiement (deploy/bootstrap.sh) ou à la main :
 *   docker compose -f deploy/docker-compose.yml --env-file .env exec -T api php database/creer_admin.php
 */
require __DIR__ . '/../vendor/autoload.php';

use MadMen\Core\Database;

$db = Database::connection();

$existe = (int) $db->query("SELECT COUNT(*) FROM employe WHERE role = 'super_admin'")->fetchColumn();
if ($existe > 0) {
    echo "Compte super_admin déjà présent — rien à créer.\n";
    exit(0);
}

$matricule = getenv('ADMIN_MATRICULE') ?: 'ADMIN-001';
$nom       = getenv('ADMIN_NOM') ?: 'Admin';
$prenom    = getenv('ADMIN_PRENOM') ?: 'Principal';
$pin       = getenv('ADMIN_PIN') ?: str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

$hash = password_hash($pin, PASSWORD_BCRYPT);
$db->prepare(
    "INSERT INTO employe (matricule, nom, prenom, code_pin_hash, statut, role)
     VALUES (?, ?, ?, ?, 'actif', 'super_admin')"
)->execute([$matricule, $nom, $prenom, $hash]);

echo "\n=================== COMPTE ADMIN CRÉÉ ===================\n";
echo "  matricule : {$matricule}\n";
echo "  nom       : {$prenom} {$nom}\n";
echo "  role      : super_admin\n";
echo "  PIN       : {$pin}\n";
echo "  >>> NOTE CE PIN — il ne sera plus jamais affiché ! <<<\n";
echo "========================================================\n\n";
