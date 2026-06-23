<?php
declare(strict_types=1);

namespace MadMen\Core;

use PDO;

/**
 * Salaire fixe (de base) avec historique daté. Le salaire « effectif » pour un mois est
 * la dernière entrée `salaire_fixe` dont `date_application` est <= dernier jour du mois.
 */
final class Salaire
{
    /**
     * Montant du salaire fixe EFFECTIF à la fin du mois `YYYY-MM`.
     * Renvoie null si l'employé n'a aucun historique de salaire (l'appelant retombe
     * alors sur l'ancienne colonne `employe.salaire`).
     */
    public static function effectif(PDO $db, int $employeId, string $mois): ?float
    {
        $finMois = date('Y-m-t', strtotime($mois . '-01'));
        $stmt = $db->prepare(
            'SELECT montant FROM salaire_fixe
             WHERE employe_id = ? AND date_application <= ?
             ORDER BY date_application DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$employeId, $finMois]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (float) $v;
    }

    /** Salaire fixe ACTUEL (effectif ce mois-ci). Null si aucun historique. */
    public static function actuel(PDO $db, int $employeId): ?float
    {
        return self::effectif($db, $employeId, date('Y-m'));
    }
}
