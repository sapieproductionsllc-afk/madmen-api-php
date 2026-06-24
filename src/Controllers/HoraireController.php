<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Presence;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Horaire de travail PAR EMPLOYÉ (table horaire_employe). L'admin définit pour
 * chaque employé : heure d'arrivée/départ, pause déjeuner, tolérance de retard,
 * jours travaillés. Sert de référence aux calculs de retard / présence / heures sup.
 */
final class HoraireController
{
    /** GET /api/employes/{id}/horaire — horaire de l'employé (ou défaut global). */
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $this->assertEmploye($id);

        $stmt = Database::connection()->prepare(
            'SELECT heure_arrivee, heure_depart, pause_debut, pause_fin,
                    tolerance_minutes, avance_minutes, jours_travailles, planning
             FROM horaire_employe WHERE employe_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row) {
            $plan = !empty($row['planning']) ? json_decode((string) $row['planning'], true) : null;
            Response::json([
                'employe_id'        => $id,
                'personnalise'      => true,
                'planning'          => is_array($plan) ? $plan : null,
                'heure_arrivee'     => substr((string) $row['heure_arrivee'], 0, 5),
                'heure_depart'      => substr((string) $row['heure_depart'], 0, 5),
                'pause_debut'       => $row['pause_debut'] !== null ? substr((string) $row['pause_debut'], 0, 5) : null,
                'pause_fin'         => $row['pause_fin'] !== null ? substr((string) $row['pause_fin'], 0, 5) : null,
                'tolerance_minutes' => (int) $row['tolerance_minutes'],
                'avance_minutes'    => (int) $row['avance_minutes'],
                'jours_travailles'  => (string) $row['jours_travailles'],
            ]);
        }

