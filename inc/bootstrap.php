<?php
declare(strict_types=1);

date_default_timezone_set('America/Asuncion');
require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

session_name('piexpo');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

/** Raíz web de la app (p. ej. "/petersenexpomaquila/"), calculada desde este archivo. */
function base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $app = realpath(__DIR__ . '/..') ?: '';
    $rel = ($root && str_starts_with($app, $root)) ? substr($app, strlen($root)) : '';
    return $base = rtrim(str_replace('\\', '/', $rel), '/') . '/';
}

function h(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_ok(?string $t): bool
{
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Devuelve false si la IP superó $max acciones en la ventana; si no, registra el intento. */
function rate_limit(string $action, int $max, int $windowSec, bool $record = true): bool
{
    $pdo = db();
    $since = now_ms() - $windowSec * 1000;
    $pdo->prepare('DELETE FROM hits WHERE at < ?')->execute([now_ms() - 86400000]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE ip = ? AND action = ? AND at >= ?');
    $st->execute([client_ip(), $action, $since]);
    if ((int) $st->fetchColumn() >= $max) return false;
    if ($record) hit($action);
    return true;
}

function hit(string $action): void
{
    db()->prepare('INSERT INTO hits (ip, action, at) VALUES (?,?,?)')->execute([client_ip(), $action, now_ms()]);
}

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) return $admin;
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) return $admin = null;
    $st = db()->prepare('SELECT id, username, name FROM admins WHERE id = ? AND active = 1');
    $st->execute([$id]);
    $admin = $st->fetch() ?: null;
    if (!$admin) unset($_SESSION['admin_id']);
    return $admin;
}

function require_admin_page(): array
{
    $a = current_admin();
    if (!$a) {
        header('Location: ' . base_url() . 'admin/login.php');
        exit;
    }
    return $a;
}

/** Participante registrado en esta sesión. */
function visitor(): ?array
{
    $id = $_SESSION['visitor'] ?? null;
    if (!$id) return null;
    $st = db()->prepare('SELECT id, nombre, token, spun FROM participants WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** El recorrido y la descarga del brochure se habilitan después de jugar (el staff entra siempre). */
function can_tour(): bool
{
    return current_admin() !== null || !empty(visitor()['spun']);
}

/** Igual que phoneKey() del demo: solo dígitos, sin prefijo 595 ni ceros iniciales. */
function phone_key(string $t): string
{
    $d = preg_replace('/\D/', '', $t);
    if (str_starts_with($d, '595')) $d = substr($d, 3);
    return ltrim($d, '0');
}

function asset(string $path): string
{
    $file = __DIR__ . '/../' . $path;
    return base_url() . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Rutas guardadas en la base (logos/x.png, uploads/x.jpg) a URL pública. */
function media_url(string $path): string
{
    if ($path === '' || preg_match('#^https?://#', $path)) return $path;
    return base_url() . ltrim($path, '/');
}

const RUBROS = ['Textil y confecciones', 'Autopartes y metalmecánica', 'Plásticos y envases', 'Alimentos y bebidas', 'Farmacéutica y química', 'Electrónica y cableado', 'Logística y servicios', 'Otro'];
const DESAFIOS = ['Soldadura', 'Mantenimiento', 'Seguridad Industrial', 'Corte', 'Automatización', 'Productividad', 'Herramientas'];
