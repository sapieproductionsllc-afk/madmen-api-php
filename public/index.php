<?php
declare(strict_types=1);

/**
 * Point d'entree de l'API (front controller).
 *
 * Lancer :  php -S 127.0.0.1:8000 -t public public/index.php
 */

// --- Autoload Composer (rats/zkteco, etc.) si présent ---
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

// --- Autoloader PSR-4 minimal (MadMen\ -> src/) — fallback ---
spl_autoload_register(static function (string $class): void {
    $prefix = 'MadMen\\';
    $base = dirname(__DIR__) . '/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Env;
use MadMen\Core\Response;
use MadMen\Core\Router;
use MadMen\Controllers\EmployeController;
use MadMen\Controllers\PointageController;
use MadMen\Controllers\SessionController;
use MadMen\Controllers\AlerteController;
use MadMen\Controllers\DashboardController;
use MadMen\Controllers\ProductiviteController;
use MadMen\Controllers\BiometrieController;
use MadMen\Controllers\ConfigController;
use MadMen\Controllers\K40Controller;
use MadMen\Controllers\K40PushController;
use MadMen\Controllers\SyncController;
use MadMen\Controllers\MotifController;
use MadMen\Controllers\PosteController;
use MadMen\Controllers\AuthController;
use MadMen\Controllers\HeuresSupController;
use MadMen\Controllers\HoraireController;
use MadMen\Controllers\PaieController;
use MadMen\Controllers\MessagerieController;
use MadMen\Controllers\FichierController;
use MadMen\Controllers\JourFerieController;
use MadMen\Controllers\IncidentController;
use MadMen\Controllers\MeController;
use MadMen\Controllers\DemandeController;
use MadMen\Controllers\NotificationController;
use MadMen\Controllers\ObjectifController;
use MadMen\Controllers\PretController;
// Intégration dashboard React (ressources additives — cf. docs/INTEGRATION-FRONT.md)
use MadMen\Controllers\DepenseController;
use MadMen\Controllers\PaiementController;
use MadMen\Controllers\ParametreController;
use MadMen\Controllers\UtilisateurController;
use MadMen\Controllers\AppareilController;
use MadMen\Controllers\RapportController;
use MadMen\Controllers\PresenceController;
use MadMen\Controllers\RhController;
use MadMen\Controllers\AjustementController;

// Cohérence horaire PHP/MySQL : fixe le fuseau PHP tôt (depuis APP_TIMEZONE,
// défaut Europe/Paris). Database aligne ensuite NOW()/CURDATE() MySQL dessus.
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Europe/Paris'));

// Sert les fichiers statiques existants tels quels (serveur intégré PHP).
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

// Garde-fou de production : refuse de démarrer si la config est dangereuse
// (auth off, secrets par défaut, CORS *). Sans effet hors production.
Auth::assertProductionSafe();

// En-têtes de sécurité (sans effet néfaste en dev ; indispensables en ligne).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if (Env::get('APP_ENV') === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// CORS : origine restreinte via .env (CORS_ORIGIN). Défaut '*' pour la démo.
header('Access-Control-Allow-Origin: ' . Env::get('CORS_ORIGIN', '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/') ?: '/';

$router = new Router();

// --- Système & documentation ---
$router->get('/', static fn () => Response::json([
    'app'     => 'MadMen API',
    'module'  => 'Gestion Intelligente des Employes & Controle des Postes',
    'version' => '0.1.0',
    'status'  => 'ok',
    'docs'    => '/docs',
]));

$router->get('/health', static function (): void {
    Database::connection()->query('SELECT 1');
    Response::json(['status' => 'ok', 'db' => 'connected']);
});

$router->get('/openapi.yaml', static function (): void {
    header('Content-Type: application/yaml; charset=utf-8');
    readfile(dirname(__DIR__) . '/openapi.yaml');
    exit;
});

$router->get('/docs', static function (): void {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MadMen API — Documentation</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>body { margin: 0; }</style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                url: '/openapi.yaml',
                dom_id: '#swagger-ui',
                deepLinking: true,
            });
        };
    </script>
</body>
</html>
HTML;
    exit;
});

