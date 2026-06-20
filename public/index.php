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

// Sert les fichiers statiques existants tels quels (serveur intégré PHP).
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
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

// --- API : Configuration (front) ---
$router->get('/api/config/biometrie', [ConfigController::class, 'biometrie']);

// --- API : Enrôlement biométrique ---
$router->get('/api/employes/{id}/biometrie', [BiometrieController::class, 'index']);
$router->post('/api/employes/{id}/biometrie', [BiometrieController::class, 'store']);
$router->delete('/api/biometrie/{id}', [BiometrieController::class, 'destroy']);

// --- API : Présence ---
$router->get('/api/pointages', [PointageController::class, 'index']);
$router->post('/api/pointages', [PointageController::class, 'store']);

// --- API : Sessions (temps réel) ---
$router->get('/api/sessions', [SessionController::class, 'index']);
$router->post('/api/sessions/login', [SessionController::class, 'login']);
$router->post('/api/sessions/identifier', [SessionController::class, 'identifier']);
$router->post('/api/sessions/{id}/lock', [SessionController::class, 'lock']);
$router->post('/api/sessions/{id}/unlock', [SessionController::class, 'unlock']);
$router->post('/api/sessions/{id}/logout', [SessionController::class, 'logout']);
$router->post('/api/sessions/{id}/activite', [SessionController::class, 'activite']);

// --- API : Alertes ---
$router->get('/api/alertes', [AlerteController::class, 'index']);

// --- API : Tableau de bord ---
$router->get('/api/dashboard/presence', [DashboardController::class, 'presence']);

// --- API : Productivité ---
$router->get('/api/productivite/classement', [ProductiviteController::class, 'classement']);
$router->get('/api/productivite/{id}', [ProductiviteController::class, 'show']);

// --- API : Pointeuse K40 (terminal de pointage) ---
$router->get('/api/k40/status', [K40Controller::class, 'status']);
$router->post('/api/k40/sync', [K40Controller::class, 'sync']);
$router->get('/api/k40/users', [K40Controller::class, 'users']);
$router->post('/api/k40/push-user/{id}', [K40Controller::class, 'pushUser']);

// --- Authentification (C1) : appliquée AVANT le dispatch, après l'enregistrement
// des routes. Liste blanche : /, /health, /docs, /openapi.yaml. Piloté par
// AUTH_ENABLED (.env) ; si false, on laisse passer (démo).
Auth::enforce($uri);

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
