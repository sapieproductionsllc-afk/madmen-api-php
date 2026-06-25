<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\K40Pointage;
use MadMen\Core\Presence;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class PointageController
{
    public function index(): void
    {
        $sql = 'SELECT * FROM pointage WHERE 1=1';
        $params = [];

        if (($emp = Request::query('employe_id')) !== null) {
            // Accepte l'id numérique OU le matricule (le profil filtre par matricule).
            if (!ctype_digit((string) $emp)) {
                $r = Database::connection()->prepare('SELECT id FROM employe WHERE matricule = ?');
                $r->execute([(string) $emp]);
                $emp = (int) ($r->fetchColumn() ?: 0);
            }
            $sql .= ' AND employe_id = :emp';
            $params['emp'] = (int) $emp;
        }
        if (($date = Request::query('date')) !== null) {
            $sql .= ' AND date = :date';
            $params['date'] = $date;
        }
        // Plage de dates (jour / semaine / mois / année) — feuille de pointage.
        if (($from = Request::query('from')) !== null) {
            $sql .= ' AND date >= :from';
            $params['from'] = $from;
        }
        if (($to = Request::query('to')) !== null) {
            $sql .= ' AND date <= :to';
            $params['to'] = $to;
        }
        // Garde-fou anti-dump : sans AUCUNE borne de date, on limite aux 30 derniers jours.
        if ($date === null && $from === null && $to === null) {
            $sql .= ' AND date >= :defaut';
            $params['defaut'] = date('Y-m-d', strtotime('-30 days'));
        }
        $sql .= ' ORDER BY date DESC, id DESC LIMIT 5000';

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
        // Retard calculé selon l'horaire de l'employé (cohérent avec K40 et login).
        $horaire = Presence::horaire(Database::connection(), (int) $body['employe_id']);
        $retard = Presence::retardMinutes($now, $horaire);
        $stmt = Database::connection()->prepare(
            'INSERT INTO pointage (employe_id, appareil_id, date, heure_entree, methode, retard_minutes, statut)
             VALUES (:employe_id, :appareil_id, :date, :heure_entree, :methode, :retard, :statut)'
        );
        $stmt->execute([
            'employe_id'   => (int) $body['employe_id'],
            'appareil_id'  => $body['appareil_id'] ?? null,
            'date'         => date('Y-m-d'),
            'heure_entree' => $now,
            'methode'      => $body['methode'],
            'retard'       => $retard,
            'statut'       => $retard > 0 ? 'retard' : 'present',
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

    /**
     * POST /api/employes/{id}/pointage-manuel — saisie MANUELLE d'un pointage par
     * l'admin (secours quand le K40 est injoignable). Body : { horodatage:
     * 'YYYY-MM-DD HH:MM[:SS]', type?: 'entree'|'sortie' }. Sans 'type', la bascule
     * automatique s'applique (1er passage du jour = arrivée, 2e = départ, ...), si
     * bien qu'un pointage K40 ultérieur sera correctement pris comme un DÉPART.
     */
    public function manuel(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];

        $check = $db->prepare('SELECT 1 FROM employe WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetchColumn()) {
            Response::error('Employé introuvable', 404);
        }

        $body = Request::body();
        $ts = $this->horodatageValide($body['horodatage'] ?? null);
        if ($ts === null) {
            Response::error("'horodatage' requis (format 'YYYY-MM-DD HH:MM' ou '...:SS')", 422);
        }
        $type = $body['type'] ?? null;
        if ($type !== null && !in_array($type, ['entree', 'sortie'], true)) {
            Response::error("'type' doit valoir 'entree' ou 'sortie' (ou être omis pour la bascule auto)", 422);
        }

        K40Pointage::recordManuel($db, $id, $ts, $type);

        // Résumé du jour : passages (avec source) + ligne pointage recalculée.
        $date = substr($ts, 0, 10);
        $stmt = $db->prepare(
            'SELECT id, type, horodatage, source FROM pointage_passage
             WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$id, $date]);
        $passages = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM pointage WHERE employe_id = ? AND date = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id, $date]);

        Response::json([
            'message'   => 'Pointage manuel enregistré',
            'pointage'  => $stmt->fetch() ?: null,
            'passages'  => $passages,
        ], 201);
    }

    /**
     * PUT /api/employes/{id}/pointage-jour — fixe (override admin) l'heure d'arrivée
     * et/ou de départ d'un jour depuis le calendrier. REMPLACE les passages du jour par
     * ces heures puis recalcule le résumé (heure_entree/heure_sortie, retard, HS).
     * body : { date:'YYYY-MM-DD', heure_entree:'HH:MM'|null, heure_sortie:'HH:MM'|null }.
     */
    public function setJour(array $params): void
    {
        $db = Database::connection();
        $id = (int) $params['id'];
        $check = $db->prepare('SELECT 1 FROM employe WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetchColumn()) {
            Response::error('Employé introuvable', 404);
        }

        $body = Request::body();
        $date = (string) ($body['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            Response::error("'date' requise (YYYY-MM-DD)", 422);
        }
        $norm = static function ($h) {
            if ($h === null || $h === '') {
                return null;
            }
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $h) === 1 ? (string) $h : false;
        };
        $entree = $norm($body['heure_entree'] ?? null);
        $sortie = $norm($body['heure_sortie'] ?? null);
        if ($entree === false || $sortie === false) {
            Response::error('Heures au format HH:MM', 422);
        }

        // Override admin : on remplace les passages du jour par les heures fournies.
        $db->prepare('DELETE FROM pointage_passage WHERE employe_id = ? AND date = ?')->execute([$id, $date]);
        if ($entree !== null) {
            K40Pointage::recordManuel($db, $id, "$date $entree:00", 'entree');
        }
        if ($sortie !== null) {
            K40Pointage::recordManuel($db, $id, "$date $sortie:00", 'sortie');
        }
        // Aucune heure -> jour vidé : on retire aussi la ligne de résumé.
        if ($entree === null && $sortie === null) {
            $db->prepare('DELETE FROM pointage WHERE employe_id = ? AND date = ?')->execute([$id, $date]);
        }

        $stmt = $db->prepare('SELECT * FROM pointage WHERE employe_id = ? AND date = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id, $date]);
        Response::json(['message' => 'Jour mis à jour', 'pointage' => $stmt->fetch() ?: null], 200);
    }

    /** Valide un horodatage 'YYYY-MM-DD HH:MM[:SS]' -> 'YYYY-MM-DD HH:MM:SS' ; null si invalide. */
    private function horodatageValide($v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v) !== 1) {
            return null;
        }
        $t = strtotime($v);

        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }
}