// --- API : Employés ---
$router->get('/api/employes', [EmployeController::class, 'index']);
$router->post('/api/employes', [EmployeController::class, 'store']);
$router->get('/api/employes/{id}', [EmployeController::class, 'show']);
$router->put('/api/employes/{id}', [EmployeController::class, 'update']);
$router->delete('/api/employes/{id}', [EmployeController::class, 'destroy']);

// --- API : Horaire de travail par employé (retard/présence/heures sup) ---
$router->get('/api/employes/{id}/horaire', [HoraireController::class, 'show']);
$router->put('/api/employes/{id}/horaire', [HoraireController::class, 'upsert']);

// --- API : Paie mensuelle (bulletin par employé + liste de paie) ---
$router->get('/api/employes/{id}/paie', [PaieController::class, 'bulletin']);
$router->get('/api/paie', [PaieController::class, 'liste']);

// --- API : Jours fériés (journées payées, non comptées comme absence) ---
$router->get('/api/jours-feries', [JourFerieController::class, 'index']);
$router->post('/api/jours-feries', [JourFerieController::class, 'store']);
$router->delete('/api/jours-feries/{id}', [JourFerieController::class, 'destroy']);

// --- API : Configuration (front) ---
$router->get('/api/config/biometrie', [ConfigController::class, 'biometrie']);
$router->get('/api/config/postes', [ConfigController::class, 'postes']);
$router->get('/api/postes/{code}/roster', [PosteController::class, 'roster']);

// --- API : Enrôlement biométrique ---
$router->get('/api/employes/{id}/biometrie', [BiometrieController::class, 'index']);
$router->post('/api/employes/{id}/biometrie', [BiometrieController::class, 'store']);
$router->delete('/api/biometrie/{id}', [BiometrieController::class, 'destroy']);

// --- API : Présence ---
$router->get('/api/pointages', [PointageController::class, 'index']);
$router->post('/api/pointages', [PointageController::class, 'store']);
$router->get('/api/pointages/{id}/passages', [PointageController::class, 'passages']);
$router->post('/api/employes/{id}/pointage-manuel', [PointageController::class, 'manuel']);

// --- API : Sessions (temps réel) ---
$router->get('/api/sessions', [SessionController::class, 'index']);
$router->get('/api/sessions/{id}', [SessionController::class, 'show']);
$router->post('/api/sessions/login', [SessionController::class, 'login']);
$router->post('/api/sessions/login-pin', [SessionController::class, 'loginPin']);

// --- API : Authentification dashboard (login par PIN -> JWT + rôle) ---
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/login-pin', [AuthController::class, 'loginPin']);
$router->get('/api/auth/me', [AuthController::class, 'me']);

// --- API : Espace self-service de l'employé connecté (/api/me/*, scopé au jeton) ---
$router->get('/api/me/profil', [MeController::class, 'profil']);
$router->get('/api/me/pointages', [MeController::class, 'pointages']);
$router->get('/api/me/horaire', [MeController::class, 'horaire']);
$router->get('/api/me/paie', [MeController::class, 'paie']);
$router->get('/api/me/collegues', [MeController::class, 'collegues']);
// Demandes (self-service)
$router->get('/api/me/demandes', [DemandeController::class, 'mesDemandes']);
$router->post('/api/me/demandes', [DemandeController::class, 'creer']);
$router->post('/api/me/demandes/{id}/annuler', [DemandeController::class, 'annuler']);
// Notifications (self-service)
$router->get('/api/me/notifications', [NotificationController::class, 'index']);
$router->post('/api/me/notifications/tout-lire', [NotificationController::class, 'toutLire']);
$router->post('/api/me/notifications/{id}/lu', [NotificationController::class, 'lire']);
// Objectifs (self-service)
$router->get('/api/me/objectifs', [ObjectifController::class, 'index']);
$router->post('/api/me/objectifs', [ObjectifController::class, 'creer']);
$router->put('/api/me/objectifs/{id}', [ObjectifController::class, 'update']);
$router->delete('/api/me/objectifs/{id}', [ObjectifController::class, 'destroy']);
// Prêts / avances (lecture employé)
$router->get('/api/me/prets', [PretController::class, 'mesPrets']);

// --- API : Demandes (côté manager : liste + décision) ---
$router->get('/api/demandes', [DemandeController::class, 'index']);
$router->post('/api/demandes', [DemandeController::class, 'creerPour']);
$router->post('/api/demandes/{id}/decision', [DemandeController::class, 'decision']);

