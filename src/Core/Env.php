<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Chargement des variables depuis le fichier .env (sans dépendance externe).
 *
 * Factorise le parsing dupliqué auparavant dans config/app.php, database.php,
 * biometrie.php et k40.php.
 */
final class Env
{
    /** @var array<string,string>|null Cache du .env parsé. */
    private static ?array $cache = null;

    /**
     * Charge et retourne les variables du .env sous forme de tableau clé => valeur.
     *
     * Parsing robuste : ignore lignes vides et commentaires (#), supporte
     * l'optionnel préfixe « export », retire les guillemets entourants et les
     * commentaires de fin de ligne sur les valeurs non quotées.
     *
     * @return array<string,string>
     */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $env = [];
        $envFile = dirname(__DIR__, 2) . '/.env';

        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                // Supporte « export KEY=value ».
                if (str_starts_with($line, 'export ')) {
                    $line = ltrim(substr($line, 7));
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $key = trim($key);
                if ($key === '') {
                    continue;
                }
                $env[$key] = self::parseValue(trim($value));
            }
        }

        return self::$cache = $env;
    }

    /**
     * Lit une valeur unique avec valeur par défaut.
     */
    public static function get(string $key, string $default = ''): string
    {
        return self::load()[$key] ?? $default;
    }

    /**
     * Interprète une valeur booléenne (1/true/yes/on).
     */
    public static function bool(string $key, bool $default): bool
    {
        $env = self::load();
        if (!isset($env[$key])) {
            return $default;
        }

        return in_array(strtolower($env[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Nettoie une valeur brute : guillemets entourants, ou commentaire de fin
     * de ligne pour les valeurs non quotées (ex. « true   # commentaire »).
     */
    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if (($first === '"' || $first === "'") && str_ends_with($value, $first) && strlen($value) >= 2) {
            return substr($value, 1, -1);
        }

        // Valeur non quotée : retire un éventuel commentaire de fin de ligne.
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = rtrim(substr($value, 0, $hash));
        }

        return $value;
    }
}
