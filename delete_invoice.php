<?php
require_once 'config/bootstrap.php';
requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit;
}

try {
    // Fetch before delete for audit record
    $row = db()->prepare("SELECT invoice_no, customer_name, total_amount, status FROM invoices WHERE id = ?");
    $row->execute([$id]);
    $inv = $row->fetch();

    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']); exit;
    }

    $stmt = db()->prepare("DELETE FROM invoices WHERE id = ?");
    $stmt->execute([$id]);

    auditLog('DELETE_INVOICE', 'invoices', $id, [
        'old' => ['invoice_no' => $inv['invoice_no'], 'customer' => $inv['customer_name'], 'total' => $inv['total_amount'], 'status' => $inv['status']],
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}