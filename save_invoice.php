<?php
/**
 * save_invoice.php
 * Handles CREATE and EDIT invoice.
 * Synced with create_invoice.php fields as of Phase 4d.
 */
require_once 'config/bootstrap.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('invoice.php');

$pdo    = db();
$editId = (int)($_POST['edit_id'] ?? 0);
$isEdit = $editId > 0;

// ── Collect all inputs ─────────────────────────

$invoiceNo          = trim($_POST['invoice_no']          ?? '');
$referenceNo        = trim($_POST['reference_no']        ?? '');
$status             = $_POST['status']                    ?? 'draft';
$currency           = $_POST['currency']                  ?? 'MYR';
$taxMode            = $_POST['tax_mode']                  ?? 'exclusive';
$roundingAdj        = (float)($_POST['rounding_adjustment'] ?? 0);

// Date — Flatpickr submits dd/mm/yyyy, convert to yyyy-mm-dd
$rawDate            = trim($_POST['invoice_date'] ?? '');
$invoiceDate        = convertDate($rawDate);

// Customer fields
$customerName       = trim($_POST['customer_name']       ?? '');
$customerTin        = trim($_POST['customer_tin']        ?? '');
$customerRegNo      = trim($_POST['customer_reg_no']     ?? '');
$customerEmail      = trim($_POST['customer_email']      ?? '');
$customerPhone      = trim($_POST['customer_phone']      ?? '');
$customerAddr       = trim($_POST['customer_address']    ?? '');
$billingAttention   = trim($_POST['billing_attention']   ?? '');
$shippingRef        = trim($_POST['shipping_reference']  ?? '');
$shippingAttention  = trim($_POST['shipping_attention']  ?? '');
$shippingAddress    = trim($_POST['shipping_address']    ?? '');

// Amounts (computed by JS, recalculated server-side below for security)
$jsSubtotal         = (float)($_POST['subtotal']         ?? 0);
$jsDiscount         = (float)($_POST['discount_amount']  ?? 0);
$jsTaxAmount        = (float)($_POST['tax_amount']       ?? 0);
$jsTotalAmount      = (float)($_POST['total_amount']     ?? 0);

// General fields
$description        = trim($_POST['description']         ?? '');
$internalNote       = trim($_POST['internal_note']       ?? '');
$paymentMode        = in_array($_POST['payment_mode'] ?? '', ['cash','credit']) ? $_POST['payment_mode'] : 'cash';
$notes              = trim($_POST['notes']               ?? '');
$paymentTermId      = (int)($_POST['payment_term_id']    ?? 0) ?: null;

// Status from visible dropdown (overrides hidden if set)
$visibleStatus      = $_POST['status_visible']           ?? '';
if ($visibleStatus !== '') $status = $visibleStatus;

// ── Date conversion helper ─────────────────────
function convertDate(string $raw): string {
    if (!$raw) return date('Y-m-d');
    // Already ISO yyyy-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    // dd/mm/yyyy from Flatpickr
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) return "$m[3]-$m[2]-$m[1]";
    // Fallback — try strtotime
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

// ── Validation ─────────────────────────────────
if (!$invoiceNo || !$customerName || !$invoiceDate) {
    flash('error', 'Invoice number, customer name and date are required.');
    redirect($isEdit ? "invoice.php?action=edit&id=$editId" : 'invoice.php?action=new');
}

$allowedStatus = ['draft','sent','paid','overdue','cancelled'];
if (!in_array($status, $allowedStatus)) $status = 'draft';

// ── Line items — recalculate server-side ───────
$taxRates  = ['none' => 0, 'sst6' => 0.06, 'sst10' => 0.10, 'service6' => 0.06];
$lineItems = [];
$serverSubtotal  = 0;
$serverTax       = 0;
$serverDiscount  = 0;

