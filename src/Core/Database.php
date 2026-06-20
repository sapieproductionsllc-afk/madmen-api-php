<?php
declare(strict_types=1);

namespace MadMen\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Connexion PDO unique (singleton) vers MySQL/MariaDB.
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $cfg = require dirname(__DIR__, 2) . '/config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['database'],
                $cfg['charset']
            );

            try {
                self::$instance = new PDO($dsn, $cfg['username'], $cfg['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // Cohérence horaire : aligne le fuseau MySQL (NOW(), CURDATE())
                // sur celui de PHP en lui passant l'offset courant (ex. +02:00).
                // PHP a déjà fixé son fuseau via date_default_timezone_set().
                self::$instance->exec("SET time_zone = '" . date('P') . "'");
            } catch (PDOException $e) {
                throw new RuntimeException('Connexion DB impossible : ' . $e->getMessage(), (int) $e->getCode());
            }
        }

        return self::$instance;
    }
}
