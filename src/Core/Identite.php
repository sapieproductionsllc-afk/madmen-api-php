<?php
declare(strict_types=1);

namespace MadMen\Core;

use PDO;
use RuntimeException;

/**
 * Génération automatique des identifiants employé :
 *  - matricule séquentiel « EMP-NNNN »,
 *  - code PIN à 4 chiffres UNIQUE (aucun autre employé ne l'a déjà).
 *
 * Le PIN étant haché (bcrypt salé), son unicité ne peut pas s'appuyer sur un index
 * SQL : on tire un candidat aléatoire et on vérifie par password_verify contre tous
 * les hash existants (coût O(employés) par tirage, acceptable à l'échelle visée).
 */
final class Identite
{
    private const PIN_LONGUEUR = 4;
    private const MAX_TENTATIVES_PIN = 200;

    /**
     * Prochain matricule séquentiel « EMP-NNNN » = (max numérique conforme) + 1.
     * Ignore les matricules non conformes existants (ex. « Ss-23 », « DEMO-EMP »).
     */
    public static function prochainMatricule(PDO $db): string
    {
        $max = (int) $db->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(matricule, 5) AS UNSIGNED)), 0)
             FROM employe WHERE matricule REGEXP '^EMP-[0-9]+$'"
        )->fetchColumn();

        return sprintf('EMP-%04d', $max + 1);
    }

    /**
     * Génère un PIN à 4 chiffres UNIQUE et le renvoie EN CLAIR.
     * Lève une RuntimeException si l'espace des PIN est saturé.
     */
    public static function genererPinUnique(PDO $db): string
    {
        $hashes = $db->query('SELECT code_pin_hash FROM employe')->fetchAll(PDO::FETCH_COLUMN);
        $espace = 10 ** self::PIN_LONGUEUR; // 10000

        if (count($hashes) >= $espace) {
            throw new RuntimeException('Espace des PIN saturé (trop d\'employés pour des PIN à 4 chiffres).');
        }

        for ($essai = 0; $essai < self::MAX_TENTATIVES_PIN; $essai++) {
            $pin = str_pad((string) random_int(0, $espace - 1), self::PIN_LONGUEUR, '0', STR_PAD_LEFT);
            $libre = true;
            foreach ($hashes as $h) {
                if ($h !== null && password_verify($pin, (string) $h)) {
                    $libre = false;
                    break;
                }
            }
            if ($libre) {
                return $pin;
            }
        }

        throw new RuntimeException('Impossible de générer un PIN unique après plusieurs tentatives.');
    }
}