foreach ($_POST['items'] ?? [] as $i => $item) {
    $desc      = trim($item['description']      ?? '');
    $descNote  = trim($item['item_description'] ?? '');
    $qty       = (float)($item['quantity']       ?? 1);
    $price     = (float)($item['unit_price']     ?? 0);
    $taxType   = array_key_exists($item['tax_type'] ?? '', $taxRates) ? $item['tax_type'] : 'none';
    $discMode  = ($item['discount_mode'] ?? 'pct') === 'fixed' ? 'fixed' : 'pct';

    // Resolve discount: prefer hidden field (already parsed by JS), fallback to raw
    $discPct   = (float)($item['discount_pct'] ?? 0);
    $discRaw   = trim($item['discount_raw'] ?? $item['discount_raw_num'] ?? '');
    if ($discRaw !== '' && $discPct == 0) {
        // Parse raw value server-side as fallback
        if (str_ends_with($discRaw, '%')) {
            $discPct  = (float)rtrim($discRaw, '%');
            $discMode = 'pct';
        } else {
            $discPct  = (float)$discRaw;
            $discMode = 'fixed';
        }
    }

    if (!$desc || $qty <= 0) continue;

    $gross   = $qty * $price;
    $discAmt = $discMode === 'fixed' ? $discPct : $gross * ($discPct / 100);
    $base    = $gross - $discAmt;
    $taxRate = $taxRates[$taxType];

    if ($taxMode === 'inclusive') {
        $taxAmt    = $taxRate > 0 ? $base - ($base / (1 + $taxRate)) : 0;
        $lineTotal = $base;
    } else {
        $taxAmt    = $base * $taxRate;
        $lineTotal = $base + $taxAmt;
    }

    $serverSubtotal += ($taxMode === 'inclusive' && $taxRate > 0) ? ($base / (1 + $taxRate)) : $base;
    $serverTax      += $taxAmt;
    $serverDiscount += $discAmt;

    $lineItems[] = [
        'description'      => $desc,
        'item_description' => $descNote,
        'quantity'         => $qty,
        'unit_price'       => $price,
        'discount_pct'     => $discPct,
        'discount_mode'    => $discMode,
        'tax_type'         => $taxType,
        'tax_amount'       => round($taxAmt, 2),
        'line_total'       => round($lineTotal, 2),
        'sort_order'       => $i,
        'classification'   => trim($item['classification'] ?? ''),
    ];
}

if (empty($lineItems)) {
    flash('error', 'At least one valid line item is required.');
    redirect($isEdit ? "invoice.php?action=edit&id=$editId" : 'invoice.php?action=new');
}

// Final totals (server-calculated — always trust server over JS)
$serverSubtotal  = round($serverSubtotal, 2);
$serverTax       = round($serverTax, 2);
$serverDiscount  = round($serverDiscount, 2);
$serverTotal     = round($serverSubtotal + $serverTax + $roundingAdj, 2);

// ── Overall tax type ───────────────────────────
$usedTaxTypes = array_unique(array_column($lineItems, 'tax_type'));
$usedTaxTypes = array_filter($usedTaxTypes, fn($t) => $t !== 'none');
$invTaxType   = !empty($usedTaxTypes) ? reset($usedTaxTypes) : 'none';

// ── Customer ID lookup ─────────────────────────
$customerId = null;
$cStmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ? LIMIT 1");
$cStmt->execute([$customerName]);
$cRow = $cStmt->fetch();
if ($cRow) $customerId = $cRow['id'];

// ── Handle file attachments ─────────────────────
// Files are uploaded via attachments[] — save to storage/attachments/
$attachmentPaths = [];
if (!empty($_FILES['attachments']['name'][0])) {
    $attachDir = APP_ROOT . '/storage/attachments';
    if (!is_dir($attachDir)) mkdir($attachDir, 0755, true);

    $allowedMime = ['application/pdf','image/jpeg','image/png','application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $maxSize     = 10 * 1024 * 1024; // 10MB

    foreach ($_FILES['attachments']['tmp_name'] as $k => $tmp) {
        if ($_FILES['attachments']['error'][$k] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['attachments']['size'][$k] > $maxSize) continue;

        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowedMime)) continue;

        $ext      = pathinfo($_FILES['attachments']['name'][$k], PATHINFO_EXTENSION);
        $safeName = uniqid('att_', true) . '.' . strtolower($ext);
        $dest     = $attachDir . '/' . $safeName;

        if (move_uploaded_file($tmp, $dest)) {
            $attachmentPaths[] = [
                'original' => $_FILES['attachments']['name'][$k],
                'stored'   => $safeName,
            ];
        }
    }
}

