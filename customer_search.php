<?php
require_once 'config/bootstrap.php';
header('Content-Type: application/json');

$keyword = trim($_GET['keyword'] ?? '');
if ($keyword === '') { echo json_encode([]); exit; }

$like = '%' . $keyword . '%';
$stmt = db()->prepare("
    SELECT id, customer_name, tin
    FROM   customers
    WHERE  customer_name LIKE ? OR tin LIKE ?
    ORDER BY customer_name ASC
    LIMIT 15
");
$stmt->execute([$like, $like]);
echo json_encode($stmt->fetchAll());
