<?php
/**
 * logout.php
 * ─────────────────────────────────────────────
 * Destroys the session token and redirects
 * to login page.
 * ─────────────────────────────────────────────
 */
require_once 'config/bootstrap.php';

$token = $_COOKIE['login_token'] ?? null;

if ($token) {
    // Delete session from DB
    db()->prepare("DELETE FROM user_sessions WHERE token = ?")->execute([$token]);

    // Expire the cookie
    setcookie('login_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

redirect('login.php');
