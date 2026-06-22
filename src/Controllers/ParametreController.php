<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

/**
 * Paramètres globaux de l'application (Parametres.jsx).
 * Stockage clé/valeur : la valeur de chaque clé est sérialisée en JSON.
 * Catégories front : entreprise, fuseau, langue, devise, seuilInactivite,
 * toleranceRetard, notifs, securite.
 */
final class ParametreController
{
    /** GET /api/parametres — objet { cle: valeur_decodee }. */
    public function index(): void
    {
        $stmt = Database::connection()->prepare('SELECT cle, valeur FROM parametre');
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['cle']] = $this->decode($row['valeur']);
        }

        Response::json($out);
    }

    /** PUT /api/parametres — { cle: valeur, ... } : upsert de chaque clé (valeur en JSON). */
    public function update(): void
    {
        $body = Request::body();
        if ($body === []) {
            Response::error('Aucun paramètre à mettre à jour', 422);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO parametre (cle, valeur) VALUES (:cle, :valeur)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
        );

        foreach ($body as $cle => $valeur) {
            $cle = trim((string) $cle);
            if ($cle === '' || mb_strlen($cle) > 120) {
                Response::error("Clé invalide : '$cle'", 422);
            }
            $stmt->execute([
                ':cle'    => $cle,
                ':valeur' => json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $this->index();
    }

    // ---------------------------------------------------------------- helpers

    /** Décode la valeur JSON stockée ; retourne null si valeur absente/illisible. */
    private function decode(?string $valeur): mixed
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        $decoded = json_decode($valeur, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $valeur;
    }
}
