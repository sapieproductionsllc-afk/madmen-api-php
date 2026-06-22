<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class UtilisateurController
{
    /**
     * GET /api/utilisateurs
     * Liste les comptes (lecture depuis employe).
     * derniere_connexion = MAX(session_travail.heure_debut) ou null.
     * NB : agence n'existe pas en base -> non renvoyée (décision : on garde département/poste).
     */
    public function index(): void
    {
        $db = Database::connection();
        $sql = "SELECT
                    e.id,
                    e.matricule,
                    e.email,
                    TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
                    e.role,
                    e.statut,
                    MAX(s.heure_debut) AS derniere_connexion
                FROM employe e
                LEFT JOIN session_travail s ON s.employe_id = e.id
                WHERE 1=1";
        $params = [];

        if (($role = Request::query('role')) !== null) {
            $sql .= ' AND e.role = :role';
            $params['role'] = $role;
        }
        if (($statut = Request::query('statut')) !== null) {
            $sql .= ' AND e.statut = :statut';
            $params['statut'] = $statut;
        }

        $sql .= ' GROUP BY e.id, e.matricule, e.email, e.prenom, e.nom, e.role, e.statut
                  ORDER BY e.id DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    /**
     * GET /api/roles
     * Rôles distincts avec comptage des comptes.
     */
    public function roles(): void
    {
        $sql = 'SELECT role, COUNT(*) AS users
                FROM employe
                GROUP BY role
                ORDER BY users DESC, role ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute();

        Response::json($stmt->fetchAll());
    }
}
