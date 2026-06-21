<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Request;
use MadMen\Core\Response;

final class SessionController
{
    /** Anti-brute-force : nb max d'échecs PIN tolérés sur la fenêtre ci-dessous. */
    private const MAX_ECHECS_PIN = 5;

    /** Fenêtre glissante (en minutes) pour le comptage des échecs. */
    private const FENETRE_ECHECS_MINUTES = 15;

    public function index(): void
    {
        $sql = 'SELECT * FROM session_travail WHERE 1=1';
        $params = [];

        if (($statut = Request::query('statut')) !== null) {
            $sql .= ' AND statut = :statut';
            $params['statut'] = $statut;
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    /**
     * Détail d'une session (colonnes explicites). Sert au kiosque/poste pour
     * détecter un verrouillage forcé : quand le K40 a marqué la personne « partie »,
     * le statut passe à 'verrouillee'. 404 si la session est introuvable.
     */
    public function show(array $params): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, employe_id, poste_travail_id, statut, heure_debut, heure_fin
             FROM session_travail WHERE id = ?'
        );
        $stmt->execute([(int) $params['id']]);
        $session = $stmt->fetch();

        if (!$session) {
            Response::error('Session introuvable', 404);
        }

        Response::json($session);
    }

    /**
     * Ouverture de session : Matricule + Code PIN (+ empreinte validée côté device).
     * Vérifie PIN, autorisation sur le poste, puis ouvre la session.
     */
    public function login(): void
    {
        $db = Database::connection();
        $body = Request::body();

        foreach (['matricule', 'code_pin', 'poste_travail_code'] as $required) {
            if (empty($body[$required])) {
                Response::error("Le champ '$required' est obligatoire", 422);
            }
        }

        // 1) Poste de travail
        $stmt = $db->prepare('SELECT * FROM poste_travail WHERE code = ?');
        $stmt->execute([$body['poste_travail_code']]);
        $poste = $stmt->fetch();
        if (!$poste) {
            Response::error('Poste de travail inconnu', 404);
        }
        $posteId = (int) $poste['id'];

        // 1bis) Anti-brute-force : trop d'échecs récents sur ce poste => 429.
        if ($this->tropDeTentatives($posteId)) {
            Response::error('Trop de tentatives, réessayez plus tard', 429);
        }

        // 2) Employé — colonnes explicites (jamais de fuite involontaire ; le
        //    hash sert uniquement à la vérification locale ci-dessous).
        $stmt = $db->prepare(
            'SELECT id, matricule, nom, prenom, superieur_id, code_pin_hash FROM employe WHERE matricule = ?'
        );
        $stmt->execute([$body['matricule']]);
        $employe = $stmt->fetch();

        // 3) Vérification du PIN
        if (!$employe || !password_verify((string) $body['code_pin'], (string) $employe['code_pin_hash'])) {
            $this->logTentative($employe['id'] ?? null, $posteId, 'pin', 'echec', 'PIN erroné ou matricule inconnu');
            Response::error('Identifiants invalides', 401);
        }
        $empId = (int) $employe['id'];

        // 4) Autorisation sur le poste
        $stmt = $db->prepare('SELECT 1 FROM autorisation_poste WHERE employe_id = ? AND poste_travail_id = ?');
        $stmt->execute([$empId, $posteId]);
        if (!$stmt->fetchColumn()) {
            $this->logTentative($empId, $posteId, 'pin', 'echec', 'Non autorisé sur ce poste');
            $this->alerte('connexion_refusee', $empId, $posteId, $employe['superieur_id'],
                'Tentative de connexion refusée (poste non autorisé)');
            Response::error('Vous n\'êtes pas autorisé sur ce poste', 403);
        }

        // 5) Empreinte (2e facteur, validée par le device)
        $empreinteOk = !empty($body['empreinte_ok']);
        $methodeAuth = $empreinteOk ? 'pin+empreinte' : 'pin';

        // 6) Session unique : on ferme d'abord toute session ouverte de l'employé
        //    sur un autre poste (le nouveau login « gagne »), puis on ouvre.
        $this->fermerSessionsOuvertes($empId);

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO session_travail (employe_id, poste_travail_id, heure_debut, methode_auth, autorisation_ok, statut)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$empId, $posteId, $now, $methodeAuth, 'ouverte']);
        $sessionId = (int) $db->lastInsertId();

        $db->prepare("UPDATE poste_travail SET statut = 'occupe' WHERE id = ?")->execute([$posteId]);
        $this->logTentative($empId, $posteId, $empreinteOk ? 'empreinte' : 'pin', 'succes', null);

        Response::json([
            'message' => 'Session ouverte',
            'session' => $this->find($sessionId),
            'employe' => [
                'id'        => $empId,
                'matricule' => $employe['matricule'],
                'nom'       => $employe['nom'],
                'prenom'    => $employe['prenom'],
            ],
        ], 201);
    }

    /**
     * Ouverture de session par PIN seul (sans matricule).
     *
     * Le PIN identifie l'employé PARMI ceux autorisés sur le poste (recherche bornée
     * au poste : sur un PC personnel, c'est en général une seule personne). Plus sûr
     * qu'une recherche globale de PIN (pas de collision entre postes).
     */
    public function loginPin(): void
    {
        $db = Database::connection();
        $body = Request::body();

        foreach (['code_pin', 'poste_travail_code'] as $required) {
            if (empty($body[$required])) {
                Response::error("Le champ '$required' est obligatoire", 422);
            }
        }

        // 1) Poste de travail
        $stmt = $db->prepare('SELECT * FROM poste_travail WHERE code = ?');
        $stmt->execute([$body['poste_travail_code']]);
        $poste = $stmt->fetch();
        if (!$poste) {
            Response::error('Poste de travail inconnu', 404);
        }
        $posteId = (int) $poste['id'];

        // 2) Anti-brute-force
        if ($this->tropDeTentatives($posteId)) {
            Response::error('Trop de tentatives, réessayez plus tard', 429);
        }

        // 3) Identification : on cherche, parmi les employés autorisés sur ce poste,
        //    celui dont le PIN correspond (password_verify sur chaque candidat).
        $stmt = $db->prepare(
            'SELECT e.id, e.matricule, e.nom, e.prenom, e.superieur_id, e.code_pin_hash
             FROM employe e
             JOIN autorisation_poste a ON a.employe_id = e.id AND a.poste_travail_id = ?'
        );
        $stmt->execute([$posteId]);

        $employe = null;
        foreach ($stmt->fetchAll() as $candidat) {
            if (password_verify((string) $body['code_pin'], (string) $candidat['code_pin_hash'])) {
                $employe = $candidat;
                break;
            }
        }

        if (!$employe) {
            $this->logTentative(null, $posteId, 'pin', 'echec', 'PIN ne correspond à aucun employé autorisé sur ce poste');
            Response::error('PIN invalide', 401);
        }
        $empId = (int) $employe['id'];

        // 4) Empreinte (2e facteur, validée par le device)
        $empreinteOk = !empty($body['empreinte_ok']);
        $methodeAuth = $empreinteOk ? 'pin+empreinte' : 'pin';

        // 5) Session unique : on ferme d'abord toute session ouverte de l'employé
        //    sur un autre poste (le nouveau login « gagne »), puis on ouvre.
        $this->fermerSessionsOuvertes($empId);

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO session_travail (employe_id, poste_travail_id, heure_debut, methode_auth, autorisation_ok, statut)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$empId, $posteId, $now, $methodeAuth, 'ouverte']);
        $sessionId = (int) $db->lastInsertId();

        $db->prepare("UPDATE poste_travail SET statut = 'occupe' WHERE id = ?")->execute([$posteId]);
        $this->logTentative($empId, $posteId, $empreinteOk ? 'empreinte' : 'pin', 'succes', null);

        Response::json([
            'message' => 'Session ouverte',
            'session' => $this->find($sessionId),
            'employe' => [
                'id'        => $empId,
                'matricule' => $employe['matricule'],
                'nom'       => $employe['nom'],
                'prenom'    => $employe['prenom'],
            ],
        ], 201);
    }

    /**
     * Connexion par empreinte (identification 1:N) — ouvre la session sans matricule ni PIN.
     *
     * Le matching réel de gabarits (ANSI/ISO) n'est PAS faisable en PHP pur : il est
     * délégué au service ZKBioOnline / au device, qui résout l'empreinte capturée en un
     * employe_id. Ce backend se contente alors d'ouvrir la session.
     *
     *  - Mode simulation (BIO_SIMULATION=true) : on simule l'identification en choisissant
     *    un employé qui a une empreinte enrôlée ET qui est autorisé sur le poste, puis on
     *    ouvre la session. La réponse porte "simulated": true.
     *  - Mode réel : l'identification est faite par ZKBioOnline/device qui doit fournir
     *    l'employe_id. Sans employe_id, on renvoie 501 (service biométrique requis).
     */
    public function identifier(): void
    {
        $db = Database::connection();
        $body = Request::body();
        $cfg = require dirname(__DIR__, 2) . '/config/biometrie.php';
        $simulation = (bool) $cfg['simulation'];

        if (empty($body['poste_travail_code'])) {
            Response::error("Le champ 'poste_travail_code' est obligatoire", 422);
        }

        // 1) Poste de travail
        $stmt = $db->prepare('SELECT * FROM poste_travail WHERE code = ?');
        $stmt->execute([$body['poste_travail_code']]);
        $poste = $stmt->fetch();
        if (!$poste) {
            Response::error('Poste de travail inconnu', 404);
        }
        $posteId = (int) $poste['id'];

        // 2) Résolution de l'employé (identification 1:N)
        if ($simulation) {
            // Simulation : on prend un employé ayant une empreinte enrôlée active et
            // autorisé sur ce poste. Aucun matching de gabarit n'est effectué.
            $stmt = $db->prepare(
                'SELECT e.id, e.matricule, e.nom, e.prenom, e.superieur_id
                 FROM employe e
                 JOIN employe_biometrie b ON b.employe_id = e.id AND b.type = \'empreinte\' AND b.actif = 1
                 JOIN autorisation_poste a ON a.employe_id = e.id AND a.poste_travail_id = ?
                 ORDER BY e.id
                 LIMIT 1'
            );
            $stmt->execute([$posteId]);
            $employe = $stmt->fetch();

            if (!$employe) {
                $this->logTentative(null, $posteId, 'empreinte', 'echec',
                    'Aucun employé avec empreinte enrôlée et autorisé sur ce poste (simulation)');
                Response::error(
                    'Aucun employé avec empreinte enrôlée n\'est autorisé sur ce poste',
                    404
                );
            }
        } else {
            // Mode réel : ZKBioOnline / le device fait le matching et renvoie l'employe_id.
            if (empty($body['employe_id'])) {
                Response::error(
                    'Identification biométrique indisponible : le matching 1:N doit être '
                    . 'effectué par le service ZKBioOnline/device, qui doit fournir employe_id.',
                    501
                );
            }

            $stmt = $db->prepare(
                'SELECT id, matricule, nom, prenom, superieur_id FROM employe WHERE id = ?'
            );
            $stmt->execute([(int) $body['employe_id']]);
            $employe = $stmt->fetch();
            if (!$employe) {
                $this->logTentative(null, $posteId, 'empreinte', 'echec', 'employe_id inconnu');
                Response::error('Employé inconnu', 404);
            }

            // L'employé identifié doit posséder une empreinte enrôlée active.
            $stmt = $db->prepare(
                'SELECT 1 FROM employe_biometrie WHERE employe_id = ? AND type = \'empreinte\' AND actif = 1'
            );
            $stmt->execute([(int) $employe['id']]);
            if (!$stmt->fetchColumn()) {
                $this->logTentative((int) $employe['id'], $posteId, 'empreinte', 'echec',
                    'Aucune empreinte enrôlée pour cet employé');
                Response::error('Aucune empreinte enrôlée pour cet employé', 422);
            }
        }

        $empId = (int) $employe['id'];

        // 3) Autorisation sur le poste
        $stmt = $db->prepare('SELECT 1 FROM autorisation_poste WHERE employe_id = ? AND poste_travail_id = ?');
        $stmt->execute([$empId, $posteId]);
        if (!$stmt->fetchColumn()) {
            $this->logTentative($empId, $posteId, 'empreinte', 'echec', 'Non autorisé sur ce poste');
            $this->alerte('connexion_refusee', $empId, $posteId, $employe['superieur_id'] ?? null,
                'Tentative de connexion refusée (poste non autorisé)');
            Response::error('Vous n\'êtes pas autorisé sur ce poste', 403);
        }

        // 4) Ouverture de session (même logique que login())
        //    Session unique : on ferme d'abord toute session ouverte de l'employé
        //    sur un autre poste (le nouveau login « gagne »), puis on ouvre.
        $this->fermerSessionsOuvertes($empId);

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO session_travail (employe_id, poste_travail_id, heure_debut, methode_auth, autorisation_ok, statut)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$empId, $posteId, $now, 'empreinte', 'ouverte']);
        $sessionId = (int) $db->lastInsertId();

        $db->prepare("UPDATE poste_travail SET statut = 'occupe' WHERE id = ?")->execute([$posteId]);
        $this->logTentative($empId, $posteId, 'empreinte', 'succes', $simulation ? 'identification simulée' : null);

        Response::json([
            'message'   => 'Session ouverte (empreinte)',
            'simulated' => $simulation,
            'session'   => $this->find($sessionId),
            'employe'   => [
                'id'        => $empId,
                'matricule' => $employe['matricule'],
                'nom'       => $employe['nom'],
                'prenom'    => $employe['prenom'],
            ],
        ], 201);
    }

    /** Verrouillage (inactivité détectée). Crée un incident ouvert + alerte le supérieur. */
    public function lock(array $params): void
    {
        $db = Database::connection();
        $session = $this->find((int) $params['id']);
        if ($session === null) {
            Response::error('Session introuvable', 404);
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE session_travail SET statut = 'verrouillee' WHERE id = ?")->execute([$session['id']]);
        $db->prepare("UPDATE poste_travail SET statut = 'verrouille' WHERE id = ?")->execute([$session['poste_travail_id']]);

        $db->prepare(
            'INSERT INTO incident_inactivite (session_id, employe_id, poste_travail_id, heure_verrouillage, statut)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$session['id'], $session['employe_id'], $session['poste_travail_id'], $now, 'ouvert']);

        $superieur = $this->superieurDe((int) $session['employe_id']);
        $this->alerte('inactivite', (int) $session['employe_id'], (int) $session['poste_travail_id'],
            $superieur, 'Poste verrouillé pour inactivité');

        Response::json(['message' => 'Session verrouillée', 'session' => $this->find((int) $session['id'])]);
    }

    /** Reprise : Code PIN + motif. Clôture l'incident d'inactivité ouvert. */
    public function unlock(array $params): void
    {
        $db = Database::connection();
        $session = $this->find((int) $params['id']);
        if ($session === null) {
            Response::error('Session introuvable', 404);
        }

        $body = Request::body();
        if (empty($body['code_pin'])) {
            Response::error("Le champ 'code_pin' est obligatoire", 422);
        }

        $posteId = (int) $session['poste_travail_id'];

        // Anti-brute-force : trop d'échecs récents sur ce poste => 429.
        if ($this->tropDeTentatives($posteId)) {
            Response::error('Trop de tentatives, réessayez plus tard', 429);
        }

        $stmt = $db->prepare('SELECT code_pin_hash FROM employe WHERE id = ?');
        $stmt->execute([$session['employe_id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify((string) $body['code_pin'], (string) $hash)) {
            $this->logTentative((int) $session['employe_id'], $posteId, 'pin', 'echec', 'PIN erroné (reprise)');
            Response::error('PIN invalide', 401);
        }

        // Clôture du dernier incident ouvert
        $stmt = $db->prepare(
            "SELECT * FROM incident_inactivite WHERE session_id = ? AND statut = 'ouvert' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$session['id']]);
        $incident = $stmt->fetch();

        if ($incident) {
            $now = date('Y-m-d H:i:s');
            $duree = (int) round((strtotime($now) - strtotime($incident['heure_verrouillage'])) / 60);
            $db->prepare(
                "UPDATE incident_inactivite
                 SET heure_reprise = ?, duree_minutes = ?, motif_id = ?, justification = ?, statut = 'justifie'
                 WHERE id = ?"
            )->execute([
                $now, $duree, $body['motif_id'] ?? null, $body['justification'] ?? null, $incident['id'],
            ]);
        }

        $db->prepare("UPDATE session_travail SET statut = 'ouverte' WHERE id = ?")->execute([$session['id']]);
        $db->prepare("UPDATE poste_travail SET statut = 'occupe' WHERE id = ?")->execute([$session['poste_travail_id']]);

        Response::json(['message' => 'Session reprise', 'session' => $this->find((int) $session['id'])]);
    }

    /** Fermeture de session : calcule les durées active/inactive. */
    public function logout(array $params): void
    {
        $db = Database::connection();
        $session = $this->find((int) $params['id']);
        if ($session === null) {
            Response::error('Session introuvable', 404);
        }

        $now = date('Y-m-d H:i:s');
        $totalSec = max(0, strtotime($now) - strtotime($session['heure_debut']));

        $stmt = $db->prepare('SELECT COALESCE(SUM(duree_minutes), 0) FROM incident_inactivite WHERE session_id = ?');
        $stmt->execute([$session['id']]);
        $inactiveSec = (int) $stmt->fetchColumn() * 60;
        $activeSec = max(0, $totalSec - $inactiveSec);

        $db->prepare(
            "UPDATE session_travail
             SET statut = 'fermee', heure_fin = ?, duree_active_sec = ?, duree_inactive_sec = ?
             WHERE id = ?"
        )->execute([$now, $activeSec, $inactiveSec, $session['id']]);

        $db->prepare("UPDATE poste_travail SET statut = 'libre' WHERE id = ?")->execute([$session['poste_travail_id']]);

        Response::json(['message' => 'Session fermée', 'session' => $this->find((int) $session['id'])]);
    }

    /** Heartbeat de surveillance : enregistre un échantillon d'activité. */
    public function activite(array $params): void
    {
        $session = $this->find((int) $params['id']);
        if ($session === null) {
            Response::error('Session introuvable', 404);
        }

        $body = Request::body();

        // Un heartbeat doit porter au moins un signal d'activité exploitable.
        $champsSignal = ['mouvements_souris', 'frappes_clavier', 'app_active', 'niveau_activite'];
        $aSignal = false;
        foreach ($champsSignal as $champ) {
            if (array_key_exists($champ, $body) && $body[$champ] !== null && $body[$champ] !== '') {
                $aSignal = true;
                break;
            }
        }
        if (!$aSignal) {
            Response::error(
                "Au moins un champ d'activité est requis (mouvements_souris, frappes_clavier, app_active ou niveau_activite)",
                422
            );
        }

        Database::connection()->prepare(
            'INSERT INTO activite_echantillon (session_id, horodatage, mouvements_souris, frappes_clavier, app_active, niveau_activite)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $session['id'], date('Y-m-d H:i:s'),
            (int) ($body['mouvements_souris'] ?? 0), (int) ($body['frappes_clavier'] ?? 0),
            $body['app_active'] ?? null, $body['niveau_activite'] ?? 'actif',
        ]);

        Response::json(['message' => 'Activité enregistrée'], 201);
    }

    // ----------------------------------------------------------------- helpers

    private function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM session_travail WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Session unique par employé : ferme toutes les sessions encore 'ouverte' de
     * cet employé (typiquement sur un AUTRE poste) avant d'en ouvrir une nouvelle.
     * Le nouveau login « gagne » ; l'ancien poste, en pollant GET /api/sessions/{id},
     * verra statut='fermee' et reviendra à l'écran de login (déconnexion auto).
     * Libère aussi le poste de chaque session fermée. Requêtes préparées.
     */
    private function fermerSessionsOuvertes(int $employeId): void
    {
        $db = Database::connection();
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare(
            "SELECT id, poste_travail_id FROM session_travail WHERE employe_id = ? AND statut = 'ouverte'"
        );
        $stmt->execute([$employeId]);

        foreach ($stmt->fetchAll() as $ancienne) {
            $db->prepare("UPDATE session_travail SET statut = 'fermee', heure_fin = ? WHERE id = ?")
                ->execute([$now, $ancienne['id']]);
            $db->prepare("UPDATE poste_travail SET statut = 'libre' WHERE id = ?")
                ->execute([$ancienne['poste_travail_id']]);
        }
    }

    private function superieurDe(int $employeId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT superieur_id FROM employe WHERE id = ?');
        $stmt->execute([$employeId]);
        $sup = $stmt->fetchColumn();

        return $sup ? (int) $sup : null;
    }

    /**
     * Anti-brute-force : vrai si le nombre d'échecs ('echec') enregistrés pour ce
     * poste sur la fenêtre glissante dépasse le seuil autorisé. Requête préparée.
     */
    private function tropDeTentatives(int $posteId): bool
    {
        // Désactivable via .env : BRUTE_FORCE_ENABLED=false => jamais de blocage.
        if (!\MadMen\Core\Env::bool('BRUTE_FORCE_ENABLED', true)) {
            return false;
        }

        $depuis = date('Y-m-d H:i:s', time() - self::FENETRE_ECHECS_MINUTES * 60);

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM tentative_connexion
             WHERE poste_travail_id = ? AND resultat = 'echec' AND horodatage >= ?"
        );
        $stmt->execute([$posteId, $depuis]);

        return (int) $stmt->fetchColumn() >= self::MAX_ECHECS_PIN;
    }

    private function logTentative(?int $employeId, int $posteId, string $methode, string $resultat, ?string $raison): void
    {
        Database::connection()->prepare(
            'INSERT INTO tentative_connexion (employe_id, poste_travail_id, horodatage, methode, resultat, raison_echec)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$employeId, $posteId, date('Y-m-d H:i:s'), $methode, $resultat, $raison]);
    }

    private function alerte(string $type, int $employeId, int $posteId, ?int $destinataire, string $message): void
    {
        Database::connection()->prepare(
            'INSERT INTO alerte (type, employe_id, poste_travail_id, destinataire_id, message, horodatage, lu)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        )->execute([$type, $employeId, $posteId, $destinataire, $message, date('Y-m-d H:i:s')]);
    }
}
