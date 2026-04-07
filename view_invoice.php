<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect('invoice.php');

// ── Fetch invoice ──────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) redirect('invoice.php');

// ── Fetch items ────────────────────────────────
$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
$items->execute([$id]);
$items = $items->fetchAll();

// ── Fetch LHDN submission ──────────────────────
$lhdn = $pdo->prepare("SELECT * FROM lhdn_submissions WHERE invoice_id = ? ORDER BY submitted_at DESC LIMIT 1");
$lhdn->execute([$id]);
$lhdn = $lhdn->fetch();

// ── Fetch company profile ──────────────────────
$company = $pdo->query("SELECT * FROM company_profiles WHERE id = 1")->fetch();

// ── Fetch attachments ──────────────────────────
$attachments = [];
try {
    $aStmt = $pdo->prepare("SELECT * FROM invoice_attachments WHERE invoice_id = ? ORDER BY uploaded_at");
    $aStmt->execute([$id]);
    $attachments = $aStmt->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet
}

// ── Tax type label ─────────────────────────────
$taxLabels = ['none' => '—', 'sst6' => 'SST 6%', 'sst10' => 'SST 10%', 'service6' => 'Service 6%'];

layoutOpen($inv['invoice_no'], 'Invoice details');
?>

<!-- Actions -->
<script>
document.getElementById('pageActions').innerHTML = `
    <a href="invoice.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back
    </a>
    <a href="invoice.php?action=edit&id=<?= $id ?>" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
    </a>
    <button onclick="window.print()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
    </button>`;
</script>