// --- API : Prêts / avances (gestion manager — données financières) ---
$router->get('/api/prets', [PretController::class, 'index']);
$router->post('/api/prets', [PretController::class, 'creer']);
$router->post('/api/prets/{id}/remboursement', [PretController::class, 'remboursement']);
$router->post('/api/sessions/identifier', [SessionController::class, 'identifier']);
$router->post('/api/sessions/{id}/lock', [SessionController::class, 'lock']);
$router->post('/api/sessions/{id}/unlock', [SessionController::class, 'unlock']);
$router->post('/api/sessions/{id}/logout', [SessionController::class, 'logout']);
$router->post('/api/sessions/{id}/activite', [SessionController::class, 'activite']);

// --- API : Journal d'activité du kiosque (surveillance, SANS impact paie) ---
$router->get('/api/kiosque-activite', [SessionController::class, 'journal']);

// --- API : Synchronisation montante (offline-first) ---
$router->post('/api/sync', [SyncController::class, 'sync']);

// --- API : Messagerie (communication interne : fils directs + groupes) ---
$router->get('/api/conversations', [MessagerieController::class, 'conversations']);
$router->post('/api/conversations', [MessagerieController::class, 'creer']);
$router->post('/api/conversations/broadcast', [MessagerieController::class, 'broadcast']);
$router->get('/api/conversations/{id}', [MessagerieController::class, 'show']);
$router->post('/api/conversations/{id}/membres', [MessagerieController::class, 'ajouterMembres']);
$router->delete('/api/conversations/{id}/membres/{employeId}', [MessagerieController::class, 'retirerMembre']);
$router->get('/api/conversations/{id}/messages', [MessagerieController::class, 'messages']);
$router->post('/api/conversations/{id}/messages', [MessagerieController::class, 'envoyer']);
$router->post('/api/conversations/{id}/lu', [MessagerieController::class, 'marquerLu']);
$router->delete('/api/messages/{id}', [MessagerieController::class, 'supprimerMessage']);

// --- API : Pièces jointes (images, audio/vocaux, PDF, documents) ---
$router->post('/api/fichiers', [FichierController::class, 'upload']);
$router->get('/api/fichiers/{id}', [FichierController::class, 'download']);

// --- API : Heures supplémentaires ---
$router->get('/api/heures-supplementaires', [HeuresSupController::class, 'index']);

// --- API : Alertes ---
$router->get('/api/alertes', [AlerteController::class, 'index']);
$router->post('/api/alertes/tout-lire', [AlerteController::class, 'toutLire']);
$router->post('/api/alertes/{id}/lu', [AlerteController::class, 'marquerLu']);

// --- API : Motifs d'absence ---
$router->get('/api/motifs', [MotifController::class, 'index']);

// --- API : Incidents d'inactivité (absences + motif/justification enregistrés) ---
$router->get('/api/incidents', [IncidentController::class, 'index']);

// --- API : Tableau de bord ---
$router->get('/api/dashboard/presence', [DashboardController::class, 'presence']);

// --- API : Productivité ---
$router->get('/api/productivite/classement', [ProductiviteController::class, 'classement']);
$router->get('/api/productivite/{id}', [ProductiviteController::class, 'show']);

