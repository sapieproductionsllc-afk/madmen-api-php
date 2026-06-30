<?php
declare(strict_types=1);

namespace MadMen\Core;

use Throwable;

/**
 * Permissions configurables PAR RÔLE (Projet B). Domaines avec 3 niveaux :
 * none < voir < gerer. super_admin = toujours 'gerer' partout (jamais stocké).
 * Lecture mise en cache une fois par requête (la table est minuscule).
 */
final class Permissions
{
    public const AREAS = ['presence', 'employes', 'pointages', 'paie', 'rapports', 'demandes', 'communication', 'administration'];

    /** Rôles configurables (les chefs d'équipe). */
    public const ROLES = ['directeur', 'superviseur'];

    private const ORDRE = ['none' => 0, 'voir' => 1, 'gerer' => 2];

    /** @var array<string,array<string,string>>|null */
    private static ?array $cache = null;

    /** Niveau d'un rôle pour un domaine ('none' par défaut ; super_admin = 'gerer'). */
    public static function niveau(string $role, string $area): string
    {
        if ($role === 'super_admin') {
            return 'gerer';
        }
        return self::charger()[$role][$area] ?? 'none';
    }

    /** Vrai si $role atteint au moins le niveau $requis sur $area. */
    public static function peut(string $role, string $area, string $requis): bool
    {
        return (self::ORDRE[self::niveau($role, $area)] ?? 0) >= (self::ORDRE[$requis] ?? 0);
    }

    /** Carte {area: niveau} d'un rôle (pour le front). */
    public static function pourRole(string $role): array
    {
        $out = [];
        foreach (self::AREAS as $a) {
            $out[$a] = self::niveau($role, $a);
        }
        return $out;
    }

    /** Matrice {role: {area: niveau}} des rôles configurables (éditeur super-admin). */
    public static function matrice(): array
    {
        $out = [];
        foreach (self::ROLES as $r) {
            $out[$r] = self::pourRole($r);
        }
        return $out;
    }

    /** Recharge le cache (après une mise à jour dans la même requête). */
    public static function viderCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string,array<string,string>> */
    private static function charger(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            foreach (Database::connection()->query('SELECT role, area, niveau FROM role_permission')->fetchAll() as $r) {
                self::$cache[$r['role']][$r['area']] = $r['niveau'];
            }
        } catch (Throwable $e) {
            self::$cache = []; // table absente (avant migration) -> tout 'none' (sauf super_admin)
        }
        return self::$cache;
    }
}
