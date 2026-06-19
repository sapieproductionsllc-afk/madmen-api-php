<?php
declare(strict_types=1);

/**
 * Runner de migrations en PHP natif (PDO).
 *
 * Usage :
 *   php database/migrate.php            -> applique les migrations en attente
 *   php database/migrate.php migrate    -> idem
 *   php database/migrate.php rollback   -> annule le dernier lot (batch)
 *   php database/migrate.php fresh      -> supprime toutes les tables (puis relancer migrate)
 *   php database/migrate.php status     -> liste l'etat des migrations
 *
 * Chaque migration est un fichier database/migrations/NNN_nom.php qui retourne
 * un tableau ['up' => 'SQL...', 'down' => 'SQL...'].
 */

$cfg = require __DIR__ . '/../config/database.php';
$action = $argv[1] ?? 'migrate';

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// 1) Connexion sans base pour pouvoir la creer si elle n'existe pas.
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}",
    $cfg['username'],
    $cfg['password'],
    $pdoOptions
);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$cfg['database']}`");

// 2) Table de suivi des migrations.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        batch INT NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files);

$ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

switch ($action) {
    case 'migrate':
        $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $applied = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $ran, true)) {
                continue;
            }
            $migration = require $file;
            $pdo->exec($migration['up']);
            $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$name, $batch]);
            echo "  [up]   $name\n";
            $applied++;
        }
        echo $applied ? "OK : $applied migration(s) appliquee(s).\n" : "Deja a jour, rien a migrer.\n";
        break;

    case 'rollback':
        $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();
        if ($batch === 0) {
            echo "Aucune migration a annuler.\n";
            break;
        }
        $names = $pdo->query("SELECT migration FROM migrations WHERE batch = $batch ORDER BY migration DESC")
                     ->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($names as $name) {
            $migration = require __DIR__ . "/migrations/$name.php";
            $pdo->exec($migration['down']);
            $pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$name]);
            echo "  [down] $name\n";
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        echo "OK : lot (batch) $batch annule.\n";
        break;

    case 'fresh':
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        echo "Toutes les tables ont ete supprimees. Relancer : php database/migrate.php\n";
        break;

    case 'status':
        echo "Migrations appliquees : " . count($ran) . " / " . count($files) . "\n";
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $mark = in_array($name, $ran, true) ? '[x]' : '[ ]';
            echo "  $mark $name\n";
        }
        break;

    default:
        echo "Usage : php database/migrate.php [migrate|rollback|fresh|status]\n";
}
