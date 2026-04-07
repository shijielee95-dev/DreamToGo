<?php
/**
 * config/bootstrap.php
 * ─────────────────────────────────────────────
 * Every page does: require_once 'config/bootstrap.php'
 * This loads everything needed in one line.
 * ─────────────────────────────────────────────
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lhdn.php';

// Load theme tokens into global $theme
$theme = require __DIR__ . '/theme.php';

// Helper: get a theme value
function t(string $key): string {
    global $theme;
    return $theme[$key] ?? '';
}

// Helper: get a badge class by status
function badge(string $status): string {
    global $theme;
    $cls = $theme['badge'][$status] ?? $theme['badge']['default'];
    return 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $cls;
}

// Helper: format Malaysian Ringgit
function rm(float $amount): string {
    return 'RM ' . number_format($amount, 2);
}

// Helper: format date
function fmtDate(string $date, string $fmt = 'd M Y'): string {
    return $date ? date($fmt, strtotime($date)) : '—';
}

// Helper: sanitize output
function e(mixed $val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
}

// Helper: redirect
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// Session start (needed for flash messages)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Flash message helpers
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Auth check — call on protected pages
function requireAuth(): void {
    $token = $_COOKIE['login_token'] ?? null;
    if (!$token) redirect('login.php');

    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email
        FROM user_sessions us
        JOIN users u ON u.id = us.user_id
        WHERE us.token = ? AND us.expire_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        setcookie('login_token', '', time() - 1, '/');
        redirect('login.php');
    }

    // Make current user available globally
    $GLOBALS['authUser'] = $user;
}

// Get current auth user (after requireAuth)
function authUser(): array {
    return $GLOBALS['authUser'] ?? ['id' => 0, 'name' => 'Guest', 'email' => ''];
}

// ── Audit log helper ──────────────────────────
// Call auditLog('action', 'table', id, ['old'=>..., 'new'=>...]) from any page.
function auditLog(string $action, string $table = '', int $recordId = 0, array $data = []): void {
    try {
        $user = authUser();
        db()->prepare("
            INSERT INTO audit_logs
                (user_id, user_name, action, table_name, record_id, old_value, new_value, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $user['id'],
            $user['name'],
            $action,
            $table,
            $recordId ?: null,
            isset($data['old']) ? json_encode($data['old'], JSON_UNESCAPED_UNICODE) : null,
            isset($data['new']) ? json_encode($data['new'], JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Never let logging break the app — fail silently
    }
}
