<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

final class PosteController
{
    /**
     * Roster d'un poste : le poste + la liste des employés autorisés.
     *
     * Sert au cache hors-ligne du kiosque (offline-first) : le hash bcrypt du PIN
     * est inclus afin que l'app Tauri puisse vérifier le PIN localement (login_pin_local)
     * quand le serveur est injoignable. Le poste est une machine de confiance.
     */
    public function roster(array $params): void
    {
        $db = Database::connection();
        $code = (string) ($params['code'] ?? '');

        $stmt = $db->prepare('SELECT id, code, nom, statut FROM poste_travail WHERE code = ?');
        $stmt->execute([$code]);
        $poste = $stmt->fetch();
        if (!$poste) {
            Response::error('Poste de travail inconnu', 404);
        }

        $stmt = $db->prepare(
            'SELECT e.id, e.matricule, e.nom, e.prenom, e.code_pin_hash
             FROM employe e
             JOIN autorisation_poste a ON a.employe_id = e.id AND a.poste_travail_id = ?
             ORDER BY e.nom, e.prenom'
        );
        $stmt->execute([(int) $poste['id']]);

        $employes = array_map(static fn (array $e): array => [
            'id'            => (int) $e['id'],
            'matricule'     => $e['matricule'],
            'nom'           => $e['nom'],
            'prenom'        => $e['prenom'],
            'code_pin_hash' => $e['code_pin_hash'],
        ], $stmt->fetchAll());

        Response::json([
            'poste' => [
                'code'   => $poste['code'],
                'nom'    => $poste['nom'],
                'statut' => $poste['statut'],
            ],
            'employes' => $employes,
        ]);
    }
}