// ── Save to database ───────────────────────────
try {
    $pdo->beginTransaction();

    $fields = [
        'invoice_no'          => $invoiceNo,
        'invoice_format_id'   => (int)($_POST['invoice_format_id'] ?? 0) ?: null,
        'reference_no'        => $referenceNo,
        'customer_id'         => $customerId,
        'customer_name'       => $customerName,
        'customer_tin'        => $customerTin,
        'customer_reg_no'     => $customerRegNo,
        'customer_email'      => $customerEmail,
        'customer_phone'      => $customerPhone,
        'customer_address'    => $customerAddr,
        'billing_attention'   => $billingAttention,
        'shipping_reference'  => $shippingRef,
        'shipping_attention'  => $shippingAttention,
        'shipping_address'    => $shippingAddress,
        'invoice_date'        => $invoiceDate,
        'subtotal'            => $serverSubtotal,
        'discount_amount'     => $serverDiscount,
        'tax_type'            => $invTaxType,
        'tax_amount'          => $serverTax,
        'rounding_adjustment' => $roundingAdj,
        'total_amount'        => $serverTotal,
        'currency'            => $currency,
        'tax_mode'            => $taxMode,
        'description'         => $description,
        'internal_note'       => $internalNote,
        'notes'               => $notes,
        'payment_mode'        => $paymentMode,
        'payment_term_id'     => $paymentTermId,
        'status'              => $status,
    ];

    if ($isEdit) {
        // Snapshot old data for audit trail
        $oldStmt = $pdo->prepare("SELECT invoice_no, status, total_amount, customer_name FROM invoices WHERE id = ?");
        $oldStmt->execute([$editId]);
        $oldData = $oldStmt->fetch();

        $set    = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $vals   = array_values($fields);
        $vals[] = $editId;
        $pdo->prepare("UPDATE invoices SET $set WHERE id = ?")->execute($vals);
        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$editId]);
        $invoiceId = $editId;

        auditLog('UPDATE_INVOICE', 'invoices', $invoiceId, [
            'old' => [
                'invoice_no'   => $oldData['invoice_no'],
                'customer'     => $oldData['customer_name'],
                'status'       => $oldData['status'],
                'total_amount' => $oldData['total_amount'],
            ],
            'new' => [
                'invoice_no'   => $invoiceNo,
                'customer'     => $customerName,
                'status'       => $status,
                'total_amount' => $serverTotal,
            ],
        ]);

    } else {
        // ── Generate invoice number atomically ─────────────────────────────────
        // Never trust the POSTed invoice_no — always generate fresh inside the
        // transaction with FOR UPDATE to prevent duplicate entries under concurrency.
        $year            = date('Y');
        $invoiceFormatId = (int)($_POST['invoice_format_id'] ?? 0);

        // Load format
        $fmtRow = false;
        if ($invoiceFormatId > 0) {
            $s = $pdo->prepare("SELECT format FROM number_formats WHERE id=?");
            $s->execute([$invoiceFormatId]);
            $fmtRow = $s->fetch();
        }
        // Fallback: use first invoice format available
        if (!$fmtRow) {
            $fmtRow = $pdo->query("SELECT format FROM number_formats WHERE doc_type='invoice' ORDER BY id LIMIT 1")->fetch();
        }

        if ($fmtRow) {
            $format = $fmtRow['format'];
            $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $format), 0, 50);

            // Ensure row exists, then lock it exclusively for this transaction
            $pdo->prepare("INSERT IGNORE INTO invoice_sequences (prefix, year, next_no) VALUES (?, ?, 1)")
                ->execute([$seqKey, $year]);
            $lockStmt = $pdo->prepare("SELECT next_no FROM invoice_sequences WHERE prefix=? AND year=? FOR UPDATE");
            $lockStmt->execute([$seqKey, $year]);
            $seq = (int)$lockStmt->fetchColumn();

            // Walk forward past any numbers already in invoices (handles gaps from manual entry etc.)
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no=?");
            $now = new DateTime();
            for ($i = 0; $i < 10000; $i++, $seq++) {
                $candidate = str_replace(
                    ['[YYYY]','[YY]','[MM]','[DD]'],
                    [$now->format('Y'),$now->format('y'),$now->format('m'),$now->format('d')],
                    $format
                );
                for ($n = 2; $n <= 8; $n++) {
                    $candidate = str_replace("[{$n}DIGIT]", str_pad((string)$seq, $n, '0', STR_PAD_LEFT), $candidate);
                }
                $checkStmt->execute([$candidate]);
                if ((int)$checkStmt->fetchColumn() === 0) break;
            }
            $invoiceNo = $candidate;
        } else {
            // No format at all — safe unique fallback
            $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $seqKey    = null;
        }

        // Inject generated number into fields
        $fields['invoice_no'] = $invoiceNo;

        $cols   = implode(', ', array_keys($fields));
        $pholds = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO invoices ($cols) VALUES ($pholds)")->execute(array_values($fields));
        $invoiceId = (int)$pdo->lastInsertId();

        // Bump sequence to one past what we just used
        if (!empty($seqKey)) {
            $pdo->prepare("UPDATE invoice_sequences SET next_no=? WHERE prefix=? AND year=?")
                ->execute([$seq + 1, $seqKey, $year]);
        }

        auditLog('CREATE_INVOICE', 'invoices', $invoiceId, [
            'new' => [
                'invoice_no'   => $invoiceNo,
                'customer'     => $customerName,
                'status'       => $status,
                'total_amount' => $serverTotal,
            ],
        ]);
    }

    // ── Insert line items ──────────────────────
    $iStmt = $pdo->prepare("
        INSERT INTO invoice_items
            (invoice_id, description, item_description, quantity, unit_price,
             discount_pct, discount_mode, tax_type, tax_amount, line_total, sort_order, classification)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lineItems as $item) {
        $iStmt->execute([
            $invoiceId,
            $item['description'],
            $item['item_description'],
            $item['quantity'],
            $item['unit_price'],
            $item['discount_pct'],
            $item['discount_mode'],
            $item['tax_type'],
            $item['tax_amount'],
            $item['line_total'],
            $item['sort_order'],
            $item['classification'],
        ]);
    }

    // ── Save payment records ───────────────────
    // Delete existing payments for this invoice then re-insert
    $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id=?")->execute([$invoiceId]);

    $pmtStmt = $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_term_id, amount, reference_no, notes) VALUES (?,?,?,?,?)");
    foreach ($_POST['payments'] ?? [] as $pmt) {
        $pmtTermId = (int)($pmt['payment_term_id'] ?? 0) ?: null;
        $pmtAmt    = round((float)($pmt['amount'] ?? 0), 2);
        $pmtRef    = trim($pmt['reference_no'] ?? '');
        $pmtNotes  = trim($pmt['notes'] ?? '');
        if ($pmtAmt <= 0) continue; // skip zero-amount rows
        $pmtStmt->execute([$invoiceId, $pmtTermId, $pmtAmt, $pmtRef, $pmtNotes]);
    }

    // ── Save attachment records (if table exists) ──
    if (!empty($attachmentPaths)) {
        // Check if invoice_attachments table exists first
        $tblCheck = $pdo->query("SHOW TABLES LIKE 'invoice_attachments'")->fetchColumn();
        if ($tblCheck) {
            $aStmt = $pdo->prepare("
                INSERT INTO invoice_attachments (invoice_id, original_name, stored_name, uploaded_by)
                VALUES (?, ?, ?, ?)
            ");
            $userId = authUser()['id'];
            foreach ($attachmentPaths as $att) {
                $aStmt->execute([$invoiceId, $att['original'], $att['stored'], $userId]);
            }
        }
    }

    $pdo->commit();

    flash('success', $isEdit ? 'Invoice updated successfully.' : 'Invoice created successfully.');
    redirect("invoice.php?action=edit&id=$invoiceId&saved=1");

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Save failed: ' . $e->getMessage());
    redirect($isEdit ? "invoice.php?action=edit&id=$editId" : 'invoice.php?action=new');
}
