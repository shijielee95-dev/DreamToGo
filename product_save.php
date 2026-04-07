<?php
require_once 'config/bootstrap.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('product.php');

$pdo = db();

function s(?string $v): string { return trim($v ?? ''); }
function n(?string $v): ?float { $v=trim($v??''); return $v!==''?(float)$v:null; }

// ── Delete ─────────────────────────────────────────────────────────
if (!empty($_POST['delete'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    try {
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── Save ───────────────────────────────────────────────────────────
$id                   = (int)($_POST['id'] ?? 0);
$name                 = s($_POST['name'] ?? '');
$sku                  = s($_POST['sku'] ?? '');
$barcode              = s($_POST['barcode'] ?? '');
$classification_code  = s($_POST['classification_code'] ?? '');
$track_inventory      = (int)($_POST['track_inventory'] ?? 1);
$low_stock_level      = n($_POST['low_stock_level'] ?? '');
$selling              = (int)($_POST['selling'] ?? 1);
$sale_price           = n($_POST['sale_price'] ?? '');
$sales_tax            = s($_POST['sales_tax'] ?? '');
$sale_description     = s($_POST['sale_description'] ?? '');
$buying               = (int)($_POST['buying'] ?? 1);
$purchase_price       = n($_POST['purchase_price'] ?? '');
$purchase_description = s($_POST['purchase_description'] ?? '');
$base_unit_label      = s($_POST['base_unit_label'] ?? 'unit') ?: 'unit';
$multiple_uoms        = (int)($_POST['multiple_uoms'] ?? 0);
$uom_base_default_sales    = !empty($_POST['uom_base_default_sales']) ? 1 : 0;
$uom_base_default_purchase = !empty($_POST['uom_base_default_purchase']) ? 1 : 0;
$remarks              = s($_POST['remarks'] ?? '');

if (!$name) { flash('error','Product name is required.'); redirect('product.php?action='.($id?'edit&id='.$id:'new')); }

// Handle image upload
$image_path = null;
if (!empty($_FILES['image']['tmp_name'])) {
    $ext  = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed)) { flash('error','Invalid image type.'); redirect('product.php?action='.($id?'edit&id='.$id:'new')); }
    $dir  = 'uploads/products/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = uniqid('prod_').'.'.$ext;
    if (move_uploaded_file($_FILES['image']['tmp_name'], $dir.$fname)) {
        $image_path = $dir.$fname;
    }
}

try {
    $pdo->beginTransaction();

    $fields = [
        'name'=>$name,'sku'=>$sku,'barcode'=>$barcode,
        'classification_code'=>$classification_code,
        'track_inventory'=>$track_inventory,'low_stock_level'=>$low_stock_level,
        'selling'=>$selling,'sale_price'=>$sale_price,'sales_tax'=>$sales_tax,
        'sale_description'=>$sale_description,
        'buying'=>$buying,'purchase_price'=>$purchase_price,
        'purchase_description'=>$purchase_description,
        'base_unit_label'=>$base_unit_label,'multiple_uoms'=>$multiple_uoms,
        'uom_base_default_sales'=>$uom_base_default_sales,
        'uom_base_default_purchase'=>$uom_base_default_purchase,
        'remarks'=>$remarks,
    ];
    if ($image_path !== null) $fields['image_path'] = $image_path;

    if ($id > 0) {
        $set  = implode(', ', array_map(fn($k)=>"$k=?", array_keys($fields)));
        $vals = array_values($fields);
        $vals[]= $id;
        $pdo->prepare("UPDATE products SET $set WHERE id=?")->execute($vals);
        $pdo->prepare("DELETE FROM product_uoms WHERE product_id=?")->execute([$id]);
    } else {
        $cols   = implode(', ', array_keys($fields));
        $pholds = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO products ($cols) VALUES ($pholds)")->execute(array_values($fields));
        $id = (int)$pdo->lastInsertId();
    }

    // UOMs
    if ($multiple_uoms && !empty($_POST['uoms'])) {
        $stmtU = $pdo->prepare("INSERT INTO product_uoms (product_id,label,rate,sale_price,purchase_price,default_sales,default_purchase) VALUES (?,?,?,?,?,?,?)");
        foreach ($_POST['uoms'] as $u) {
            $label = s($u['label'] ?? '');
            if (!$label) continue;
            $stmtU->execute([
                $id, $label,
                max(0.0001, (float)($u['rate'] ?? 1)),
                n($u['sale_price'] ?? ''),
                n($u['purchase_price'] ?? ''),
                !empty($u['default_sales'])    ? 1 : 0,
                !empty($u['default_purchase']) ? 1 : 0,
            ]);
        }
    }

    $pdo->commit();

    // If called via AJAX (from invoice panel), return JSON
    if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>true, 'id'=>$id]);
        exit;
    }

    flash('success', $id ? 'Product updated.' : 'Product created.');
    redirect('product.php?action=edit&id='.$id);

} catch (Exception $e) {
    $pdo->rollBack();

    if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
        exit;
    }

    flash('error', 'Save failed: '.$e->getMessage());
    redirect('product.php?action='.($id?'edit&id='.$id:'new'));
}
