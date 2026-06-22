<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class PosteController
{
    /**
     * « Roster » d'un poste pour le mode hors-ligne : TOUS les employés actifs (avec PIN
     * haché), motifs et seuils. N'importe quel employé peut ouvrir n'importe quel poste ;
     * le garde « présence » (pointage K40) est appliqué au login (en ligne) et à la
     * synchro (sessions ouvertes hors-ligne). Le client met ces données en cache local.
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

        $stmt = $db->query(
            'SELECT id, matricule, nom, prenom, superieur_id, code_pin_hash
             FROM employe WHERE statut = \'actif\''
        );
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
