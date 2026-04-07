<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';

$pdo = db();

// ── Stats ──────────────────────────────────────
$stats = $pdo->query("
    SELECT
        COUNT(*)                                                        AS total,
        COALESCE(SUM(CASE WHEN status='paid'    THEN 1 END), 0)        AS paid,
        COALESCE(SUM(CASE WHEN status='sent'    THEN 1 END), 0)        AS sent,
        COALESCE(SUM(CASE WHEN status='overdue' THEN 1 END), 0)        AS overdue,
        COALESCE(SUM(CASE WHEN status='draft'   THEN 1 END), 0)        AS draft,
        COALESCE(SUM(CASE WHEN status='paid'    THEN total_amount END), 0) AS revenue
    FROM invoices
")->fetch();

$totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

// ── LHDN pending count ─────────────────────────
$lhdnPending = $pdo->query("
    SELECT COUNT(*) FROM lhdn_submissions WHERE status = 'pending'
")->fetchColumn();

// ── Recent invoices (last 7) ───────────────────
$recent = $pdo->query("
    SELECT i.id, i.invoice_no, i.customer_name, i.invoice_date,
           i.total_amount, i.status,
           ls.status AS lhdn_status
    FROM invoices i
    LEFT JOIN lhdn_submissions ls ON ls.invoice_id = i.id
    ORDER BY i.created_at DESC
    LIMIT 7
")->fetchAll();

// ── Revenue this month vs last month ──────────
$revenueMonth = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN MONTH(invoice_date)=MONTH(CURDATE()) AND YEAR(invoice_date)=YEAR(CURDATE()) AND status='paid' THEN total_amount END),0) AS this_month,
        COALESCE(SUM(CASE WHEN MONTH(invoice_date)=MONTH(CURDATE()-INTERVAL 1 MONTH) AND YEAR(invoice_date)=YEAR(CURDATE()-INTERVAL 1 MONTH) AND status='paid' THEN total_amount END),0) AS last_month
    FROM invoices
")->fetch();

$monthDiff = $revenueMonth['last_month'] > 0
    ? round((($revenueMonth['this_month'] - $revenueMonth['last_month']) / $revenueMonth['last_month']) * 100, 1)
    : null;

layoutOpen('Dashboard', 'Welcome back, ' . authUser()['name']);
?>

<!-- Page actions -->
<script>
document.getElementById('pageActions').innerHTML = `
    <a href="invoice.php?action=new"
       class="<?= t('btn_base') ?> <?= t('btn_primary') ?>">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        New Invoice
    </a>`;
</script>

<!-- ── Stat cards ──────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

    <!-- Revenue -->
    <div class="<?= t('card') ?> flex flex-col gap-1">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400 uppercase tracking-wide">Revenue (Paid)</span>
            <div class="w-8 h-8 rounded-lg bg-green-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-800 mt-1"><?= rm($stats['revenue']) ?></div>
        <?php if ($monthDiff !== null): ?>
        <div class="text-xs <?= $monthDiff >= 0 ? 'text-green-600' : 'text-red-500' ?>">
            <?= $monthDiff >= 0 ? '▲' : '▼' ?> <?= abs($monthDiff) ?>% vs last month
        </div>
        <?php else: ?>
        <div class="text-xs text-slate-300">No data last month</div>
        <?php endif; ?>
    </div>

    <!-- Total Invoices -->
    <div class="<?= t('card') ?> flex flex-col gap-1">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400 uppercase tracking-wide">Total Invoices</span>
            <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-800 mt-1"><?= $stats['total'] ?></div>
        <div class="text-xs text-slate-400"><?= $stats['draft'] ?> draft · <?= $stats['sent'] ?> sent</div>
    </div>

    <!-- Overdue -->
    <div class="<?= t('card') ?> flex flex-col gap-1">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400 uppercase tracking-wide">Overdue</span>
            <div class="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-800 mt-1"><?= $stats['overdue'] ?></div>
        <div class="text-xs <?= $stats['overdue'] > 0 ? 'text-red-500' : 'text-slate-300' ?>">
            <?= $stats['overdue'] > 0 ? 'Requires attention' : 'All clear' ?>
        </div>
    </div>

    <!-- LHDN Pending -->
    <div class="<?= t('card') ?> flex flex-col gap-1">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400 uppercase tracking-wide">LHDN Pending</span>
            <div class="w-8 h-8 rounded-lg bg-violet-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-violet-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-800 mt-1"><?= $lhdnPending ?></div>
        <div class="text-xs text-slate-400">Awaiting validation</div>
    </div>

</div>

<!-- ── Main grid ───────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    <!-- Recent Invoices (spans 2 cols) -->
    <div class="lg:col-span-2">
        <div class="<?= t('card') ?>">
            <div class="flex items-center justify-between mb-4">
                <h2 class="<?= t('card_title') ?> mb-0 border-0 pb-0">Recent Invoices</h2>
                <a href="invoice.php" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium transition-colors">
                    View all →
                </a>
            </div>

            <?php if (empty($recent)): ?>
            <div class="text-center py-10">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <p class="text-sm text-slate-400">No invoices yet.</p>
                <a href="invoice.php?action=new" class="text-sm text-indigo-600 hover:underline mt-1 inline-block">Create your first invoice →</a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto -mx-5 px-5">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wide pb-2">Invoice</th>
                            <th class="text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wide pb-2">Customer</th>
                            <th class="text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wide pb-2">Date</th>
                            <th class="text-right text-[10px] font-semibold text-slate-400 uppercase tracking-wide pb-2">Amount</th>
                            <th class="text-center text-[10px] font-semibold text-slate-400 uppercase tracking-wide pb-2">Status</th>
                            <th class="text-center text-[10px] font-semibold text-slate-400 uppercase tracking-wide pb-2">LHDN</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($recent as $inv): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="py-2.5 pr-3">
                                <a href="view_invoice.php?id=<?= $inv['id'] ?>"
                                   class="font-semibold text-indigo-600 hover:text-indigo-800 transition-colors text-xs">
                                    <?= e($inv['invoice_no']) ?>
                                </a>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-700 text-xs truncate max-w-[160px]">
                                <?= e($inv['customer_name']) ?>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-400 text-xs whitespace-nowrap">
                                <?= fmtDate($inv['invoice_date']) ?>
                            </td>
                            <td class="py-2.5 pr-3 text-right font-semibold text-slate-800 text-xs whitespace-nowrap">
                                <?= rm($inv['total_amount']) ?>
                            </td>
                            <td class="py-2.5 pr-3 text-center">
                                <span class="<?= badge($inv['status']) ?>">
                                    <?= ucfirst($inv['status']) ?>
                                </span>
                            </td>
                            <td class="py-2.5 text-center">
                                <?php
                                $ls = $inv['lhdn_status'] ?? null;
                                if ($ls):
                                    echo '<span class="' . badge($ls) . '">' . ucfirst($ls) . '</span>';
                                else:
                                    echo '<span class="text-xs text-slate-300">—</span>';
                                endif;
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right column -->
    <div class="space-y-5">

        <!-- Quick actions -->
        <div class="<?= t('card') ?>">
            <h2 class="<?= t('card_title') ?>">Quick Actions</h2>
            <div class="space-y-2">
                <a href="invoice.php?action=new"
                   class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center shrink-0 group-hover:bg-indigo-100 transition-colors">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-slate-700">New Invoice</div>
                        <div class="text-xs text-slate-400">Create & submit to LHDN</div>
                    </div>
                </a>
                <a href="customer.php"
                   class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                    <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center shrink-0 group-hover:bg-blue-100 transition-colors">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-slate-700">Add Customer</div>
                        <div class="text-xs text-slate-400"><?= $totalCustomers ?> customers total</div>
                    </div>
                </a>
                <a href="company_details.php"
                   class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center shrink-0 group-hover:bg-slate-200 transition-colors">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-slate-700">Company Details</div>
                        <div class="text-xs text-slate-400">Update LHDN credentials</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Invoice breakdown -->
        <div class="<?= t('card') ?>">
            <h2 class="<?= t('card_title') ?>">Invoice Breakdown</h2>
            <div class="space-y-3">
                <?php
                $breakdown = [
                    ['label' => 'Paid',     'count' => $stats['paid'],    'color' => 'bg-green-500', 'pct' => $stats['total'] > 0 ? round($stats['paid']/$stats['total']*100) : 0],
                    ['label' => 'Sent',     'count' => $stats['sent'],    'color' => 'bg-blue-500',  'pct' => $stats['total'] > 0 ? round($stats['sent']/$stats['total']*100) : 0],
                    ['label' => 'Draft',    'count' => $stats['draft'],   'color' => 'bg-slate-300', 'pct' => $stats['total'] > 0 ? round($stats['draft']/$stats['total']*100) : 0],
                    ['label' => 'Overdue',  'count' => $stats['overdue'], 'color' => 'bg-red-500',   'pct' => $stats['total'] > 0 ? round($stats['overdue']/$stats['total']*100) : 0],
                ];
                foreach ($breakdown as $row):
                ?>
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-xs text-slate-600"><?= $row['label'] ?></span>
                        <span class="text-xs font-semibold text-slate-700"><?= $row['count'] ?> <span class="text-slate-300 font-normal">(<?= $row['pct'] ?>%)</span></span>
                    </div>
                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full <?= $row['color'] ?> rounded-full transition-all duration-500"
                             style="width: <?= $row['pct'] ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<?php layoutClose(); ?>