        $d = Presence::defaultHoraire();
        Response::json([
            'employe_id'        => $id,
            'personnalise'      => false,
            'planning'          => null,
            'heure_arrivee'     => $d['debut'],
            'heure_depart'      => $d['fin'],
            'pause_debut'       => $d['dejeuner_debut'],
            'pause_fin'         => $d['dejeuner_fin'],
            'tolerance_minutes' => $d['tolerance'],
            'avance_minutes'    => $d['avance'],
            'jours_travailles'  => $d['jours'],
        ]);
    }

    /** PUT /api/employes/{id}/horaire — crée/met à jour l'horaire de l'employé. */
    public function upsert(array $params): void
    {
        $id = (int) $params['id'];
        $this->assertEmploye($id);
        $body = Request::body();

        // --- Mode EMPLOI DU TEMPS PAR JOUR (planning) ---
        if (isset($body['planning']) && is_array($body['planning'])) {
            $tol = (int) ($body['tolerance_minutes'] ?? 0);
            if ($tol < 0 || $tol > 240) {
                Response::error("'tolerance_minutes' doit être entre 0 et 240", 422);
            }
            $avance = $this->normAvance($body['avance_minutes'] ?? null);
            if ($avance === null) {
                Response::error("'avance_minutes' doit être un entier entre 0 et 240", 422);
            }
            $planning = $this->normPlanning($body['planning']);
            if ($planning === null) {
                Response::error("'planning' invalide (clés 1-7 ; chaque jour : debut/fin HH:MM avec fin > debut)", 422);
            }
            if ($planning === []) {
                Response::error("'planning' doit contenir au moins un jour travaillé", 422);
            }
            $arr = min(array_column($planning, 'debut')) . ':00';
            $dep = max(array_column($planning, 'fin')) . ':00';
            $jours = implode(',', array_keys($planning));

            Database::connection()->prepare(
                "INSERT INTO horaire_employe
                    (employe_id, heure_arrivee, heure_depart, pause_debut, pause_fin, tolerance_minutes, avance_minutes, jours_travailles, planning)
                 VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    heure_arrivee = VALUES(heure_arrivee), heure_depart = VALUES(heure_depart),
                    pause_debut = NULL, pause_fin = NULL,
                    tolerance_minutes = VALUES(tolerance_minutes), avance_minutes = VALUES(avance_minutes),
                    jours_travailles = VALUES(jours_travailles), planning = VALUES(planning)"
            )->execute([$id, $arr, $dep, $tol, $avance, $jours, json_encode($planning)]);

            $this->show($params);
            return; // ne pas tomber dans le mode legacy ci-dessous
        }

        // --- Mode HORAIRE UNIQUE (legacy : mêmes heures tous les jours) ---
        $arr = $this->normTime($body['heure_arrivee'] ?? null);
        $dep = $this->normTime($body['heure_depart'] ?? null);
        if ($arr === null || $dep === null) {
            Response::error("'heure_arrivee' et 'heure_depart' sont requis (format HH:MM)", 422);
        }
        if ($dep <= $arr) {
            Response::error("L'heure de départ doit être après l'heure d'arrivée", 422);
        }

        $pdeb = isset($body['pause_debut']) ? $this->normTime($body['pause_debut']) : null;
        $pfin = isset($body['pause_fin']) ? $this->normTime($body['pause_fin']) : null;
        if (($pdeb === null) !== ($pfin === null)) {
            Response::error("La pause déjeuner exige 'pause_debut' ET 'pause_fin' (ou aucune des deux)", 422);
        }
        if ($pdeb !== null && $pfin !== null && $pfin <= $pdeb) {
            Response::error("La fin de la pause doit être après son début", 422);
        }
        if ($pdeb !== null && ($pdeb < $arr || $pfin > $dep)) {
            Response::error("La pause doit être comprise dans les heures de travail", 422);
        }

        $tol = (int) ($body['tolerance_minutes'] ?? 0);
        if ($tol < 0 || $tol > 240) {
            Response::error("'tolerance_minutes' doit être entre 0 et 240", 422);
        }

        $avance = $this->normAvance($body['avance_minutes'] ?? null);
        if ($avance === null) {
            Response::error("'avance_minutes' doit être un entier entre 0 et 240", 422);
        }

        $jours = $this->normJours($body['jours_travailles'] ?? '1,2,3,4,5');
        if ($jours === null) {
            Response::error("'jours_travailles' invalide (ex. '1,2,3,4,5' ; 1=lundi … 7=dimanche)", 422);
        }

        Database::connection()->prepare(
            "INSERT INTO horaire_employe
                (employe_id, heure_arrivee, heure_depart, pause_debut, pause_fin, tolerance_minutes, avance_minutes, jours_travailles)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                heure_arrivee = VALUES(heure_arrivee), heure_depart = VALUES(heure_depart),
                pause_debut = VALUES(pause_debut), pause_fin = VALUES(pause_fin),
                tolerance_minutes = VALUES(tolerance_minutes), avance_minutes = VALUES(avance_minutes),
                jours_travailles = VALUES(jours_travailles),
                planning = NULL"
        )->execute([$id, $arr, $dep, $pdeb, $pfin, $tol, $avance, $jours]);

        $this->show($params);
    }

    /** Normalise une heure 'HH:MM' -> 'HH:MM:00' ; null si invalide. */
    private function normTime($v): ?string
    {
        if (!is_string($v) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($v)) !== 1) {
            return null;
        }

        return trim($v) . ':00';
    }

    /**
     * Normalise avance_minutes : entier dans [0,240]. Champ OMIS => défaut (30) pour
     * rester rétro-compatible (clients qui n'envoient pas le champ). null si invalide.
     */
    private function normAvance($v): ?int
    {
        if ($v === null) {
            return Presence::AVANCE_DEFAUT;
        }
        if (is_bool($v) || !is_numeric($v) || (string) (int) $v !== (string) $v) {
            return null;
        }
        $n = (int) $v;

        return ($n < 0 || $n > 240) ? null : $n;
    }

    /** Normalise une liste de jours ISO (1-7) -> '1,2,3' trié ; null si invalide. */
    private function normJours($v): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $v)), 'strlen');
        $set = [];
        foreach ($parts as $p) {
            if (!ctype_digit($p) || (int) $p < 1 || (int) $p > 7) {
                return null;
            }
            $set[(int) $p] = true;
        }
        if ($set === []) {
            return null;
        }
        $k = array_keys($set);
        sort($k);

        return implode(',', $k);
    }

    /**
     * Valide/normalise un planning par jour {jourISO: {debut, fin}} -> map triée
     * [1 => ['debut'=>'08:00','fin'=>'18:00'], ...]. null si invalide ; [] si vide.
     */
    private function normPlanning($plan): ?array
    {
        $out = [];
        foreach ($plan as $k => $v) {
            $j = (int) $k;
            if ((string) $j !== (string) $k || $j < 1 || $j > 7 || !is_array($v)) {
                return null;
            }
            $debut = $this->normHM($v['debut'] ?? null);
            $fin = $this->normHM($v['fin'] ?? null);
            if ($debut === null || $fin === null || $fin <= $debut) {
                return null;
            }
            $out[$j] = ['debut' => $debut, 'fin' => $fin];
        }
        ksort($out);

        return $out;
    }

    /** Valide une heure 'HH:MM' (renvoyée telle quelle) ; null si invalide. */
    private function normHM($v): ?string
    {
        if (!is_string($v) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($v)) !== 1) {
            return null;
        }

        return trim($v);
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
