<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class PointageController
{
    public function index(): void
    {
        $sql = 'SELECT * FROM pointage WHERE 1=1';
        $params = [];

        if (($emp = Request::query('employe_id')) !== null) {
            $sql .= ' AND employe_id = :emp';
            $params['emp'] = $emp;
        }
        if (($date = Request::query('date')) !== null) {
            $sql .= ' AND date = :date';
            $params['date'] = $date;
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    public function store(): void
    {
        $body = Request::body();

        if (empty($body['employe_id']) || empty($body['methode'])) {
            Response::error("Les champs 'employe_id' et 'methode' sont obligatoires", 422);
        }

        // L'employé doit exister : sinon la contrainte FK lèverait une 500.
        $check = Database::connection()->prepare('SELECT 1 FROM employe WHERE id = ?');
        $check->execute([(int) $body['employe_id']]);
        if (!$check->fetchColumn()) {
            Response::error("L'employé spécifié est introuvable", 422);
        }

        $now = date('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'INSERT INTO pointage (employe_id, appareil_id, date, heure_entree, methode, statut)
             VALUES (:employe_id, :appareil_id, :date, :heure_entree, :methode, :statut)'
        );
        $stmt->execute([
            'employe_id'   => (int) $body['employe_id'],
            'appareil_id'  => $body['appareil_id'] ?? null,
            'date'         => date('Y-m-d'),
            'heure_entree' => $now,
            'methode'      => $body['methode'],
            'statut'       => 'present',
        ]);

        $id = (int) Database::connection()->lastInsertId();
        $stmt = Database::connection()->prepare('SELECT * FROM pointage WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        Response::json($row ?: [], 201);
    }

    /** GET /api/pointages/{id}/passages — détail des allers-retours (entrée/sortie) du jour. */
    public function passages(array $params): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT employe_id, date FROM pointage WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $p = $stmt->fetch();
        if (!$p) {
            Response::error('Pointage introuvable', 404);
        }
        $stmt = $db->prepare(
            'SELECT id, type, horodatage, source FROM pointage_passage
             WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$p['employe_id'], $p['date']]);

        Response::json($stmt->fetchAll());
    }
}
