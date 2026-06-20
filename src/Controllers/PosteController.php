<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class PosteController
{
    /**
     * « Roster » d'un poste pour le mode hors-ligne : employés autorisés (avec PIN
     * haché), motifs et seuils. Le client met ces données en cache chiffré localement
     * afin de pouvoir authentifier et fonctionner sans réseau.
     */
    public function roster(array $params): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id, code, nom FROM poste_travail WHERE code = ?');
        $stmt->execute([$params['code']]);
        $poste = $stmt->fetch();
        if (!$poste) {
            Response::error('Poste de travail inconnu', 404);
        }

        $stmt = $db->prepare(
            'SELECT e.id, e.matricule, e.nom, e.prenom, e.superieur_id, e.code_pin_hash
             FROM employe e
             JOIN autorisation_poste a ON a.employe_id = e.id AND a.poste_travail_id = ?
             WHERE e.statut = \'actif\''
        );
        $stmt->execute([(int) $poste['id']]);
        $employes = $stmt->fetchAll();

        $seuils = require dirname(__DIR__, 2) . '/config/postes.php';
        $motifs = $db->query('SELECT id, libelle FROM motif_absence ORDER BY id')->fetchAll();

        Response::json([
            'poste'    => ['code' => $poste['code'], 'nom' => $poste['nom']],
            'seuils'   => [
                'inactivite_lock_minutes' => (int) $seuils['inactivite_lock_minutes'],
                'justification_minutes'   => (int) $seuils['justification_minutes'],
                'heartbeat_seconds'       => (int) $seuils['heartbeat_seconds'],
            ],
            'motifs'   => $motifs,
            'employes' => $employes,
        ]);
    }
}
