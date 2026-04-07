<?php
require_once 'config/bootstrap.php';
requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
}

function s(?string $v): string { return trim($v ?? ''); }

$pdo = db();

// ── Delete ──────────────────────────────────────────────────────────
if (!empty($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    try {
        $pdo->prepare("DELETE FROM tax_rates WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── Save ──────────────────────────────────────────────────────────
$id         = (int)($_POST['id']         ?? 0);
$name       = s($_POST['name']           ?? '');
$rate       = $_POST['rate']             ?? '';
$details    = s($_POST['details']        ?? '');
$is_default = (int)($_POST['is_default'] ?? 0);

if (!$name)                            { echo json_encode(['success'=>false,'message'=>'Tax name is required.']); exit; }
if ($rate === '' || !is_numeric($rate)){ echo json_encode(['success'=>false,'message'=>'Rate is required.']); exit; }
$rate = round((float)$rate, 4);

try {
    $pdo->beginTransaction();

    // If setting as default, clear existing defaults first
    if ($is_default) {
        $pdo->exec("UPDATE tax_rates SET is_default=0");
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE tax_rates SET name=?, rate=?, details=?, is_default=? WHERE id=?")
            ->execute([$name, $rate, $details, $is_default, $id]);
    } else {
        $pdo->prepare("INSERT INTO tax_rates (name, rate, details, is_default) VALUES (?, ?, ?, ?)")
            ->execute([$name, $rate, $details, $is_default]);
        $id = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
    echo json_encode(['success'=>true, 'id'=>$id]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
