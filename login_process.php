<?php
/**
 * login_process.php
 * ─────────────────────────────────────────────
 * POST handler for login form. Returns JSON.
 * ─────────────────────────────────────────────
 */
require_once 'config/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');
$remember = !empty($_POST['remember']);

// Validate inputs
if (!$email) {
    echo json_encode(['success' => false, 'field' => 'email', 'message' => 'Email address is required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'field' => 'email', 'message' => 'Invalid email address.']);
    exit;
}
if (!$password) {
    echo json_encode(['success' => false, 'field' => 'password', 'message' => 'Password is required.']);
    exit;
}

// Find user
$stmt = db()->prepare("SELECT id, name, email, password FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'field' => 'email', 'message' => 'No account found with this email.']);
    exit;
}

if (!password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'field' => 'password', 'message' => 'Incorrect password.']);
    exit;
}

// Create session token
$token    = bin2hex(random_bytes(32));
$expiry   = $remember
    ? date('Y-m-d H:i:s', strtotime('+7 days'))
    : date('Y-m-d H:i:s', strtotime('+8 hours'));

// Clean old sessions for this user
db()->prepare("DELETE FROM user_sessions WHERE user_id = ? AND expire_at < NOW()")->execute([$user['id']]);

// Insert new session
db()->prepare("INSERT INTO user_sessions (user_id, token, expire_at) VALUES (?, ?, ?)")
   ->execute([$user['id'], $token, $expiry]);

// Set cookie
$cookieExpiry = $remember ? time() + (7 * 24 * 60 * 60) : 0;
setcookie('login_token', $token, [
    'expires'  => $cookieExpiry,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

echo json_encode(['success' => true]);
