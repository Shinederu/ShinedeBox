<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

require_login();
rate_limit('delete', 10, 60); // 10 req/min/IP

global $UPLOAD_DIR;

// Accept id via POST (preferred) or query for flexibility
$id = $_POST['id'] ?? $_GET['id'] ?? '';
if (!is_string($id) || $id === '') {
    json_response(400, ['success' => false, 'error' => 'Paramètre id requis']);
}

// Validate stored filename pattern: YYYYMMDD-HHMMSS-<hex16>.<ext>
if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.[A-Za-z0-9]{1,10}$/', $id)) {
    json_response(400, ['success' => false, 'error' => 'Identifiant invalide']);
}

$path = $UPLOAD_DIR . DIRECTORY_SEPARATOR . $id;
if (!is_file($path)) {
    json_response(404, ['success' => false, 'error' => 'Fichier introuvable']);
}

if (!@unlink($path)) {
    json_response(500, ['success' => false, 'error' => 'Échec suppression']);
}

json_response(200, ['success' => true]);

