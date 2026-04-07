<?php
/**
 * index.php
 * ─────────────────────────────────────────────
 * Entry point of the system
 * - If logged in → go to dashboard
 * - If not logged in → go to login page
 * ─────────────────────────────────────────────
 */

require_once 'config/bootstrap.php';

// Check login token
$token = $_COOKIE['login_token'] ?? null;

if ($token) {
    $stmt = db()->prepare("
        SELECT u.id FROM user_sessions us
        JOIN users u ON u.id = us.user_id
        WHERE us.token = ? AND us.expire_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);

    if ($stmt->fetch()) {
        redirect('dashboard.php');
    }
}

// Not logged in → go to login page
redirect('login.php');
