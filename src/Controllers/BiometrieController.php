<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Crypto;
use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;

final class BiometrieController
{
    /** Lister les biométries enrôlées d'un employé (sans le gabarit). */
    public function index(array $params): void
    {
        $this->assertEmploye((int) $params['id']);

        $stmt = Database::connection()->prepare(
            'SELECT id, employe_id, type, doigt, badge_rfid, actif, created_at
             FROM employe_biometrie WHERE employe_id = ? ORDER BY id'
        );
        $stmt->execute([(int) $params['id']]);

        Response::json($stmt->fetchAll());
    }

    /**
     * Enrôler une donnée biométrique.
     * - empreinte / facial : body.template (base64 du gabarit) [+ doigt]
     * - rfid               : body.badge_rfid
     */
    public function store(array $params): void
    {
        $employeId = (int) $params['id'];
        $this->assertEmploye($employeId);

        $body = Request::body();
        $type = $body['type'] ?? null;

        if (!in_array($type, ['empreinte', 'rfid', 'facial'], true)) {
            Response::error("Le champ 'type' doit être : empreinte, rfid ou facial", 422);
        }

        $doigt = $body['doigt'] ?? null;
        $badge = null;
        $template = null;

        if ($type === 'rfid') {
            if (empty($body['badge_rfid'])) {
                Response::error("Le champ 'badge_rfid' est obligatoire pour le type rfid", 422);
            }
            $badge = (string) $body['badge_rfid'];
        } else {
            if (empty($body['template'])) {
                Response::error("Le champ 'template' (base64) est obligatoire pour empreinte/facial", 422);
            }
            $raw = base64_decode((string) $body['template'], true);
            if ($raw === false) {
                Response::error("Le 'template' n'est pas un base64 valide", 422);
            }
            // Chiffrement du gabarit au repos
            $template = Crypto::encrypt($raw);
        }

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO employe_biometrie (employe_id, type, doigt, template, badge_rfid, actif)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->bindValue(1, $employeId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $type);
            $stmt->bindValue(3, $doigt);
            $stmt->bindValue(4, $template, $template === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $stmt->bindValue(5, $badge);
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Ce badge RFID est déjà enrôlé', 422);
            }
            throw $e;
        }

        $id = (int) Database::connection()->lastInsertId();
        Response::json([
            'message' => 'Biométrie enrôlée',
            'id'      => $id,
            'type'    => $type,
            'doigt'   => $doigt,
            'badge_rfid' => $badge,
        ], 201);
    }

    /** Supprimer une biométrie enrôlée. */
    public function destroy(array $params): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM employe_biometrie WHERE id = ?');
        $stmt->execute([(int) $params['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Biométrie introuvable', 404);
        }
        Response::noContent();
    }

    private function assertEmploye(int $id): void
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM employe WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            Response::error('Employé introuvable', 404);
        }
    }
}