// =====================================================================
// Intégration dashboard React — ressources ADDITIVES (docs/INTEGRATION-FRONT.md §3)
// N'altèrent aucun endpoint existant (kiosque/sync intacts).
// =====================================================================
// Dépenses société (Finance)
$router->get('/api/depenses', [DepenseController::class, 'index']);
$router->post('/api/depenses', [DepenseController::class, 'store']);
$router->delete('/api/depenses/{id}', [DepenseController::class, 'destroy']);
// Journal des paiements de paie + synthèse Finance (PaieController lecture inchangé)
$router->post('/api/paie/payer-lot', [PaiementController::class, 'payerLot']);
$router->post('/api/paie/{employe_id}/payer', [PaiementController::class, 'payer']);
$router->get('/api/mouvements', [PaiementController::class, 'mouvements']);
$router->get('/api/finance/synthese', [PaiementController::class, 'synthese']);
// Paramètres globaux entreprise
$router->get('/api/parametres', [ParametreController::class, 'index']);
$router->put('/api/parametres', [ParametreController::class, 'update']);
// Comptes & rôles du dashboard (lecture depuis employe)
$router->get('/api/utilisateurs', [UtilisateurController::class, 'index']);
$router->get('/api/roles', [UtilisateurController::class, 'roles']);
// Appareils biométriques (lecture de appareil_biometrique)
$router->get('/api/appareils', [AppareilController::class, 'index']);
$router->get('/api/appareils/{id}', [AppareilController::class, 'show']);
// Rapports (agrégats + export imprimable -> PDF navigateur)
$router->get('/api/rapports/synthese', [RapportController::class, 'synthese']);
$router->get('/api/rapports/export', [RapportController::class, 'export']);
// Composition du salaire : primes / retenues manuelles par mois (#4)
$router->get('/api/employes/{id}/ajustements', [AjustementController::class, 'index']);
$router->post('/api/employes/{id}/ajustements', [AjustementController::class, 'store']);
$router->delete('/api/ajustements/{id}', [AjustementController::class, 'destroy']);
// Présence temps réel + calendrier de présence d'un agent (vue admin)
$router->get('/api/presence/temps-reel', [PresenceController::class, 'tempsReel']);
$router->get('/api/employes/{id}/presence', [PresenceController::class, 'calendrier']);
// Documents RH & historique RH
$router->get('/api/employes/{id}/documents', [RhController::class, 'documents']);
$router->get('/api/employes/{id}/historique-rh', [RhController::class, 'historique']);

$k40Config = require dirname(__DIR__) . '/config/k40.php';

// --- API : Pointeuse K40 — mode PULL (l'API interroge le K40 sur le LAN) ---
// Ces routes (interrogation directe du K40 + pont pyzk) n'ont de sens que sur la
// PASSERELLE locale (K40_ROLE=gateway). En 'cloud' (mutualisé) elles ne sont PAS
// montées : le cloud ne peut pas joindre le K40 (NAT) et n'a pas proc_open/UDP.
if (($k40Config['role'] ?? 'cloud') === 'gateway') {
    $router->get('/api/k40/status', [K40Controller::class, 'status']);
    $router->post('/api/k40/sync', [K40Controller::class, 'sync']);
    $router->get('/api/k40/users', [K40Controller::class, 'users']);
    $router->post('/api/k40/push-user/{id}', [K40Controller::class, 'pushUser']);
    $router->post('/api/k40/push-all', [K40Controller::class, 'pushAll']);
    $router->delete('/api/k40/users/{id}', [K40Controller::class, 'removeUser']);
    $router->post('/api/k40/clear-users', [K40Controller::class, 'clearUsers']);
    $router->post('/api/k40/push-fingerprints', [K40Controller::class, 'pushFingerprints']);
}

// --- Pointeuse K40 — mode PUSH / ADMS (le K40 envoie vers l'API, protocole iclock) ---
// C1.3 : ces routes ne sont enregistrées qu'en mode 'push' ou 'both'. En mode
// 'pull' (défaut) elles n'existent pas (réduction de la surface d'attaque).
if (in_array($k40Config['mode'] ?? 'pull', ['push', 'both'], true)) {
    $router->get('/iclock/cdata', [K40PushController::class, 'handshake']);
    $router->post('/iclock/cdata', [K40PushController::class, 'receive']);
    $router->get('/iclock/getrequest', [K40PushController::class, 'getrequest']);
    $router->post('/iclock/devicecmd', [K40PushController::class, 'devicecmd']);
}

// --- Authentification (C1) : appliquée AVANT le dispatch, après l'enregistrement
// des routes. Liste blanche : /, /health, /docs, /openapi.yaml. Piloté par
// AUTH_ENABLED (.env) ; si false, on laisse passer (démo).
Auth::enforce($method, $uri);

try {
    $router->dispatch($method, $uri);
} catch (Throwable $e) {
    // C3 : message générique en prod, détail uniquement si APP_DEBUG=true.
    if (Env::bool('APP_DEBUG', false)) {
        Response::json(['error' => $e->getMessage()], 500);
    }
    error_log('Erreur non gérée : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::json(['error' => 'Erreur interne'], 500);
}
