<?php

declare(strict_types=1);

function load_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

load_env(dirname(__DIR__) . '/.env');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Ho_Chi_Minh');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '5432');
    $name = env_value('DB_NAME', 'library');
    $user = env_value('DB_USER', 'postgres');
    $password = env_value('DB_PASSWORD', '');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($stored) || $stored === '' || !hash_equals($stored, $sent)) {
        http_response_code(419);
        exit('Phiên biểu mẫu hết hạn. Hãy quay lại và thử lại.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $page = 'dashboard'): never
{
    header('Location: ?page=' . rawurlencode($page));
    exit;
}

function post_string(string $key, int $maxLength = 255): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("Trường {$key} vượt quá {$maxLength} ký tự.");
    }
    return $value;
}

function post_int(string $key, int $min = 0, int $max = 2147483647): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        throw new InvalidArgumentException("Giá trị {$key} không hợp lệ.");
    }
    return $value;
}