<div class="flex gap-5 items-start">

    <!-- ── Invoice card (printable) ────────────── -->
    <div class="flex-1 min-w-0">
        <div class="<?= t('card') ?>" id="invoicePrintArea">

            <!-- Invoice header -->
            <div class="flex justify-between items-start mb-8">
                <!-- From -->
                <div>
                    <?php if (!empty($company['logo_path']) && file_exists($company['logo_path'])): ?>
                    <img src="<?= e($company['logo_path']) ?>" alt="Logo" class="h-12 object-contain mb-3">
                    <?php else: ?>
                    <div class="text-base font-bold text-slate-800 mb-1"><?= e($company['company_name'] ?: 'Your Company') ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-slate-500 space-y-0.5">
                        <?php if ($company['company_tin']): ?>
                        <div>TIN: <?= e($company['company_tin']) ?></div>
                        <?php endif; ?>
                        <?php if ($company['sst_no']): ?>
                        <div>SST: <?= e($company['sst_no']) ?></div>
                        <?php endif; ?>
                        <?php
                        $addr = array_filter([
                            $company['address_line_0'] ?? '',
                            $company['address_line_1'] ?? '',
                            $company['city'] ?? '',
                            $company['postal_code'] ?? '',
                        ]);
                        if ($addr): ?>
                        <div><?= e(implode(', ', $addr)) ?></div>
                        <?php endif; ?>
                        <?php if ($company['phone']): ?>
                        <div><?= e($company['phone']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Invoice meta -->
                <div class="text-right">
                    <div class="text-2xl font-bold text-slate-800 mb-1"><?= e($inv['invoice_no']) ?></div>
                    <span class="<?= badge($inv['status']) ?>"><?= ucfirst($inv['status']) ?></span>
                    <div class="text-xs text-slate-500 mt-3 space-y-0.5">
                        <div><span class="text-slate-400">Date:</span> <?= fmtDate($inv['invoice_date']) ?></div>
                        <?php if ($inv['due_date']): ?>
                        <div><span class="text-slate-400">Due:</span> <?= fmtDate($inv['due_date']) ?></div>
                        <?php endif; ?>
                        <div><span class="text-slate-400">Currency:</span> <?= e($inv['currency']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Bill to -->
            <div class="mb-8 p-4 bg-slate-50 rounded-xl">
                <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Bill To</div>
                <div class="font-semibold text-slate-800 text-sm"><?= e($inv['customer_name']) ?></div>
                <div class="text-xs text-slate-500 mt-1 space-y-0.5">
                    <?php if ($inv['customer_tin']): ?>
                    <div>TIN: <?= e($inv['customer_tin']) ?></div>
                    <?php endif; ?>
                    <?php if ($inv['customer_reg_no']): ?>
                    <div>Reg No: <?= e($inv['customer_reg_no']) ?></div>
                    <?php endif; ?>
                    <?php if ($inv['customer_email']): ?>
                    <div><?= e($inv['customer_email']) ?></div>
                    <?php endif; ?>
                    <?php if ($inv['customer_phone']): ?>
                    <div><?= e($inv['customer_phone']) ?></div>
                    <?php endif; ?>
                    <?php if ($inv['customer_address']): ?>
                    <div><?= nl2br(e($inv['customer_address'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Line items table -->
            <table class="w-full text-sm mb-6">
                <thead>
                    <tr class="border-b-2 border-slate-200">
                        <th class="text-left pb-2 text-xs font-semibold text-slate-500">Description</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Qty</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Unit Price</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Tax</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="py-2.5 pr-4 text-slate-700"><?= e($item['description']) ?></td>
                        <td class="py-2.5 text-right text-slate-500"><?= rtrim(rtrim(number_format($item['quantity'], 4), '0'), '.') ?></td>
                        <td class="py-2.5 text-right text-slate-500"><?= rm($item['unit_price']) ?></td>
                        <td class="py-2.5 text-right text-slate-400 text-xs"><?= $taxLabels[$item['tax_type']] ?? '—' ?></td>
                        <td class="py-2.5 text-right font-medium text-slate-800"><?= rm($item['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="flex justify-end">
                <div class="w-64 space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal</span>
                        <span><?= rm($inv['subtotal']) ?></span>
                    </div>
                    <?php if ($inv['discount_amount'] > 0): ?>
                    <div class="flex justify-between text-slate-600">
                        <span>Discount</span>
                        <span class="text-red-500">− <?= rm($inv['discount_amount']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($inv['tax_amount'] > 0): ?>
                    <div class="flex justify-between text-slate-600">
                        <span>Tax</span>
                        <span><?= rm($inv['tax_amount']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between font-bold text-slate-900 text-base border-t border-slate-200 pt-2 mt-2">
                        <span>Total</span>
                        <span><?= rm($inv['total_amount']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <?php if ($inv['notes']): ?>
            <div class="mt-6 pt-5 border-t border-slate-100">
                <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Notes</div>
                <p class="text-xs text-slate-600"><?= nl2br(e($inv['notes'])) ?></p>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ── Right panel ──────────────────────────── -->
    <div class="w-72 shrink-0 space-y-4">

        <!-- LHDN Status -->
        <div class="<?= t('lhdn_section') ?>">
            <div class="flex items-center justify-between mb-3">
                <h3 class="<?= t('lhdn_title') ?> mb-0">
                    <svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    LHDN e-Invoice
                </h3>
                <?php if ($lhdn): ?>
                <span class="<?= badge($lhdn['status']) ?>"><?= ucfirst($lhdn['status']) ?></span>
                <?php else: ?>
                <span class="text-xs text-slate-400">Not submitted</span>
                <?php endif; ?>
            </div>

            <?php if ($lhdn): ?>
            <div class="space-y-2 text-xs mb-4">
                <div class="flex flex-col">
                    <span class="text-violet-600 font-medium">Environment</span>
                    <span class="text-slate-700 capitalize"><?= e($lhdn['environment']) ?></span>
                </div>
                <?php if ($lhdn['submission_uid']): ?>
                <div class="flex flex-col">
                    <span class="text-violet-600 font-medium">Submission UID</span>
                    <span class="text-slate-700 font-mono text-[10px] break-all"><?= e($lhdn['submission_uid']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($lhdn['document_uuid']): ?>
                <div class="flex flex-col">
                    <span class="text-violet-600 font-medium">Document UUID</span>
                    <span class="text-slate-700 font-mono text-[10px] break-all"><?= e($lhdn['document_uuid']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($lhdn['long_id']): ?>
                <div class="flex flex-col">
                    <span class="text-violet-600 font-medium">Long ID (QR)</span>
                    <span class="text-slate-700 font-mono text-[10px] break-all"><?= e($lhdn['long_id']) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex flex-col">
                    <span class="text-violet-600 font-medium">Submitted At</span>
                    <span class="text-slate-700"><?= fmtDate($lhdn['submitted_at'], 'd M Y, H:i') ?></span>
                </div>
                <?php if ($lhdn['validated_at']): ?>
                <div class="flex flex-col">
                    <span class="text-violet-600 font-medium">Validated At</span>
                    <span class="text-slate-700"><?= fmtDate($lhdn['validated_at'], 'd M Y, H:i') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($lhdn['error_message']): ?>
                <div class="flex flex-col">
                    <span class="text-red-600 font-medium">Error</span>
                    <span class="text-red-600 text-[10px]"><?= e($lhdn['error_message']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Submit button -->
            <?php if (!$lhdn || $lhdn['status'] === 'invalid'): ?>
            <button onclick="submitToLhdn()"
                    id="lhdnSubmitBtn"
                    class="<?= t('btn_base') ?> <?= t('btn_primary') ?> w-full justify-center h-9 bg-violet-600 hover:bg-violet-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Submit to LHDN
            </button>
            <?php elseif ($lhdn['status'] === 'pending'): ?>
            <button onclick="pollLhdn()"
                    id="lhdnPollBtn"
                    class="<?= t('btn_base') ?> w-full justify-center h-9 bg-amber-500 hover:bg-amber-600 text-white">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Check Status
            </button>
            <?php endif; ?>
        </div>

        <!-- Invoice info summary -->
        <div class="<?= t('card') ?>">
            <h3 class="<?= t('card_title') ?>">Invoice Info</h3>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between">
                    <span class="text-slate-400">Currency</span>
                    <span class="text-slate-700 font-mono"><?= e($inv['currency']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Created</span>
                    <span class="text-slate-700"><?= fmtDate($inv['created_at'], 'd M Y') ?></span>
                </div>
                <?php if ($inv['reference_no']): ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">Reference</span>
                    <span class="text-slate-700"><?= e($inv['reference_no']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="<?= t('card') ?>">
            <h3 class="<?= t('card_title') ?>">Attachments</h3>
            <div class="space-y-2">
                <?php foreach ($attachments as $att):
                    $attUrl  = APP_URL . '/storage/attachments/' . urlencode($att['stored_name']);
                    $ext     = strtoupper(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                    $canView = in_array($ext, ['PDF','JPG','JPEG','PNG']);
                ?>
                <div class="flex items-center gap-2.5 p-2.5 bg-slate-50 rounded-lg border border-slate-100 group">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
                        <span class="text-[8px] font-bold text-indigo-600"><?= e($ext) ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium text-slate-700 truncate"><?= e($att['original_name']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= fmtDate($att['uploaded_at'], 'd M Y') ?></div>
                    </div>
                    <?php if ($canView): ?>
                    <a href="<?= e($attUrl) ?>" target="_blank"
                       class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="Open">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function submitToLhdn() {
    const btn = document.getElementById('lhdnSubmitBtn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Submitting...';

    fetch('lhdn_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'invoice_id=<?= $id ?>'
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Submitted to LHDN successfully!', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(d.message || 'Submission failed.', 'error');
            btn.disabled = false;
            btn.textContent = 'Submit to LHDN';
        }
    })
    .catch(() => {
        showToast('Server error.', 'error');
        btn.disabled = false;
        btn.textContent = 'Submit to LHDN';
    });
}

function pollLhdn() {
    const btn = document.getElementById('lhdnPollBtn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Checking...';

    fetch('lhdn_poll.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'invoice_id=<?= $id ?>'
    })
    .then(r => r.json())
    .then(d => {
        showToast(d.message || 'Status updated.', d.success ? 'success' : 'warning');
        setTimeout(() => location.reload(), 1200);
    })
    .catch(() => {
        showToast('Server error.', 'error');
        btn.disabled = false;
        btn.textContent = 'Check Status';
    });
}
</script>

<?php layoutClose(); ?>
