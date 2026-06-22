<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Prêts / avances accordés à l'employé (suivi du remboursement).
 *  - Employé : GET /api/me/prets (lecture seule, scopé au jeton, rang 1).
 *  - Manager : GET /api/prets, POST /api/prets (accorder), POST /api/prets/{id}/remboursement
 *    (données financières -> rang directeur).
 */
final class PretController
{
    /** GET /api/me/prets — mes prêts (avec solde calculé). */
    public function mesPrets(): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            'SELECT * FROM pret WHERE employe_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$id]);

        Response::json(array_map([$this, 'formate'], $stmt->fetchAll()));
    }

    /** GET /api/prets?employe_id=&statut= — liste (manager). */
    public function index(): void
    {
        $sql = "SELECT p.*, CONCAT(e.prenom, ' ', e.nom) AS employe, e.matricule
                FROM pret p JOIN employe e ON e.id = p.employe_id WHERE 1=1";
        $params = [];
        if (($emp = Request::query('employe_id')) !== null && ctype_digit((string) $emp)) {
            $sql .= ' AND p.employe_id = :emp';
            $params['emp'] = (int) $emp;
        }
        if (($s = Request::query('statut')) !== null && in_array($s, ['en_cours', 'solde'], true)) {
            $sql .= ' AND p.statut = :s';
            $params['s'] = $s;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT 200';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json(array_map([$this, 'formate'], $stmt->fetchAll()));
    }

    /** POST /api/prets — accorder un prêt { employe_id, montant, mensualite?, prochaine_echeance?, motif? }. */
    public function creer(): void
    {
        $body = Request::body();
        $db = Database::connection();

        $employeId = (int) ($body['employe_id'] ?? 0);
        $check = $db->prepare('SELECT 1 FROM employe WHERE id = ?');
        $check->execute([$employeId]);
        if (!$check->fetchColumn()) {
            Response::error("'employe_id' introuvable", 422);
        }
        if (!isset($body['montant']) || !is_numeric($body['montant']) || (float) $body['montant'] <= 0) {
            Response::error("'montant' (> 0) est requis", 422);
        }
        $montant = round((float) $body['montant'], 2);
        $mensualite = isset($body['mensualite']) && is_numeric($body['mensualite'])
            ? round((float) $body['mensualite'], 2) : null;
        $echeance = $this->dateValide($body['prochaine_echeance'] ?? null);
        $motif = isset($body['motif']) ? trim((string) $body['motif']) : null;

        $user = Auth::currentUser();
        $accordePar = isset($user['sub']) ? (int) $user['sub'] : null;

        $db->prepare(
            'INSERT INTO pret (employe_id, montant, mensualite, prochaine_echeance, motif, accorde_par)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$employeId, $montant, $mensualite, $echeance, $motif ?: null, $accordePar]);

        $stmt = $db->prepare('SELECT * FROM pret WHERE id = ?');
        $stmt->execute([(int) $db->lastInsertId()]);

        Response::json($this->formate($stmt->fetch()), 201);
    }

    /** POST /api/prets/{id}/remboursement — { montant } : enregistre un remboursement. */
    public function remboursement(array $params): void
    {
        $body = Request::body();
        if (!isset($body['montant']) || !is_numeric($body['montant']) || (float) $body['montant'] <= 0) {
            Response::error("'montant' (> 0) est requis", 422);
        }
        $verse = round((float) $body['montant'], 2);

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM pret WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $pret = $stmt->fetch();
        if (!$pret) {
            Response::error('Prêt introuvable', 404);
        }

        $nouveau = round((float) $pret['montant_rembourse'] + $verse, 2);
        if ($nouveau > (float) $pret['montant']) {
            $nouveau = (float) $pret['montant']; // on ne dépasse pas le montant emprunté
        }
        $statut = $nouveau >= (float) $pret['montant'] ? 'solde' : 'en_cours';

        $db->prepare('UPDATE pret SET montant_rembourse = ?, statut = ? WHERE id = ?')
           ->execute([$nouveau, $statut, (int) $pret['id']]);

        $stmt = $db->prepare('SELECT * FROM pret WHERE id = ?');
        $stmt->execute([(int) $pret['id']]);

        Response::json($this->formate($stmt->fetch()));
    }

    // ---------------------------------------------------------------- helpers

    private function formate(array $p): array
    {
        $montant = (float) $p['montant'];
        $rembourse = (float) $p['montant_rembourse'];

        return [
            'id'                 => (int) $p['id'],
            'employe_id'         => (int) $p['employe_id'],
            'employe'            => $p['employe'] ?? null,
            'matricule'          => $p['matricule'] ?? null,
            'montant'            => $montant,
            'montant_rembourse'  => $rembourse,
            'solde'              => round($montant - $rembourse, 2),
            'mensualite'         => $p['mensualite'] !== null ? (float) $p['mensualite'] : null,
            'prochaine_echeance' => $p['prochaine_echeance'],
            'motif'              => $p['motif'],
            'statut'             => $p['statut'],
            'created_at'         => $p['created_at'] ?? null,
        ];
    }

    private function dateValide($v): ?string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }

        return strtotime($v) !== false ? $v : null;
    }

    private function employeId(): int
    {
        $user = Auth::currentUser();
        $id = $user['sub'] ?? null;
        if (!$id) {
            Response::error('Non authentifié', 401);
        }

        return (int) $id;
    }
}
