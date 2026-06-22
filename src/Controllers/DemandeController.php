<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Demandes des employés (avance, congé, formation, attestation, autre).
 *  - Self-service employé : /api/me/demandes (scopé au jeton, rang 1).
 *  - Manager : /api/demandes (liste) + décision (rang superviseur+).
 */
final class DemandeController
{
    private const TYPES = ['avance', 'conge', 'formation', 'attestation', 'autre'];

    // ---------------------------------------------------------------- employé

    /** GET /api/me/demandes — mes demandes (plus récentes d'abord). */
    public function mesDemandes(): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            'SELECT * FROM demande WHERE employe_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$id]);

        Response::json(array_map([$this, 'formate'], $stmt->fetchAll()));
    }

    /** POST /api/me/demandes — créer une demande { type, objet, montant?, date_debut?, date_fin?, details? }. */
    public function creer(): void
    {
        $id = $this->employeId();
        $body = Request::body();

        $type = $body['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            Response::error("'type' invalide (avance, conge, formation, attestation, autre)", 422);
        }
        $objet = trim((string) ($body['objet'] ?? ''));
        if ($objet === '') {
            Response::error("Le champ 'objet' est obligatoire", 422);
        }

        $montant = null;
        $dateDebut = null;
        $dateFin = null;

        if ($type === 'avance') {
            if (!isset($body['montant']) || !is_numeric($body['montant']) || (float) $body['montant'] <= 0) {
                Response::error("'montant' (> 0) est requis pour une avance sur salaire", 422);
            }
            $montant = round((float) $body['montant'], 2);
        }
        if ($type === 'conge') {
            $dateDebut = $this->dateValide($body['date_debut'] ?? null);
            $dateFin = $this->dateValide($body['date_fin'] ?? null);
            if ($dateDebut === null || $dateFin === null) {
                Response::error("'date_debut' et 'date_fin' (YYYY-MM-DD) sont requis pour un congé", 422);
            }
            if ($dateFin < $dateDebut) {
                Response::error("'date_fin' doit être postérieure ou égale à 'date_debut'", 422);
            }
        }

        $details = isset($body['details']) ? trim((string) $body['details']) : null;

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO demande (employe_id, type, objet, details, montant, date_debut, date_fin, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $type, $objet, $details ?: null, $montant, $dateDebut, $dateFin, 'en_attente']);

        $newId = (int) $db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM demande WHERE id = ?');
        $stmt->execute([$newId]);

        Response::json($this->formate($stmt->fetch()), 201);
    }

    /** POST /api/me/demandes/{id}/annuler — annuler MA demande encore en attente. */
    public function annuler(array $params): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            "UPDATE demande SET statut = 'annule' WHERE id = ? AND employe_id = ? AND statut = 'en_attente'"
        );
        $stmt->execute([(int) $params['id'], $id]);
        if ($stmt->rowCount() === 0) {
            Response::error('Demande introuvable ou non annulable (déjà traitée)', 409);
        }

        Response::json(['message' => 'Demande annulée']);
    }

    // ---------------------------------------------------------------- manager

    /** GET /api/demandes?statut=&employe_id= — liste pour validation (manager). */
    public function index(): void
    {
        $sql = "SELECT d.*, CONCAT(e.prenom, ' ', e.nom) AS employe, e.matricule
                FROM demande d JOIN employe e ON e.id = d.employe_id WHERE 1=1";
        $params = [];

        $statut = Request::query('statut');
        if (is_string($statut) && in_array($statut, ['en_attente', 'approuve', 'refuse', 'annule'], true)) {
            $sql .= ' AND d.statut = :statut';
            $params['statut'] = $statut;
        }
        if (($emp = Request::query('employe_id')) !== null && ctype_digit((string) $emp)) {
            $sql .= ' AND d.employe_id = :emp';
            $params['emp'] = (int) $emp;
        }
        $sql .= ' ORDER BY d.id DESC LIMIT 200';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json(array_map([$this, 'formate'], $stmt->fetchAll()));
    }

    /** POST /api/demandes/{id}/decision — { decision: 'approuve'|'refuse', motif_refus? } (manager). */
    public function decision(array $params): void
    {
        $body = Request::body();
        $decision = $body['decision'] ?? null;
        if (!in_array($decision, ['approuve', 'refuse'], true)) {
            Response::error("'decision' doit valoir 'approuve' ou 'refuse'", 422);
        }
        $motif = $decision === 'refuse' ? trim((string) ($body['motif_refus'] ?? '')) : null;

        $user = Auth::currentUser();
        $valideur = isset($user['sub']) ? (int) $user['sub'] : null;

        $stmt = Database::connection()->prepare(
            "UPDATE demande SET statut = ?, motif_refus = ?, valide_par = ?
             WHERE id = ? AND statut = 'en_attente'"
        );
        $stmt->execute([$decision, $motif ?: null, $valideur, (int) $params['id']]);
        if ($stmt->rowCount() === 0) {
            Response::error('Demande introuvable ou déjà traitée', 409);
        }

        Response::json(['message' => $decision === 'approuve' ? 'Demande approuvée' : 'Demande refusée']);
    }

    // ---------------------------------------------------------------- helpers

    private function employeId(): int
    {
        $user = Auth::currentUser();
        $id = $user['sub'] ?? null;
        if (!$id) {
            Response::error('Non authentifié', 401);
        }

        return (int) $id;
    }

    /** Normalise une ligne demande pour l'API (référence + montants typés). */
    private function formate(array $d): array
    {
        return [
            'id'          => (int) $d['id'],
            'reference'   => '#' . str_pad((string) $d['id'], 4, '0', STR_PAD_LEFT),
            'employe_id'  => (int) $d['employe_id'],
            'employe'     => $d['employe'] ?? null,
            'matricule'   => $d['matricule'] ?? null,
            'type'        => $d['type'],
            'objet'       => $d['objet'],
            'details'     => $d['details'],
            'montant'     => $d['montant'] !== null ? (float) $d['montant'] : null,
            'date_debut'  => $d['date_debut'],
            'date_fin'    => $d['date_fin'],
            'statut'      => $d['statut'],
            'motif_refus' => $d['motif_refus'],
            'created_at'  => $d['created_at'],
        ];
    }

    private function dateValide($v): ?string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }

        return strtotime($v) !== false ? $v : null;
    }
}
