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
    private const TYPES = ['avance', 'conge', 'permission', 'absence', 'formation', 'attestation', 'autre'];

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

    /** POST /api/me/demandes — créer MA demande { type, objet, montant?, date_debut?, date_fin?, details? }. */
    public function creer(): void
    {
        $this->inserer($this->employeId(), Request::body());
    }

    /** POST /api/demandes — créer une demande AU NOM d'un agent (manager, #7.2). */
    public function creerPour(): void
    {
        $body = Request::body();
        $employeId = isset($body['employe_id']) && ctype_digit((string) $body['employe_id']) ? (int) $body['employe_id'] : 0;
        if ($employeId <= 0) {
            Response::error("'employe_id' est obligatoire (création au nom d'un agent)", 422);
        }
        $stmt = Database::connection()->prepare('SELECT 1 FROM employe WHERE id = ?');
        $stmt->execute([$employeId]);
        if (!$stmt->fetchColumn()) {
            Response::error('Employé introuvable', 422);
        }

        $this->inserer($employeId, $body);
    }

    /** Validation commune + insertion d'une demande pour un employé donné (réponse 201). */
    private function inserer(int $employeId, array $body): void
    {
        $type = $body['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            Response::error("'type' invalide (" . implode(', ', self::TYPES) . ')', 422);
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
        // Congé / Permission / Absence : période obligatoire.
        if (in_array($type, ['conge', 'permission', 'absence'], true)) {
            $dateDebut = $this->dateValide($body['date_debut'] ?? null);
            $dateFin = $this->dateValide($body['date_fin'] ?? null);
            if ($dateDebut === null || $dateFin === null) {
                Response::error("'date_debut' et 'date_fin' (YYYY-MM-DD) sont requis pour ce type", 422);
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
        )->execute([$employeId, $type, $objet, $details ?: null, $montant, $dateDebut, $dateFin, 'en_attente']);

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

        $db = Database::connection();
        $demandeId = (int) $params['id'];

        // Récupère la demande AVANT la décision : un congé APPROUVÉ doit aussi POSER les
        // jours 'conge' dans pointage, sinon le calendrier/présence affiche « absent »
        // alors que la paie le compte payé (incohérence relevée à l'audit).
        $dem = $db->prepare("SELECT employe_id, type, date_debut, date_fin FROM demande WHERE id = ? AND statut = 'en_attente'");
        $dem->execute([$demandeId]);
        $demande = $dem->fetch();

        $stmt = $db->prepare(
            "UPDATE demande SET statut = ?, motif_refus = ?, valide_par = ?
             WHERE id = ? AND statut = 'en_attente'"
        );
        $stmt->execute([$decision, $motif ?: null, $valideur, $demandeId]);
        if ($stmt->rowCount() === 0) {
            Response::error('Demande introuvable ou déjà traitée', 409);
        }

        if ($decision === 'approuve' && $demande && ($demande['type'] ?? '') === 'conge'
            && !empty($demande['date_debut']) && !empty($demande['date_fin'])) {
            $this->poserConge($db, (int) $demande['employe_id'], (string) $demande['date_debut'], (string) $demande['date_fin']);
        }

        Response::json(['message' => $decision === 'approuve' ? 'Demande approuvée' : 'Demande refusée']);
    }

    /** Pose les jours 'conge' dans pointage sur une plage (congé approuvé). Best-effort. */
    private function poserConge(\PDO $db, int $employeId, string $debut, string $fin): void
    {
        try {
            $d0 = new \DateTime($debut);
            $d1 = new \DateTime($fin);
        } catch (\Throwable $e) {
            return;
        }
        if ($d1 < $d0 || (int) $d0->diff($d1)->days > 366) {
            return;
        }
        $delPassage = $db->prepare('DELETE FROM pointage_passage WHERE employe_id = ? AND date = ?');
        $delJour    = $db->prepare('DELETE FROM pointage WHERE employe_id = ? AND date = ?');
        $insJour    = $db->prepare("INSERT INTO pointage (employe_id, date, statut) VALUES (?, ?, 'conge')");
        for ($cur = clone $d0; $cur <= $d1; $cur->modify('+1 day')) {
            $jour = $cur->format('Y-m-d');
            $delPassage->execute([$employeId, $jour]); // un jour de congé n'a pas d'allers-retours
            $delJour->execute([$employeId, $jour]);     // remplace tout statut existant du jour
            $insJour->execute([$employeId, $jour]);
        }
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
