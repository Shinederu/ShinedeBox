<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

rate_limit('auth', 10, 60); // 10 req/min/IP

start_secure_session();

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'status':
        json_response(200, [
            'success' => true,
            'authenticated' => !empty($_SESSION['auth']) && $_SESSION['auth'] === true,
        ]);
        break;

    case 'login':
        $pwd = $_POST['password'] ?? '';
        global $AUTH_PASSWORD;
        if (!is_string($pwd) || $pwd === '') {
            json_response(400, ['success' => false, 'error' => 'Mot de passe requis']);
        }
        if (!hash_equals($AUTH_PASSWORD, $pwd)) {
            json_response(401, ['success' => false, 'error' => 'Identifiants invalides']);
        }
        $_SESSION['auth'] = true;
        json_response(200, ['success' => true]);
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        @session_destroy();
        json_response(200, ['success' => true]);
        break;

    default:
        json_response(400, ['success' => false, 'error' => 'Action inconnue']);
}

