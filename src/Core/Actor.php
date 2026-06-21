<?php
declare(strict_types=1);

namespace MadMen\Core;

/**
 * Identité de l'employé courant pour les fonctions « utilisateur » (messagerie).
 *
 * Source de vérité : le JWT (claim `sub` = employe.id) via Auth::currentUser().
 * En mode DÉMO uniquement (AUTH_ENABLED=false), on accepte un repli par en-tête
 * `X-Employe-Id` pour faciliter les tests sans jeton. En production (auth ON),
 * seul le jeton compte — impossible d'usurper une autre identité.
 */
final class Actor
{
    /** Id de l'employé courant, ou null si non identifiable. */
    public static function employeId(): ?int
    {
        $user = Auth::currentUser();
        if ($user !== null && isset($user['sub']) && (int) $user['sub'] > 0) {
            return (int) $user['sub'];
        }

        if (!Env::bool('AUTH_ENABLED', false)) {
            $h = $_SERVER['HTTP_X_EMPLOYE_ID'] ?? null;
            if ($h !== null && ctype_digit((string) $h) && (int) $h > 0) {
                return (int) $h;
            }
        }

        return null;
    }

    /** Id de l'employé courant ou 401 (termine la requête). */
    public static function requireEmployeId(): int
    {
        $id = self::employeId();
        if ($id === null) {
            Response::error('Non authentifié', 401);
        }

        return $id;
    }
}
