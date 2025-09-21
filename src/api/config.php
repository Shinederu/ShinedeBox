<?php
declare(strict_types=1);

// Simple config + helpers shared by API endpoints

// Load .env (best-effort)
function load_dotenv(string $path): void {
    if (!is_file($path)) return;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        // Do not override existing env
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
load_dotenv($projectRoot . '/.env');

function env(string $key, ?string $default = null): string {
    $v = getenv($key);
    if ($v === false || $v === null || $v === '') return $default ?? '';
    return (string)$v;
}

$BASE_URL = env('BASE_URL', 'https://box.shinederu.lol');
$UPLOAD_DIR = env('UPLOAD_DIR', '/var/www/shinedebox/public/uploads');
$MAX_FILE_MB = (int) (env('MAX_FILE_MB', '2048'));
$AUTH_PASSWORD = env('AUTH_PASSWORD', 'change-me');
$ALLOWED_EXT = array_filter(array_map('trim', explode(',', env('ALLOWED_EXT', '.zip,.jar,.png,.jpg,.jpeg,.pdf'))));
$ALLOWED_MIME = array_filter(array_map('trim', explode(',', env('ALLOWED_MIME', 'application/zip,application/x-zip-compressed,application/java-archive,image/png,image/jpeg,application/pdf'))));

// If UPLOAD_DIR is relative, make it relative to project root
if (!str_starts_with($UPLOAD_DIR, DIRECTORY_SEPARATOR)) {
    $UPLOAD_DIR = $projectRoot . DIRECTORY_SEPARATOR . $UPLOAD_DIR;
}

// Ensure upload dir exists at runtime (best-effort)
if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);

// JSON response helper
function json_response(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Session helpers
function start_secure_session(): void {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
}

function require_login(): void {
    start_secure_session();
    if (empty($_SESSION['auth']) || $_SESSION['auth'] !== true) {
        json_response(401, ['success' => false, 'error' => 'Non authentifié']);
    }
}

// Simple IP-based rate limit using files
function rate_limit(string $key, int $limit, int $windowSeconds): void {
    $dir = __DIR__ . '/_ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $bucket = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key . '_' . $ip) . '.json';
    $data = ['ts' => [], 'limit' => $limit, 'window' => $windowSeconds];
    if (is_file($bucket)) {
        $raw = @file_get_contents($bucket);
        if ($raw !== false) {
            $tmp = @json_decode($raw, true);
            if (is_array($tmp) && isset($tmp['ts']) && is_array($tmp['ts'])) $data = $tmp;
        }
    }
    // prune old
    $data['ts'] = array_values(array_filter($data['ts'], fn($t) => (int)$t > $now - $windowSeconds));
    if (count($data['ts']) >= $limit) {
        json_response(429, ['success' => false, 'error' => 'Trop de requêtes, réessayez plus tard']);
    }
    $data['ts'][] = $now;
    @file_put_contents($bucket, json_encode($data));
}

// Validation helpers
function is_ascii_name(string $name): bool {
    // no slashes, control chars; ASCII printable only
    if ($name === '' || preg_match('/[\\\/\x00-\x1F\x7F]/', $name)) return false;
    return (bool)preg_match('/^[\x20-\x7E]+$/', $name);
}

function get_ext(string $name): string {
    $ext = strtolower('.' . (pathinfo($name, PATHINFO_EXTENSION) ?: ''));
    return $ext === '.' ? '' : $ext;
}

function is_double_ext_danger(string $name): bool {
    return (bool)preg_match('/\.(php|phtml|phar|pl|py|sh|exe|bat|cmd|com)(\..*)?$/i', $name);
}

function mime_of(string $tmp): string {
    $f = new finfo(FILEINFO_MIME_TYPE);
    $m = $f->file($tmp) ?: '';
    return $m;
}

function unique_filename(string $ext): string {
    $ts = date('Ymd-His');
    $rand = bin2hex(random_bytes(8));
    $ext = ltrim($ext, '.');
    return sprintf('%s-%s.%s', $ts, $rand, $ext);
}

function storage_url(string $baseUrl, string $stored): string {
    return rtrim($baseUrl, '/') . '/uploads/' . rawurlencode($stored);
}

