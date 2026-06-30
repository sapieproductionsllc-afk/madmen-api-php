<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Response;

/**
 * Données RH rattachées à un employé, consultées depuis ProfilDetails.jsx.
 * Lecture seule (aucune écriture exposée ici).
 *  - GET /api/employes/{id}/documents      — documents RH de l'employé
 *  - GET /api/employes/{id}/historique-rh  — historique RH de l'employé
 */
final class RhController
{
    /** GET /api/employes/{id}/documents — documents RH de l'employé. */
    public function documents(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $this->verifierEmploye($employeId);

        $stmt = Database::connection()->prepare(
            'SELECT d.id, d.titre, d.type, d.description, d.created_at,
                    d.taille_octets, d.url,
                    TRIM(CONCAT(a.prenom, " ", a.nom)) AS ajoute_par_nom
             FROM document_rh d
             LEFT JOIN employe a ON a.id = d.ajoute_par
             WHERE d.employe_id = :emp
             ORDER BY d.created_at DESC, d.id DESC'
        );
        $stmt->execute(['emp' => $employeId]);

        Response::json(array_map([$this, 'formateDocument'], $stmt->fetchAll()));
    }

    /** GET /api/employes/{id}/historique-rh — historique RH de l'employé. */
    public function historique(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $this->verifierEmploye($employeId);

        $stmt = Database::connection()->prepare(
            'SELECT id, evenement, detail, date, created_at
             FROM historique_rh WHERE employe_id = :emp
             ORDER BY date DESC, id DESC'
        );
        $stmt->execute(['emp' => $employeId]);

        Response::json(array_map([$this, 'formateHistorique'], $stmt->fetchAll()));
    }

    // ---------------------------------------------------------------- helpers

    /** Renvoie 404 si l'employé n'existe pas. */
    private function verifierEmploye(int $employeId): void
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM employe WHERE id = :id');
        $stmt->execute(['id' => $employeId]);
        if ($stmt->fetch() === false) {
            Response::error('Employé introuvable', 404);
        }
    }

    private function formateDocument(array $d): array
    {
        $nom = isset($d['ajoute_par_nom']) ? trim((string) $d['ajoute_par_nom']) : '';

        return [
            'id'             => (int) $d['id'],
            'titre'          => $d['titre'],
            'type'           => $d['type'],
            'description'    => $d['description'] ?? null,
            'created_at'     => $d['created_at'] ?? null,
            'taille_octets'  => $d['taille_octets'] !== null ? (int) $d['taille_octets'] : null,
            'ajoute_par_nom' => $nom !== '' ? $nom : null,
            'url'            => $d['url'] ?? null,
        ];
    }

    private function formateHistorique(array $h): array
    {
        return [
            'id'         => (int) $h['id'],
            'evenement'  => $h['evenement'],
            'detail'     => $h['detail'],
            'date'       => $h['date'],
            'created_at' => $h['created_at'] ?? null,
        ];
    }
}
