<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Actor;
use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;

/**
 * CRUD des documents RH d'un employé (table `document_rh`).
 *
 * Les binaires (PDF UNIQUEMENT) sont stockés HORS de /public — dans
 * storage/uploads/rh/AAAA/MM — exactement comme les pièces jointes de la
 * messagerie (cf. FichierController). L'accès au binaire passe par l'URL
 * exposée /api/employes/{id}/documents/{docId}/fichier.
 *
 *  - GET    /api/employes/{id}/documents               (RhController::documents)
 *  - POST   /api/employes/{id}/documents               — téléverse un PDF + métadonnées
 *  - PATCH  /api/employes/{id}/documents/{docId}        — renomme (titre/type/description)
 *  - POST   /api/employes/{id}/documents/{docId}/remplacer — remplace le fichier (PDF)
 *  - GET    /api/employes/{id}/documents/{docId}/fichier   — sert le binaire
 *  - DELETE /api/employes/{id}/documents/{docId}        — supprime (204)
 */
final class DocumentController
{
    /** Taille max d'un document (25 Mo, aligné sur les pièces jointes). */
    private const TAILLE_MAX = 25 * 1024 * 1024;

    /** PDF UNIQUEMENT (type réel détecté côté serveur via finfo). */
    private const MIME_PDF = 'application/pdf';

    /** POST /api/employes/{id}/documents — téléverse un PDF (multipart « fichier »). */
    public function store(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $this->verifierEmploye($employeId);

        $auteurId = Actor::employeId(); // utilisateur courant (JWT) ; null si non identifiable

        $titre = trim((string) ($_POST['titre'] ?? ''));
        if ($titre === '') {
            Response::error("Le champ 'titre' est obligatoire", 422);
        }
        $type        = $this->champNullable($_POST['type'] ?? null, 60);
        $description = $this->champNullable($_POST['description'] ?? null);

        [$cheminRel, $mime, $taille] = $this->recevoirPdf();

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO document_rh
                (employe_id, titre, type, description, url, chemin, mime, taille_octets, ajoute_par)
             VALUES (:emp, :titre, :type, :description, :url, :chemin, :mime, :taille, :ajoute_par)'
        )->execute([
            'emp'         => $employeId,
            'titre'       => mb_substr($titre, 0, 160),
            'type'        => $type,
            'description' => $description,
            'url'         => null, // renseignée juste après (dépend de l'id inséré)
            'chemin'      => $cheminRel,
            'mime'        => $mime,
            'taille'      => $taille,
            'ajoute_par'  => $auteurId,
        ]);
        $docId = (int) $db->lastInsertId();

        // URL d'accès exposée (téléchargement du binaire via l'API).
        $url = '/api/employes/' . $employeId . '/documents/' . $docId . '/fichier';
        $db->prepare('UPDATE document_rh SET url = ? WHERE id = ?')->execute([$url, $docId]);

