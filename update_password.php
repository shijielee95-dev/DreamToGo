<?php
require_once 'config/bootstrap.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo 'error'; exit; }

$current = $_POST['current_password'] ?? '';
$new     = $_POST['new_password']     ?? '';
$confirm = $_POST['confirm_password'] ?? '';
$user    = authUser();

if (!$current || !$new || !$confirm) { echo 'All fields are required.'; exit; }
if (strlen($new) < 8)                { echo 'New password must be at least 8 characters.'; exit; }
if ($new !== $confirm)               { echo 'Passwords do not match.'; exit; }

$stmt = db()->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password'])) {
    echo 'Current password is incorrect.'; exit;
}

$hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);

// Invalidate other sessions
$token = $_COOKIE['login_token'] ?? '';
db()->prepare("DELETE FROM user_sessions WHERE user_id = ? AND token != ?")
   ->execute([$user['id'], $token]);

auditLog('CHANGE_PASSWORD', 'users', $user['id']);

echo 'success';
