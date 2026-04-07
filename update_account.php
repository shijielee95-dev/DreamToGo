<?php
require_once 'config/bootstrap.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo 'error'; exit; }

$name  = trim($_POST['name']  ?? '');
$email = trim($_POST['email'] ?? '');
$user  = authUser();

if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo 'Invalid input.'; exit;
}

$stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
$stmt->execute([$email, $user['id']]);
if ($stmt->fetch()) { echo 'This email is already in use.'; exit; }

// Fetch old values for audit
$old = db()->prepare("SELECT name, email FROM users WHERE id = ?");
$old->execute([$user['id']]);
$oldData = $old->fetch();

db()->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
   ->execute([$name, $email, $user['id']]);

auditLog('UPDATE_ACCOUNT', 'users', $user['id'], [
    'old' => ['name' => $oldData['name'], 'email' => $oldData['email']],
    'new' => ['name' => $name,            'email' => $email],
]);

echo 'success';
