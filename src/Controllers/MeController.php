<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Presence;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Espace SELF-SERVICE de l'employé connecté (app « madmen-employe »).
 *
 * Toutes les routes /api/me/* sont scopées à l'employé du JETON (clé 'sub' du JWT)
 * — donc aucun risque d'IDOR (un employé ne peut voir que SES données) — et sont
 * accessibles dès le rang employé (1). Lecture seule.
 */
final class MeController
{
    /** Id de l'employé courant (depuis le JWT) ; 401 si non authentifié. */
    private function employeId(): int
    {
        $user = Auth::currentUser();
        $id = $user['sub'] ?? null;
        if (!$id) {
            Response::error('Non authentifié', 401);
        }

        return (int) $id;
    }

    /** GET /api/me/profil — fiche complète de l'employé connecté (noms résolus). */
    public function profil(): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.photo_url, e.telephone, e.adresse,
                    e.contact_urgence_nom, e.contact_urgence_tel, e.salaire, e.statut, e.role,
                    p.intitule AS poste,
                    d.nom      AS departement,
                    CONCAT(s.prenom, ' ', s.nom) AS manager
             FROM employe e
             LEFT JOIN poste p        ON p.id = e.poste_id
             LEFT JOIN departement d  ON d.id = e.departement_id
             LEFT JOIN employe s      ON s.id = e.superieur_id
             WHERE e.id = ?"
        );
        $stmt->execute([$id]);
        $e = $stmt->fetch();
        if (!$e) {
            Response::error('Employé introuvable', 404);
        }

        Response::json($e);
    }

    /** GET /api/me/pointages?from=YYYY-MM-DD&to=YYYY-MM-DD — historique de présence. */
    public function pointages(): void
    {
        $id = $this->employeId();
        $sql = 'SELECT date, heure_entree, heure_sortie, retard_minutes,
                       temps_present_minutes, temps_pause_minutes, nb_pauses, statut
                FROM pointage WHERE employe_id = ?';
        $params = [$id];

        $from = Request::query('from');
        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $sql .= ' AND date >= ?';
            $params[] = $from;
        }
        $to = Request::query('to');
        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $sql .= ' AND date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY date DESC LIMIT 90';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    /** GET /api/me/horaire — emploi du temps (planning par jour) de l'employé. */
    public function horaire(): void
    {
        $id = $this->employeId();
        $p = Presence::planning(Database::connection(), $id);

        Response::json(['planning' => $p['jours'], 'tolerance_minutes' => $p['tolerance']]);
    }

    /** GET /api/me/paie?mois=YYYY-MM — bulletin de paie de l'employé connecté. */
    public function paie(): void
    {
        $id = $this->employeId();
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id, matricule, nom, prenom, salaire FROM employe WHERE id = ?');
        $stmt->execute([$id]);
        $employe = $stmt->fetch();
        if (!$employe) {
            Response::error('Employé introuvable', 404);
        }

        $mois = Request::query('mois');
        $mois = is_string($mois) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) === 1
            ? $mois
            : date('Y-m');

        Response::json(PaieController::calculer($db, $employe, $mois));
    }

    /** GET /api/me/collegues — annuaire (pour démarrer une conversation). */
    public function collegues(): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            "SELECT e.id, e.nom, e.prenom, e.photo_url, p.intitule AS poste
             FROM employe e
             LEFT JOIN poste p ON p.id = e.poste_id
             WHERE e.id <> ? AND e.statut <> 'suspendu'
             ORDER BY e.nom, e.prenom"
        );
        $stmt->execute([$id]);

        Response::json($stmt->fetchAll());
    }
}
