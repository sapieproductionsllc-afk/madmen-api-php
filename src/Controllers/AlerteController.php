<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class AlerteController
{
    public function index(): void
    {
        // Enrichissement ADDITIF : on conserve toutes les colonnes de `alerte` et on ajoute
        // `employe_nom` (JOIN) + `severite` (dérivée du type). Voir docs/INTEGRATION-FRONT.md §3.A.
        $sql = "SELECT a.id, a.type, a.employe_id, a.poste_travail_id, a.destinataire_id,
                       a.message, a.horodatage, a.lu, a.created_at,
                       CONCAT(e.prenom, ' ', e.nom) AS employe_nom,
                       CASE a.type
                           WHEN 'connexion_refusee' THEN 'Critique'
                           WHEN 'deconnexion'       THEN 'Faible'
                           ELSE 'Moyen'
                       END AS severite
                FROM alerte a
                LEFT JOIN employe e ON e.id = a.employe_id
                WHERE 1=1";
        $params = [];

        if (($dest = Request::query('destinataire_id')) !== null) {
            $sql .= ' AND a.destinataire_id = :dest';
            $params['dest'] = $dest;
        }
        if (($lu = Request::query('lu')) !== null) {
            $sql .= ' AND a.lu = :lu';
            $params['lu'] = filter_var($lu, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $limit = max(1, min(200, (int) Request::query('limit', 50)));
        $sql .= " ORDER BY a.horodatage DESC LIMIT $limit";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    /** POST /api/alertes/{id}/lu — marque une alerte comme lue. */
    public function marquerLu(array $params): void
    {
        $stmt = Database::connection()->prepare('UPDATE alerte SET lu = 1 WHERE id = :id');
        $stmt->execute(['id' => (int) $params['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Alerte introuvable', 404);
        }
        Response::noContent();
    }

    /** POST /api/alertes/tout-lire — marque toutes les alertes non lues comme lues. */
    public function toutLire(): void
    {
        $stmt = Database::connection()->prepare('UPDATE alerte SET lu = 1 WHERE lu = 0');
        $stmt->execute();

        Response::json(['marquees' => $stmt->rowCount()]);
    }
}
