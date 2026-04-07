<?php
require_once 'config/bootstrap.php';
requireAuth();

$id  = (int)($_GET['id']       ?? 0);
$cid = (int)($_GET['customer'] ?? 0);

if ($id > 0) {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT stored_name FROM customer_attachments WHERE id=?");
        $stmt->execute([$id]);
        $row  = $stmt->fetch();
        if ($row) {
            $path = (defined('APP_ROOT') ? APP_ROOT : __DIR__) . '/storage/attachments/' . $row['stored_name'];
            if (file_exists($path)) @unlink($path);
            $pdo->prepare("DELETE FROM customer_attachments WHERE id=?")->execute([$id]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
}
