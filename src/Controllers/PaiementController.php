<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;

/**
 * Journal des paiements de paie. Marque une période de paie comme PAYÉE pour un
 * employé (ou un lot d'employés) depuis l'écran de paie, et alimente la synthèse
 * Finance (mouvements + totaux par période).
 *
 *  - POST /api/paie/{employe_id}/payer  { periode, montant }  -> marquer payé
 *  - POST /api/paie/payer-lot           { employe_ids, periode } -> paiement groupé
 *  - GET  /api/mouvements?periode=                            -> journal d'une période
 *  - GET  /api/finance/synthese?periode=                      -> { total_courant, total_precedent, delta }
 *
 * Idempotent : un (employe_id, periode) déjà payé n'est pas re-créé (UNIQUE en base) ;
 * le montant est mis à jour si fourni à nouveau.
 */
final class PaiementController
{
    /** POST /api/paie/{employe_id}/payer — marque la paie d'un employé comme payée. */
    public function payer(array $params): void
    {
        $db = Database::connection();
        $body = Request::body();

        $employeId = (int) ($params['employe_id'] ?? 0);
        $check = $db->prepare('SELECT 1 FROM employe WHERE id = ?');
        $check->execute([$employeId]);
        if (!$check->fetchColumn()) {
            Response::error("'employe_id' introuvable", 422);
        }

        $periode = $this->periodeValide($body['periode'] ?? null);
        if (!isset($body['montant']) || !is_numeric($body['montant']) || (float) $body['montant'] < 0) {
            Response::error("'montant' (>= 0) est requis", 422);
        }
        $montant = round((float) $body['montant'], 2);

        $ligne = $this->enregistrer($db, $employeId, $periode, $montant);

        Response::json($ligne, 201);
    }

    /** POST /api/paie/payer-lot — marque payée la paie de plusieurs employés pour une période. */
    public function payerLot(): void
    {
        $db = Database::connection();
        $body = Request::body();

        $periode = $this->periodeValide($body['periode'] ?? null);
        $ids = $body['employe_ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            Response::error("'employe_ids' (liste non vide) est requis", 422);
        }

        $mois = $periode; // les bulletins sont calculés par mois (YYYY-MM)
        $payes = [];
        foreach ($ids as $rawId) {
            if (!is_numeric($rawId)) {
                continue;
            }
            $employeId = (int) $rawId;

            $stmt = $db->prepare('SELECT id, matricule, nom, prenom, salaire, DATE(created_at) AS created_at, date_embauche FROM employe WHERE id = ?');
            $stmt->execute([$employeId]);
            $employe = $stmt->fetch();
            if (!$employe) {
                continue;
            }

            // Montant = salaire net calculé du bulletin (méthode pure, lecture seule).
            $bulletin = PaieController::calculer($db, $employe, $mois);
            $montant = $bulletin['paie_calculable'] ? round((float) $bulletin['salaire_net'], 2) : 0.0;

            $payes[] = $this->enregistrer($db, $employeId, $periode, $montant);
        }

        Response::json([
            'periode' => $periode,
            'nombre'  => count($payes),
            'total'   => round(array_sum(array_map(static fn ($p) => $p['montant'], $payes)), 2),
            'paiements' => $payes,
        ], 201);
    }

    /** GET /api/mouvements?periode=YYYY-MM — journal des paiements d'une période. */
    public function mouvements(): void
    {
        $db = Database::connection();
        $periode = $this->periodeValide(Request::query('periode'), true);

        $sql = "SELECT p.id, p.employe_id, p.periode, p.montant, p.statut, p.paye_le, p.created_at,
                       e.matricule, CONCAT(e.prenom, ' ', e.nom) AS employe
                FROM paie_paiement p
                JOIN employe e ON e.id = p.employe_id";
        $args = [];
        if ($periode !== null) {
            $sql .= ' WHERE p.periode = :periode';
            $args['periode'] = $periode;
        }
        $sql .= ' ORDER BY p.paye_le DESC, p.id DESC LIMIT 500';

        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $lignes = array_map([$this, 'formate'], $stmt->fetchAll());

        Response::json([
            'periode'    => $periode,
            'total'      => round(array_sum(array_map(static fn ($l) => $l['montant'], $lignes)), 2),
            'mouvements' => $lignes,
        ]);
    }

    /** GET /api/finance/synthese?periode=YYYY-MM — total payé courant, précédent et delta. */
    public function synthese(): void
    {
        $db = Database::connection();
        $periode = $this->periodeValide(Request::query('periode'), true) ?? date('Y-m');
        $precedente = date('Y-m', strtotime($periode . '-01 -1 month'));

        $totalCourant = $this->totalPeriode($db, $periode);
        $totalPrecedent = $this->totalPeriode($db, $precedente);

        Response::json([
            'periode'           => $periode,
            'periode_precedente' => $precedente,
            'total_courant'     => $totalCourant,
            'total_precedent'   => $totalPrecedent,
            'delta'             => round($totalCourant - $totalPrecedent, 2),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Insère (ou met à jour le montant d') une ligne (employe_id, periode) du journal.
     * @return array<string,mixed> la ligne formatée
     */
    private function enregistrer(PDO $db, int $employeId, string $periode, float $montant): array
    {
        // UNIQUE (employe_id, periode) : on réécrit le montant/paye_le si déjà payé.
        $db->prepare(
            "INSERT INTO paie_paiement (employe_id, periode, montant, statut, paye_le)
             VALUES (:employe_id, :periode, :montant, 'paye', NOW())
             ON DUPLICATE KEY UPDATE montant = VALUES(montant), paye_le = NOW()"
        )->execute([
            'employe_id' => $employeId,
            'periode'    => $periode,
            'montant'    => $montant,
        ]);

        $stmt = $db->prepare(
            "SELECT p.id, p.employe_id, p.periode, p.montant, p.statut, p.paye_le, p.created_at,
                    e.matricule, CONCAT(e.prenom, ' ', e.nom) AS employe
             FROM paie_paiement p
             JOIN employe e ON e.id = p.employe_id
             WHERE p.employe_id = ? AND p.periode = ?"
        );
        $stmt->execute([$employeId, $periode]);

        return $this->formate($stmt->fetch());
    }

    /** Somme des montants payés pour une période. */
    private function totalPeriode(PDO $db, string $periode): float
    {
        $stmt = $db->prepare('SELECT COALESCE(SUM(montant), 0) FROM paie_paiement WHERE periode = ?');
        $stmt->execute([$periode]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function formate(array $p): array
    {
        return [
            'id'         => (int) $p['id'],
            'employe_id' => (int) $p['employe_id'],
            'employe'    => $p['employe'] ?? null,
            'matricule'  => $p['matricule'] ?? null,
            'periode'    => $p['periode'],
            'montant'    => (float) $p['montant'],
            'statut'     => $p['statut'],
            'paye_le'    => $p['paye_le'],
            'created_at' => $p['created_at'] ?? null,
        ];
    }

    /**
     * Valide et normalise la période (YYYY-MM, CHAR(7)).
     * @param bool $optionnel si vrai, null/absent renvoie null au lieu d'une erreur
     */
    private function periodeValide(mixed $periode, bool $optionnel = false): ?string
    {
        if ($optionnel && ($periode === null || $periode === '')) {
            return null;
        }
        if (!is_string($periode) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode) !== 1) {
            Response::error("'periode' invalide (format attendu : YYYY-MM)", 422);
        }

        return $periode;
    }
}
