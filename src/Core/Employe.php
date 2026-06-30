<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Résolution UNIQUE d'un identifiant d'employé passé dans une URL.
 *
 * Le front appelle les routes /api/employes/{id}/... tantôt avec l'id numérique,
 * tantôt avec le MATRICULE (ex. EMP-0002). Toute route employé DOIT passer par ici
 * plutôt que de faire (int)$params['id'] — sinon un matricule devient 0 -> 404
 * "Employé introuvable" (la cause de la biométrie « non enregistrée » silencieuse).
 *
 * Source unique de vérité : un seul endroit à maintenir, impossible d'oublier la
 * résolution sur une nouvelle route.
 */
final class Employe
{
    /** id numérique OU matricule -> id d'employé (0 si introuvable). */
    public static function resolveId($idParam): int
    {
        $s = trim((string) $idParam);
        if ($s === '') {
            return 0;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $stmt = Database::connection()->prepare('SELECT id FROM employe WHERE matricule = ?');
        $stmt->execute([$s]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
