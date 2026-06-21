<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Actor;
use MadMen\Core\Database;
use MadMen\Core\Response;

/**
 * Pièces jointes de la messagerie : téléversement et téléchargement.
 * Le binaire est stocké HORS de /public (storage/uploads) ; l'accès passe
 * uniquement par l'API, avec contrôle : seuls l'auteur ou un membre d'une
 * conversation où le fichier est partagé peuvent le récupérer.
 */
final class FichierController
{
    /** Taille max d'une pièce jointe (25 Mo). */
    private const TAILLE_MAX = 25 * 1024 * 1024;

    /** Types autorisés (mime => extension). Whitelist stricte (validée par finfo). */
    private const MIMES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac',
        'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'weba',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    /** POST /api/fichiers — téléverse une pièce jointe (champ multipart « fichier »). */
    public function upload(): void
    {
        $employeId = Actor::requireEmployeId();

        $f = $_FILES['fichier'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error("Aucun fichier reçu (champ 'fichier' attendu)", 422);
        }
        if (($f['size'] ?? 0) <= 0 || $f['size'] > self::TAILLE_MAX) {
            Response::error('Fichier vide ou trop volumineux (max 25 Mo)', 422);
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            Response::error('Téléversement invalide', 422);
        }

        // Type réel détecté côté serveur (jamais le type annoncé par le client).
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        if (!isset(self::MIMES[$mime])) {
            Response::error('Type de fichier non autorisé : ' . $mime, 415);
        }

        $sousDossier = date('Y/m');
        $baseDir = dirname(__DIR__, 2) . '/storage/uploads/' . $sousDossier;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            Response::error('Stockage indisponible', 500);
        }

        $nomStocke = bin2hex(random_bytes(16)) . '.' . self::MIMES[$mime];
        $cheminAbs = $baseDir . '/' . $nomStocke;
        if (!move_uploaded_file($f['tmp_name'], $cheminAbs)) {
            Response::error("Échec d'enregistrement du fichier", 500);
        }

        $cheminRel = $sousDossier . '/' . $nomStocke;
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO fichier (nom_original, chemin, mime, taille, televerse_par) VALUES (?, ?, ?, ?, ?)'
        )->execute([self::nomSur($f['name'] ?? 'fichier'), $cheminRel, $mime, (int) $f['size'], $employeId]);
        $id = (int) $db->lastInsertId();

        Response::json([
            'id'           => $id,
            'nom_original' => self::nomSur($f['name'] ?? 'fichier'),
            'mime'         => $mime,
            'taille'       => (int) $f['size'],
            'url'          => '/api/fichiers/' . $id,
        ], 201);
    }

    /** GET /api/fichiers/{id} — sert le binaire (contrôle d'accès par conversation). */
    public function download(array $params): void
    {
        $employeId = Actor::requireEmployeId();
        $db = Database::connection();

        $stmt = $db->prepare('SELECT * FROM fichier WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $fichier = $stmt->fetch();
        if (!$fichier) {
            Response::error('Fichier introuvable', 404);
        }

        // Accès : l'auteur, ou un membre d'une conversation où ce fichier est partagé.
        if ((int) $fichier['televerse_par'] !== $employeId && !self::peutVoir($db, $employeId, (int) $fichier['id'])) {
            Response::error('Accès refusé', 403);
        }

        $cheminAbs = dirname(__DIR__, 2) . '/storage/uploads/' . $fichier['chemin'];
        if (!is_file($cheminAbs)) {
            Response::error('Fichier absent du stockage', 410);
        }

        header('Content-Type: ' . $fichier['mime']);
        header('Content-Length: ' . (string) filesize($cheminAbs));
        header('Content-Disposition: inline; filename="' . self::nomSur($fichier['nom_original']) . '"');
        header('Cache-Control: private, max-age=86400');
        readfile($cheminAbs);
        exit;
    }

    /** Vrai si l'employé partage une conversation contenant un message lié à ce fichier. */
    private static function peutVoir(\PDO $db, int $employeId, int $fichierId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM message m
             JOIN conversation_membre cm ON cm.conversation_id = m.conversation_id AND cm.employe_id = ?
             WHERE m.fichier_id = ? LIMIT 1'
        );
        $stmt->execute([$employeId, $fichierId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Nettoie un nom de fichier pour les en-têtes (anti-injection, pas de chemin). */
    private static function nomSur(string $nom): string
    {
        $nom = basename($nom);
        $nom = preg_replace('/[\r\n"\\\\]/', '', $nom) ?? 'fichier';

        return $nom === '' ? 'fichier' : mb_substr($nom, 0, 200);
    }
}
