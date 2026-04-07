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
        $pdo->prepare("DELETE FROM number_formats WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── Save (insert / update) ──────────────────────────────────────────
$id       = (int)($_POST['id']       ?? 0);
$doc_type = s($_POST['doc_type']     ?? '');
$format   = strtoupper(s($_POST['format'] ?? ''));

if (!$doc_type) { echo json_encode(['success'=>false,'message'=>'Document type is required.']); exit; }
if (!$format)   { echo json_encode(['success'=>false,'message'=>'Format is required.']); exit; }
if (!preg_match('/\[\d+DIGIT\]/', $format)) {
    echo json_encode(['success'=>false,'message'=>'Format must include a number placeholder e.g. [5DIGIT].']); exit;
}

try {
    if ($id > 0) {
        $pdo->prepare("UPDATE number_formats SET doc_type=?, format=? WHERE id=?")
            ->execute([$doc_type, $format, $id]);
    } else {
        $pdo->prepare("INSERT INTO number_formats (doc_type, format) VALUES (?, ?)")
            ->execute([$doc_type, $format]);
        $id = (int)$pdo->lastInsertId();
    }
    echo json_encode(['success'=>true, 'id'=>$id]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