        Response::json($this->formate($this->charger($employeId, $docId)), 201);
    }

    /** PATCH /api/employes/{id}/documents/{docId} — renomme (titre/type/description). */
    public function update(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $docId     = (int) $params['docId'];
        $this->verifierEmploye($employeId);
        $this->charger($employeId, $docId); // 404 si introuvable

        $body = Request::body();
        $data = [];

        if (array_key_exists('titre', $body)) {
            $titre = trim((string) $body['titre']);
            if ($titre === '') {
                Response::error('Le titre ne peut pas être vide', 422);
            }
            $data['titre'] = mb_substr($titre, 0, 160);
        }
        if (array_key_exists('type', $body)) {
            $data['type'] = $this->champNullable($body['type'], 60);
        }
        if (array_key_exists('description', $body)) {
            $data['description'] = $this->champNullable($body['description']);
        }

        if ($data === []) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $data['id'] = $docId;
        Database::connection()->prepare("UPDATE document_rh SET $set WHERE id = :id")->execute($data);

        Response::json($this->formate($this->charger($employeId, $docId)));
    }

    /**
     * POST /api/employes/{id}/documents/{docId}/remplacer — remplace le binaire (PDF).
     * Le nouveau fichier remplace l'ancien (supprimé du stockage) ; les métadonnées
     * (titre/type/description) sont conservées.
     */
    public function remplacer(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $docId     = (int) $params['docId'];
        $this->verifierEmploye($employeId);
        $doc = $this->charger($employeId, $docId);

        [$cheminRel, $mime, $taille] = $this->recevoirPdf();

        Database::connection()->prepare(
            'UPDATE document_rh SET chemin = ?, mime = ?, taille_octets = ? WHERE id = ?'
        )->execute([$cheminRel, $mime, $taille, $docId]);

        // Supprime l'ancien binaire (best-effort, ne fait pas échouer la requête).
        $this->supprimerBinaire($doc['chemin'] ?? null);

        Response::json($this->formate($this->charger($employeId, $docId)));
    }

    /** GET /api/employes/{id}/documents/{docId}/fichier — sert le binaire. */
    public function telecharger(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $docId     = (int) $params['docId'];
        $this->verifierEmploye($employeId);
        $doc = $this->charger($employeId, $docId);

        $chemin = (string) ($doc['chemin'] ?? '');
        if ($chemin === '') {
            Response::error('Aucun fichier attaché à ce document', 404);
        }
        $cheminAbs = self::baseStockage() . '/' . $chemin;
        if (!is_file($cheminAbs)) {
            Response::error('Fichier absent du stockage', 410);
        }

        header('Content-Type: ' . ($doc['mime'] ?: self::MIME_PDF));
        header('Content-Length: ' . (string) filesize($cheminAbs));
        header('Content-Disposition: inline; filename="' . self::nomSur((string) $doc['titre']) . '.pdf"');
        header('Cache-Control: private, max-age=86400');
        readfile($cheminAbs);
        exit;
    }

    /** DELETE /api/employes/{id}/documents/{docId} — supprime le document (204). */
    public function destroy(array $params): void
    {
        $employeId = \MadMen\Core\Employe::resolveId($params['id']);
        $docId     = (int) $params['docId'];
        $this->verifierEmploye($employeId);
        $doc = $this->charger($employeId, $docId);

        Database::connection()->prepare('DELETE FROM document_rh WHERE id = ?')->execute([$docId]);
        $this->supprimerBinaire($doc['chemin'] ?? null);

        Response::noContent();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Valide et enregistre le PDF reçu (champ multipart « fichier »). PDF UNIQUEMENT.
     * @return array{0:string,1:string,2:int} [cheminRelatif, mime, taille]
     */
    private function recevoirPdf(): array
    {
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
        if ($mime !== self::MIME_PDF) {
            Response::error('Seuls les fichiers PDF sont acceptés (type détecté : ' . ($mime ?: 'inconnu') . ')', 422);
        }

        $sousDossier = 'rh/' . date('Y/m');
        $baseDir = self::baseStockage() . '/' . $sousDossier;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            Response::error('Stockage indisponible', 500);
        }

        $nomStocke = bin2hex(random_bytes(16)) . '.pdf';
        $cheminAbs = $baseDir . '/' . $nomStocke;
        if (!move_uploaded_file($f['tmp_name'], $cheminAbs)) {
            Response::error("Échec d'enregistrement du fichier", 500);
        }

        return [$sousDossier . '/' . $nomStocke, $mime, (int) $f['size']];
    }

    /** Renvoie 404 si l'employé n'existe pas. */
    private function verifierEmploye(int $employeId): void
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM employe WHERE id = :id');
        $stmt->execute(['id' => $employeId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Employé introuvable', 404);
        }
    }

    /** Charge un document de cet employé, ou 404 (termine la requête). */
    private function charger(int $employeId, int $docId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, TRIM(CONCAT(a.prenom, " ", a.nom)) AS ajoute_par_nom
             FROM document_rh d
             LEFT JOIN employe a ON a.id = d.ajoute_par
             WHERE d.id = :doc AND d.employe_id = :emp'
        );
        $stmt->execute(['doc' => $docId, 'emp' => $employeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            Response::error('Document introuvable', 404);
        }

        return $row;
    }

    /** Forme JSON exposée d'un document (cf. contrat). */
    private function formate(array $d): array
    {
        $nom = isset($d['ajoute_par_nom']) ? trim((string) $d['ajoute_par_nom']) : '';

        return [
            'id'             => (int) $d['id'],
            'titre'          => $d['titre'],
            'type'           => $d['type'],
            'description'    => $d['description'] ?? null,
            'created_at'     => $d['created_at'] ?? null,
            'taille_octets'  => $d['taille_octets'] !== null ? (int) $d['taille_octets'] : null,
            'ajoute_par_nom' => $nom !== '' ? $nom : null,
            'url'            => $d['url'] ?? null,
        ];
    }

    /** Normalise un champ texte optionnel : '' -> null, tronqué si $max fourni. */
    private function champNullable(mixed $valeur, ?int $max = null): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }

        return $max !== null ? mb_substr($valeur, 0, $max) : $valeur;
    }

    /** Supprime un binaire du stockage (best-effort). */
    private function supprimerBinaire(?string $cheminRel): void
    {
        if ($cheminRel === null || $cheminRel === '') {
            return;
        }
        $abs = self::baseStockage() . '/' . $cheminRel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    /** Racine de stockage des binaires (hors /public), identique à FichierController. */
    private static function baseStockage(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    /** Nettoie un libellé pour l'en-tête Content-Disposition. */
    private static function nomSur(string $nom): string
    {
        $nom = basename($nom);
        $nom = preg_replace('/[\r\n"\\\\\/]/', '', $nom) ?? 'document';

        return $nom === '' ? 'document' : mb_substr($nom, 0, 200);
    }
}
