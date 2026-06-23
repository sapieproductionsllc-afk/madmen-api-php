<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDOException;

final class PosteController
{
    /** Colonnes publiques d'un poste (jamais d'infos sensibles). */
    private const COLUMNS = 'id, code, nom, statut, adresse_ip, departement_id, created_at';

    /** GET /api/postes — liste des postes de travail. */
    public function index(): void
    {
        $rows = Database::connection()
            ->query('SELECT ' . self::COLUMNS . ' FROM poste_travail ORDER BY code')
            ->fetchAll();

        Response::json($rows);
    }

    /**
     * POST /api/postes — crée un poste de travail.
     * Requis : `code` (unique). Optionnels : `nom`, `adresse_ip`, `adresse_mac`, `departement_id`.
     */
    public function store(): void
    {
        $body = Request::body();
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            Response::error("Le champ 'code' est obligatoire", 422);
        }

        $data = [
            'code'           => $code,
            'nom'            => isset($body['nom']) ? trim((string) $body['nom']) : null,
            'adresse_ip'     => $body['adresse_ip'] ?? null,
            'adresse_mac'    => $body['adresse_mac'] ?? null,
            'departement_id' => $body['departement_id'] ?? null,
        ];

        $db = Database::connection();
        try {
            $db->prepare(
                'INSERT INTO poste_travail (code, nom, adresse_ip, adresse_mac, departement_id)
                 VALUES (:code, :nom, :adresse_ip, :adresse_mac, :departement_id)'
            )->execute($data);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    Response::error('Ce code de poste existe déjà', 422);
                }
                Response::error('Référence invalide (département inexistant ?)', 422);
            }
            throw $e;
        }

        $id = (int) $db->lastInsertId();
        $stmt = $db->prepare('SELECT ' . self::COLUMNS . ' FROM poste_travail WHERE id = ?');
        $stmt->execute([$id]);

        Response::json($stmt->fetch(), 201);
    }

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
