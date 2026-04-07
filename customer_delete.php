<?php
require_once 'config/bootstrap.php';
header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM customer_contact_persons   WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM customer_contact_addresses WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM customer_emails            WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM customer_phones            WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM customers                  WHERE id          = ?")->execute([$id]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
