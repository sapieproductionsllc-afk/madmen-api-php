<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Actor;
use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;
use PDO;

/**
 * Messagerie interne (style WhatsApp, fonctions de messagerie uniquement) :
 * conversations 1-à-1 et groupes (3+), messages texte + pièces jointes
 * (image, audio/vocal, PDF, document), accusés de lecture (envoyé/lu) et
 * compteur de non-lus. Identité de l'expéditeur via Actor (JWT).
 *
 * NB : 100 % indépendant de la paie/surveillance — c'est de la communication.
 */
final class MessagerieController
{
    private const TYPES = ['texte', 'image', 'audio', 'document', 'fichier'];

    // ---------------------------------------------------------------- Conversations

    /** GET /api/conversations — mes conversations (dernier message + non-lus). */
    public function conversations(): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT c.* FROM conversation c
             JOIN conversation_membre cm ON cm.conversation_id = c.id
             WHERE cm.employe_id = ? ORDER BY c.updated_at DESC, c.id DESC'
        );
        $stmt->execute([$me]);

        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $out[] = $this->resume($db, $c, $me);
        }
        Response::json($out);
    }

    /** POST /api/conversations — crée un fil direct ou un groupe. */
    public function creer(): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $body = Request::body();

        $type = ($body['type'] ?? 'direct') === 'groupe' ? 'groupe' : 'direct';
        $autres = $this->idsValides($db, $body['membres'] ?? [], $me);

        if ($type === 'direct') {
            if (count($autres) !== 1) {
                Response::error('Un fil direct exige exactement 1 autre participant', 422);
            }
            $existant = $this->directExistant($db, $me, $autres[0]);
            if ($existant !== null) {
                Response::json($this->resume($db, $this->conv($db, $existant), $me), 200);
            }
        } else {
            if (count($autres) < 2) {
                Response::error('Un groupe exige au moins 2 autres participants (3 au total)', 422);
            }
            if (trim((string) ($body['nom'] ?? '')) === '') {
                Response::error("Le nom du groupe ('nom') est obligatoire", 422);
            }
        }

        $nom = $type === 'groupe' ? mb_substr(trim((string) $body['nom']), 0, 120) : null;
        $db->prepare('INSERT INTO conversation (type, nom, cree_par) VALUES (?, ?, ?)')
           ->execute([$type, $nom, $me]);
        $convId = (int) $db->lastInsertId();

        // Créateur = admin ; les autres = membres.
        $this->ajouter($db, $convId, $me, 'admin');
        foreach ($autres as $eid) {
            $this->ajouter($db, $convId, $eid, 'membre');
        }

        Response::json($this->resume($db, $this->conv($db, $convId), $me), 201);
    }

    /** GET /api/conversations/{id} — détail + membres. */
    public function show(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $convId = (int) $params['id'];
        $this->exigerMembre($db, $convId, $me);

        $resume = $this->resume($db, $this->conv($db, $convId), $me);
        $resume['membres'] = $this->membres($db, $convId);
        Response::json($resume);
    }

    /** POST /api/conversations/{id}/membres — ajoute des membres (groupe, admin). */
    public function ajouterMembres(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $convId = (int) $params['id'];
        $conv = $this->conv($db, $convId);
        if (!$conv) {
            Response::error('Conversation introuvable', 404);
        }
        if ($conv['type'] !== 'groupe') {
            Response::error('On ne peut ajouter des membres qu\'à un groupe', 422);
        }
        if ($this->role($db, $convId, $me) !== 'admin') {
            Response::error('Seul un administrateur du groupe peut ajouter des membres', 403);
        }

        $ajoutes = [];
        foreach ($this->idsValides($db, Request::body()['membres'] ?? [], $me) as $eid) {
            if ($this->ajouter($db, $convId, $eid, 'membre')) {
                $ajoutes[] = $eid;
            }
        }
        Response::json(['message' => 'Membres ajoutés', 'ajoutes' => $ajoutes, 'membres' => $this->membres($db, $convId)]);
    }

    /** DELETE /api/conversations/{id}/membres/{employeId} — retire un membre / quitter. */
    public function retirerMembre(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $convId = (int) $params['id'];
        $cible = (int) $params['employeId'];
        $this->exigerMembre($db, $convId, $me);

        // On peut se retirer soi-même ; sinon il faut être admin.
        if ($cible !== $me && $this->role($db, $convId, $me) !== 'admin') {
            Response::error('Seul un administrateur peut retirer un autre membre', 403);
        }
        $db->prepare('DELETE FROM conversation_membre WHERE conversation_id = ? AND employe_id = ?')
           ->execute([$convId, $cible]);

        Response::json(['message' => 'Membre retiré', 'membres' => $this->membres($db, $convId)]);
    }

    // ---------------------------------------------------------------- Messages

    /** GET /api/conversations/{id}/messages?before=&limit= — historique (chronologique). */
    public function messages(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $convId = (int) $params['id'];
        $this->exigerMembre($db, $convId, $me);

        $limit = (int) (Request::query('limit') ?? 50);
        $limit = max(1, min(100, $limit));
        $before = Request::query('before');

        $sql = 'SELECT m.*, e.nom AS exp_nom, e.prenom AS exp_prenom,
                       f.mime AS f_mime, f.nom_original AS f_nom, f.taille AS f_taille
                FROM message m
                JOIN employe e ON e.id = m.expediteur_id
                LEFT JOIN fichier f ON f.id = m.fichier_id
                WHERE m.conversation_id = :cid';
        $args = ['cid' => $convId];
        if ($before !== null && ctype_digit((string) $before)) {
            $sql .= ' AND m.id < :before';
            $args['before'] = (int) $before;
        }
        $sql .= ' ORDER BY m.id DESC LIMIT ' . $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = array_reverse($stmt->fetchAll());

        $lectures = $this->lectures($db, $convId, $me);
        $messages = array_map(fn ($r) => $this->formatMessage($r, $me, $lectures), $rows);

        Response::json($messages);
    }

    /** POST /api/conversations/{id}/messages — envoie un message (texte ou pièce jointe). */
    public function envoyer(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $convId = (int) $params['id'];
        $this->exigerMembre($db, $convId, $me);

        $body = Request::body();
        $type = in_array($body['type'] ?? 'texte', self::TYPES, true) ? $body['type'] : 'texte';
        $contenu = trim((string) ($body['contenu'] ?? ''));
        $fichierId = isset($body['fichier_id']) && ctype_digit((string) $body['fichier_id']) ? (int) $body['fichier_id'] : null;
        $clientUuid = isset($body['client_uuid']) && $body['client_uuid'] !== '' ? (string) $body['client_uuid'] : null;

        if ($type === 'texte') {
            if ($contenu === '') {
                Response::error("Un message texte exige 'contenu'", 422);
            }
            $fichierId = null;
        } else {
            if ($fichierId === null) {
                Response::error("Un message '$type' exige 'fichier_id'", 422);
            }
            $stmt = $db->prepare('SELECT 1 FROM fichier WHERE id = ?');
            $stmt->execute([$fichierId]);
            if (!$stmt->fetchColumn()) {
                Response::error('Pièce jointe (fichier_id) introuvable', 422);
            }
        }

        // Idempotence offline-first : un même client_uuid ne crée qu'un message.
        if ($clientUuid !== null) {
            $stmt = $db->prepare('SELECT id FROM message WHERE client_uuid = ?');
            $stmt->execute([$clientUuid]);
            $deja = $stmt->fetchColumn();
            if ($deja) {
                Response::json($this->messageParId($db, (int) $deja, $me), 200);
            }
        }

        $db->prepare(
            'INSERT INTO message (conversation_id, expediteur_id, type, contenu, fichier_id, client_uuid)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$convId, $me, $type, $contenu !== '' ? $contenu : null, $fichierId, $clientUuid]);
        $msgId = (int) $db->lastInsertId();

        // Remonte la conversation et marque l'expéditeur comme à jour.
        $db->prepare('UPDATE conversation SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$convId]);
        $db->prepare('UPDATE conversation_membre SET last_read_message_id = ? WHERE conversation_id = ? AND employe_id = ?')
           ->execute([$msgId, $convId, $me]);

        Response::json($this->messageParId($db, $msgId, $me), 201);
    }

    /** POST /api/conversations/{id}/lu — marque comme lu jusqu'au dernier (ou message_id). */
    public function marquerLu(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();
        $convId = (int) $params['id'];
        $this->exigerMembre($db, $convId, $me);

        $body = Request::body();
        if (isset($body['message_id']) && ctype_digit((string) $body['message_id'])) {
            $jusqua = (int) $body['message_id'];
        } else {
            $stmt = $db->prepare('SELECT MAX(id) FROM message WHERE conversation_id = ?');
            $stmt->execute([$convId]);
            $jusqua = (int) $stmt->fetchColumn();
        }

        $db->prepare(
            'UPDATE conversation_membre
             SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?)
             WHERE conversation_id = ? AND employe_id = ?'
        )->execute([$jusqua, $convId, $me]);

        Response::json(['message' => 'Marqué comme lu', 'last_read_message_id' => $jusqua]);
    }

    /** DELETE /api/messages/{id} — supprime (efface le contenu de) son propre message. */
    public function supprimerMessage(array $params): void
    {
        $me = Actor::requireEmployeId();
        $db = Database::connection();

        $stmt = $db->prepare('SELECT expediteur_id FROM message WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $exp = $stmt->fetchColumn();
        if ($exp === false) {
            Response::error('Message introuvable', 404);
        }
        if ((int) $exp !== $me) {
            Response::error('On ne peut supprimer que ses propres messages', 403);
        }
        $db->prepare("UPDATE message SET supprime = 1, contenu = NULL, fichier_id = NULL WHERE id = ?")
           ->execute([(int) $params['id']]);

        Response::json(['message' => 'Message supprimé']);
    }

    // ---------------------------------------------------------------- Helpers

    private function conv(PDO $db, int $id): array|false
    {
        $stmt = $db->prepare('SELECT * FROM conversation WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    private function estMembre(PDO $db, int $convId, int $employeId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM conversation_membre WHERE conversation_id = ? AND employe_id = ?');
        $stmt->execute([$convId, $employeId]);

        return $stmt->fetchColumn() !== false;
    }

    private function exigerMembre(PDO $db, int $convId, int $employeId): void
    {
        if (!$this->conv($db, $convId)) {
            Response::error('Conversation introuvable', 404);
        }
        if (!$this->estMembre($db, $convId, $employeId)) {
            Response::error('Vous ne faites pas partie de cette conversation', 403);
        }
    }

    private function role(PDO $db, int $convId, int $employeId): ?string
    {
        $stmt = $db->prepare('SELECT role FROM conversation_membre WHERE conversation_id = ? AND employe_id = ?');
        $stmt->execute([$convId, $employeId]);
        $r = $stmt->fetchColumn();

        return $r === false ? null : (string) $r;
    }

    private function ajouter(PDO $db, int $convId, int $employeId, string $role): bool
    {
        $stmt = $db->prepare(
            'INSERT IGNORE INTO conversation_membre (conversation_id, employe_id, role) VALUES (?, ?, ?)'
        );
        $stmt->execute([$convId, $employeId, $role]);

        return $stmt->rowCount() > 0;
    }

    /** Normalise une liste d'ids employés : entiers, existants, sans moi ni doublons. */
    private function idsValides(PDO $db, mixed $ids, int $me): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0 && $i !== $me)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id FROM employe WHERE id IN ($in)");
        $stmt->execute($ids);
        $existants = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $manquants = array_diff($ids, $existants);
        if ($manquants !== []) {
            Response::error('Employé(s) introuvable(s) : ' . implode(', ', $manquants), 422);
        }

        return $existants;
    }

    private function directExistant(PDO $db, int $a, int $b): ?int
    {
        $stmt = $db->prepare(
            "SELECT c.id FROM conversation c
             JOIN conversation_membre m1 ON m1.conversation_id = c.id AND m1.employe_id = ?
             JOIN conversation_membre m2 ON m2.conversation_id = c.id AND m2.employe_id = ?
             WHERE c.type = 'direct' LIMIT 1"
        );
        $stmt->execute([$a, $b]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Membres d'une conversation avec identité et curseur de lecture. */
    private function membres(PDO $db, int $convId): array
    {
        $stmt = $db->prepare(
            'SELECT cm.employe_id, cm.role, cm.last_read_message_id, e.nom, e.prenom, e.matricule
             FROM conversation_membre cm JOIN employe e ON e.id = cm.employe_id
             WHERE cm.conversation_id = ? ORDER BY e.nom, e.prenom'
        );
        $stmt->execute([$convId]);

        return array_map(static fn ($m) => [
            'employe_id'           => (int) $m['employe_id'],
            'nom'                  => $m['nom'],
            'prenom'               => $m['prenom'],
            'matricule'            => $m['matricule'],
            'role'                 => $m['role'],
            'last_read_message_id' => $m['last_read_message_id'] !== null ? (int) $m['last_read_message_id'] : null,
        ], $stmt->fetchAll());
    }

    /** Curseurs de lecture des AUTRES membres (pour les accusés de lecture). */
    private function lectures(PDO $db, int $convId, int $me): array
    {
        $stmt = $db->prepare(
            'SELECT employe_id, last_read_message_id FROM conversation_membre
             WHERE conversation_id = ? AND employe_id <> ?'
        );
        $stmt->execute([$convId, $me]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['employe_id']] = $r['last_read_message_id'] !== null ? (int) $r['last_read_message_id'] : 0;
        }

        return $out;
    }

    /** Résumé d'une conversation pour la liste (nom affiché, dernier message, non-lus). */
    private function resume(PDO $db, array $c, int $me): array
    {
        $convId = (int) $c['id'];
        $membres = $this->membres($db, $convId);

        $titre = $c['nom'];
        $autre = null;
        if ($c['type'] === 'direct') {
            foreach ($membres as $m) {
                if ($m['employe_id'] !== $me) {
                    $autre = $m;
                    $titre = trim($m['prenom'] . ' ' . $m['nom']);
                }
            }
        }

        // Dernier message.
        $stmt = $db->prepare(
            'SELECT m.id, m.type, m.contenu, m.expediteur_id, m.supprime, m.created_at
             FROM message m WHERE m.conversation_id = ? ORDER BY m.id DESC LIMIT 1'
        );
        $stmt->execute([$convId]);
        $last = $stmt->fetch() ?: null;

        // Non-lus pour moi.
        $monCurseur = 0;
        foreach ($membres as $m) {
            if ($m['employe_id'] === $me) {
                $monCurseur = $m['last_read_message_id'] ?? 0;
            }
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM message WHERE conversation_id = ? AND id > ? AND expediteur_id <> ? AND supprime = 0'
        );
        $stmt->execute([$convId, $monCurseur, $me]);
        $nonLus = (int) $stmt->fetchColumn();

        return [
            'id'             => $convId,
            'type'           => $c['type'],
            'nom'            => $titre,
            'autre_membre'   => $autre,
            'membres_count'  => count($membres),
            'dernier_message' => $last ? [
                'id'         => (int) $last['id'],
                'type'       => $last['type'],
                'apercu'     => $last['supprime'] ? 'Message supprimé' : $this->apercu($last),
                'mien'       => (int) $last['expediteur_id'] === $me,
                'created_at' => $last['created_at'],
            ] : null,
            'non_lus'    => $nonLus,
            'updated_at' => $c['updated_at'],
        ];
    }

    private function apercu(array $last): string
    {
        return match ($last['type']) {
            'image'    => '📷 Photo',
            'audio'    => '🎤 Message vocal',
            'document' => '📄 Document',
            'fichier'  => '📎 Pièce jointe',
            default    => mb_substr((string) ($last['contenu'] ?? ''), 0, 80),
        };
    }

    private function messageParId(PDO $db, int $id, int $me): array
    {
        $stmt = $db->prepare(
            'SELECT m.*, e.nom AS exp_nom, e.prenom AS exp_prenom,
                    f.mime AS f_mime, f.nom_original AS f_nom, f.taille AS f_taille
             FROM message m
             JOIN employe e ON e.id = m.expediteur_id
             LEFT JOIN fichier f ON f.id = m.fichier_id
             WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $lectures = $this->lectures($db, (int) $row['conversation_id'], $me);

        return $this->formatMessage($row, $me, $lectures);
    }

    /** Met en forme un message (expéditeur, pièce jointe, accusé de lecture). */
    private function formatMessage(array $r, int $me, array $lectures): array
    {
        $id = (int) $r['id'];
        $supprime = (int) $r['supprime'] === 1;
        $mien = (int) $r['expediteur_id'] === $me;

        // Accusé : nombre d'autres membres ayant lu ce message.
        $luPar = 0;
        foreach ($lectures as $curseur) {
            if ($curseur >= $id) {
                $luPar++;
            }
        }
        $statut = $luPar > 0 && $luPar >= count($lectures) ? 'lu' : ($luPar > 0 ? 'lu_partiel' : 'envoye');

        $fichier = null;
        if (!$supprime && $r['fichier_id'] !== null) {
            $fichier = [
                'id'           => (int) $r['fichier_id'],
                'mime'         => $r['f_mime'],
                'nom_original' => $r['f_nom'],
                'taille'       => $r['f_taille'] !== null ? (int) $r['f_taille'] : null,
                'url'          => '/api/fichiers/' . (int) $r['fichier_id'],
            ];
        }

        return [
            'id'              => $id,
            'conversation_id' => (int) $r['conversation_id'],
            'expediteur'      => [
                'id'     => (int) $r['expediteur_id'],
                'nom'    => $r['exp_nom'],
                'prenom' => $r['exp_prenom'],
            ],
            'mien'       => $mien,
            'type'       => $r['type'],
            'contenu'    => $supprime ? null : $r['contenu'],
            'fichier'    => $fichier,
            'supprime'   => $supprime,
            'statut'     => $mien ? $statut : null,
            'created_at' => $r['created_at'],
        ];
    }
}
