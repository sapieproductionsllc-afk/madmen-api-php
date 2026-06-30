<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Permissions;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Permissions configurables par rôle (Projet B).
 *  - GET  /api/me/permissions    : domaines->niveau de l'utilisateur courant (front).
 *  - GET  /api/role-permissions  : matrice complète (super_admin).
 *  - PUT  /api/role-permissions  : { role, area, niveau } (super_admin).
 */
final class PermissionController
{
    public function mine(): void
    {
        $u = Auth::currentUser();
        if ($u === null) {
            Response::error('Non authentifié', 401);
        }
        $role = (string) ($u['role'] ?? 'employe');
        Response::json(['role' => $role, 'permissions' => Permissions::pourRole($role)]);
    }

    public function matrice(): void
    {
        Response::json(Permissions::matrice());
    }

    public function update(): void
    {
        $b = Request::body();
        $role = (string) ($b['role'] ?? '');
        $area = (string) ($b['area'] ?? '');
        $niveau = (string) ($b['niveau'] ?? '');

        if (!in_array($role, Permissions::ROLES, true)) {
            Response::error("'role' doit valoir directeur ou superviseur", 422);
        }
        if (!in_array($area, Permissions::AREAS, true)) {
            Response::error("'area' invalide", 422);
        }
        if (!in_array($niveau, ['none', 'voir', 'gerer'], true)) {
            Response::error("'niveau' doit valoir none, voir ou gerer", 422);
        }

        Database::connection()->prepare(
            'INSERT INTO role_permission (role, area, niveau) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE niveau = VALUES(niveau)'
        )->execute([$role, $area, $niveau]);
        Permissions::viderCache();

        Response::json(['message' => 'Permission mise à jour', 'role' => $role, 'area' => $area, 'niveau' => $niveau]);
    }
}
