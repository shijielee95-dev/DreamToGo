<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';  // list | new | edit

// ── Helper: compute next number for a format (defined here so available in all branches) ──
function computeNextNo(PDO $pdo, string $format): string {
    $now    = new DateTime();
    $year   = (int)$now->format('Y');
    $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $format), 0, 50);
    $pdo->prepare("INSERT IGNORE INTO invoice_sequences (prefix, year, next_no) VALUES (?, ?, 1)")
        ->execute([$seqKey, $year]);
    $stmt = $pdo->prepare("SELECT next_no FROM invoice_sequences WHERE prefix=? AND year=?");
    $stmt->execute([$seqKey, $year]);
    $seq = (int)$stmt->fetchColumn();

    // Walk forward past any numbers already used
    $check = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no=?");
    for ($i = 0; $i < 10000; $i++, $seq++) {
        $out = str_replace(['[YYYY]','[YY]','[MM]','[DD]'],
                           [$now->format('Y'),$now->format('y'),$now->format('m'),$now->format('d')], $format);
        for ($n = 2; $n <= 8; $n++) {
            $out = str_replace("[{$n}DIGIT]", str_pad((string)$seq, $n, '0', STR_PAD_LEFT), $out);
        }
        $check->execute([$out]);
        if ((int)$check->fetchColumn() === 0) return $out;
    }
    return $out;
}

// ══════════════════════════════════════════════════════════════════
// VIEW: LIST
// ══════════════════════════════════════════════════════════════════
if ($action === 'list'):

// ── Stats ──────────────────────────────────────
$stats = $pdo->query("
    SELECT
        COUNT(*)                                                           AS total,
        COALESCE(SUM(CASE WHEN status='paid'    THEN 1 END), 0)           AS paid,
        COALESCE(SUM(CASE WHEN status='sent'    THEN 1 END), 0)           AS sent,
        COALESCE(SUM(CASE WHEN status='overdue' THEN 1 END), 0)           AS overdue,
        COALESCE(SUM(CASE WHEN status='draft'   THEN 1 END), 0)           AS draft,
        COALESCE(SUM(CASE WHEN status='paid' THEN total_amount END), 0)   AS revenue
    FROM invoices
")->fetch();

// ── Filters ────────────────────────────────────
$search  = trim($_GET['search']  ?? '');
$statusF = trim($_GET['status']  ?? '');
$lhdnF   = trim($_GET['lhdn']    ?? '');

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(i.invoice_no LIKE ? OR i.customer_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusF !== '') {
    $where[]  = 'i.status = ?';
    $params[] = $statusF;
}
if ($lhdnF !== '') {
    if ($lhdnF === 'none') {
        $where[] = 'ls.id IS NULL';
    } else {
        $where[]  = 'ls.status = ?';
        $params[] = $lhdnF;
    }
}

$sql = "
    SELECT i.id, i.invoice_no, i.customer_name, i.invoice_date, i.due_date,
           i.total_amount, i.status,
           ls.status AS lhdn_status
    FROM invoices i
    LEFT JOIN lhdn_submissions ls ON ls.invoice_id = i.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

layoutOpen('Invoices', number_format($stats['total']) . ' total invoices');
?>

<!-- Page action -->
<script>
document.getElementById('pageActions').innerHTML = `
    <a href="invoice.php?action=new" class="<?= t('btn_base') ?> <?= t('btn_primary') ?>">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        New Invoice
    </a>`;
</script>

<!-- ── Stat cards ──────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
    <?php
    $cards = [
        ['label' => 'All',     'value' => $stats['total'],   'sub' => '',                          'active' => $statusF === '',        'filter' => ''],
        ['label' => 'Paid',    'value' => $stats['paid'],    'sub' => rm($stats['revenue']),       'active' => $statusF === 'paid',    'filter' => 'paid'],
        ['label' => 'Sent',    'value' => $stats['sent'],    'sub' => 'Awaiting payment',          'active' => $statusF === 'sent',    'filter' => 'sent'],
        ['label' => 'Draft',   'value' => $stats['draft'],   'sub' => 'Not yet sent',              'active' => $statusF === 'draft',   'filter' => 'draft'],
        ['label' => 'Overdue', 'value' => $stats['overdue'], 'sub' => 'Past due date',             'active' => $statusF === 'overdue', 'filter' => 'overdue'],
    ];
    foreach ($cards as $c):
        $active = $c['active'];
        $url    = '?' . http_build_query(array_merge($_GET, ['status' => $c['filter'], 'search' => $search]));
    ?>
    <a href="<?= $url ?>"
       class="<?= t('card') ?> block transition-all <?= $active ? 'ring-2 ring-indigo-500 ring-offset-1' : 'hover:border-slate-300' ?>">
        <div class="text-[10px] font-semibold uppercase tracking-wide <?= $active ? 'text-indigo-600' : 'text-slate-400' ?> mb-1">
            <?= $c['label'] ?>
        </div>
        <div class="text-xl font-bold text-slate-800"><?= $c['value'] ?></div>
        <?php if ($c['sub']): ?>
        <div class="text-[10px] text-slate-400 mt-0.5 truncate"><?= $c['sub'] ?></div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── Filter bar ─────────────────────────────── -->
<form method="GET" class="<?= t('card') ?> flex flex-wrap items-end gap-3 mb-5">
<input type="hidden" name="action" value="list">
    <!-- Search -->
    <div class="flex-1 min-w-48">
        <label class="<?= t('label') ?>">Search</label>
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" name="search" value="<?= e($search) ?>"
                   placeholder="Invoice # or customer..."
                   class="<?= t('input') ?> pl-8">
        </div>
    </div>

    <!-- Status -->
<div>
    <label class="<?= t('label') ?>">Status</label>

    <?php
    renderDropdown(
        'status',
        [
            ''          => 'All Status',
            'draft'     => 'Draft',
            'sent'      => 'Sent',
            'paid'      => 'Paid',
            'overdue'   => 'Overdue',
            'cancelled' => 'Cancelled',
        ],
        $statusF ?? ''
    );
    ?>
</div>

    <!-- LHDN -->
    <div>
        <label class="<?= t('label') ?>">LHDN Status</label>
<?php
renderDropdown(
    'lhdn',
    [
        ''        => 'All',
        'none'    => 'Not Submitted',
        'pending' => 'Pending',
        'valid'   => 'Validated',
        'invalid' => 'Invalid',
    ],
    $lhdnF ?? ''
);
?>
    </div>

    <div class="flex gap-2">
        <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Filter</button>
        <?php if ($search || $statusF || $lhdnF): ?>
        <a href="invoice.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Clear</a>
        <?php endif; ?>
    </div>
    <div class="ml-auto">
        <button type="button" onclick="openColPanel()"
                class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9 gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
            </svg>
            Columns
        </button>
    </div>
</form>

<!-- ── Invoice table ──────────────────────────── -->
<div class="<?= t('table_wrap') ?> overflow-x-auto">
    <table class="w-full text-sm" id="invoiceTable" style="min-width:520px">
        <thead>
            <tr>
                <th class="<?= t('th') ?>" data-col="invoice_no">Invoice #</th>
                <th class="<?= t('th') ?>" data-col="customer">Customer</th>
                <th class="<?= t('th') ?>" data-col="invoice_date">Invoice Date</th>
                <th class="<?= t('th') ?>" data-col="due_date">Due Date</th>
                <th class="<?= t('th') ?> text-right" data-col="amount">Amount</th>
                <th class="<?= t('th') ?> text-center" data-col="status">Status</th>
                <th class="<?= t('th') ?> text-center" data-col="lhdn">LHDN</th>
                <th class="<?= t('th') ?> text-center" data-col="actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
            <tr>
                <td colspan="8" class="px-4 py-12 text-center">
                    <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-5 h-5 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <p class="text-sm text-slate-400 mb-1">No invoices found.</p>
                    <a href="invoice.php?action=new" class="text-sm text-indigo-600 hover:underline">Create your first invoice â</a>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
            <?php
                $overdue = $inv['status'] === 'sent'
                    && $inv['due_date']
                    && strtotime($inv['due_date']) < time();
            ?>
            <tr class="hover:bg-slate-50/60 transition-colors <?= $overdue ? 'bg-red-50/30' : '' ?>">
                <td class="<?= t('td') ?>" data-col="invoice_no">
                    <a href="view_invoice.php?id=<?= $inv['id'] ?>"
                       class="font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
                        <?= e($inv['invoice_no']) ?>
                    </a>
                </td>
                <td class="<?= t('td') ?> max-w-[200px] truncate" data-col="customer"><?= e($inv['customer_name']) ?></td>
                <td class="<?= t('td') ?> text-slate-500 whitespace-nowrap" data-col="invoice_date"><?= fmtDate($inv['invoice_date']) ?></td>
                <td class="<?= t('td') ?> whitespace-nowrap <?= $overdue ? 'text-red-600 font-medium' : 'text-slate-500' ?>" data-col="due_date">
                    <?= $inv['due_date'] ? fmtDate($inv['due_date']) : '—' ?>
                    <?php if ($overdue): ?>
                    <span class="text-[10px] text-red-500 block">Overdue</span>
                    <?php endif; ?>
                </td>
                <td class="<?= t('td') ?> text-right font-semibold text-slate-800 whitespace-nowrap" data-col="amount">
                    <?= rm($inv['total_amount']) ?>
                </td>
                <td class="<?= t('td') ?> text-center" data-col="status">
                    <span class="<?= badge($inv['status']) ?>"><?= ucfirst($inv['status']) ?></span>
                </td>
                <td class="<?= t('td') ?> text-center" data-col="lhdn">
                    <?php if ($inv['lhdn_status']): ?>
                        <span class="<?= badge($inv['lhdn_status']) ?>"><?= ucfirst($inv['lhdn_status']) ?></span>
                    <?php else: ?>
                        <span class="text-xs text-slate-300">—</span>
                    <?php endif; ?>
                </td>
                <td class="<?= t('td') ?> text-center" data-col="actions">
                    <div class="flex items-center justify-center gap-1">
                        <!-- View -->
                        <a href="view_invoice.php?id=<?= $inv['id'] ?>"
                           title="View"
                           class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a>
                        <!-- Edit -->
                        <a href="invoice.php?action=edit&id=<?= $inv['id'] ?>"
                           title="Edit"
                           class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <!-- Delete -->
                        <button onclick="confirmDelete(<?= $inv['id'] ?>, '<?= e($inv['invoice_no']) ?>')"
                                title="Delete"
                                class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ── Delete modal ───────────────────────────── -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelete()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
        <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        </div>
        <h3 class="text-base font-semibold text-slate-800 text-center mb-1">Delete Invoice</h3>
        <p class="text-sm text-slate-500 text-center mb-5">
            Delete <strong id="deleteInvNo" class="text-slate-700"></strong>? This cannot be undone.
        </p>
        <div class="flex gap-3">
            <button onclick="closeDelete()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> flex-1 justify-center">Cancel</button>
            <button onclick="doDelete()"    class="<?= t('btn_base') ?> <?= t('btn_danger') ?> flex-1 justify-center">Delete</button>
        </div>
    </div>
</div>

<!-- ── Column Selector Panel ──────────────────── -->
<div id="colBackdrop" onclick="closeColPanel()"
     style="opacity:0;pointer-events:none;transition:opacity 0.25s ease"
     class="fixed inset-0 bg-black/30 z-[9998]"></div>

<div id="colPanel"
     style="transform:translateX(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1)"
     class="fixed top-0 right-0 h-screen w-64 bg-white shadow-2xl z-[9999] flex flex-col border-l border-slate-200 invisible">

    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 shrink-0">
        <h2 class="text-sm font-semibold text-slate-800">Select Columns</h2>
        <button type="button" onclick="closeColPanel()"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl font-light">&times;</button>
    </div>

    <div class="flex-1 overflow-y-auto py-3 px-5 space-y-0.5">
        <!-- Always-on: Invoice # -->
        <div class="flex items-center gap-3 py-2.5 text-sm text-slate-400 select-none">
            <svg class="w-4 h-4 text-indigo-300 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            Invoice #
            <span class="ml-auto text-xs text-slate-300">Always</span>
        </div>
        <!-- Toggleable -->
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 px-1 -mx-1 transition-colors">
            <input type="checkbox" class="col-toggle w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-col="customer">
            <span class="text-sm text-slate-700">Customer</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 px-1 -mx-1 transition-colors">
            <input type="checkbox" class="col-toggle w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-col="invoice_date">
            <span class="text-sm text-slate-700">Invoice Date</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 px-1 -mx-1 transition-colors">
            <input type="checkbox" class="col-toggle w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-col="due_date">
            <span class="text-sm text-slate-700">Due Date</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 px-1 -mx-1 transition-colors">
            <input type="checkbox" class="col-toggle w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-col="amount">
            <span class="text-sm text-slate-700">Amount</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 px-1 -mx-1 transition-colors">
            <input type="checkbox" class="col-toggle w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-col="status">
            <span class="text-sm text-slate-700">Status</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 px-1 -mx-1 transition-colors">
            <input type="checkbox" class="col-toggle w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-col="lhdn">
            <span class="text-sm text-slate-700">LHDN</span>
        </label>
        <!-- Always-on: Actions -->
        <div class="flex items-center gap-3 py-2.5 text-sm text-slate-400 select-none">
            <svg class="w-4 h-4 text-indigo-300 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            Actions
            <span class="ml-auto text-xs text-slate-300">Always</span>
        </div>
    </div>

    <div class="shrink-0 border-t border-slate-100 px-5 py-3 flex items-center justify-between bg-white">
        <button type="button" onclick="resetCols()"
                class="text-xs text-slate-400 hover:text-indigo-600 transition-colors">Reset to default</button>
        <button type="button" onclick="closeColPanel()"
                class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-8 text-xs px-4">Done</button>
    </div>
</div>

<script>
let _deleteId = null;

function confirmDelete(id, no) {
    _deleteId = id;
    document.getElementById('deleteInvNo').textContent = no;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}
function closeDelete() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    _deleteId = null;
}
function doDelete() {
    if (!_deleteId) return;
    fetch('delete_invoice.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + _deleteId
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Invoice deleted.', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(d.message || 'Failed to delete.', 'error');
        }
    })
    .catch(() => showToast('Server error.', 'error'));
}

// ── Column visibility ─────────────────────────────────────────────
var COL_KEY      = 'invoice_col_prefs_v1';
var COL_DEFAULTS = {
    customer:true, invoice_date:true, due_date:true,
    amount:true, status:true, lhdn:true
};

function loadColPrefs() {
    try { var s = localStorage.getItem(COL_KEY); return s ? JSON.parse(s) : Object.assign({},COL_DEFAULTS); }
    catch(e) { return Object.assign({},COL_DEFAULTS); }
}
function saveColPrefs(prefs) {
    try { localStorage.setItem(COL_KEY, JSON.stringify(prefs)); } catch(e) {}
}
function applyColPrefs(prefs) {
    Object.keys(prefs).forEach(function(col) {
        var vis = prefs[col];
        document.querySelectorAll('[data-col="'+col+'"]').forEach(function(el){ el.style.display = vis ? '' : 'none'; });
        var cb = document.querySelector('.col-toggle[data-col="'+col+'"]');
        if (cb) cb.checked = vis;
    });
}
function resetCols() {
    saveColPrefs(COL_DEFAULTS);
    applyColPrefs(COL_DEFAULTS);
}

document.querySelectorAll('.col-toggle').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var prefs = loadColPrefs();
        prefs[cb.dataset.col] = cb.checked;
        saveColPrefs(prefs);
        applyColPrefs(prefs);
    });
});

// Apply saved prefs immediately on load
applyColPrefs(loadColPrefs());

function openColPanel() {
    var bd = document.getElementById('colBackdrop'), p = document.getElementById('colPanel');
    bd.style.pointerEvents = 'auto';
    p.classList.remove('invisible');
    requestAnimationFrame(function(){ requestAnimationFrame(function(){
        bd.style.opacity = '1';
        p.style.transform = 'translateX(0)';
    }); });
}
function closeColPanel() {
    var bd = document.getElementById('colBackdrop'), p = document.getElementById('colPanel');
    bd.style.opacity = '0'; bd.style.pointerEvents = 'none';
    p.style.transform = 'translateX(100%)';
    setTimeout(function(){ p.classList.add('invisible'); }, 300);
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeColPanel(); });
</script>

<?php layoutClose(); ?>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: NEW / EDIT
// ══════════════════════════════════════════════════════════════════
else: // action === 'new' or 'edit'

// ── Edit mode ──────────────────────────────────
$editMode = false;
$inv      = null;
$items    = [];
$history  = [];

if ($action === 'edit' && !empty($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$id]);
    $inv  = $stmt->fetch();
    if ($inv) {
        $editMode = true;
        $iStmt    = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
        $iStmt->execute([$id]);
        $items    = $iStmt->fetchAll();
        $hStmt    = $pdo->prepare("
            SELECT action, user_name, old_value, new_value, created_at
            FROM audit_logs WHERE table_name='invoices' AND record_id=?
            ORDER BY created_at DESC LIMIT 20
        ");
        $hStmt->execute([$id]);
        $history  = $hStmt->fetchAll();
    }
}

// ── Tax rates from DB ─────────────────────────
try {
    $taxRates = $pdo->query("SELECT id, name, rate FROM tax_rates ORDER BY is_default DESC, name")->fetchAll();
} catch (Exception $e) { $taxRates = []; }

// ── Invoice number formats ────────────────────
$invoiceFormats = $pdo->query("SELECT * FROM number_formats WHERE doc_type='invoice' ORDER BY id")->fetchAll();
$nextNo = '';
$defaultFormatId = 0;
if (!$editMode && !empty($invoiceFormats)) {
    $defaultFormatId = $invoiceFormats[0]['id'];
    $nextNo = computeNextNo($pdo, $invoiceFormats[0]['format']);
}

// ── Customers with contact persons & addresses ─────────────────────
// Try fetching customers with payment_term_id (requires setup.php migration)
try {
    $customers = $pdo->query("SELECT c.id,c.customer_name,c.tin,c.reg_no,c.email,c.phone,c.address_line_0,c.address_line_1,c.city,c.postal_code,c.state_code,c.country_code,c.currency,c.default_payment_mode,c.payment_term_id,COALESCE(pt.name,'') AS payment_term_name FROM customers c LEFT JOIN payment_terms pt ON pt.id=c.payment_term_id ORDER BY c.customer_name")->fetchAll();
} catch (Exception $e) {
    $customers = $pdo->query("SELECT id,customer_name,tin,reg_no,email,phone,address_line_0,address_line_1,city,postal_code,state_code,country_code,currency,default_payment_mode FROM customers ORDER BY customer_name")->fetchAll();
    foreach ($customers as &$c) { $c['payment_term_id'] = null; $c['payment_term_name'] = ''; } unset($c);
}

// Payment terms for invoice payment term dropdown
try {
    $allInvPaymentTerms = $pdo->query("SELECT id, name, payment_mode FROM payment_terms WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {
    try { $allInvPaymentTerms = $pdo->query("SELECT id, name, payment_mode FROM payment_terms ORDER BY name")->fetchAll(); }
    catch (Exception $e2) { $allInvPaymentTerms = []; }
}
$invPtByCash   = array_values(array_filter($allInvPaymentTerms, function($r){ return $r['payment_mode']==='cash'; }));
$invPtByCredit = array_values(array_filter($allInvPaymentTerms, function($r){ return $r['payment_mode']==='credit'; }));

// ── Products for item dropdown ──────────────────────────────────────
try {
    $products_for_invoice = $pdo->query("SELECT id, name, sku, sale_price, sale_description, classification_code FROM products ORDER BY name")->fetchAll();
} catch (Exception $e) { $products_for_invoice = []; }

// Load default billing/shipping contact persons and addresses for each customer
$_custIds = array_column($customers, 'id');
$_defPersonsBilling  = [];
$_defPersonsShipping = [];
$_defAddrBilling     = [];
$_defAddrShipping    = [];

if (!empty($_custIds)) {
    $in = implode(',', array_map('intval', $_custIds));

    $rows = $pdo->query("SELECT customer_id, first_name, last_name FROM customer_contact_persons WHERE customer_id IN ($in) AND default_billing=1")->fetchAll();
    foreach ($rows as $r) $_defPersonsBilling[$r['customer_id']] = trim($r['first_name'].' '.$r['last_name']);

    $rows = $pdo->query("SELECT customer_id, first_name, last_name FROM customer_contact_persons WHERE customer_id IN ($in) AND default_shipping=1")->fetchAll();
    foreach ($rows as $r) $_defPersonsShipping[$r['customer_id']] = trim($r['first_name'].' '.$r['last_name']);

    $rows = $pdo->query("SELECT customer_id, street_address, city, postcode, state, country FROM customer_contact_addresses WHERE customer_id IN ($in) AND default_billing=1")->fetchAll();
    foreach ($rows as $r) $_defAddrBilling[$r['customer_id']] = $r;

    $rows = $pdo->query("SELECT customer_id, street_address, city, postcode, state, country FROM customer_contact_addresses WHERE customer_id IN ($in) AND default_shipping=1")->fetchAll();
    foreach ($rows as $r) $_defAddrShipping[$r['customer_id']] = $r;
}

// Attach to each customer
foreach ($customers as &$_c) {
    $cid = $_c['id'];
    $_c['default_billing_person']   = strtoupper($_defPersonsBilling[$cid]  ?? '');
    $_c['default_shipping_person']  = strtoupper($_defPersonsShipping[$cid] ?? '');
    $_c['default_billing_address']  = $_defAddrBilling[$cid]  ?? null;
    $_c['default_shipping_address'] = $_defAddrShipping[$cid] ?? null;
}
unset($_c);

// ── Existing attachments ───────────────────────
$existingAttachments = [];
if ($editMode) {
    try {
        $aStmt = $pdo->prepare("SELECT * FROM invoice_attachments WHERE invoice_id=? ORDER BY uploaded_at");
        $aStmt->execute([$id]);
        $existingAttachments = $aStmt->fetchAll();
    } catch (Exception $e) {}
}

$initItems = $editMode && !empty($items) ? $items : [
    ['description'=>'','quantity'=>1,'unit_price'=>0,'discount_pct'=>0,'tax_type'=>'none','line_total'=>0]
];

$pageTitle = $editMode ? 'Edit Invoice' : 'New Invoice';
$pageSub   = $editMode ? e($inv['invoice_no']) : 'Fill in the details below';
layoutOpen($pageTitle, $pageSub);
?>
<style>
/* ── Hide number input spinners in items table ── */
.no-spin::-webkit-outer-spin-button,
.no-spin::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.no-spin { -moz-appearance: textfield; }

/* ── Date picker ── */
.dp-popup{position:fixed;z-index:9999;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.12);padding:16px;width:284px;font-family:'Inter',sans-serif;display:none}
.dp-popup.is-open{display:block}
.dp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.dp-nav-btn{width:28px;height:28px;border-radius:7px;border:none;background:transparent;cursor:pointer;color:#64748b;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dp-nav-btn:hover{background:#f1f5f9;color:#4f46e5}
.dp-nav-btn:disabled{opacity:0.3;cursor:default}
.dp-title-btn{background:transparent;border:none;font-size:13px;font-weight:600;color:#1e293b;cursor:pointer;padding:3px 8px;border-radius:7px;letter-spacing:0.01em}
.dp-title-btn:hover{background:#eef2ff;color:#4f46e5}
/* Day grid */
.dp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.dp-dow{font-size:10px;font-weight:600;color:#94a3b8;text-align:center;padding:4px 0;text-transform:uppercase}
.dp-day{width:32px;height:32px;border-radius:8px;border:none;background:transparent;font-size:12px;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center;margin:0 auto}
.dp-day:hover:not(.dp-other):not(.dp-sel){background:#eef2ff;color:#4f46e5}
.dp-day.dp-sel{background:#4f46e5;color:#fff;font-weight:600}
.dp-day.dp-today:not(.dp-sel){color:#4f46e5;font-weight:600}
.dp-day.dp-other{color:#cbd5e1;cursor:default}
/* Month grid */
.dp-month-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 0}
.dp-mon-cell{height:36px;border-radius:9px;border:none;background:transparent;font-size:12px;font-weight:500;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center}
.dp-mon-cell:hover:not(.dp-mon-sel){background:#eef2ff;color:#4f46e5}
.dp-mon-cell.dp-mon-sel{background:#4f46e5;color:#fff;font-weight:600}
.dp-mon-cell.dp-mon-cur:not(.dp-mon-sel){color:#4f46e5;font-weight:600}
/* Year grid */
.dp-year-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 0}
.dp-yr-cell{height:36px;border-radius:9px;border:none;background:transparent;font-size:12px;font-weight:500;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center}
.dp-yr-cell:hover:not(.dp-yr-sel):not(.dp-yr-out){background:#eef2ff;color:#4f46e5}
.dp-yr-cell.dp-yr-sel{background:#4f46e5;color:#fff;font-weight:600}
.dp-yr-cell.dp-yr-cur:not(.dp-yr-sel){color:#4f46e5;font-weight:600}
.dp-yr-cell.dp-yr-out{color:#cbd5e1;cursor:default}
/* Footer */
.dp-footer{border-top:1px solid #f1f5f9;margin-top:10px;padding-top:8px;text-align:center}
.dp-today-btn{font-size:12px;font-weight:500;color:#4f46e5;background:none;border:none;cursor:pointer;padding:2px 10px;border-radius:6px}
.dp-today-btn:hover{background:#eef2ff}
</style>

<script>
document.getElementById('pageActions').innerHTML = `
    <a href="invoice.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</a>
    <button type="button" onclick="submitForm('draft')" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Save Draft</button>
    <button type="button" onclick="submitForm('sent')"  class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">
        <?= $editMode ? 'Update Invoice' : 'Save Invoice' ?>
    </button>`;
</script>

<script>
// Tax data — defined early so Alpine x-data on item rows can reference these
const TAX_RATES = <?php
    $tr = [''=>0];
    foreach ($taxRates as $t) $tr[(string)$t['id']] = (float)$t['rate'] / 100;
    echo json_encode($tr);
?>;
const TAX_OPTIONS = <?php
    $opts = [['value'=>'','text'=>'—']];
    foreach ($taxRates as $t) $opts[] = ['value'=>(string)$t['id'], 'text'=>htmlspecialchars($t['name']).' ('.number_format((float)$t['rate'],2).'%)'];
    echo json_encode($opts);
?>;

// Payment methods — defined early so Alpine x-data on payment rows can reference these
// Returns payment terms from DB filtered by current payment mode
// Each term: {v: id, l: name}
function getPaymentTerms() {
    var list = INVOICE_PT[_invoicePaymentMode] || [];
    return list.map(function(pt) { return {v: String(pt.id), l: pt.name}; });
}

// Payment terms grouped by mode — for the Payment Term dropdown
const INVOICE_PT = {
    cash:   <?= json_encode($invPtByCash,   JSON_HEX_TAG|JSON_HEX_QUOT) ?>,
    credit: <?= json_encode($invPtByCredit, JSON_HEX_TAG|JSON_HEX_QUOT) ?>
};
// Current payment mode (reactive, updated by setPaymentMode)
var _invoicePaymentMode = '<?= e($editMode && $inv ? ($inv['payment_mode'] ?? 'cash') : 'cash') ?>';
// Current customer payment term (set on customer select)
var _customerPaymentTermId   = null;
var _customerPaymentTermName = '';
</script>

<form id="invoiceForm" method="POST" action="save_invoice.php" enctype="multipart/form-data" novalidate>
<?php if ($editMode): ?>
<input type="hidden" name="edit_id"    value="<?= $inv['id'] ?>">
<?php endif; ?>
<input type="hidden" name="status"          id="formStatus"      value="<?= e($editMode ? $inv['status'] : 'draft') ?>">
<input type="hidden" name="invoice_format_id" id="invoiceFormatId" value="<?= e($defaultFormatId) ?>">
<input type="hidden" name="subtotal"        id="hiddenSubtotal"  value="0">
<input type="hidden" name="tax_amount"      id="hiddenTaxAmount" value="0">
<input type="hidden" name="total_amount"    id="hiddenTotal"     value="0">
<input type="hidden" name="discount_amount" id="hiddenDiscount"  value="0">
<input type="hidden" name="rounding_adjustment" id="hiddenRounding" value="0">
<input type="hidden" name="tax_mode"        id="taxMode"         value="exclusive">

<div class="bg-white rounded-xl border border-slate-200 mb-24">

<!-- ═══ SECTION 1: Billing & Shipping ═══ -->
<div class="grid grid-cols-[200px_1fr]" x-data="customerSearch()">
    <div class="p-6 border-r border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">Billing &amp; Shipping</h3>
        <p class="text-xs text-slate-400 leading-relaxed">Billing &amp; shipping parties for the transaction.</p>
    </div>
    <div class="p-6" x-init="selected = <?= $editMode ? 'true' : 'false' ?>; ship = <?= ($editMode && !empty($inv['shipping_address'])) ? 'true' : 'false' ?>">

        <!-- Customer row — label left, toggle right, same line -->
        <div class="grid grid-cols-2 gap-4 mb-4">
            <!-- Customer search (left col) -->
            <div>
                <div class="flex items-center h-7 mb-1"><label class="text-xs font-medium text-slate-600">Customer <span class="text-red-400">*</span></label></div>
                <div class="relative" id="customerWrap">

                    <!-- Input row -->
                    <div class="relative">
                        <input type="text" id="customerSearchInput"
                               @focus="onFocus()"
                               @input="onType()"
                               @keydown="onKey($event)"
                               @blur="onBlur($event)"
                               placeholder="Search customer..."
                               autocomplete="new-password"
                               value="<?= e($editMode ? $inv['customer_name'] : '') ?>"
                               class="<?= t('input') ?>"
                               :class="selected ? 'pr-16 font-medium text-slate-800' : 'pr-4'"
                               style="outline:none">

                        <!-- Edit + Clear icons — only when selected -->
                        <div x-show="selected" class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                            <button type="button"
                                    onmousedown="event.preventDefault(); openQuickEdit();"
                                    class="w-6 h-6 flex items-center justify-center rounded text-amber-400 hover:text-amber-600 hover:bg-amber-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button type="button" @mousedown.prevent="clearCustomer()"
                                    class="w-6 h-6 flex items-center justify-center rounded text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Dropdown panel -->
                    <div id="customerDropdown"
                         style="display:none"
                         class="fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">

                        <!-- Static: Add Customer -->
                        <button type="button" tabindex="-1"
                                onmousedown="event.preventDefault(); openQuickAdd();"
                                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50 transition-colors border-b border-slate-100">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                            Add Customer
                        </button>

                        <!-- Results list -->
                        <ul id="customerResultsList" class="max-h-52 overflow-y-auto py-1">
                        </ul>
                    </div>

                </div>
            </div>
            <!-- Shipping toggle (right col) -->
            <div>
                <div class="flex items-center justify-between h-7 mb-1">
                    <label class="text-xs font-medium text-slate-600">Shipping Reference</label>
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <span class="text-xs text-slate-400">Show Shipping</span>
                        <button type="button" @click="toggleShip()"
                                class="relative w-9 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                                :class="ship?'bg-indigo-500':'bg-slate-200'">
                            <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                                 :class="ship?'translate-x-4':''"></div>
                        </button>
                    </label>
                </div>
                <input type="text" name="shipping_reference"
                       value="<?= e($editMode ? ($inv['shipping_reference'] ?? '') : '') ?>"
                       :placeholder="ship ? 'Shipping instructions, tracking no &amp; etc.' : ''"
                       :placeholder="ship ? 'Shipping attention...' : ''"
                       :class="ship ? '' : 'bg-slate-50 text-slate-300'"
                       :disabled="!ship"
                       class="<?= t('input') ?> transition-colors">
            </div>
        </div>

        <!-- Billing Attention + Shipping Attention -->
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="<?= t('label') ?>">Billing Attention</label>
                <input type="text" name="billing_attention" id="f_billing_attention"
                       value="<?= e($editMode ? ($inv['billing_attention'] ?? '') : '') ?>"
                       class="<?= t('input') ?>">
            </div>
            <div>
                <label class="<?= t('label') ?>">Shipping Attention</label>
                <input type="text" name="shipping_attention" id="f_shipping_attention"
                       value="<?= e($editMode ? ($inv['shipping_attention'] ?? '') : '') ?>"
                       :placeholder="ship ? 'Shipping attention...' : ''"
                       :class="ship ? '' : 'bg-slate-50 text-slate-300'"
                       :disabled="!ship"
                       class="<?= t('input') ?> transition-colors">
            </div>
        </div>

        <!-- Billing Address + Shipping Address -->
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="<?= t('label') ?>">Billing Address</label>
                <textarea name="customer_address" id="f_customer_address" rows="4"
                          placeholder="Street, City, Postcode, State"
                          class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($editMode ? ($inv['customer_address'] ?? '') : '') ?></textarea>
            </div>
            <div>
                <label class="<?= t('label') ?>">Shipping Address</label>
                <textarea name="shipping_address" id="f_shipping_address" rows="4"
                          :placeholder="ship ? 'If different from billing address' : ''"
                          :class="ship ? '' : 'bg-slate-50 text-slate-300'"
                          :disabled="!ship"
                          class="<?= t('input') ?> h-auto py-2 resize-none transition-colors"><?= e($editMode ? ($inv['shipping_address'] ?? '') : '') ?></textarea>
            </div>
        </div>

        <!-- Hidden customer fields -->
        <input type="hidden" name="customer_name"   id="f_customer_name"   value="<?= e($editMode ? $inv['customer_name']   : '') ?>">
        <input type="hidden" name="customer_tin"    id="f_customer_tin"    value="<?= e($editMode ? $inv['customer_tin']    : '') ?>">
        <input type="hidden" name="customer_reg_no" id="f_customer_reg_no" value="<?= e($editMode ? $inv['customer_reg_no'] : '') ?>">
        <input type="hidden" name="customer_email"  id="f_customer_email"  value="<?= e($editMode ? $inv['customer_email']  : '') ?>">
        <input type="hidden" name="customer_phone"  id="f_customer_phone"  value="<?= e($editMode ? $inv['customer_phone']  : '') ?>">
    </div>
</div>

<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 2: General Info ═══ -->
<div class="grid grid-cols-[200px_1fr]">
    <div class="p-6 border-r border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">General Info</h3>
        <p class="text-xs text-slate-400 leading-relaxed">Invoice number, date and general information.</p>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="<?= t('label') ?>">Invoice Number <span class="text-red-400">*</span></label>
                <?php if ($editMode): ?>
                    <!-- Locked in edit mode -->
                    <div class="<?= t('input') ?> font-mono text-slate-700 bg-slate-50 cursor-not-allowed flex items-center justify-between">
                        <span><?= e($inv['invoice_no']) ?></span>
                        <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 17v-6m0 0V7m0 4h4m-4 0H8"/><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                    <input type="hidden" name="invoice_no" value="<?= e($inv['invoice_no']) ?>">
                <?php elseif (empty($invoiceFormats)): ?>
                    <div class="<?= t('input') ?> text-slate-400 flex items-center">No invoice formats configured</div>
                    <p class="text-xs text-amber-600 mt-1">Add one in Control Panel → Number Formats.</p>
                <?php else: ?>
                    <div id="invoiceNoDd" class="relative">
                        <!-- Trigger -->
                        <button type="button" id="invoiceNoBtn" onclick="invoiceNoDdToggle()"
                                style="outline:none"
                                class="<?= t('input') ?> text-left flex items-center justify-between font-mono">
                            <span id="invoiceNoDisplay"><?= e($nextNo) ?></span>
                            <svg id="invoiceNoChevron" class="w-4 h-4 text-slate-400 shrink-0 transition-transform"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Dropdown panel -->
                        <div id="invoiceNoDdPanel" style="display:none"
                             class="absolute z-50 left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="py-1">
                                <?php foreach ($invoiceFormats as $f): ?>
                                <li>
                                    <button type="button"
                                            onclick="invoiceNoDdSelect(<?= $f['id'] ?>, '<?= e(addslashes($f['format'])) ?>')"
                                            id="invoiceNoOpt_<?= $f['id'] ?>"
                                            class="w-full text-left px-4 py-2.5 text-sm font-mono transition-colors <?= $f['id'] == $defaultFormatId ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-800 hover:bg-slate-50' ?>">
                                        <?= e($f['format']) ?>
                                    </button>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <input type="hidden" name="invoice_no" id="invoiceNoValue" value="<?= e($nextNo) ?>">
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <label class="<?= t('label') ?>">Reference No.</label>
                <input type="text" name="reference_no"
                       value="<?= e($editMode ? ($inv['reference_no'] ?? '') : '') ?>"
                       placeholder="Optional reference" class="<?= t('input') ?>">
            </div>
            <div>
                <label class="<?= t('label') ?>">Date <span class="text-red-400">*</span></label>
                <div class="relative dp-wrap">
                    <input type="text" id="invoiceDate" name="invoice_date" required readonly
                           value="<?= e($editMode ? date('d/m/Y', strtotime($inv['invoice_date'])) : date('d/m/Y')) ?>"
                           placeholder="DD/MM/YYYY"
                           class="<?= t('input') ?> cursor-pointer pr-8">
                    <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
            </div>
            <div>
                <label class="<?= t('label') ?>">Currency <span class="text-red-400">*</span></label>
                <div id="invoiceCurrencyDd" x-data="invoiceCurrencyComp('<?= e($editMode ? $inv['currency'] : 'MYR') ?>')" class="relative">
                    <!-- Trigger -->
                    <div class="relative">
                        <input type="text" id="invoiceCurrencyInput"
                               :value="open ? q : selected.label"
                               @focus="onFocus()"
                               @input="q=$event.target.value; activeIdx=-1"
                               @blur="onBlur()"
                               @keydown.escape="open=false; q=''"
                               @keydown.arrow-down.prevent="moveDown()"
                               @keydown.arrow-up.prevent="moveUp()"
                               @keydown.enter.prevent="pickActive()"
                               placeholder="Search currency..."
                               autocomplete="off"
                               class="<?= t('input') ?> pr-8">
                        <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                             :class="open ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                    <!-- Dropdown -->
                    <div x-show="open && filtered.length" @mousedown.prevent style="display:none"
                         class="absolute z-[9996] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                        <ul class="max-h-52 overflow-y-auto py-1" x-ref="list">
                            <template x-for="(c, i) in filtered" :key="c.code">
                                <li>
                                    <button type="button"
                                            @mousedown.prevent="pick(c)"
                                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                            :class="i===activeIdx ? 'bg-indigo-50 text-indigo-700 font-medium' : (c.code===selected.code ? 'bg-slate-50 text-slate-700 font-medium' : 'text-slate-800 hover:bg-slate-50')">
                                        <span class="font-mono text-xs font-semibold" x-text="c.code"></span>
                                        <span class="ml-2 text-slate-500" x-text="c.name"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <input type="hidden" name="currency" :value="selected.code">
                </div>
            </div>
            <div>
                <label class="<?= t('label') ?>">Description</label>
                <input type="text" name="description"
                       value="<?= e($editMode ? ($inv['description'] ?? '') : '') ?>"
                       placeholder="Brief description" class="<?= t('input') ?>">
            </div>
            <div>
                <label class="<?= t('label') ?>">Internal Note</label>
                <input type="text" name="internal_note"
                       value="<?= e($editMode ? ($inv['internal_note'] ?? '') : '') ?>"
                       placeholder="Internal use only" class="<?= t('input') ?>">
            </div>
            <div>
                <label class="<?= t('label') ?>">Payment Mode</label>
                <?php
                $curPayMode = $editMode ? ($inv['payment_mode'] ?? 'cash') : 'cash';
                ?>
                <div class="flex rounded-lg border border-slate-200 overflow-hidden text-sm h-9" id="paymentModeBtns">
                    <button type="button" id="pmCash"
                            onclick="setPaymentMode('cash')"
                            class="flex-1 flex items-center justify-center gap-1.5 px-4 transition-colors <?= $curPayMode === 'cash' ? 'bg-indigo-600 text-white font-medium' : 'bg-white text-slate-500 hover:bg-slate-50' ?>">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M22 10H2M6 14h.01"/></svg>
                        Cash Sales
                    </button>
                    <button type="button" id="pmCredit"
                            onclick="setPaymentMode('credit')"
                            class="flex-1 flex items-center justify-center gap-1.5 px-4 border-l border-slate-200 transition-colors <?= $curPayMode === 'credit' ? 'bg-indigo-600 text-white font-medium' : 'bg-white text-slate-500 hover:bg-slate-50' ?>">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        Credit Sales
                    </button>
                </div>
                <input type="hidden" name="payment_mode" id="invoicePaymentMode" value="<?= e($curPayMode) ?>">
            </div>
            <div></div><?php // spacer to maintain 2-col grid ?>
        </div>
    </div>
</div>

<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 3: Items ═══ -->
<div>
    <div class="flex items-center justify-between px-6 py-4">
        <h3 class="text-sm font-semibold text-slate-800">Items</h3>
        <div class="flex rounded-lg border border-slate-200 overflow-hidden text-xs font-medium">
            <button type="button" onclick="setTaxMode('exclusive')" id="btn_exclusive"
                    class="px-3 py-1.5 bg-indigo-600 text-white transition-colors">Tax Exclusive</button>
            <button type="button" onclick="setTaxMode('inclusive')" id="btn_inclusive"
                    class="px-3 py-1.5 bg-white text-slate-500 hover:bg-slate-50 transition-colors">Tax Inclusive</button>
        </div>
    </div>
    <div class="border-t border-slate-100 overflow-x-auto">
        <table class="w-full text-sm" style="table-layout:fixed">
            <colgroup>
                <col style="width:44px">   <!-- # -->
                <col>                      <!-- item name (flex) -->
                <col style="width:110px">  <!-- qty -->
                <col style="width:110px">  <!-- unit price -->
                <col style="width:110px">  <!-- amount -->
                <col style="width:110px">  <!-- discount -->
                <col style="width:110px">  <!-- tax -->
                <col style="width:44px">   <!-- delete -->
            </colgroup>
            <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                    <th class="px-3 py-2.5 text-center text-[10px] font-semibold text-slate-700 uppercase tracking-wide">#</th>
                    <th class="px-3 py-2.5 text-left   text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Item / Description</th>
                    <th class="px-2 py-2.5 text-right  text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Qty</th>
                    <th class="px-2 py-2.5 text-right  text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Unit Price</th>
                    <th class="px-2 py-2.5 text-right  text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Amount</th>
                    <th class="px-2 py-2.5 text-right  text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Discount</th>
                    <th class="px-2 py-2.5 text-center text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Tax</th>
                    <th class="px-2 py-2.5"></th>
                </tr>
            </thead>
            <tbody id="itemsBody">
<?php
$lhdnDesc = ['001'=>'Breastfeeding equipment','002'=>'Child care centres and kindergartens fees','003'=>'Computer, smartphone or tablet','004'=>'Consolidated e-Invoice','005'=>'Construction materials','006'=>'Disbursement','007'=>'Donation','008'=>'e-Commerce - e-Invoice to buyer/purchaser','009'=>'e-Commerce - Self-billed e-Invoice','010'=>'Education fees','011'=>'Goods on consignment (Consignor)','012'=>'Goods on consignment (Consignee)','013'=>'Gym membership','014'=>'Insurance - Education and medical benefits','015'=>'Insurance - Takaful or life insurance','016'=>'Interest and financing expenses','017'=>'Internet subscription','018'=>'Land and building','019'=>'Medical examination for learning disabilities','020'=>'Medical examination or vaccination expenses','021'=>'Medical expenses for serious diseases','022'=>'Others','023'=>'Petroleum operations','024'=>'Private retirement scheme','025'=>'Motor vehicle','026'=>'Subscription of books/journals/magazines','027'=>'Reimbursement','028'=>'Rental of motor vehicle','029'=>'EV charging facilities','030'=>'Repair and maintenance','031'=>'Research and development','032'=>'Foreign income','033'=>'Self-billed - Betting and gaming','034'=>'Self-billed - Importation of goods','035'=>'Self-billed - Importation of services','036'=>'Self-billed - Others','037'=>'Self-billed - Monetary payment to agents','038'=>'Sports equipment and facilities','039'=>'Supporting equipment for disabled person','040'=>'Voluntary contribution to approved provident fund','041'=>'Dental examination or treatment','042'=>'Fertility treatment','043'=>'Treatment and home care nursing','044'=>'Vouchers, gift cards, loyalty points','045'=>'Self-billed - Non-monetary payment to agents'];
?>
<?php foreach ($initItems as $i => $item):
    $discVal  = (float)($item['discount_pct'] ?? 0);
    $discMode = !empty($item['discount_mode']) ? $item['discount_mode'] : 'pct';
    $descNote = $item['item_description'] ?? '';
    $classVal = $item['classification'] ?? '';
    $txVal   = $item['tax_type'] ?? '';
    $txLabel = '—';
    foreach ($taxRates as $_tr) {
        if ((string)$_tr['id'] === (string)$txVal) {
            $txLabel = e($_tr['name']).' ('.number_format((float)$_tr['rate'],2).'%)';
            break;
        }
    }
    $qty      = (float)($item['quantity'] ?? 1);
    $price    = (float)($item['unit_price'] ?? 0);
    // For new rows (not edit mode), show placeholder instead of prefilled value
    $qtyVal   = ($editMode && isset($item['id'])) ? number_format($qty, 2, '.', '') : '';
    $priceVal = ($editMode && isset($item['id'])) ? number_format($price, 2, '.', '') : '';
    // Discount display: "20.00%" for pct, "20.00" for fixed
    if ($discVal > 0) {
        $dv = $discMode === 'pct' ? number_format($discVal, 2, '.', '').'%' : number_format($discVal, 2, '.', '');
    } else {
        $dv = '';
    }
?>
                <!-- ROW 1: item name + all numeric columns -->
                <tr class="item-row hover:bg-slate-50/30 transition-colors">
                    <td class="px-3 pt-2 pb-0 text-sm text-slate-700 text-center font-medium row-num" rowspan="2"
                        style="vertical-align:top;padding-top:12px"><?= $i+1 ?></td>

                    <!-- Item name — product search combobox -->
                    <td class="px-3 pt-2 pb-0">
                        <div class="relative">
                            <input type="text" name="items[<?= $i ?>][description]"
                                   value="<?= e($item['description']) ?>"
                                   placeholder="Item name" required
                                   autocomplete="off"
                                   class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-slate-800 focus:outline-none focus:border-indigo-500 transition item-desc-input"
                                   onfocus="itemDdOpen(this)"
                                   oninput="itemDdFilter(this)"
                                   onblur="itemDdBlur(this)"
                                   onkeydown="itemDdKey(event,this)">
                            <div class="item-dd-panel fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" style="display:none">
                                <ul class="item-dd-list max-h-52 overflow-y-auto py-1"></ul>
                            </div>
                        </div>
                    </td>

                    <!-- Qty -->
                    <td class="px-2 pt-2 pb-0">
                        <input type="number" name="items[<?= $i ?>][quantity]" value="<?= e($qtyVal) ?>"
                               min="0.01" step="0.01" placeholder="1.00"
                               class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition item-qty"
                               onblur="if(this.value)this.value=parseFloat(this.value).toFixed(2)">
                    </td>

                    <!-- Unit Price -->
                    <td class="px-2 pt-2 pb-0">
                        <input type="number" name="items[<?= $i ?>][unit_price]" value="<?= e($priceVal) ?>"
                               min="0" step="0.01" placeholder="0.00"
                               class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition item-price"
                               onblur="if(this.value)this.value=parseFloat(this.value).toFixed(2)">
                    </td>

                    <!-- Amount (calculated) -->
                    <td class="px-2 pt-2 pb-0">
                        <input type="text" name="items[<?= $i ?>][line_total]"
                               value="<?= ($editMode && isset($item['id'])) ? number_format($qty * $price, 2, '.', '') : '' ?>" readonly
                               placeholder="0.00"
                               class="w-full h-8 border border-slate-100 rounded-lg px-2.5 text-sm text-right font-semibold text-slate-700 bg-slate-50 cursor-default item-total">
                    </td>

                    <!-- Discount: plain text, "20.00" = fixed RM, "20.00%" = pct -->
                    <td class="px-2 pt-2 pb-0">
                        <input type="text" name="items[<?= $i ?>][discount_raw]" value="<?= e($dv) ?>"
                               placeholder="0.00 or %"
                               class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition item-disc-raw"
                               onblur="formatDisc(this)">
                        <input type="hidden" name="items[<?= $i ?>][discount_pct]"  class="item-disc"      value="<?= e($discVal) ?>">
                        <input type="hidden" name="items[<?= $i ?>][discount_mode]" class="item-disc-mode" value="<?= e($discMode) ?>">
                    </td>

                    <!-- Tax dropdown — dropdown.php pattern -->
                    <td class="px-2 pt-2 pb-0"
                        x-data="{open:false,value:'<?= e($txVal) ?>',options:TAX_OPTIONS}">
                        <div class="relative">
                            <button type="button"
                                    @click="open=!open" @keydown.escape="open=false"
                                    class="w-full h-8 px-2.5 rounded-lg bg-white border border-slate-200 text-left flex items-center justify-between gap-1 text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-300">
                                <span x-text="options.find(o=>o.value===value)?.text||'—'" class="text-slate-800 truncate"></span>
                                <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" @click.outside="open=false" style="display:none"
                                 x-transition
                                 class="fixed z-50 bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden origin-top"
                                 style="min-width:140px"
                                 data-dd-panel
                                 x-init="$watch('open',function(v){if(v){ddPos($el.previousElementSibling,$el);}})">
                                <ul class="max-h-56 overflow-y-auto py-1">
                                    <template x-for="o in options" :key="o.value">
                                        <li>
                                            <button type="button"
                                                    @click="value=o.value;open=false;$el.closest('tr').querySelector('.item-tax').value=o.value;$el.closest('tr').querySelector('.item-tax').dispatchEvent(new Event('change'))"
                                                    class="w-full text-left px-3 py-2 text-sm transition-colors"
                                                    :class="value===o.value?'bg-indigo-50 text-indigo-700 font-medium':'text-slate-700 hover:bg-slate-50'">
                                                <span x-text="o.text"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <select name="items[<?= $i ?>][tax_type]"
                                    class="absolute opacity-0 pointer-events-none w-0 h-0 top-0 left-0 item-tax" tabindex="-1" aria-hidden="true">
                                <option value="">—</option>
                                <?php foreach ($taxRates as $_tr): ?>
                                <option value="<?= $_tr['id'] ?>" <?= (string)$_tr['id'] === (string)$txVal ? 'selected' : '' ?>><?= e($_tr['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </td>

                    <!-- Delete — rowspan covers both rows -->
                    <td class="px-2 py-2 text-center" rowspan="2" style="vertical-align:middle">
                        <button type="button" onclick="removeRow(this)"
                                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </td>
                </tr>

                <!-- ROW 2: description note + LHDN classification on same row -->
                <tr class="item-desc-row border-b border-slate-50 transition-colors">
                    <!-- # col handled by rowspan -->
                    <td class="px-3 pb-2 pt-1">
                        <input type="text" name="items[<?= $i ?>][item_description]" value="<?= e($descNote) ?>"
                               placeholder="Description (optional)"
                               class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-slate-600 focus:outline-none focus:border-indigo-500 transition placeholder-slate-300">
                    </td>
                    <!-- LHDN Classification spans the remaining 5 numeric cols -->
                    <td class="px-2 pb-2 pt-1" colspan="5">
                        <input type="hidden" name="items[<?= $i ?>][classification]" class="item-class" value="<?= e($classVal) ?>">
                        <div class="relative" x-data="lhdnComp('<?= e($classVal) ?>')">
                            <input type="text" x-ref="inp"
                                   :value="open ? q : displayLabel"
                                   @focus="onFocus()"
                                   @input="q=$event.target.value"
                                   @blur="onBlur()"
                                   @keydown.escape="open=false;q=''"
                                   @keydown.arrow-down.prevent="moveDown()"
                                   @keydown.arrow-up.prevent="moveUp()"
                                   @keydown.enter.prevent="pickActive()"
                                   placeholder="LHDN Classification..."
                                   autocomplete="off"
                                   class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition pr-7">
                            <svg class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                                 :class="open?'rotate-180':''"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M19 9l-7 7-7-7"/>
                            </svg>
                            <div x-show="open" @mousedown.prevent style="display:none"
                                 class="fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden" data-dd-panel
                                 x-init="$watch('open',function(v){if(v){ddPos($el.parentElement,$el);}})">
                                <ul class="max-h-56 overflow-y-auto py-1" x-ref="list">
                                    <template x-for="(o,i) in filteredCodes" :key="o.v">
                                        <li>
                                            <button type="button" @mousedown.prevent="pickItem(o)"
                                                    class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                    :class="i===activeIdx?'bg-indigo-50 text-indigo-700 font-medium':(value===o.v?'bg-slate-50 text-slate-700 font-medium':'text-slate-700 hover:bg-slate-50')">
                                                <span x-text="o.l"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </td>
                    <!-- delete col handled by rowspan -->
                </tr>

<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="px-6 py-3 border-t border-slate-50">
        <button type="button" onclick="addRow()" class="flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            Add Item
        </button>
    </div>
    <!-- Totals -->
    <div class="border-t border-slate-200 px-6 py-5 flex justify-end">
        <div class="w-72 space-y-2.5 text-sm">
            <div class="flex justify-between text-slate-600">
                <span>Sub Total</span>
                <span class="font-medium text-slate-800">RM <span id="dispSubtotal">0.00</span></span>
            </div>
            <div class="flex justify-between text-slate-600">
                <span>Discount Given</span>
                <span class="text-slate-500">RM <span id="dispDiscount">0.00</span></span>
            </div>
            <div class="flex justify-between text-slate-600">
                <span>Tax</span>
                <span class="font-medium text-slate-800">RM <span id="dispTax">0.00</span></span>
            </div>
            <div class="flex items-center justify-between text-slate-600">
                <div class="flex items-center gap-2">
                    <span>Rounding Adjustment</span>
                    <button type="button" onclick="toggleRounding()" id="roundTrack"
                            class="relative w-8 h-4 rounded-full transition-colors bg-slate-200 focus:outline-none shrink-0">
                        <div id="roundThumb" class="absolute top-0.5 left-0.5 w-3 h-3 bg-white rounded-full shadow transition-transform"></div>
                    </button>
                </div>
                <span class="text-slate-400">RM <span id="dispRounding">0.00</span></span>
            </div>
            <div class="border-t border-slate-200 pt-3 flex justify-between font-bold text-slate-900 text-base">
                <span>TOTAL</span>
                <span>RM <span id="dispTotal">0.00</span></span>
            </div>
        </div>
    </div>
</div>

<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 4: Additional Info ═══ -->
<div class="grid grid-cols-[200px_1fr]">
    <div class="p-6 border-r border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">Additional Info</h3>
        <p class="text-xs text-slate-400 leading-relaxed">Remarks displayed on the invoice.</p>
    </div>
    <div class="p-6">
        <label class="<?= t('label') ?>">Remarks</label>
        <textarea name="notes" rows="4" placeholder="Payment terms, bank details, terms &amp; conditions..."
                  class="<?= t('input') ?> h-auto py-2 resize-none w-full"><?= e($editMode ? ($inv['notes'] ?? '') : '') ?></textarea>
    </div>
</div>

<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 4b: Payment Received ═══ -->
<?php
// Load existing payments (edit mode)
$existingPayments = [];
if ($editMode) {
    try {
        $pStmt = $pdo->prepare("SELECT * FROM invoice_payments WHERE invoice_id=? ORDER BY id");
        $pStmt->execute([$id]);
        $existingPayments = $pStmt->fetchAll();
    } catch (Exception $e) { $existingPayments = []; }
}
$paymentMethodLabels = [
    '01' => 'Cash',
    '02' => 'Cheque',
    '03' => 'Bank Transfer',
    '04' => 'Online Banking',
    '05' => 'Credit / Debit Card',
    '06' => 'Others',
];
?>
<div>
    <div class="flex items-center justify-between px-6 py-4">
        <div>
            <h3 class="text-sm font-semibold text-slate-800">Payment Received</h3>
            <p class="text-xs text-slate-400 mt-0.5">Record payments made against this invoice.</p>
        </div>
    </div>
    <div class="border-t border-slate-100 overflow-x-auto">
        <table class="w-full text-sm" style="table-layout:fixed">
            <colgroup>
                <col style="width:44px">    <!-- # -->
                <col style="width:190px">   <!-- Payment Method -->
                <col style="width:120px">   <!-- Amount -->
                <col>                        <!-- Reference No. (flex) -->
                <col>                        <!-- Notes (flex, same as Ref) -->
                <col style="width:44px">    <!-- delete -->
            </colgroup>
            <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                    <th class="px-3 py-2.5 text-center text-[10px] font-semibold text-slate-700 uppercase tracking-wide">#</th>
                    <th class="px-3 py-2.5 text-left   text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Payment Term</th>
                    <th class="px-2 py-2.5 text-right  text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Amount</th>
                    <th class="px-2 py-2.5 text-left   text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Reference No.</th>
                    <th class="px-2 py-2.5 text-left   text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Notes</th>
                    <th class="px-2 py-2.5"></th>
                </tr>
            </thead>
            <tbody id="paymentsBody">
<?php foreach ($existingPayments as $pi => $pmt): ?>
                <tr class="payment-row border-b border-slate-50 hover:bg-slate-50/30 transition-colors">
                    <td class="px-3 py-2 text-center text-sm text-slate-500 payment-num"><?= $pi + 1 ?></td>
                    <td class="px-3 py-2" x-data="{open:false,value:'<?= e($pmt['payment_term_id'] ?? '') ?>',options:getPaymentTerms()}" @payment-mode-changed.window="options=getPaymentTerms(); if(options.length && !options.find(function(o){return o.v===value})){value='';$el.querySelector('.pmt-term').value='';}">
                        <div class="relative">
                            <button type="button" @click="open=!open" @keydown.escape="open=false"
                                    class="w-full h-8 px-2.5 rounded-lg bg-white border border-slate-200 text-left flex items-center justify-between gap-1 text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-300">
                                <span x-text="options.find(function(o){return o.v===value}) ? options.find(function(o){return o.v===value}).l : 'Select...'" :class="value ? 'text-slate-800' : 'text-slate-400'" class="truncate"></span>
                                <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" @click.outside="open=false" @mousedown.prevent style="display:none"
                                 class="fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" data-dd-panel
                                 x-init="$watch('open',function(v){if(v){ddPos($el.previousElementSibling,$el);}})">
                                <ul class="py-1 min-w-[180px]">
                                    <template x-for="o in options" :key="o.v">
                                        <li>
                                            <button type="button" @mousedown.prevent="value=o.v;open=false;$el.closest('tr').querySelector('.pmt-term').value=o.v"
                                                    class="w-full text-left px-3 py-2 text-sm transition-colors"
                                                    :class="value===o.v?'bg-indigo-50 text-indigo-700 font-medium':'text-slate-700 hover:bg-slate-50'">
                                                <span x-text="o.l"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <input type="hidden" name="payments[<?= $pi ?>][payment_term_id]" class="pmt-term" value="<?= e($pmt['payment_term_id'] ?? '') ?>">
                        </div>
                    </td>
                    <td class="px-2 py-2">
                        <input type="number" name="payments[<?= $pi ?>][amount]" value="<?= number_format((float)$pmt['amount'], 2, '.', '') ?>"
                               min="0" step="0.01" placeholder="0.00"
                               class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition pmt-amount"
                               oninput="updatePaymentTotal()"
                               onblur="this.value=parseFloat(this.value||0).toFixed(2)">
                    </td>
                    <td class="px-2 py-2">
                        <input type="text" name="payments[<?= $pi ?>][reference_no]" value="<?= e($pmt['reference_no'] ?? '') ?>"
                               placeholder="Ref. no."
                               class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition">
                    </td>
                    <td class="px-2 py-2">
                        <input type="text" name="payments[<?= $pi ?>][notes]" value="<?= e($pmt['notes'] ?? '') ?>"
                               placeholder="Notes"
                               class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition">
                    </td>
                    <td class="px-2 py-2 text-center">
                        <button type="button" onclick="removePaymentRow(this)"
                                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Footer row: + Payment button LEFT, balance details RIGHT -->
    <div class="px-6 py-3 border-t border-slate-50 flex items-center justify-between">
        <button type="button" onclick="addPaymentRow()"
                class="flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            Payment
        </button>
        <div class="text-sm text-slate-500">
            Paid: <span class="font-semibold text-slate-800 ml-1">RM <span id="pmtPaidTotal">0.00</span></span>
            <span class="mx-2 text-slate-300">|</span>
            Balance: <span id="pmtBalanceLabel" class="font-semibold ml-1 text-slate-800">RM <span id="pmtBalance">0.00</span></span>
        </div>
    </div>
</div>

<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 5: Attachments ═══ -->
<div class="grid grid-cols-[200px_1fr]">
    <div class="p-6 border-r border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">Attachments</h3>
        <p class="text-xs text-slate-400 leading-relaxed">Supporting documents for this transaction.</p>
    </div>
    <div class="p-6">
        <div id="dropZone"
             class="border-2 border-dashed border-slate-200 rounded-xl p-8 text-center transition-colors hover:border-indigo-300 hover:bg-indigo-50/30 cursor-pointer"
             onclick="document.getElementById('fileInput').click()"
             ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
            <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <p class="text-sm font-medium text-slate-600 mb-1">Drop files to upload</p>
            <p class="text-xs text-slate-400">or <span class="text-indigo-500 font-medium">click to browse</span></p>
            <p class="text-[10px] text-slate-300 mt-2">PDF, JPG, PNG, DOC up to 10MB each</p>
        </div>
        <input type="file" id="fileInput" name="attachments[]" multiple
               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" onchange="handleFiles(this.files)">

        <!-- Saved attachments (edit mode) -->
        <div id="fileList" class="mt-3 space-y-2">
            <?php foreach ($existingAttachments as $att):
                $attUrl  = APP_URL . '/storage/attachments/' . rawurlencode($att['stored_name']);
                $ext     = strtoupper(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                $canView = in_array($ext, ['PDF','JPG','JPEG','PNG']);
            ?>
            <div class="flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200 group">
                <div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
                    <span class="text-[9px] font-bold text-indigo-600"><?= e($ext) ?></span>
                </div>
                <div class="flex-1 min-w-0">
                    <?php if ($canView): ?>
                    <a href="<?= e($attUrl) ?>" target="_blank"
                       class="text-xs font-medium text-indigo-600 hover:underline truncate block"><?= e($att['original_name']) ?></a>
                    <?php else: ?>
                    <div class="text-xs font-medium text-slate-700 truncate"><?= e($att['original_name']) ?></div>
                    <?php endif; ?>
                    <div class="text-[10px] text-slate-400"><?= fmtDate($att['uploaded_at'], 'd M Y') ?></div>
                </div>
                <?php if ($canView): ?>
                <a href="<?= e($attUrl) ?>" target="_blank"
                   class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100" title="Open">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <?php endif; ?>
                <a href="delete_attachment.php?id=<?= $att['id'] ?>&invoice=<?= $id ?>"
                   onclick="return confirm('Remove this attachment?')"
                   class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100" title="Remove">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 6: Controls ═══ -->
<div class="grid grid-cols-[200px_1fr]">
    <div class="p-6 border-r border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">Controls</h3>
        <p class="text-xs text-slate-400 leading-relaxed">Status for this transaction.</p>
    </div>
    <div class="p-6">
        <div class="max-w-xs">
            <label class="<?= t('label') ?>">Status</label>
            <?php renderDropdown('status_visible', [
                'draft'  =>'Draft',
                'sent'   =>'Sent',
                'paid'   =>'Paid',
                'overdue'=>'Overdue',
            ], $editMode ? $inv['status'] : 'draft', 'Select status', false, 'status-dd'); ?>
        </div>
    </div>
</div>

<?php if ($editMode && !empty($history)): ?>
<div class="border-t border-slate-100"></div>

<!-- ═══ SECTION 7: History ═══ -->
<div class="grid grid-cols-[200px_1fr]">
    <div class="p-6 border-r border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">History</h3>
        <p class="text-xs text-slate-400 leading-relaxed">Action history for this invoice.</p>
    </div>
    <div class="p-4">
        <!-- Max 5 rows shown, scrollable -->
        <div class="overflow-y-auto border border-slate-100 rounded-lg" style="max-height:260px">
        <table class="w-full text-sm">
            <thead class="sticky top-0 bg-slate-50 border-b border-slate-100 z-10">
                <tr>
                    <th class="px-3 py-2 text-left  text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Date</th>
                    <th class="px-3 py-2 text-left  text-[10px] font-semibold text-slate-400 uppercase tracking-wide">User</th>
                    <th class="px-3 py-2 text-left  text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Action</th>
                    <th class="px-3 py-2 text-center text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php
                $actionLabels = ['CREATE_INVOICE'=>'Created','UPDATE_INVOICE'=>'Updated','DELETE_INVOICE'=>'Deleted'];
                foreach ($history as $h):
                    $label     = $actionLabels[$h['action']] ?? $h['action'];
                    $hasDetail = !empty($h['old_value']) || !empty($h['new_value']);
                    $histData  = json_encode([
                        'old' => $h['old_value'] ? json_decode($h['old_value'], true) : null,
                        'new' => $h['new_value'] ? json_decode($h['new_value'], true) : null,
                    ]);
                ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-3 py-3 text-xs text-slate-500 whitespace-nowrap"><?= fmtDate($h['created_at'], 'd/m/Y g:i a') ?></td>
                    <td class="px-3 py-3 text-xs font-medium text-slate-700"><?= e($h['user_name']) ?></td>
                    <td class="px-3 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 text-xs text-slate-600">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                            <?= e($label) ?>
                        </span>
                    </td>
                    <td class="px-3 py-3 text-center">
                        <?php if ($hasDetail): ?>
                        <button type="button" onclick='showHistory(<?= htmlspecialchars($histData, ENT_QUOTES) ?>)'
                                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors mx-auto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                        <?php else: ?>
                        <span class="text-slate-300 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /scroll -->
    </div>
</div>
<?php endif; ?>

</div><!-- end card -->
</form>

<!-- Sticky footer -->
<div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-between px-8 py-3" style="left:224px">
    <div class="text-sm text-slate-500">
        Total: <span class="font-bold text-slate-900 text-base ml-1">RM <span id="footerTotal">0.00</span></span>
    </div>
    <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-sm text-slate-500 cursor-pointer select-none">
            <input type="checkbox" class="w-4 h-4 rounded border-slate-300 text-indigo-600">
            QuickShare via Email
        </label>
        <a href="invoice.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</a>
        <button type="button" onclick="submitForm('draft')" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Save Draft</button>
        <button type="button" onclick="submitForm('sent')"  class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">
            <?= $editMode ? 'Update Invoice' : 'Save Invoice' ?>
        </button>
    </div>
</div>

<!-- History modal -->
<div id="historyModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeHistoryModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-slate-800">Change Detail</h3>
            <button type="button" onclick="closeHistoryModal()" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 text-xl">&times;</button>
        </div>
        <div id="historyContent" class="space-y-3 text-sm"></div>
    </div>
</div>

<script>
const CUSTOMERS = <?= json_encode(array_values($customers), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const PRODUCTS  = <?= json_encode(array_values($products_for_invoice), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

<?php if ($editMode && $inv):
    // Load the actual customer record so we have the real customer ID
    $_custRow = null;
    if (!empty($inv['customer_id'])) {
        $s = $pdo->prepare("SELECT id, customer_name, tin, reg_no, currency, email, phone FROM customers WHERE id=?");
        $s->execute([$inv['customer_id']]);
        $_custRow = $s->fetch();
    }
    // Fallback: look up by name if no customer_id stored
    if (!$_custRow && !empty($inv['customer_name'])) {
        $s = $pdo->prepare("SELECT id, customer_name, tin, reg_no, currency, email, phone FROM customers WHERE customer_name=? LIMIT 1");
        $s->execute([$inv['customer_name']]);
        $_custRow = $s->fetch();
    }

    // Load default billing/shipping person and address for this customer
    $_defBillingPerson = ''; $_defShippingPerson = '';
    $_defBillingAddr   = null; $_defShippingAddr = null;
    if ($_custRow) {
        $cid = (int)$_custRow['id'];
        $ps = $pdo->prepare("SELECT first_name, last_name, default_billing, default_shipping FROM customer_contact_persons WHERE customer_id=?");
        $ps->execute([$cid]);
        foreach ($ps->fetchAll() as $_p) {
            $name = strtoupper(trim($_p['first_name'].' '.$_p['last_name']));
            if ($_p['default_billing']  && !$_defBillingPerson)  $_defBillingPerson  = $name;
            if ($_p['default_shipping'] && !$_defShippingPerson) $_defShippingPerson = $name;
        }
        $as = $pdo->prepare("SELECT street_address, city, postcode, state, country, default_billing, default_shipping FROM customer_contact_addresses WHERE customer_id=?");
        $as->execute([$cid]);
        foreach ($as->fetchAll() as $_a) {
            if ($_a['default_billing']  && !$_defBillingAddr)  $_defBillingAddr  = $_a;
            if ($_a['default_shipping'] && !$_defShippingAddr) $_defShippingAddr = $_a;
        }
    }
?>
// Pre-populate _qaLastCustomer for edit mode so edit icon works on first load
window._qaLastCustomer = <?php if ($_custRow): ?>{
    id:                       <?= (int)$_custRow['id'] ?>,
    customer_name:            <?= json_encode($_custRow['customer_name'],   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    tin:                      <?= json_encode($_custRow['tin']    ?? '',    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    reg_no:                   <?= json_encode($_custRow['reg_no'] ?? '',    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    currency:                 <?= json_encode($_custRow['currency'] ?? 'MYR', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    email:                    <?= json_encode($_custRow['email']  ?? '',    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    phone:                    <?= json_encode($_custRow['phone']  ?? '',    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    address_line_0: '', address_line_1: '', city: '', postal_code: '',
    default_billing_person:   <?= json_encode($_defBillingPerson,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    default_shipping_person:  <?= json_encode($_defShippingPerson, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    default_billing_address:  <?= $_defBillingAddr  ? json_encode($_defBillingAddr,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) : 'null' ?>,
    default_shipping_address: <?= $_defShippingAddr ? json_encode($_defShippingAddr, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) : 'null' ?>,
}<?php else: ?>null<?php endif; ?>;
<?php endif; ?>
// ── LHDN Classification codes ────────────────────────────────────
var LHDN_CODES = [
    {v:'',    l:'— No classification —'},
    {v:'001', l:'001 - Breastfeeding equipment'},
    {v:'002', l:'002 - Child care centres and kindergartens fees'},
    {v:'003', l:'003 - Computer, smartphone or tablet'},
    {v:'004', l:'004 - Consolidated e-Invoice'},
    {v:'005', l:'005 - Construction materials'},
    {v:'006', l:'006 - Disbursement'},
    {v:'007', l:'007 - Donation'},
    {v:'008', l:'008 - e-Commerce - e-Invoice to buyer/purchaser'},
    {v:'009', l:'009 - e-Commerce - Self-billed e-Invoice'},
    {v:'010', l:'010 - Education fees'},
    {v:'011', l:'011 - Goods on consignment (Consignor)'},
    {v:'012', l:'012 - Goods on consignment (Consignee)'},
    {v:'013', l:'013 - Gym membership'},
    {v:'014', l:'014 - Insurance - Education and medical benefits'},
    {v:'015', l:'015 - Insurance - Takaful or life insurance'},
    {v:'016', l:'016 - Interest and financing expenses'},
    {v:'017', l:'017 - Internet subscription'},
    {v:'018', l:'018 - Land and building'},
    {v:'019', l:'019 - Medical examination for learning disabilities'},
    {v:'020', l:'020 - Medical examination or vaccination expenses'},
    {v:'021', l:'021 - Medical expenses for serious diseases'},
    {v:'022', l:'022 - Others'},
    {v:'023', l:'023 - Petroleum operations'},
    {v:'024', l:'024 - Private retirement scheme'},
    {v:'025', l:'025 - Motor vehicle'},
    {v:'026', l:'026 - Subscription of books/journals/magazines'},
    {v:'027', l:'027 - Reimbursement'},
    {v:'028', l:'028 - Rental of motor vehicle'},
    {v:'029', l:'029 - EV charging facilities'},
    {v:'030', l:'030 - Repair and maintenance'},
    {v:'031', l:'031 - Research and development'},
    {v:'032', l:'032 - Foreign income'},
    {v:'033', l:'033 - Self-billed - Betting and gaming'},
    {v:'034', l:'034 - Self-billed - Importation of goods'},
    {v:'035', l:'035 - Self-billed - Importation of services'},
    {v:'036', l:'036 - Self-billed - Others'},
    {v:'037', l:'037 - Self-billed - Monetary payment to agents'},
    {v:'038', l:'038 - Sports equipment and facilities'},
    {v:'039', l:'039 - Supporting equipment for disabled person'},
    {v:'040', l:'040 - Voluntary contribution to approved provident fund'},
    {v:'041', l:'041 - Dental examination or treatment'},
    {v:'042', l:'042 - Fertility treatment'},
    {v:'043', l:'043 - Treatment and home care nursing'},
    {v:'044', l:'044 - Vouchers, gift cards, loyalty points'},
    {v:'045', l:'045 - Self-billed - Non-monetary payment to agents'}
];

let taxMode    = 'exclusive';
let rowIndex   = <?= count($initItems) ?>;
let roundingOn = false;

// ── Dropdown position helper (fixes panels to trigger on scroll/resize) ──
function ddPos(trigger, panel) {
    var r          = trigger.getBoundingClientRect();
    var panelH     = panel.offsetHeight || 220; // estimated height if not yet visible
    var footerH    = 56; // sticky footer height
    var viewBottom = window.innerHeight - footerH;
    var fitsBelow  = (r.bottom + 4 + panelH) <= viewBottom;
    if (fitsBelow) {
        panel.style.top = (r.bottom + 4) + 'px';
    } else {
        // Flip above the trigger
        panel.style.top = Math.max(4, r.top - 4 - panelH) + 'px';
    }
    panel.style.left  = r.left + 'px';
    panel.style.width = r.width + 'px';
}
// Re-position any open fixed dropdown panels on scroll or resize
(function() {
    function reposAll() {
        document.querySelectorAll('[data-dd-panel]').forEach(function(panel) {
            if (panel.style.display !== 'none') {
                var trigger = panel.parentElement;
                if (trigger) ddPos(trigger, panel);
            }
        });
    }
    window.addEventListener('scroll', reposAll, true);  // capture phase catches all scroll containers
    window.addEventListener('resize', reposAll);
})();

// ── Date picker (3-level drill-down: Day → Month → Year decade) ──
(function() {
    const MONTHS_LONG  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const DAYS         = ['Su','Mo','Tu','We','Th','Fr','Sa'];

    // 'day' | 'month' | 'year'
    var view     = 'day';
    var decadeStart = 0; // first year shown in decade grid

    function parseDMY(s) {
        if (!s) return new Date();
        var p = s.split('/');
        return p.length === 3 ? new Date(+p[2], +p[1]-1, +p[0]) : new Date();
    }
    function toDMY(d) { return pad(d.getDate())+'/'+pad(d.getMonth()+1)+'/'+d.getFullYear(); }
    function toISO(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
    function pad(n)   { return String(n).padStart(2,'0'); }

    var input   = document.getElementById('invoiceDate');
    var current = parseDMY(input.value);
    var viewing = new Date(current.getFullYear(), current.getMonth(), 1);
    input.dataset.isoVal = toISO(current);

    var popup = document.createElement('div');
    popup.className = 'dp-popup';
    document.body.appendChild(popup);

    function pos() {
        var r = input.getBoundingClientRect();
        popup.style.top  = (r.bottom + 6) + 'px';
        popup.style.left = r.left + 'px';
    }

    // ── Chevron SVG helpers ──
    var chevL = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>';
    var chevR = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>';
    var dblL  = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/></svg>';
    var dblR  = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M13 17l5-5-5-5"/><path d="M6 17l5-5-5-5"/></svg>';

    // ── Render day grid ──
    function renderDay() {
        var today = new Date();
        var y = viewing.getFullYear(), mo = viewing.getMonth();
        var first = new Date(y, mo, 1).getDay();
        var dim   = new Date(y, mo+1, 0).getDate();
        var prev  = new Date(y, mo, 0).getDate();

        var cells = '';
        for (var i = first-1; i >= 0; i--)
            cells += '<button type="button" class="dp-day dp-other">'+(prev-i)+'</button>';
        for (var d = 1; d <= dim; d++) {
            var isT = (d===today.getDate() && mo===today.getMonth() && y===today.getFullYear());
            var isS = (d===current.getDate() && mo===current.getMonth() && y===current.getFullYear());
            var cls = 'dp-day'+(isT?' dp-today':'')+(isS?' dp-sel':'');
            cells += '<button type="button" class="'+cls+'" onclick="window.__dpPick('+d+')">'+d+'</button>';
        }
        var rem = (first + dim) % 7;
        if (rem > 0) for (var d2 = 1; d2 <= 7-rem; d2++)
            cells += '<button type="button" class="dp-day dp-other">'+d2+'</button>';

        popup.innerHTML =
            '<div class="dp-head">'+
                '<button type="button" class="dp-nav-btn" onclick="window.__dpNav(-1)">'+chevL+'</button>'+
                '<button type="button" class="dp-title-btn" onclick="window.__dpSetView(\'month\')">'+MONTHS_LONG[mo]+' '+y+'</button>'+
                '<button type="button" class="dp-nav-btn" onclick="window.__dpNav(1)">'+chevR+'</button>'+
            '</div>'+
            '<div class="dp-grid">'+
                DAYS.map(function(d){return '<div class="dp-dow">'+d+'</div>';}).join('')+
                cells+
            '</div>'+
            '<div class="dp-footer"><button type="button" class="dp-today-btn" onclick="window.__dpToday()">Today</button></div>';
    }

    // ── Render month grid ──
    function renderMonth() {
        var y  = viewing.getFullYear();
        var cm = viewing.getMonth();
        var todayM = (new Date()).getMonth(), todayY = (new Date()).getFullYear();

        var cells = MONTHS_SHORT.map(function(m, i) {
            var isSel = (i === cm);
            var isCur = (i === todayM && y === todayY);
            var cls = 'dp-mon-cell'+(isSel?' dp-mon-sel':'')+((!isSel && isCur)?' dp-mon-cur':'');
            return '<button type="button" class="'+cls+'" onclick="window.__dpPickMonth('+i+')">'+m+'</button>';
        }).join('');

        popup.innerHTML =
            '<div class="dp-head">'+
                '<button type="button" class="dp-nav-btn" onclick="window.__dpYearStep(-1)">'+chevL+'</button>'+
                '<button type="button" class="dp-title-btn" onclick="window.__dpSetView(\'year\')">'+y+'</button>'+
                '<button type="button" class="dp-nav-btn" onclick="window.__dpYearStep(1)">'+chevR+'</button>'+
            '</div>'+
            '<div class="dp-month-grid">'+cells+'</div>'+
            '<div class="dp-footer"><button type="button" class="dp-today-btn" onclick="window.__dpToday()">Today</button></div>';
    }

    // ── Render year decade grid ──
    function renderYear() {
        var y = viewing.getFullYear();
        // Align decade: e.g. 2000-2009
        if (!decadeStart) decadeStart = Math.floor(y / 10) * 10;
        var todayY = (new Date()).getFullYear();

        var cells = '';
        // Show decade range: decadeStart-1 (greyed) … decadeStart+10 (greyed)
        for (var yr = decadeStart - 1; yr <= decadeStart + 10; yr++) {
            var isOut = (yr < decadeStart || yr > decadeStart + 9);
            var isSel = (yr === y);
            var isCur = (yr === todayY && !isSel);
            var cls = 'dp-yr-cell'+(isSel?' dp-yr-sel':'')+(isCur?' dp-yr-cur':'')+(isOut?' dp-yr-out':'');
            var handler = isOut ? '' : ' onclick="window.__dpPickYear('+yr+')"';
            cells += '<button type="button" class="'+cls+'"'+handler+'>'+yr+'</button>';
        }

        var label = decadeStart+'-'+(decadeStart+9);
        popup.innerHTML =
            '<div class="dp-head">'+
                '<button type="button" class="dp-nav-btn" onclick="window.__dpDecade(-1)">'+dblL+'</button>'+
                '<button type="button" class="dp-title-btn" style="cursor:default">'+label+'</button>'+
                '<button type="button" class="dp-nav-btn" onclick="window.__dpDecade(1)">'+dblR+'</button>'+
            '</div>'+
            '<div class="dp-year-grid">'+cells+'</div>'+
            '<div class="dp-footer"><button type="button" class="dp-today-btn" onclick="window.__dpToday()">Today</button></div>';
    }

    function render() {
        if (view === 'day')   renderDay();
        else if (view === 'month') renderMonth();
        else renderYear();
    }

    // ── Public handlers ──
    window.__dpSetView = function(v) { view = v; if (v==='year') decadeStart = Math.floor(viewing.getFullYear()/10)*10; render(); };
    window.__dpNav = function(dir) { viewing.setMonth(viewing.getMonth()+dir); render(); };
    window.__dpYearStep = function(dir) { viewing.setFullYear(viewing.getFullYear()+dir); render(); };
    window.__dpDecade = function(dir) { decadeStart += dir*10; render(); };
    window.__dpPick = function(d) {
        current = new Date(viewing.getFullYear(), viewing.getMonth(), d);
        input.value = toDMY(current);
        input.dataset.isoVal = toISO(current);
        popup.classList.remove('is-open');
        view = 'day';
    };
    window.__dpPickMonth = function(m) {
        viewing.setMonth(m);
        view = 'day';
        render();
    };
    window.__dpPickYear = function(y) {
        viewing.setFullYear(y);
        view = 'month';
        render();
    };
    window.__dpToday = function() {
        current = new Date();
        viewing = new Date(current.getFullYear(), current.getMonth(), 1);
        input.value = toDMY(current);
        input.dataset.isoVal = toISO(current);
        popup.classList.remove('is-open');
        view = 'day';
    };

    // Stop clicks inside popup from bubbling to document
    popup.addEventListener('click', function(e) { e.stopPropagation(); });

    input.addEventListener('click', function(e) {
        e.stopPropagation();
        if (popup.classList.contains('is-open')) {
            popup.classList.remove('is-open');
            view = 'day';
        } else {
            view = 'day';
            pos(); render(); popup.classList.add('is-open');
        }
    });
    document.addEventListener('click', function() {
        if (popup.classList.contains('is-open')) {
            popup.classList.remove('is-open');
            view = 'day';
        }
    });
    var mainEl = document.querySelector('main');
    if (mainEl) mainEl.addEventListener('scroll', function() { if (popup.classList.contains('is-open')) pos(); }, {passive:true});
})();

// ── LHDN description lookup ──────────────────────────────────────
var LHDN_DESC = {};
(function(){ var codes = typeof LHDN_CODES !== 'undefined' ? LHDN_CODES : [];
  codes.forEach(function(o){ if(o.v) LHDN_DESC[o.v] = o.l.replace(/^\d{3} - /,''); }); })();

// ── Discount formatter (called onblur) ────────────────────────────
// "20.00%" → pct mode, "20.00" → fixed mode. Always 2dp.
function formatDisc(input) {
    var raw   = input.value.trim();
    var isPct = raw.endsWith('%');
    var num   = parseFloat(raw.replace('%','')) || 0;
    input.value = isPct ? num.toFixed(2)+'%' : num.toFixed(2);
    var row = input.closest('tr');
    if (!row) return;
    var dEl = row.querySelector('.item-disc');
    var mEl = row.querySelector('.item-disc-mode');
    if (dEl) dEl.value = num;
    if (mEl) mEl.value = isPct ? 'pct' : 'fixed';
}

// ── Tax mode ──────────────────────────────────────────────────────
function setTaxMode(mode) {
    taxMode = mode;
    document.getElementById('taxMode').value = mode;
    document.getElementById('btn_exclusive').className = 'px-3 py-1.5 transition-colors ' + (mode==='exclusive' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50');
    document.getElementById('btn_inclusive').className = 'px-3 py-1.5 transition-colors ' + (mode==='inclusive' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50');
    // Recalculate every row's Amount display when mode changes
    document.querySelectorAll('.item-row').forEach(function(row) { calcRow(row); });
}

// ── Rounding ──────────────────────────────────────────────────────
function toggleRounding() {
    roundingOn = !roundingOn;
    document.getElementById('roundTrack').className = 'relative w-8 h-4 rounded-full transition-colors focus:outline-none shrink-0 ' + (roundingOn ? 'bg-indigo-500' : 'bg-slate-200');
    document.getElementById('roundThumb').className = 'absolute top-0.5 left-0.5 w-3 h-3 bg-white rounded-full shadow transition-transform ' + (roundingOn ? 'translate-x-4' : '');
    updateTotals();
}

// ── Row calculation ──────────────────────────────────────────────
// Tax Exclusive: Amount = qty*price - discount (pre-tax). Tax is added on top in totals.
// Tax Inclusive: Amount = back-calculated pre-tax amount (gross / (1+rate)).
function calcRow(row) {
    var qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
    var price = parseFloat(row.querySelector('.item-price').value) || 0;
    var disc  = parseFloat(row.querySelector('.item-disc').value)  || 0;
    var dMode = row.querySelector('.item-disc-mode') ? row.querySelector('.item-disc-mode').value : 'pct';
    var ttype = row.querySelector('.item-tax').value;
    var trate = TAX_RATES[ttype] || 0;
    var gross   = qty * price;
    var discAmt = dMode === 'fixed' ? disc : gross * (disc / 100);
    var base    = gross - discAmt; // after discount
    var shown;
    if (taxMode === 'inclusive') {
        // Unit price includes tax — show back-calculated pre-tax amount
        shown = trate > 0 ? base / (1 + trate) : base;
    } else {
        // Exclusive — Amount column shows pre-tax (after discount), tax added separately in totals
        shown = base;
    }
    row.querySelector('.item-total').value = shown.toFixed(2);
    updateTotals();
}

// ── Totals ────────────────────────────────────────────────────────
function updateTotals() {
    var subtotal = 0, totalTax = 0, totalDisc = 0;
    document.querySelectorAll('.item-row').forEach(function(row) {
        var qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
        var price = parseFloat(row.querySelector('.item-price').value) || 0;
        var disc  = parseFloat(row.querySelector('.item-disc').value)  || 0;
        var dMode = row.querySelector('.item-disc-mode') ? row.querySelector('.item-disc-mode').value : 'pct';
        var ttype = row.querySelector('.item-tax').value;
        var trate = TAX_RATES[ttype] || 0;
        var gross   = qty * price;
        var discAmt = dMode === 'fixed' ? disc : gross * (disc / 100);
        var base    = gross - discAmt; // pre-tax (exclusive) OR inclusive amount
        var preTax, tax;
        if (taxMode === 'inclusive') {
            preTax = trate > 0 ? base / (1 + trate) : base;
            tax    = base - preTax;
        } else {
            preTax = base;
            tax    = base * trate;
        }
        subtotal  += preTax;
        totalTax  += tax;
        totalDisc += discAmt;
    });
    var total    = subtotal + totalTax;
    var rounding = 0;
    if (roundingOn) {
        // Malaysian rounding to nearest 5 sen
        // cent digit: 0,5→keep; 1,2→down to 0; 3,4→up to 5; 6,7→down to 5; 8,9→up to 10
        var cents     = Math.round(total * 100); // work in integer cents
        var lastCent  = cents % 10;
        var roundedC;
        if (lastCent === 0 || lastCent === 5) {
            roundedC = cents;
        } else if (lastCent <= 2) {
            roundedC = cents - lastCent;           // round down to x0
        } else if (lastCent <= 4) {
            roundedC = cents - lastCent + 5;       // round up to x5
        } else if (lastCent <= 7) {
            roundedC = cents - lastCent + 5;       // round down to x5
        } else {
            roundedC = cents - lastCent + 10;      // round up to x0
        }
        var rounded = roundedC / 100;
        rounding = parseFloat((rounded - total).toFixed(2));
        total    = rounded;
    }
    document.getElementById('dispSubtotal').textContent = subtotal.toFixed(2);
    document.getElementById('dispDiscount').textContent = totalDisc.toFixed(2);
    document.getElementById('dispTax').textContent      = totalTax.toFixed(2);
    document.getElementById('dispRounding').textContent = rounding.toFixed(2);
    document.getElementById('dispTotal').textContent    = total.toFixed(2);
    document.getElementById('footerTotal').textContent  = total.toFixed(2);
    document.getElementById('hiddenSubtotal').value  = subtotal.toFixed(2);
    document.getElementById('hiddenTaxAmount').value = totalTax.toFixed(2);
    document.getElementById('hiddenDiscount').value  = totalDisc.toFixed(2);
    document.getElementById('hiddenTotal').value     = total.toFixed(2);
    document.getElementById('hiddenRounding').value  = rounding.toFixed(2);
}

// ── Row management ────────────────────────────────────────────────
function renumberRows() {
    document.querySelectorAll('.item-row').forEach(function(r, i) {
        r.querySelector('.row-num').textContent = i + 1;
    });
}
function attachRowListeners(row) {
    var discRaw = row.querySelector('.item-disc-raw');
    if (discRaw) {
        discRaw.addEventListener('input', function() {
            var raw   = discRaw.value.trim();
            var isPct = raw.endsWith('%');
            var num   = parseFloat(raw.replace('%','')) || 0;
            var dEl   = row.querySelector('.item-disc');
            var mEl   = row.querySelector('.item-disc-mode');
            if (dEl) dEl.value = num;
            if (mEl) mEl.value = isPct ? 'pct' : 'fixed';
            calcRow(row);
        });
    }
    row.querySelectorAll('.item-qty,.item-price').forEach(function(el) {
        el.addEventListener('input', function() { calcRow(row); });
    });
    var taxSel = row.querySelector('.item-tax');
    if (taxSel) taxSel.addEventListener('change', function() { calcRow(row); });
}
function removeRow(btn) {
    if (document.querySelectorAll('#itemsBody .item-row').length <= 1) return;
    var mainRow = btn.closest('tr');
    var descRow = mainRow.nextElementSibling;
    if (descRow && descRow.classList.contains('item-desc-row')) {
        descRow.remove();
    }
    mainRow.remove();
    renumberRows();
    updateTotals();
}

function addRow() {
    var tbody = document.getElementById('itemsBody');
    var n = document.querySelectorAll('#itemsBody .item-row').length + 1;
    var tr = document.createElement('tr');
    tr.className = 'item-row hover:bg-slate-50/30 transition-colors';
    tr.innerHTML =
        '<td class="px-3 pt-2 pb-0 text-sm text-slate-700 text-center font-medium row-num" rowspan="2" style="vertical-align:top;padding-top:12px">'+n+'</td>'+

        '<td class="px-3 pt-2 pb-0">'+
                '<div class="relative">'+
                    '<input type="text" name="items['+rowIndex+'][description]" placeholder="Item name" required autocomplete="off" '+
                    'class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-slate-800 focus:outline-none focus:border-indigo-500 transition item-desc-input" '+
                    'onfocus="itemDdOpen(this)" oninput="itemDdFilter(this)" onblur="itemDdBlur(this)" onkeydown="itemDdKey(event,this)">'+
                    '<div class="item-dd-panel fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" style="display:none">'+
                        '<ul class="item-dd-list max-h-52 overflow-y-auto py-1"></ul>'+
                    '</div>'+
                '</div>'+
        '</td>'+

        '<td class="px-2 pt-2 pb-0">'+
            '<input type="number" name="items['+rowIndex+'][quantity]" placeholder="1.00" min="0.01" step="0.01" '+
            'class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition item-qty" '+
            'onblur="if(this.value)this.value=parseFloat(this.value).toFixed(2)">'+
        '</td>'+

        '<td class="px-2 pt-2 pb-0">'+
            '<input type="number" name="items['+rowIndex+'][unit_price]" placeholder="0.00" min="0" step="0.01" '+
            'class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition item-price" '+
            'onblur="if(this.value)this.value=parseFloat(this.value).toFixed(2)">'+
        '</td>'+

        '<td class="px-2 pt-2 pb-0">'+
            '<input type="text" name="items['+rowIndex+'][line_total]" value="" readonly placeholder="0.00" '+
            'class="w-full h-8 border border-slate-100 rounded-lg px-2.5 text-sm text-right font-semibold text-slate-700 bg-slate-50 cursor-default item-total">'+
        '</td>'+

        '<td class="px-2 pt-2 pb-0">'+
            '<input type="text" name="items['+rowIndex+'][discount_raw]" value="" placeholder="0.00 or %" '+
            'class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition item-disc-raw" '+
            'onblur="formatDisc(this)">'+
            '<input type="hidden" name="items['+rowIndex+'][discount_pct]" class="item-disc" value="0">'+
            '<input type="hidden" name="items['+rowIndex+'][discount_mode]" class="item-disc-mode" value="pct">'+
        '</td>'+

        '<td class="px-2 pt-2 pb-0" x-data="{open:false,value:\'\',options:TAX_OPTIONS}">'+
            '<div class=\"relative\">'+
                '<button type=\"button\" @click=\"open=!open\" @keydown.escape=\"open=false\" class=\"w-full h-8 px-2.5 rounded-lg bg-white border border-slate-200 text-left flex items-center justify-between gap-1 text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-300\">'+
                    '<span x-text=\"options.find(o=>o.value===value)?.text||\'—\'\" class=\"text-slate-800 truncate\"></span>'+
                    '<svg class=\"w-4 h-4 text-slate-400 shrink-0 transition-transform\" :class=\"open?\'rotate-180\':\'\'\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" viewBox=\"0 0 24 24\"><path d=\"M19 9l-7 7-7-7\"/></svg>'+
                '</button>'+
                '<div x-show=\"open\" @click.outside=\"open=false\" style=\"display:none\" x-transition class=\"fixed z-50 bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden origin-top\" style=\"min-width:140px\" data-dd-panel x-init=\"$watch(\'open\',function(v){if(v){ddPos($el.previousElementSibling,$el);}})\">'+
                    '<ul class=\"max-h-56 overflow-y-auto py-1\">'+
                        '<template x-for=\"o in options\" :key=\"o.value\">'+
                            '<li><button type=\"button\" @click=\"value=o.value;open=false;$el.closest(\'tr\').querySelector(\'.item-tax\').value=o.value;$el.closest(\'tr\').querySelector(\'.item-tax\').dispatchEvent(new Event(\'change\'))\" class=\"w-full text-left px-3 py-2 text-sm transition-colors\" :class=\"value===o.value?\'bg-indigo-50 text-indigo-700 font-medium\':\'text-slate-700 hover:bg-slate-50\'\"><span x-text=\"o.text\"></span></button></li>'+
                        '</template>'+
                    '</ul>'+
                '</div>'+
                '<select name=\"items['+rowIndex+'][tax_type]\" x-model=\"value\" class=\"absolute opacity-0 pointer-events-none w-0 h-0 top-0 left-0 item-tax\" tabindex=\"-1\" aria-hidden=\"true\">'+
            TAX_OPTIONS.map(function(o){return '<option value=\"'+o.value+'\">'+o.text+'</option>';}).join('')+
            '</select>'+
            '</div>'+
        '</td>'+

        '<td class=\"px-2 py-2 text-center\" rowspan=\"2\" style=\"vertical-align:middle\">'+
            '<button type=\"button\" onclick=\"removeRow(this)\" class=\"w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto\">'+
                '<svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" viewBox=\"0 0 24 24\"><polyline points=\"3 6 5 6 21 6\"/><path d=\"M19 6l-1 14H6L5 6\"/><path d=\"M10 11v6M14 11v6\"/><path d=\"M9 6V4h6v2\"/></svg>'+
            '</button>'+
        '</td>'
    // Desc row (row 2)
    var tr2 = document.createElement('tr');
    tr2.className = 'item-desc-row border-b border-slate-50 transition-colors';
    tr2.innerHTML =
        '<td class="px-3 pb-2 pt-1">'+
            '<input type="text" name="items['+rowIndex+'][item_description]" placeholder="Description (optional)" '+
            'class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-slate-600 focus:outline-none focus:border-indigo-500 transition placeholder-slate-300">'+
        '</td>'+
        '<td class="px-2 pb-2 pt-1" colspan="5">'+
            '<input type=\"hidden\" name=\"items['+rowIndex+'][classification]\" class=\"item-class\" value=\"\">'+
            '<div class=\"relative\" x-data=\"lhdnComp(\'\')\">'+
                '<input type=\"text\" x-ref=\"inp\" '+
                ':value=\"open ? q : displayLabel\" '+
                '@focus=\"onFocus()\" '+
                '@input=\"q=$event.target.value\" '+
                '@blur=\"onBlur()\" '+
                '@keydown.escape=\"open=false;q=\'\'\" '+
                '@keydown.arrow-down.prevent=\"moveDown()\" '+
                '@keydown.arrow-up.prevent=\"moveUp()\" '+
                '@keydown.enter.prevent=\"pickActive()\" '+
                'placeholder=\"LHDN Classification...\" '+
                'autocomplete=\"off\" '+
                'class=\"w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition pr-7\">'+
                '<svg class=\"absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform\" :class=\"open?\'rotate-180\':\'\'\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" viewBox=\"0 0 24 24\"><path d=\"M19 9l-7 7-7-7\"/></svg>'+
                '<div x-show=\"open\" @click.outside=\"open=false\" style=\"display:none\" '+
                'class=\"fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden\" '+
                'data-dd-panel '+
                'x-init=\"$watch(\'open\',function(v){if(v){ddPos($el.parentElement,$el);}})\">'+
                    '<ul class=\"max-h-56 overflow-y-auto py-1\" x-ref=\"list\">'+
                        '<template x-for=\"(o,i) in filteredCodes\" :key=\"o.v\">'+
                            '<li><button type=\"button\" @mousedown.prevent=\"pickItem(o)\" '+
                            'class=\"w-full text-left px-3 py-1.5 text-sm transition-colors\" '+
                            ':class=\"i===activeIdx?\'bg-indigo-50 text-indigo-700 font-medium\':(value===o.v?\'bg-slate-50 text-slate-700 font-medium\':\'text-slate-700 hover:bg-slate-50\')\">'+
                            '<span x-text=\"o.l\"></span></button></li>'+
                        '</template>'+
                    '</ul>'+
                '</div>'+
            '</div>'+
        '</td>';

    tbody.appendChild(tr);
    tbody.appendChild(tr2);
    attachRowListeners(tr);
    rowIndex++;
}
document.querySelectorAll('.item-row').forEach(function(row) {
    attachRowListeners(row);
    calcRow(row); // recalculate on load so Amount is correct from saved values
});

// ── Form submit ───────────────────────────────────────────────────
function submitForm(fallback) {
    var ddSel = document.querySelector('.status-dd select');
    document.getElementById('formStatus').value = (ddSel && ddSel.value) ? ddSel.value : fallback;
    var fp = document.getElementById('invoiceDate');
    if (fp && fp.dataset.isoVal) fp.value = fp.dataset.isoVal;
    document.getElementById('invoiceForm').submit();
}

// ── Item / Product dropdown ──────────────────────────────────────
// Works exactly like the customer dropdown:
// - Focus/click: clears input, shows selected name as placeholder, opens full list
// - Typing: filters list live
// - Select: sets value, fills price + desc + LHDN class, blurs
// - Blur without select: restores selected name as value (free typing not allowed)
var _itemDdActive = null;
var _itemDdIdx    = -1;
var _itemDdTimer  = null;
var _apTargetInput = null;  // the item input that triggered "Add Product"

function itemDdGetPanel(inp) { return inp.parentElement.querySelector('.item-dd-panel'); }
function itemDdGetList(inp)  { return inp.parentElement.querySelector('.item-dd-list'); }

function itemDdPos(inp) {
    var panel = itemDdGetPanel(inp);
    if (!panel) return;
    var r = inp.getBoundingClientRect();
    panel.style.top   = (r.bottom + 2) + 'px';
    panel.style.left  = r.left + 'px';
    panel.style.width = Math.max(r.width, 300) + 'px';
}

function itemDdRender(inp, q) {
    var list  = itemDdGetList(inp);
    var panel = itemDdGetPanel(inp);
    q = (q || '').trim().toLowerCase();
    var filtered = q
        ? PRODUCTS.filter(function(p) {
              return p.name.toLowerCase().includes(q) ||
                     (p.sku && p.sku.toLowerCase().includes(q));
          }).slice(0, 20)
        : PRODUCTS.slice(0, 20);

    var sel = inp._itemSelected;

    // Pinned "Add Product" row at top
    var addRow = '<li class="border-b border-slate-100">'+
        '<button type="button" tabindex="-1" data-add-product '+
        'class="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50 transition-colors">'+
        '<svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>'+
        'Add Product'+
        '</button></li>';

    var resultsHtml = filtered.length
        ? filtered.map(function(p, i) {
            var isActive = sel && sel.id === p.id;
            return '<li data-idx="'+i+'" style="background:'+(isActive?'#eef2ff':'')+'" '+
                'class="flex items-center justify-between px-3 py-2 cursor-pointer transition-colors select-none">'+
                '<div class="min-w-0">'+
                    '<div class="text-sm font-medium text-slate-800 truncate">'+escHtml(p.name)+'</div>'+
                    (p.sku ? '<div class="text-xs text-slate-400">'+escHtml(p.sku)+'</div>' : '')+
                '</div>'+
                (p.sale_price ? '<div class="text-xs text-slate-500 font-mono shrink-0 ml-3">'+parseFloat(p.sale_price).toFixed(2)+'</div>' : '')+
                '</li>';
          }).join('')
        : '<li class="px-3 py-2 text-sm text-slate-400 italic select-none">No products found</li>';

    list.innerHTML = addRow + resultsHtml;

    // "Add Product" button click — open panel, remember which input to fill
    list.querySelector('[data-add-product]').addEventListener('mousedown', function(e) {
        e.preventDefault();
        _apTargetInput = inp;
        itemDdClose(inp);
        openAddProduct();
    });

    if (filtered.length) {
        list.querySelectorAll('li[data-idx]').forEach(function(li, i) {
            li.addEventListener('mouseover', function() {
                list.querySelectorAll('li[data-idx]').forEach(function(x){ x.style.background=''; });
                li.style.background = '#f8fafc';
                _itemDdIdx = i;
            });
            li.addEventListener('mouseleave', function() {
                li.style.background = (inp._itemSelected && inp._itemSelected.id === filtered[i].id) ? '#eef2ff' : '';
                _itemDdIdx = -1;
            });
            li.addEventListener('mousedown', function(e) {
                e.preventDefault();
                itemDdSelect(inp, filtered[i]);
            });
        });
    }

    _itemDdIdx = -1;
    itemDdPos(inp);
    panel.style.display = 'block';
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function itemDdSelect(inp, product) {
    inp._itemSelected = product;
    // Show selected name as value (not placeholder) once selected
    inp.value = product.name;
    inp.placeholder = 'Item name';

    var mainRow = inp.closest('tr');
    if (mainRow) {
        // Fill unit price
        var priceInp = mainRow.querySelector('.item-price');
        if (priceInp) {
            priceInp.value = product.sale_price ? parseFloat(product.sale_price).toFixed(2) : '';
            calcRow(mainRow);
        }
        // Row 2: description note + LHDN class
        var descRow = mainRow.nextElementSibling;
        if (descRow) {
            var noteInp = descRow.querySelector('[name*="item_description"]');
            if (noteInp) noteInp.value = product.sale_description || '';

            if (product.classification_code) {
                var hiddenClass = descRow.querySelector('.item-class');
                if (hiddenClass) hiddenClass.value = product.classification_code;
                var lhdnWrap = descRow.querySelector('[x-data]');
                if (lhdnWrap && lhdnWrap._x_dataStack && lhdnWrap._x_dataStack[0]) {
                    var d = lhdnWrap._x_dataStack[0];
                    d.value = product.classification_code;
                    d.q = ''; d.open = false;
                }
            }
        }
    }

    itemDdClose(inp);
    inp.blur();
}

function itemDdOpen(inp) {
    if (_itemDdTimer) { clearTimeout(_itemDdTimer); _itemDdTimer = null; }
    if (_itemDdActive && _itemDdActive !== inp) itemDdClose(_itemDdActive);
    _itemDdActive = inp;
    // Clear text so user can type to filter, show selected name as placeholder
    if (inp._itemSelected) {
        inp.placeholder = inp._itemSelected.name;
        inp.value = '';
    }
    itemDdRender(inp, '');
}

function itemDdFilter(inp) {
    if (_itemDdTimer) { clearTimeout(_itemDdTimer); _itemDdTimer = null; }
    _itemDdActive = inp;
    itemDdRender(inp, inp.value);
}

function itemDdClose(inp) {
    var panel = itemDdGetPanel(inp);
    if (panel) panel.style.display = 'none';
    _itemDdIdx = -1;
    if (inp === _itemDdActive) _itemDdActive = null;
}

function itemDdBlur(inp) {
    _itemDdTimer = setTimeout(function() {
        // Restore: if something selected show its name, else empty
        if (inp._itemSelected) {
            inp.value = inp._itemSelected.name;
        } else {
            inp.value = '';
        }
        inp.placeholder = 'Item name';
        itemDdClose(inp);
    }, 160);
}

function itemDdKey(e, inp) {
    var panel = itemDdGetPanel(inp);
    var isOpen = panel && panel.style.display !== 'none';
    if (!isOpen) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') { e.preventDefault(); itemDdOpen(inp); }
        return;
    }
    var items = panel.querySelectorAll('li[data-idx]');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _itemDdIdx = Math.min(_itemDdIdx + 1, items.length - 1);
        items.forEach(function(li,i){ li.style.background = i===_itemDdIdx?'#eef2ff':''; });
        if (items[_itemDdIdx]) items[_itemDdIdx].scrollIntoView({ block:'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _itemDdIdx = Math.max(_itemDdIdx - 1, 0);
        items.forEach(function(li,i){ li.style.background = i===_itemDdIdx?'#eef2ff':''; });
        if (items[_itemDdIdx]) items[_itemDdIdx].scrollIntoView({ block:'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (_itemDdIdx >= 0 && items[_itemDdIdx]) items[_itemDdIdx].dispatchEvent(new MouseEvent('mousedown'));
    } else if (e.key === 'Escape') {
        if (inp._itemSelected) inp.value = inp._itemSelected.name;
        inp.placeholder = 'Item name';
        itemDdClose(inp);
    }
}

// Reposition on scroll
(function() {
    var scroller = document.querySelector('main') || window;
    scroller.addEventListener('scroll', function() {
        if (_itemDdActive) itemDdPos(_itemDdActive);
    }, { passive: true });
})();

// ── Customer autocomplete ─────────────────────────────────────────
function customerSearch() {
    return {
        query:       '',
        selected:    false,
        ship:        false,   // shipping toggle — set by x-init from PHP
        activeIndex: -1,
        _filtered:   [],
        _open:       false,
        _blurTimer:  null,   // cancel onBlur timeout when a selection is made

        toggleShip: function() {
            this.ship = !this.ship;
            if (this.ship) {
                var c = window._qaLastCustomer;
                if (c) {
                    var attn = document.getElementById('f_shipping_attention');
                    var addr = document.getElementById('f_shipping_address');
                    if (attn) attn.value = (c.default_shipping_person || '').toUpperCase();
                    if (addr && c.default_shipping_address) addr.value = fmtAddress(c.default_shipping_address);
                }
            } else {
                var attn = document.getElementById('f_shipping_attention');
                var ref  = document.querySelector('[name="shipping_reference"]');
                var addr = document.getElementById('f_shipping_address');
                if (attn) attn.value = '';
                if (ref)  ref.value  = '';
                if (addr) addr.value = '';
            }
        },

        // On focus: open immediately, clear input text so user can type fresh
        onFocus: function() {
            // Cancel any pending blur cleanup
            if (this._blurTimer) { clearTimeout(this._blurTimer); this._blurTimer = null; }
            var input = document.getElementById('customerSearchInput');
            if (this.selected && input && input.value) {
                input.placeholder = input.value;
                input.value       = '';
            }
            this._filtered   = CUSTOMERS.slice(0, 20);
            this.activeIndex = -1;
            this.renderResults(this._filtered);
            this.openDropdown();
        },

        // On blur: restore display value if user didn't pick anything new
        onBlur: function() {
            var self  = this;
            var input = document.getElementById('customerSearchInput');
            this._blurTimer = setTimeout(function() {
                self._blurTimer = null;
                self.closeDropdown();
                if (input && input.value === '' && input.placeholder !== 'Search customer...') {
                    input.value       = input.placeholder;
                    input.placeholder = 'Search customer...';
                    self.query        = input.value;
                }
            }, 160);
        },

        // On input: filter while typing, always show dropdown
        onType: function() {
            var input = document.getElementById('customerSearchInput');
            var q     = input ? input.value.trim() : '';
            this.activeIndex = -1;
            if (q.length === 0) {
                this._filtered = CUSTOMERS.slice(0, 20);
            } else {
                this._filtered = CUSTOMERS.filter(function(c) {
                    return c.customer_name.toLowerCase().includes(q.toLowerCase())
                        || (c.tin    && c.tin.toLowerCase().includes(q.toLowerCase()))
                        || (c.reg_no && c.reg_no.toLowerCase().includes(q.toLowerCase()));
                }).slice(0, 10);
            }
            this.renderResults(this._filtered);
            this.openDropdown();
        },

        // Keyboard navigation
        onKey: function(e) {
            var list = this._filtered;

            if (e.key === 'Escape') {
                e.preventDefault();
                this.closeDropdown();
                var input = document.getElementById('customerSearchInput');
                if (input && input.value === '' && input.placeholder !== 'Search customer...') {
                    input.value       = input.placeholder;
                    input.placeholder = 'Search customer...';
                    this.query        = input.value;
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!this._open) { this.onFocus(); return; }
                this.activeIndex = Math.min(this.activeIndex + 1, list.length - 1);
                this.highlightActive();
                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.activeIndex = Math.max(this.activeIndex - 1, 0);
                this.highlightActive();
                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
                // Pick highlighted row, or first row if nothing highlighted
                var idx = this.activeIndex >= 0 ? this.activeIndex : 0;
                if (list.length > 0 && list[idx]) {
                    this.select(list[idx]);
                    // Move focus to next field
                    var next = document.querySelector('input[name="invoice_no"]');
                    if (next) next.focus();
                }
                return;
            }
        },

        // Highlight active row and scroll into view
        highlightActive: function() {
            var ul = document.getElementById('customerResultsList');
            if (!ul) return;
            ul.querySelectorAll('li[data-idx]').forEach(function(li) {
                if (parseInt(li.getAttribute('data-idx')) === this.activeIndex) {
                    li.classList.add('bg-indigo-50');
                    li.scrollIntoView({ block: 'nearest' });
                } else {
                    li.classList.remove('bg-indigo-50');
                }
            }.bind(this));
        },

        // Render results list
        renderResults: function(list) {
            var ul   = document.getElementById('customerResultsList');
            if (!ul) return;
            var self = this;
            if (list.length === 0) {
                ul.innerHTML = '<li class="px-4 py-3 text-xs text-slate-400">No customers found.</li>';
                return;
            }
            ul.innerHTML = list.map(function(c, i) {
                var sub = c.reg_no ? c.reg_no : (c.tin ? c.tin : 'No Reg / ID No.');
                return '<li data-idx="' + i + '" data-id="' + c.id + '" ' +
                    'class="flex items-center justify-between px-4 py-2.5 cursor-pointer transition-colors">' +
                    '<div>' +
                        '<div class="text-sm font-medium text-slate-800">' + c.customer_name + '</div>' +
                        '<div class="text-xs text-slate-400">' + sub + '</div>' +
                    '</div>' +
                    '<svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>' +
                '</li>';
            }).join('');
            ul.querySelectorAll('li[data-id]').forEach(function(li) {
                li.addEventListener('mouseover', function() {
                    self.activeIndex = parseInt(li.getAttribute('data-idx'));
                    self.highlightActive();
                });
                li.addEventListener('mouseleave', function() {
                    self.activeIndex = -1;
                    li.classList.remove('bg-indigo-50');
                });
                // mousedown fires before blur — use it to select without blur cancelling
                li.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    var id = parseInt(li.getAttribute('data-id'));
                    var c  = CUSTOMERS.find(function(x) { return x.id === id; });
                    if (c) self.select(c);
                });
            });
        },

        // Open dropdown, fixed-positioned under input
        openDropdown: function() {
            var panel = document.getElementById('customerDropdown');
            var input = document.getElementById('customerSearchInput');
            if (!panel || !input) return;
            this._open = true;
            panel.style.display = 'block';
            this.repositionDropdown();
        },

        repositionDropdown: function() {
            var panel = document.getElementById('customerDropdown');
            var input = document.getElementById('customerSearchInput');
            if (!panel || !input) return;
            var r = input.getBoundingClientRect();
            panel.style.top   = (r.bottom + 4) + 'px';
            panel.style.left  = r.left + 'px';
            panel.style.width = r.width + 'px';
        },

        closeDropdown: function() {
            var panel = document.getElementById('customerDropdown');
            if (panel) panel.style.display = 'none';
            this._open       = false;
            this.activeIndex = -1;
        },

        select: function(c) {
            // Cancel any pending blur so it doesn't restore the old placeholder
            if (this._blurTimer) { clearTimeout(this._blurTimer); this._blurTimer = null; }
            var input = document.getElementById('customerSearchInput');
            this.selected = true;
            this.query    = c.customer_name;
            this.closeDropdown();
            if (input) {
                input.value       = c.customer_name;
                input.placeholder = 'Search customer...';
                // Blur so the next click always fires @focus fresh,
                // regardless of whether input was already focused
                input.blur();
            }
            document.getElementById('f_customer_name').value    = c.customer_name;
            document.getElementById('f_customer_tin').value     = c.tin    || '';
            document.getElementById('f_customer_reg_no').value  = c.reg_no || '';
            document.getElementById('f_customer_email').value   = c.email  || '';
            document.getElementById('f_customer_phone').value   = c.phone  || '';
            var addr = [c.address_line_0, c.address_line_1, c.city, c.postal_code].filter(Boolean).join(', ');
            document.getElementById('f_customer_address').value = addr;

            // ── Auto-fill Billing Attention & Billing Address ──
            var billingAttn = document.getElementById('f_billing_attention');
            if (billingAttn) {
                billingAttn.value = (c.default_billing_person || '').toUpperCase();
            }

            var billingAddr = document.getElementById('f_customer_address');
            if (billingAddr && c.default_billing_address) {
                billingAddr.value = fmtAddress(c.default_billing_address);
            }

            // ── Auto-set currency from customer default ──
            if (c.currency) {
                var currEl = document.getElementById('invoiceCurrencyDd');
                if (currEl && currEl._x_dataStack && currEl._x_dataStack[0]) {
                    var curr = INVOICE_CURRENCIES.find(function(x){ return x.code === c.currency; });
                    if (curr) currEl._x_dataStack[0].pick(curr);
                }
            }

            // ── Auto-set payment mode from customer default ──
            if (c.default_payment_mode) {
                setPaymentMode(c.default_payment_mode);
            }

            // ── Auto-set payment term from customer default ──
            _customerPaymentTermId   = c.payment_term_id   || null;
            _customerPaymentTermName = c.payment_term_name || '';
            var ptEl = document.getElementById('invoicePtDd');
            if (ptEl && ptEl._x_dataStack && ptEl._x_dataStack[0]) {
                if (_customerPaymentTermId) {
                    ptEl._x_dataStack[0].select(_customerPaymentTermId, _customerPaymentTermName);
                } else {
                    ptEl._x_dataStack[0].select('', '');
                }
            }

            // ── Update payment received rows based on customer defaults ──
            // Cash Sales: leave payment rows untouched (term is free)
            // Credit Sales: enforce payment term logic
            if (c.default_payment_mode === 'credit') {
                var pmtTbody = document.getElementById('paymentsBody');
                var pmtRows  = pmtTbody ? pmtTbody.querySelectorAll('.payment-row') : [];
                if (pmtRows.length > 0) {
                    if (_customerPaymentTermId && pmtRows.length === 1) {
                        // Credit + default PT + 1 row — update that row's term
                        updateSinglePaymentRowTerm(_customerPaymentTermId, _customerPaymentTermName);
                    } else {
                        // Credit + no default PT, or 2+ rows — delete all rows
                        clearPaymentRows();
                    }
                }
            }

            // Store customer for shipping toggle to use when turned ON
            window._qaLastCustomer = c;

            // If shipping toggle is already on, update shipping fields immediately
            if (this.ship) {
                var shAttn = document.getElementById('f_shipping_attention');
                var shAddr = document.getElementById('f_shipping_address');
                if (shAttn) shAttn.value = (c.default_shipping_person || '').toUpperCase();
                if (shAddr) shAddr.value = c.default_shipping_address ? fmtAddress(c.default_shipping_address) : '';
            }
        },

        clearCustomer: function() {
            var input = document.getElementById('customerSearchInput');
            this.query    = '';
            this.selected = false;
            window._qaLastCustomer = null;
            this.closeDropdown();
            if (input) { input.value = ''; input.placeholder = 'Search customer...'; }

            // Clear all fields that were auto-filled on customer select
            ['f_customer_name','f_customer_tin','f_customer_reg_no',
             'f_customer_email','f_customer_phone','f_customer_address',
             'f_billing_attention','f_shipping_attention','f_shipping_address'].forEach(function(id) {
                var el = document.getElementById(id); if (el) el.value = '';
            });

            // Reset payment term dropdown
            _customerPaymentTermId   = null;
            _customerPaymentTermName = '';
            var ptEl2 = document.getElementById('invoicePtDd');
            if (ptEl2 && ptEl2._x_dataStack && ptEl2._x_dataStack[0]) {
                ptEl2._x_dataStack[0].select('', '');
            }
            // Delete all payment received rows when customer is cleared
            clearPaymentRows();

            // Reset currency dropdown to MYR
            var currEl = document.getElementById('invoiceCurrencyDd');
            if (currEl && currEl._x_dataStack && currEl._x_dataStack[0]) {
                var myr = INVOICE_CURRENCIES.find(function(x){ return x.code === 'MYR'; });
                if (myr) currEl._x_dataStack[0].pick(myr);
            }

            // Focus and reopen dropdown immediately
            if (input) {
                input.focus();
                this._filtered   = CUSTOMERS.slice(0, 20);
                this.activeIndex = -1;
                this.renderResults(this._filtered);
                this.openDropdown();
            }
        }
    };
}

// ── Reposition customer dropdown on scroll ────────────────────────
(function() {
    var scroller = document.querySelector('main') || window;
    scroller.addEventListener('scroll', function() {
        var wrap = document.getElementById('customerWrap');
        if (!wrap) return;
        var comp = wrap.closest('[x-data]');
        if (!comp || !comp._x_dataStack) return;
        var d = comp._x_dataStack[0];
        if (d && d._open && typeof d.repositionDropdown === 'function') {
            d.repositionDropdown();
        }
    }, { passive: true });
})();

// ── Address & shipping auto-fill helpers ─────────────────────────────
function fmtAddress(a) {
    if (!a) return '';
    // Format: street_address, city postcode state, country  (space between city/postcode/state)
    var parts = [];
    if (a.street_address && a.street_address.trim()) parts.push(a.street_address.trim());
    var mid = [
        a.city     && a.city.trim()     ? a.city.trim()     : '',
        a.postcode && a.postcode.trim() ? a.postcode.trim() : '',
        a.state    && a.state.trim()    ? a.state.trim()    : '',
    ].filter(Boolean).join(' ');
    if (mid) parts.push(mid);
    if (a.country && a.country.trim()) parts.push(a.country.trim());
    return parts.join(', ').toUpperCase();
}

// ── Attachments ───────────────────────────────────────────────────
function handleDragOver(e) { e.preventDefault(); document.getElementById('dropZone').classList.add('border-indigo-400','bg-indigo-50/40'); }
function handleDragLeave(e) { document.getElementById('dropZone').classList.remove('border-indigo-400','bg-indigo-50/40'); }
function handleDrop(e) { e.preventDefault(); handleDragLeave(e); handleFiles(e.dataTransfer.files); }
function handleFiles(files) {
    var list = document.getElementById('fileList');
    Array.from(files).forEach(function(file) {
        var ext     = file.name.split('.').pop().toUpperCase();
        var size    = file.size < 1048576 ? Math.round(file.size/1024)+'KB' : (file.size/1048576).toFixed(1)+'MB';
        var objUrl  = URL.createObjectURL(file);
        var canView = ['PDF','JPG','JPEG','PNG'].includes(ext);
        var div = document.createElement('div');
        div.className = 'flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200 group';
        var openLink = canView
            ? '<a href="'+objUrl+'" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline truncate">'+file.name+'</a>'
            : '<div class="text-xs font-medium text-slate-700 truncate">'+file.name+'</div>';
        var openBtn = canView
            ? '<a href="'+objUrl+'" target="_blank" title="Open" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>'
            : '';
        div.innerHTML =
            '<div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0"><span class="text-[9px] font-bold text-indigo-600">'+ext+'</span></div>'+
            '<div class="flex-1 min-w-0">'+openLink+'<div class="text-[10px] text-slate-400">'+size+'</div></div>'+
            openBtn+
            '<button type="button" title="Remove" onclick="this.closest(\'.group\').remove()" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>';
        list.appendChild(div);
    });
}

// ── History modal ─────────────────────────────────────────────────
function showHistory(data) {
    var html = '';
    if (data.old) {
        html += '<p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Before</p>';
        html += '<div class="bg-red-50 rounded-lg p-3 text-xs text-slate-600 space-y-1">';
        Object.entries(data.old).forEach(function(e) { html += '<div><span class="text-slate-400 mr-1">'+e[0]+':</span>'+e[1]+'</div>'; });
        html += '</div>';
    }
    if (data.new) {
        html += '<p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5 mt-3">After</p>';
        html += '<div class="bg-green-50 rounded-lg p-3 text-xs text-slate-600 space-y-1">';
        Object.entries(data.new).forEach(function(e) { html += '<div><span class="text-slate-400 mr-1">'+e[0]+':</span>'+e[1]+'</div>'; });
        html += '</div>';
    }
    document.getElementById('historyContent').innerHTML = html;
    var m = document.getElementById('historyModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeHistoryModal() {
    var m = document.getElementById('historyModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

// ── LHDN Classification combobox ─────────────────────────────────
function lhdnComp(initialCode) {
    var all = [{v:'', l:'— No classification —'}].concat(
        (typeof LHDN_CODES !== 'undefined' ? LHDN_CODES : []).filter(function(o){ return o.v; })
    );
    var def = all.find(function(o){ return o.v === initialCode; }) || all[0];
    return {
        value:     def.v,
        q:         '',
        open:      false,
        activeIdx: -1,
        get displayLabel() {
            if (!this.value) return '';
            var found = all.find(function(o){ return o.v === this.value; }.bind(this));
            return found ? found.l : this.value;
        },
        get filteredCodes() {
            var q = this.q.trim().toLowerCase();
            if (!q) return all;
            return all.filter(function(o) {
                return o.l.toLowerCase().includes(q);
            });
        },
        onFocus: function() {
            this.q         = '';
            this.open      = true;
            this.activeIdx = -1;
        },
        pickItem: function(o) {
            this.value     = o.v;
            this.q         = '';
            this.open      = false;
            this.activeIdx = -1;
            // Sync hidden input
            var td = this.$el.closest('td');
            if (td) { var h = td.querySelector('.item-class'); if (h) h.value = o.v; }
            // Blur so next click fires @focus fresh
            if (this.$refs && this.$refs.inp) this.$refs.inp.blur();
        },
        pickActive: function() {
            if (this.activeIdx < 0) return;
            var list = this.filteredCodes;
            if (list[this.activeIdx]) this.pickItem(list[this.activeIdx]);
        },
        moveDown: function() {
            this.activeIdx = Math.min(this.activeIdx + 1, this.filteredCodes.length - 1);
            this.scrollActive();
        },
        moveUp: function() {
            this.activeIdx = Math.max(this.activeIdx - 1, 0);
            this.scrollActive();
        },
        scrollActive: function() {
            var self = this;
            this.$nextTick(function() {
                var list = self.$refs.list;
                if (!list) return;
                var items = list.querySelectorAll('li');
                if (items[self.activeIdx]) items[self.activeIdx].scrollIntoView({ block: 'nearest' });
            });
        },
        onBlur: function() {
            var self = this;
            setTimeout(function() {
                if (self.open) {
                    self.open      = false;
                    self.q         = '';
                    self.activeIdx = -1;
                }
            }, 200);
        }
    };
}

// ── Currency searchable dropdown ─────────────────────────────────
var INVOICE_CURRENCIES = [
    {code:'MYR',name:'Malaysian Ringgit'},{code:'USD',name:'US Dollar'},
    {code:'EUR',name:'Euro'},{code:'GBP',name:'British Pound'},
    {code:'SGD',name:'Singapore Dollar'},{code:'AUD',name:'Australian Dollar'},
    {code:'CAD',name:'Canadian Dollar'},{code:'JPY',name:'Japanese Yen'},
    {code:'CNY',name:'Chinese Yuan'},{code:'HKD',name:'Hong Kong Dollar'},
    {code:'CHF',name:'Swiss Franc'},{code:'NZD',name:'New Zealand Dollar'},
    {code:'SEK',name:'Swedish Krona'},{code:'NOK',name:'Norwegian Krone'},
    {code:'DKK',name:'Danish Krone'},{code:'KRW',name:'South Korean Won'},
    {code:'INR',name:'Indian Rupee'},{code:'IDR',name:'Indonesian Rupiah'},
    {code:'THB',name:'Thai Baht'},{code:'PHP',name:'Philippine Peso'},
    {code:'VND',name:'Vietnamese Dong'},{code:'BDT',name:'Bangladeshi Taka'},
    {code:'PKR',name:'Pakistani Rupee'},{code:'LKR',name:'Sri Lankan Rupee'},
    {code:'MMK',name:'Myanmar Kyat'},{code:'KHR',name:'Cambodian Riel'},
    {code:'BND',name:'Brunei Dollar'},{code:'TWD',name:'Taiwan Dollar'},
    {code:'AED',name:'UAE Dirham'},{code:'SAR',name:'Saudi Riyal'},
    {code:'QAR',name:'Qatari Riyal'},{code:'KWD',name:'Kuwaiti Dinar'},
    {code:'BHD',name:'Bahraini Dinar'},{code:'OMR',name:'Omani Rial'},
    {code:'JOD',name:'Jordanian Dinar'},{code:'EGP',name:'Egyptian Pound'},
    {code:'ZAR',name:'South African Rand'},{code:'NGN',name:'Nigerian Naira'},
    {code:'GHS',name:'Ghanaian Cedi'},{code:'KES',name:'Kenyan Shilling'},
    {code:'TZS',name:'Tanzanian Shilling'},{code:'UGX',name:'Ugandan Shilling'},
    {code:'ETB',name:'Ethiopian Birr'},{code:'XOF',name:'West African CFA Franc'},
    {code:'XAF',name:'Central African CFA Franc'},{code:'MAD',name:'Moroccan Dirham'},
    {code:'TND',name:'Tunisian Dinar'},{code:'BRL',name:'Brazilian Real'},
    {code:'MXN',name:'Mexican Peso'},{code:'ARS',name:'Argentine Peso'},
    {code:'CLP',name:'Chilean Peso'},{code:'COP',name:'Colombian Peso'},
    {code:'PEN',name:'Peruvian Sol'},{code:'UYU',name:'Uruguayan Peso'},
    {code:'BOB',name:'Bolivian Boliviano'},{code:'PYG',name:'Paraguayan Guaraní'},
    {code:'RUB',name:'Russian Ruble'},{code:'UAH',name:'Ukrainian Hryvnia'},
    {code:'PLN',name:'Polish Złoty'},{code:'CZK',name:'Czech Koruna'},
    {code:'HUF',name:'Hungarian Forint'},{code:'RON',name:'Romanian Leu'},
    {code:'BGN',name:'Bulgarian Lev'},{code:'HRK',name:'Croatian Kuna'},
    {code:'RSD',name:'Serbian Dinar'},{code:'TRY',name:'Turkish Lira'},
    {code:'ILS',name:'Israeli New Shekel'},{code:'IRR',name:'Iranian Rial'},
    {code:'IQD',name:'Iraqi Dinar'},{code:'LBP',name:'Lebanese Pound'},
    {code:'SYP',name:'Syrian Pound'},{code:'YER',name:'Yemeni Rial'},
    {code:'AFN',name:'Afghan Afghani'},{code:'NPR',name:'Nepalese Rupee'},
    {code:'MNT',name:'Mongolian Tögrög'},{code:'KZT',name:'Kazakhstani Tenge'},
    {code:'UZS',name:'Uzbekistani Soʻm'},{code:'GEL',name:'Georgian Lari'},
    {code:'AMD',name:'Armenian Dram'},{code:'AZN',name:'Azerbaijani Manat'},
    {code:'BYN',name:'Belarusian Ruble'},{code:'MDL',name:'Moldovan Leu'},
    {code:'MKD',name:'Macedonian Denar'},{code:'ALL',name:'Albanian Lek'},
    {code:'BAM',name:'Bosnia-Herzegovina Convertible Mark'},
    {code:'ISK',name:'Icelandic Króna'},{code:'XCD',name:'East Caribbean Dollar'},
    {code:'JMD',name:'Jamaican Dollar'},{code:'TTD',name:'Trinidad & Tobago Dollar'},
    {code:'BBD',name:'Barbadian Dollar'},{code:'BSD',name:'Bahamian Dollar'},
    {code:'HTG',name:'Haitian Gourde'},{code:'CUP',name:'Cuban Peso'},
    {code:'DOP',name:'Dominican Peso'},{code:'GTQ',name:'Guatemalan Quetzal'},
    {code:'HNL',name:'Honduran Lempira'},{code:'NIO',name:'Nicaraguan Córdoba'},
    {code:'CRC',name:'Costa Rican Colón'},{code:'PAB',name:'Panamanian Balboa'},
    {code:'PGK',name:'Papua New Guinean Kina'},{code:'FJD',name:'Fijian Dollar'},
    {code:'SBD',name:'Solomon Islands Dollar'},{code:'VUV',name:'Vanuatu Vatu'},
    {code:'WST',name:'Samoan Tālā'},{code:'TOP',name:'Tongan Paʻanga'},
];

function invoiceCurrencyComp(initialCode) {
    var sorted = INVOICE_CURRENCIES.slice().sort(function(a, b) {
        return a.name.localeCompare(b.name);
    });
    // Keep MYR first
    sorted = sorted.filter(function(c){ return c.code !== 'MYR'; });
    sorted.unshift(INVOICE_CURRENCIES.find(function(c){ return c.code === 'MYR'; }));

    var def = sorted.find(function(c){ return c.code === initialCode; }) || sorted[0];
    return {
        q:         '',
        open:      false,
        activeIdx: -1,
        selected:  { code: def.code, label: def.code + ' — ' + def.name },
        currencies: sorted,
        get filtered() {
            var q = this.q.trim().toLowerCase();
            if (!q) return this.currencies;
            return this.currencies.filter(function(c) {
                return c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q);
            });
        },
        onFocus: function() {
            this.q         = '';        // clear so full list shows
            this.open      = true;
            this.activeIdx = -1;
            var self = this;
            this.$nextTick(function() {
                var el = document.getElementById('invoiceCurrencyInput');
                if (el) el.select();
            });
        },
        pick: function(c) {
            this.selected  = { code: c.code, label: c.code + ' \u2014 ' + c.name };
            this.q         = '';
            this.open      = false;
            this.activeIdx = -1;
            // Blur so next click fires @focus fresh
            var inp = document.getElementById('invoiceCurrencyInput');
            if (inp) inp.blur();
        },
        pickActive: function() {
            // Only pick if user explicitly moved to a row with arrow keys
            if (this.activeIdx < 0) return;
            var list = this.filtered;
            if (list[this.activeIdx]) this.pick(list[this.activeIdx]);
        },
        moveDown: function() {
            this.activeIdx = Math.min(this.activeIdx + 1, this.filtered.length - 1);
            this.scrollActive();
        },
        moveUp: function() {
            this.activeIdx = Math.max(this.activeIdx - 1, 0);
            this.scrollActive();
        },
        scrollActive: function() {
            var self = this;
            this.$nextTick(function() {
                var list = self.$refs.list;
                if (!list) return;
                var active = list.querySelectorAll('li')[self.activeIdx];
                if (active) active.scrollIntoView({ block: 'nearest' });
            });
        },
        onBlur: function() {
            var self = this;
            setTimeout(function() {
                if (self.open) {
                    self.open      = false;
                    self.q         = '';
                    self.activeIdx = -1;
                }
            }, 200);
        }
    };
}

// ── Invoice Payment Term dropdown component ──────────────────────────
function invoicePtComp() {
    return {
        open:        false,
        selectedId:  '',
        selectedName:'',
        get list() { return INVOICE_PT[_invoicePaymentMode] || []; },
        init() {
            <?php if ($editMode && $inv && !empty($inv['payment_term_id'])): ?>
            var savedId = <?= (int)($inv['payment_term_id'] ?? 0) ?>;
            var all = (INVOICE_PT.cash||[]).concat(INVOICE_PT.credit||[]);
            var found = all.find(function(pt){ return pt.id === savedId; });
            if (found) { this.selectedId = savedId; this.selectedName = found.name; }
            <?php endif; ?>
        },
        onModeChange: function(newMode) {
            var self = this;
            var list = INVOICE_PT[newMode] || [];
            var stillValid = list.find(function(pt){ return String(pt.id)===String(self.selectedId); });
            if (!stillValid) { this.selectedId = ''; this.selectedName = ''; }
        },
        select: function(id, name) {
            this.selectedId   = id;
            this.selectedName = name;
            this.open         = false;
        }
    };
}

// ── Invoice number dropdown ───────────────────────────────────────
var _invoiceNoDdOpen = false;
var _invoiceNoDdActiveId = <?= $defaultFormatId ?: 0 ?>;

function invoiceNoDdToggle() {
    var panel   = document.getElementById('invoiceNoDdPanel');
    var chevron = document.getElementById('invoiceNoChevron');
    if (!panel) return;
    _invoiceNoDdOpen = !_invoiceNoDdOpen;
    panel.style.display = _invoiceNoDdOpen ? 'block' : 'none';
    if (chevron) chevron.style.transform = _invoiceNoDdOpen ? 'rotate(180deg)' : '';
}

function invoiceNoDdSelect(formatId, formatStr) {
    _invoiceNoDdActiveId = formatId;
    // Highlight selected option
    document.querySelectorAll('[id^="invoiceNoOpt_"]').forEach(function(btn) {
        btn.className = 'w-full text-left px-4 py-2.5 text-sm font-mono transition-colors text-slate-800 hover:bg-slate-50';
    });
    var active = document.getElementById('invoiceNoOpt_' + formatId);
    if (active) active.className = 'w-full text-left px-4 py-2.5 text-sm font-mono transition-colors bg-indigo-50 text-indigo-700 font-semibold';

    // Update display to loading
    var display = document.getElementById('invoiceNoDisplay');
    var hidden  = document.getElementById('invoiceNoValue');
    var fmtId   = document.getElementById('invoiceFormatId');
    if (display) display.textContent = 'Loading\u2026';
    if (fmtId)   fmtId.value = formatId;

    // Close dropdown
    invoiceNoDdToggle();

    // Fetch next number
    fetch('number_format_next.php?format_id=' + formatId)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                if (display) display.textContent = d.number;
                if (hidden)  hidden.value        = d.number;
            }
        });
}

// Close on outside click
document.addEventListener('click', function(e) {
    if (!_invoiceNoDdOpen) return;
    var dd = document.getElementById('invoiceNoDd');
    if (dd && !dd.contains(e.target)) {
        _invoiceNoDdOpen = false;
        var panel   = document.getElementById('invoiceNoDdPanel');
        var chevron = document.getElementById('invoiceNoChevron');
        if (panel)   panel.style.display = 'none';
        if (chevron) chevron.style.transform = '';
    }
});

// ── end invoice number dropdown ──────────────────────────────────

// ── Payment Mode toggle ───────────────────────────────────────────
function setPaymentMode(mode) {
    document.getElementById('invoicePaymentMode').value = mode;
    var cashBtn   = document.getElementById('pmCash');
    var creditBtn = document.getElementById('pmCredit');
    var activeClass   = 'flex-1 flex items-center justify-center gap-1.5 px-4 transition-colors bg-indigo-600 text-white font-medium';
    var inactiveClass = 'flex-1 flex items-center justify-center gap-1.5 px-4 transition-colors bg-white text-slate-500 hover:bg-slate-50';
    if (mode === 'cash') {
        cashBtn.className   = activeClass;
        creditBtn.className = inactiveClass + ' border-l border-slate-200';
    } else {
        cashBtn.className   = inactiveClass;
        creditBtn.className = activeClass + ' border-l border-slate-200';
    }
    // Update payment mode tracker and refresh PT dropdown options
    _invoicePaymentMode = mode;
    var ptEl = document.getElementById('invoicePtDd');
    if (ptEl && ptEl._x_dataStack && ptEl._x_dataStack[0]) {
        ptEl._x_dataStack[0].onModeChange(mode);
    }
    // Notify all payment term dropdowns in the table to refresh their options
    window.dispatchEvent(new CustomEvent('payment-mode-changed', {detail: {mode: mode}}));
    // Delete all payment received rows when mode changes
    clearPaymentRows();
}

updateTotals();

// ── Payment Received ─────────────────────────────────────────────
var pmtRowIndex = <?= count($existingPayments) ?>;

// Remove all payment received rows
function clearPaymentRows() {
    var tbody = document.getElementById('paymentsBody');
    if (!tbody) return;
    tbody.querySelectorAll('.payment-row').forEach(function(r) { r.remove(); });
    renumberPaymentRows();
    updatePaymentTotal();
}

// Update the payment term in the single existing row
function updateSinglePaymentRowTerm(termId, termName) {
    var tbody = document.getElementById('paymentsBody');
    if (!tbody) return;
    var rows = tbody.querySelectorAll('.payment-row');
    if (rows.length !== 1) return;
    var row = rows[0];
    // Update hidden input
    var hidden = row.querySelector('.pmt-term');
    if (hidden) hidden.value = termId ? String(termId) : '';
    // Update Alpine dropdown display
    var ddCell = row.querySelector('[x-data]');
    if (ddCell && ddCell._x_dataStack && ddCell._x_dataStack[0]) {
        ddCell._x_dataStack[0].value = termId ? String(termId) : '';
    }
}

function addPaymentRow() {
    var tbody = document.getElementById('paymentsBody');
    var rows  = tbody.querySelectorAll('.payment-row');
    var n = rows.length + 1;
    var i = pmtRowIndex++;

    // Auto-fill: invoice total minus what's already entered
    var invoiceTotal = parseFloat(document.getElementById('hiddenTotal').value) || 0;
    var paidSoFar = 0;
    rows.forEach(function(r) { paidSoFar += parseFloat(r.querySelector('.pmt-amount').value) || 0; });
    var suggestedAmt = Math.max(0, invoiceTotal - paidSoFar).toFixed(2);

    var tr = document.createElement('tr');
    tr.className = 'payment-row border-b border-slate-50 hover:bg-slate-50/30 transition-colors';
    tr.innerHTML =
        '<td class="px-3 py-2 text-center text-sm text-slate-500 payment-num">'+n+'</td>'+
        '<td class="px-3 py-2" x-data="{open:false,value:\'\',options:getPaymentTerms()}" @payment-mode-changed.window="options=getPaymentTerms(); if(!options.find(function(o){return o.v===value})){value=\'\';$el.querySelector(\'.pmt-term\').value=\'\';}">'+
            '<div class="relative">'+
                '<button type="button" @click="open=!open" @keydown.escape="open=false" '+
                        'class="w-full h-8 px-2.5 rounded-lg bg-white border border-slate-200 text-left flex items-center justify-between gap-1 text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-300">'+
                    '<span x-text="options.find(function(o){return o.v===value}) ? options.find(function(o){return o.v===value}).l : \'Select...\'">'+
                    '</span>'+
                    '<svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?\'rotate-180\':\'\'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>'+
                '</button>'+
                '<div x-show="open" @click.outside="open=false" @mousedown.prevent style="display:none" '+
                     'class="fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" data-dd-panel '+
                     'x-init="$watch(\'open\',function(v){if(v){ddPos($el.previousElementSibling,$el);}})">'+
                    '<ul class="py-1 min-w-[180px]">'+
                        '<template x-for="o in options" :key="o.v">'+
                            '<li><button type="button" @mousedown.prevent="value=o.v;open=false;$el.closest(\'tr\').querySelector(\'.pmt-term\').value=o.v" '+
                                'class="w-full text-left px-3 py-2 text-sm transition-colors" '+
                                ':class="value===o.v?\'bg-indigo-50 text-indigo-700 font-medium\':\'text-slate-700 hover:bg-slate-50\'">'+
                                '<span x-text="o.l"></span></button></li>'+
                        '</template>'+
                    '</ul>'+
                '</div>'+
                '<input type="hidden" name="payments['+i+'][payment_term_id]" class="pmt-term" value="">'+
            '</div>'+
        '</td>'+
        '<td class="px-2 py-2">'+
            '<input type="number" name="payments['+i+'][amount]" value="'+suggestedAmt+'" min="0" step="0.01" placeholder="0.00" '+
            'class="no-spin w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition pmt-amount" '+
            'oninput="updatePaymentTotal()" onblur="this.value=parseFloat(this.value||0).toFixed(2)">'+
        '</td>'+
        '<td class="px-2 py-2">'+
            '<input type="text" name="payments['+i+'][reference_no]" placeholder="Ref. no." '+
            'class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition">'+
        '</td>'+
        '<td class="px-2 py-2">'+
            '<input type="text" name="payments['+i+'][notes]" placeholder="Notes" '+
            'class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition">'+
        '</td>'+
        '<td class="px-2 py-2 text-center">'+
            '<button type="button" onclick="removePaymentRow(this)" '+
                    'class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto">'+
                '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>'+
            '</button>'+
        '</td>';
    tbody.appendChild(tr);
    if (window.Alpine) Alpine.initTree(tr);
    renumberPaymentRows();
    updatePaymentTotal();
    // Auto-select customer's default payment term if available
    if (_customerPaymentTermId) {
        var termEl = tr.querySelector('.pmt-term');
        var termDd = tr.querySelector('[x-data]');
        if (termEl) termEl.value = String(_customerPaymentTermId);
        if (termDd && termDd._x_dataStack && termDd._x_dataStack[0]) {
            termDd._x_dataStack[0].value = String(_customerPaymentTermId);
        }
    }
    var amtInp = tr.querySelector('.pmt-amount');
    if (amtInp) { amtInp.select(); amtInp.focus(); }
}

function removePaymentRow(btn) {
    btn.closest('.payment-row').remove();
    renumberPaymentRows();
    updatePaymentTotal();
}

function renumberPaymentRows() {
    document.querySelectorAll('#paymentsBody .payment-row').forEach(function(r, i) {
        var num = r.querySelector('.payment-num');
        if (num) num.textContent = i + 1;
    });
}

function updatePaymentTotal() {
    var invoiceTotal = parseFloat(document.getElementById('hiddenTotal').value) || 0;
    var inputs = document.querySelectorAll('#paymentsBody .pmt-amount');
    var runningTotal = 0;

    inputs.forEach(function(inp) {
        var val = parseFloat(inp.value) || 0;

        // Clamp: this row cannot be negative
        if (val < 0) { val = 0; inp.value = '0.00'; }

        // Clamp: this row cannot push the running total past invoice total
        var maxAllowed = Math.max(0, invoiceTotal - runningTotal);
        if (val > maxAllowed) {
            val = maxAllowed;
            inp.value = val.toFixed(2);
        }

        runningTotal += val;
    });

    var balance = invoiceTotal - runningTotal;
    document.getElementById('pmtPaidTotal').textContent = runningTotal.toFixed(2);
    var balEl    = document.getElementById('pmtBalance');
    var balLabel = document.getElementById('pmtBalanceLabel');
    if (balance < 0.005) {
        balLabel.className = 'font-semibold ml-1 text-green-600'; // fully paid
        balEl.textContent  = (0).toFixed(2);
    } else {
        balLabel.className = 'font-semibold ml-1 text-slate-800'; // balance remaining
        balEl.textContent  = balance.toFixed(2);
    }
}

updatePaymentTotal();
</script>

<?php endif; // end action switch ?>

<!-- ══════════════════════════════════════════════════════════════
     QUICK ADD CUSTOMER PANEL (slide-in from right)
     ══════════════════════════════════════════════════════════════ -->
<?php if ($action !== 'list'): ?>

<!-- Backdrop -->
<div id="qaBackdrop" onclick="closeQuickAdd()"
     style="opacity:0;pointer-events:none;transition:opacity 0.25s ease"
     class="fixed inset-0 bg-black/40 z-[9998]"></div>

<!-- Slide panel -->
<div id="qaPanel"
     style="transform:translateX(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1)"
     class="fixed top-0 right-0 h-screen w-[820px] bg-white shadow-2xl z-[9999] flex flex-col border-l border-slate-200 invisible"
     x-data="qaComp()">

    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
        <div>
            <h2 id="qaPanelTitle" class="text-base font-semibold text-slate-800">New Customer</h2>
            <p id="qaPanelSub" class="text-xs text-slate-400 mt-0.5">Full profile — same as Customers → New Customer.</p>
        </div>
        <button type="button" onclick="closeQuickAdd()"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl font-light">&times;</button>
    </div>

    <!-- Scrollable body -->
    <div class="flex-1 overflow-y-auto" id="qaPanelBody">

        <!-- Hidden edit ID — 0 means new, >0 means edit -->
        <input type="hidden" id="qa_customer_id" value="0">

        <!-- Error banner -->
        <div id="qaError" style="display:none"
             class="mx-6 mt-4 flex items-center gap-2.5 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            <span id="qaErrorMsg"></span>
        </div>

        <!-- ── Basic Information ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Basic Information</h3>
            <div class="grid grid-cols-2 gap-x-6 gap-y-4">

                <div>
                    <label class="<?= t('label') ?>">Legal Name <span class="text-red-400">*</span></label>
                    <input type="text" id="qa_legalname" autocomplete="new-password"
                           class="<?= t('input') ?> uppercase" placeholder="Legal name">
                </div>
                <div>
                    <label class="<?= t('label') ?>">Other Name</label>
                    <input type="text" id="qa_othername" autocomplete="new-password"
                           class="<?= t('input') ?>" placeholder="Trade name / alias">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Registration No. Type</label>
                    <div id="qa_id_type_dd" class="relative" style="width:100%"
                         x-data="qaIdTypeComp()">
                        <button type="button" @click="open=!open" @keydown.escape="open=false" style="outline:none"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span x-text="(options.find(function(o){return o.v===val})||{l:'Select...'}).l"
                                  :class="val?'text-black':'text-slate-400'"></span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" @click.outside="open=false" style="display:none"
                             x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                             class="fixed z-[10000] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" data-qa-dd
                             x-init="$watch('open',function(v){if(v)qaPos($el.previousElementSibling,$el,true);})">
                            <ul class="py-1">
                                <template x-for="o in options" :key="o.v">
                                    <li>
                                        <button type="button"
                                                @click="val=o.v;open=false;document.getElementById('qa_id_type').value=o.v"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="val===o.v?'bg-indigo-50 text-indigo-700 font-medium':'text-black hover:bg-slate-50'">
                                            <span x-text="o.l"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" id="qa_id_type" value="BRN">
                    </div>
                </div>
                <div></div>

                <div>
                    <label class="<?= t('label') ?>">Registration No.</label>
                    <input type="text" id="qa_reg_no" autocomplete="new-password"
                           class="<?= t('input') ?> uppercase" placeholder="e.g. 202301234567">
                </div>
                <div>
                    <label class="<?= t('label') ?>">Old Registration No.</label>
                    <input type="text" id="qa_old_reg_no" autocomplete="new-password"
                           class="<?= t('input') ?> uppercase" placeholder="Previous reg no.">
                </div>

                <div>
                    <label class="<?= t('label') ?>">TIN</label>
                    <input type="text" id="qa_tin" autocomplete="new-password"
                           class="<?= t('input') ?> uppercase" placeholder="e.g. C20880050040">
                </div>
                <div>
                    <label class="<?= t('label') ?>">SST Registration No.</label>
                    <input type="text" id="qa_sst_reg_no" autocomplete="new-password"
                           class="<?= t('input') ?> uppercase" placeholder="e.g. W10-2402-32000160">
                </div>

            </div>
        </div>

        <!-- ── Contact Persons ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100" x-data="qaPersonsComp()">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Contact Persons</h3>
            </div>
            <template x-for="(p, i) in persons" :key="i">
                <div class="pb-3 mb-3" :class="i>0?'border-t border-slate-100 pt-3':''">
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <label class="<?= t('label') ?>">First Name</label>
                            <input type="text" :name="'qa_contact_persons['+i+'][first_name]'" x-model="p.first_name"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-1.5 cursor-pointer text-xs text-slate-500">
                                <input type="checkbox" :checked="p.default_billing" @change="setDefaultBilling(i)"
                                       class="rounded border-slate-300 text-indigo-600">
                                Default Billing
                            </label>
                        </div>
                        <div class="flex-1">
                            <label class="<?= t('label') ?>">Last Name</label>
                            <input type="text" :name="'qa_contact_persons['+i+'][last_name]'" x-model="p.last_name"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-1.5 cursor-pointer text-xs text-slate-500">
                                <input type="checkbox" :checked="p.default_shipping" @change="setDefaultShipping(i)"
                                       class="rounded border-slate-300 text-indigo-600">
                                Default Shipping
                            </label>
                        </div>
                        <button type="button" @click="removePerson(i)"
                                class="w-9 h-9 flex items-center justify-center rounded-lg bg-red-500 hover:bg-red-600 text-white transition-colors shrink-0 self-start mt-7">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </div>
            </template>
            <div class="flex justify-end">
                <button type="button" @click="addPerson()"
                        class="inline-flex items-center gap-1.5 px-3 h-8 rounded-lg border border-slate-300 text-sm text-black bg-white hover:border-indigo-500 hover:text-indigo-600 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    Contact Person
                </button>
            </div>
        </div>

        <!-- ── Contact Addresses ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100" x-data="qaAddressesComp()">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Contact Addresses</h3>
            </div>
            <template x-for="(a, i) in addresses" :key="i">
                <div class="pb-4 mb-4" :class="i>0?'border-t border-slate-100 pt-4':''">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide" x-text="'Address '+(i+1)"></span>
                        <button type="button" @click="removeAddress(i)"
                                class="w-6 h-6 flex items-center justify-center rounded text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="<?= t('label') ?>">Address Name <span class="text-red-400">*</span></label>
                            <input type="text" :name="'qa_contact_addresses['+i+'][address_name]'" x-model="a.address_name"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">Street Address</label>
                            <input type="text" :name="'qa_contact_addresses['+i+'][street_address]'" x-model="a.street_address"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">City</label>
                            <input type="text" :name="'qa_contact_addresses['+i+'][city]'" x-model="a.city"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">Postcode</label>
                            <input type="text" :name="'qa_contact_addresses['+i+'][postcode]'" x-model="a.postcode"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">State</label>
                            <input type="text" :name="'qa_contact_addresses['+i+'][state]'" x-model="a.state"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-2 cursor-pointer text-xs text-slate-500">
                                <input type="checkbox" :name="'qa_contact_addresses['+i+'][default_billing]'" x-model="a.default_billing" @change="setDefaultBilling(i)"
                                       class="rounded border-slate-300 text-indigo-600">
                                Default Billing
                            </label>
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">Country</label>
                            <input type="text" :name="'qa_contact_addresses['+i+'][country]'" x-model="a.country"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-2 cursor-pointer text-xs text-slate-500">
                                <input type="checkbox" :name="'qa_contact_addresses['+i+'][default_shipping]'" x-model="a.default_shipping" @change="setDefaultShipping(i)"
                                       class="rounded border-slate-300 text-indigo-600">
                                Default Shipping
                            </label>
                        </div>
                    </div>
                </div>
            </template>
            <div class="flex justify-end">
                <button type="button" @click="addAddress()"
                        class="inline-flex items-center gap-1.5 px-3 h-8 rounded-lg border border-slate-300 text-sm text-black bg-white hover:border-indigo-500 hover:text-indigo-600 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    Add Address
                </button>
            </div>
        </div>

        <!-- ── Contact Information ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100" x-data="qaContactInfoComp()" id="qaContactInfoSection">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Contact Information</h3>
            <div class="grid grid-cols-2 gap-6">
                <!-- Emails -->
                <div>
                    <label class="<?= t('label') ?>">Email Addresses</label>
                    <input type="text" x-model="newEmail" autocomplete="new-password"
                           @keydown.enter.prevent="addEmail()"
                           placeholder="Type email and press Enter"
                           class="<?= t('input') ?> mb-1">
                    <p x-show="emailError" x-text="emailError" class="text-red-500 text-xs mt-1"></p>
                    <div class="flex flex-wrap gap-1.5 mt-2 min-h-[26px]">
                        <template x-for="(em, i) in emails" :key="i">
                            <span class="inline-flex items-center gap-1.5 bg-indigo-100 text-indigo-700 text-xs rounded-full px-2.5 py-1">
                                <span x-text="em"></span>
                                <button type="button" @click="removeEmail(i)" class="text-indigo-400 hover:text-indigo-900 font-bold leading-none">&times;</button>
                            </span>
                        </template>
                    </div>
                </div>
                <!-- Phones -->
                <div>
                    <label class="<?= t('label') ?>">Phone Numbers</label>
                    <div class="space-y-2">
                        <div class="flex gap-2">
                            <div class="relative shrink-0" x-data="qaPhoneDrop(firstPhone)" style="width:110px">
                                <button type="button" @click="open=!open" @keydown.escape="open=false" style="outline:none"
                                        class="w-full h-9 border border-slate-300 rounded-lg px-2 text-sm font-medium text-slate-800 bg-white focus:outline-none focus:border-indigo-500 transition flex items-center justify-between gap-1">
                                    <span x-text="firstPhone.country_code"></span>
                                    <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="open" @click.outside="open=false" style="display:none"
                                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                                     class="fixed z-[10000] bg-white border border-slate-200 rounded-xl shadow-xl" style="width:220px" data-qa-dd
                                     x-init="$watch('open',function(v){if(v){qaPos($el.previousElementSibling,$el,false);q='';}})">
                                    <div class="p-2 border-b border-slate-100">
                                        <input type="text" x-model="q" placeholder="Search country..."
                                               class="w-full h-7 border border-slate-200 rounded-lg px-2.5 text-xs focus:outline-none focus:border-indigo-500">
                                    </div>
                                    <div class="max-h-48 overflow-y-auto py-1">
                                        <template x-for="cc in QA_COUNTRY_CODES.filter(function(c){return !q||c.label.toLowerCase().includes(q.toLowerCase())||c.code.includes(q);})" :key="cc.code">
                                            <button type="button" @click="firstPhone.country_code=cc.code;open=false"
                                                    class="w-full text-left px-3 py-1.5 text-xs transition-colors flex items-center gap-2"
                                                    :class="firstPhone.country_code===cc.code?'bg-indigo-50 text-indigo-700 font-medium':'text-slate-700 hover:bg-slate-50'">
                                                <span class="font-mono text-slate-500 w-10 shrink-0" x-text="cc.code"></span>
                                                <span x-text="cc.label"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <input type="text" x-model="firstPhone.number" placeholder="Phone number" autocomplete="new-password"
                                   class="qa-phone-input <?= t('input') ?>">
                        </div>
                        <template x-for="(ct, i) in contacts" :key="i">
                            <div class="flex gap-2 items-center">
                                <div class="relative shrink-0" x-data="qaPhoneDrop(ct)" style="width:110px">
                                    <button type="button" @click="open=!open" @keydown.escape="open=false" style="outline:none"
                                            class="w-full h-9 border border-slate-300 rounded-lg px-2 text-sm font-medium text-slate-800 bg-white focus:outline-none focus:border-indigo-500 transition flex items-center justify-between gap-1">
                                        <span x-text="ct.country_code"></span>
                                        <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <div x-show="open" @click.outside="open=false" style="display:none"
                                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                                         class="fixed z-[10000] bg-white border border-slate-200 rounded-xl shadow-xl" style="width:220px" data-qa-dd
                                         x-init="$watch('open',function(v){if(v){qaPos($el.previousElementSibling,$el,false);q='';}})">
                                        <div class="p-2 border-b border-slate-100">
                                            <input type="text" x-model="q" placeholder="Search country..."
                                                   class="w-full h-7 border border-slate-200 rounded-lg px-2.5 text-xs focus:outline-none focus:border-indigo-500">
                                        </div>
                                        <div class="max-h-48 overflow-y-auto py-1">
                                            <template x-for="cc in QA_COUNTRY_CODES.filter(function(c){return !q||c.label.toLowerCase().includes(q.toLowerCase())||c.code.includes(q);})" :key="cc.code">
                                                <button type="button" @click="ct.country_code=cc.code;open=false"
                                                        class="w-full text-left px-3 py-1.5 text-xs transition-colors flex items-center gap-2"
                                                        :class="ct.country_code===cc.code?'bg-indigo-50 text-indigo-700 font-medium':'text-slate-700 hover:bg-slate-50'">
                                                    <span class="font-mono text-slate-500 w-10 shrink-0" x-text="cc.code"></span>
                                                    <span x-text="cc.label"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <input type="text" x-model="ct.number" placeholder="Phone number" autocomplete="new-password"
                                       class="qa-phone-input <?= t('input') ?>">
                                <button type="button" @click="removeContact(i)"
                                        class="w-8 h-9 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                        <button type="button" @click="addPhone()"
                                class="inline-flex items-center gap-1.5 px-3 h-8 rounded-lg border border-slate-300 text-sm text-black bg-white hover:border-indigo-500 hover:text-indigo-600 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                            Add Phone
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Default Settings ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Default Settings</h3>
            <div class="grid grid-cols-2 gap-x-6 gap-y-4">

                <!-- Currency -->
                <div x-data="{cOpen:false,cVal:'MYR',cLabel:'MYR — Malaysian Ringgit',cQ:''}">
                    <label class="<?= t('label') ?>">Currency</label>
                    <div class="relative">
                        <button type="button" @click="cOpen=!cOpen" @keydown.escape="cOpen=false" style="outline:none"
                                class="<?= t('input') ?> text-left flex items-center justify-between">
                            <span x-text="cLabel" :class="cVal?'text-black':'text-slate-400'"></span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="cOpen?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="cOpen" @click.outside="cOpen=false" style="display:none"
                             x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                             class="fixed z-[10000] bg-white border border-slate-200 rounded-xl shadow-xl" data-qa-dd
                             x-init="$watch('cOpen',function(v){if(v){qaPos($el.previousElementSibling,$el,true);cQ='';}})">
                            <div class="p-2 border-b border-slate-100">
                                <input type="text" x-model="cQ" placeholder="Search currency..."
                                       class="w-full h-7 border border-slate-200 rounded-lg px-2.5 text-xs focus:outline-none focus:border-indigo-500">
                            </div>
                            <div class="max-h-48 overflow-y-auto py-1">
                                <template x-for="o in QA_CURRENCIES.filter(function(o){return !cQ||o.l.toLowerCase().includes(cQ.toLowerCase());})" :key="o.c">
                                    <button type="button" @click="cVal=o.c;cLabel=o.l;cOpen=false;document.getElementById('qa_currency').value=o.c"
                                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                            :class="cVal===o.c?'bg-indigo-50 text-indigo-700 font-medium':'text-black hover:bg-slate-50'">
                                        <span x-text="o.l"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <input type="hidden" id="qa_currency" value="MYR">
                    </div>
                </div>

                <!-- e-Invoice Control -->
                <div>
                    <label class="<?= t('label') ?>">e-Invoice Control</label>
                    <div class="relative" x-data="{open:false,value:'individual',options:[{value:'consolidate',text:'Consolidate'},{value:'individual',text:'Individual'}]}">
                        <button type="button" @click="open=!open" @keydown.escape="open=false" style="outline:none"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span x-text="options.find(o=>o.value===value)?.text" class="text-black"></span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" @click.outside="open=false" style="display:none"
                             x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                             class="fixed z-[10000] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" data-qa-dd
                             x-init="$watch('open',function(v){if(v)qaPos($el.previousElementSibling,$el,true);})">
                            <ul class="py-1">
                                <template x-for="o in options" :key="o.value">
                                    <li>
                                        <button type="button" @click="value=o.value;open=false;document.getElementById('qa_einvoice_control').value=o.value"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="value===o.value?'bg-indigo-50 text-indigo-700 font-medium':'text-black hover:bg-slate-50'">
                                            <span x-text="o.text"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" id="qa_einvoice_control" value="individual">
                    </div>
                </div>

                <!-- Credit Limit -->
                <div>
                    <label class="<?= t('label') ?>">Credit Limit</label>
                    <input type="text" id="qa_credit_limit" placeholder="0.00" autocomplete="new-password"
                           class="<?= t('input') ?>"
                           onblur="if(this.value.trim()!=='')this.value=parseFloat(this.value.replace(/[^0-9.]/g,'')||0).toFixed(2)">
                </div>

                <!-- Default Payment Mode -->
                <div>
                    <label class="<?= t('label') ?>">Default Payment Mode</label>
                    <div class="flex rounded-lg border border-slate-300 overflow-hidden text-sm h-9" id="qa_payment_mode_btns">
                        <button type="button" id="qa_pm_cash" onclick="qaSetPaymentMode('cash')"
                                class="flex-1 flex items-center justify-center gap-1.5 px-3 transition-colors bg-indigo-600 text-white font-medium">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M22 10H2M6 14h.01"/></svg>
                            Cash
                        </button>
                        <button type="button" id="qa_pm_credit" onclick="qaSetPaymentMode('credit')"
                                class="flex-1 flex items-center justify-center gap-1.5 px-3 border-l border-slate-300 transition-colors bg-white text-slate-500 hover:bg-slate-50">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            Credit
                        </button>
                    </div>
                    <input type="hidden" id="qa_default_payment_mode" value="cash">
                </div>

                <!-- Payment Term -->
                <div>
                    <label class="<?= t('label') ?>">Payment Term</label>
                    <div id="invoicePtDd" x-data="invoicePtComp()">
                        <div class="relative">
                            <button type="button"
                                @click="open=!open"
                                @keydown.escape="open=false"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                                <span :class="selectedId ? 'text-black' : 'text-slate-400'" x-text="selectedId ? selectedName : 'Select payment term...'"></span>
                                <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''"
                                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div x-show="open" @click.outside="open=false" style="display:none"
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 class="fixed z-[9998] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden"
                                 x-effect="if(open){ var r=$el.previousElementSibling.getBoundingClientRect(); $el.style.top=(r.bottom+4)+'px'; $el.style.left=r.left+'px'; $el.style.width=r.width+'px'; }">
                                <ul class="max-h-56 overflow-y-auto py-1">
                                    <li>
                                        <button type="button" @click="select('','')"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="selectedId==='' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-400 hover:bg-slate-50'">
                                            — None —
                                        </button>
                                    </li>
                                    <template x-for="pt in list" :key="pt.id">
                                        <li>
                                            <button type="button" @click="select(pt.id, pt.name)"
                                                    class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                    :class="selectedId==pt.id ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-black hover:bg-slate-50'">
                                                <span x-text="pt.name"></span>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="list.length===0">
                                        <span class="block px-3 py-2 text-xs text-slate-400">No payment terms for this mode.</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <input type="hidden" name="payment_term_id" id="invoicePtHidden" :value="selectedId">
                    </div>
                </div>

                <!-- Receivable Account -->
                <div>
                    <label class="<?= t('label') ?>">Receivable Account</label>
                    <div x-data="{open:false}">
                        <button type="button" style="outline:none"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span class="text-slate-400">— Coming soon —</span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Income Account -->
                <div>
                    <label class="<?= t('label') ?>">Income Account</label>
                    <div>
                        <button type="button" style="outline:none"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span class="text-slate-400">— Coming soon —</span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Expenses Account -->
                <div>
                    <label class="<?= t('label') ?>">Expenses Account</label>
                    <div>
                        <button type="button" style="outline:none"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span class="text-slate-400">— Coming soon —</span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Price Level -->
                <div>
                    <label class="<?= t('label') ?>">Price Level</label>
                    <div>
                        <button type="button" style="outline:none"
                                class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span class="text-slate-400">— Coming soon —</span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Remarks ── -->
        <div class="px-6 pt-5 pb-6">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Remarks</h3>
            <label class="<?= t('label') ?>">Notes</label>
            <textarea id="qa_remarks" rows="3" placeholder="Internal notes..."
                      class="<?= t('input') ?> h-auto py-2 resize-none"></textarea>
        </div>

    </div><!-- /scrollable body -->

    <!-- Footer -->
    <div class="shrink-0 border-t border-slate-100 px-6 py-4 flex items-center justify-between gap-3 bg-white">
        <p class="text-xs text-slate-400">Customer will be saved and auto-selected in the invoice.</p>
        <div class="flex gap-2 shrink-0">
            <button type="button" onclick="closeQuickAdd()"
                    class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button type="button" onclick="submitQuickAdd()" id="qaSaveBtn"
                    class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Save &amp; Select</button>
        </div>
    </div>

</div><!-- /qaPanel -->

<script>
// ── qaPos: position a fixed-inside-transformed-panel dropdown ──
// fixed children of a CSS-transformed ancestor use the ancestor as their
// containing block, so top/left are relative to the panel — not the viewport.
// getBoundingClientRect() returns viewport coords, so we subtract the panel's
// viewport offset to get panel-relative coordinates.
function qaPos(trigger, panel, withWidth) {
    var panelEl = document.getElementById('qaPanel');
    var pr = panelEl ? panelEl.getBoundingClientRect() : {top:0, left:0};
    var r  = trigger.getBoundingClientRect();
    panel.style.top  = (r.bottom - pr.top + 4) + 'px';
    panel.style.left = (r.left   - pr.left)     + 'px';
    if (withWidth) panel.style.width = r.width + 'px';
}

// Re-position open QA dropdowns on panel scroll
(function() {
    function reposQA() {
        var panelEl = document.getElementById('qaPanel');
        if (!panelEl) return;
        var pr = panelEl.getBoundingClientRect();
        document.querySelectorAll('[data-qa-dd]').forEach(function(dd) {
            if (dd.style.display === 'none') return;
            var trigger = dd.previousElementSibling;
            if (!trigger) return;
            var r = trigger.getBoundingClientRect();
            dd.style.top  = (r.bottom - pr.top + 4) + 'px';
            dd.style.left = (r.left   - pr.left)     + 'px';
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        var body = document.getElementById('qaPanelBody');
        if (body) body.addEventListener('scroll', reposQA, {passive: true});
    });
})();

// ── Quick Add: Country codes & Currencies (prefixed to avoid conflicts) ──
var QA_COUNTRY_CODES = [
    {code:'+60',label:'Malaysia'},{code:'+65',label:'Singapore'},{code:'+62',label:'Indonesia'},
    {code:'+66',label:'Thailand'},{code:'+63',label:'Philippines'},{code:'+84',label:'Vietnam'},
    {code:'+95',label:'Myanmar'},{code:'+855',label:'Cambodia'},{code:'+856',label:'Laos'},
    {code:'+673',label:'Brunei'},{code:'+61',label:'Australia'},{code:'+64',label:'New Zealand'},
    {code:'+1',label:'United States / Canada'},{code:'+44',label:'United Kingdom'},
    {code:'+91',label:'India'},{code:'+86',label:'China'},{code:'+81',label:'Japan'},
    {code:'+82',label:'South Korea'},{code:'+852',label:'Hong Kong'},{code:'+886',label:'Taiwan'},
    {code:'+971',label:'UAE'},{code:'+966',label:'Saudi Arabia'},{code:'+974',label:'Qatar'},
    {code:'+973',label:'Bahrain'},{code:'+968',label:'Oman'},{code:'+965',label:'Kuwait'},
    {code:'+49',label:'Germany'},{code:'+33',label:'France'},{code:'+39',label:'Italy'},
    {code:'+34',label:'Spain'},{code:'+31',label:'Netherlands'},{code:'+46',label:'Sweden'},
    {code:'+47',label:'Norway'},{code:'+45',label:'Denmark'},{code:'+41',label:'Switzerland'},
    {code:'+43',label:'Austria'},{code:'+48',label:'Poland'},{code:'+7',label:'Russia'},
    {code:'+55',label:'Brazil'},{code:'+52',label:'Mexico'},{code:'+27',label:'South Africa'},
    {code:'+92',label:'Pakistan'},{code:'+94',label:'Sri Lanka'},{code:'+880',label:'Bangladesh'},
    {code:'+977',label:'Nepal'},{code:'+90',label:'Turkey'},{code:'+972',label:'Israel'},
];

var QA_CURRENCIES = [
    {c:'MYR',l:'MYR — Malaysian Ringgit'},{c:'USD',l:'USD — US Dollar'},
    {c:'EUR',l:'EUR — Euro'},{c:'GBP',l:'GBP — British Pound'},
    {c:'JPY',l:'JPY — Japanese Yen'},{c:'CNY',l:'CNY — Chinese Yuan'},
    {c:'SGD',l:'SGD — Singapore Dollar'},{c:'AUD',l:'AUD — Australian Dollar'},
    {c:'CAD',l:'CAD — Canadian Dollar'},{c:'CHF',l:'CHF — Swiss Franc'},
    {c:'HKD',l:'HKD — Hong Kong Dollar'},{c:'NZD',l:'NZD — New Zealand Dollar'},
    {c:'AED',l:'AED — UAE Dirham'},{c:'SAR',l:'SAR — Saudi Riyal'},
    {c:'THB',l:'THB — Thai Baht'},{c:'IDR',l:'IDR — Indonesian Rupiah'},
    {c:'PHP',l:'PHP — Philippine Peso'},{c:'VND',l:'VND — Vietnamese Dong'},
    {c:'KRW',l:'KRW — South Korean Won'},{c:'INR',l:'INR — Indian Rupee'},
    {c:'BND',l:'BND — Brunei Dollar'},{c:'TWD',l:'TWD — New Taiwan Dollar'},
    {c:'TRY',l:'TRY — Turkish Lira'},{c:'ILS',l:'ILS — Israeli Shekel'},
    {c:'RUB',l:'RUB — Russian Ruble'},{c:'PLN',l:'PLN — Polish Zloty'},
    {c:'ZAR',l:'ZAR — South African Rand'},{c:'BRL',l:'BRL — Brazilian Real'},
    {c:'MXN',l:'MXN — Mexican Peso'},
];

// ── Alpine components (qa-scoped) ──────────────────────────────────
function qaComp() { return {}; }

function qaIdTypeComp() {
    return {
        open: false,
        val: 'BRN',
        options: [
            {v:'NA',       l:'None'},
            {v:'BRN',      l:'BRN'},
            {v:'NRIC',     l:'NRIC'},
            {v:'PASSPORT', l:'Passport'},
            {v:'ARMY',     l:'Army'}
        ]
    };
}

function qaPersonsComp() {
    return {
        persons: [],
        addPerson() {
            this.persons.push({first_name:'',last_name:'',default_billing:false,default_shipping:false});
        },
        removePerson(i) { this.persons.splice(i,1); },
        setDefaultBilling(i)  { this.persons.forEach(function(p,j){ p.default_billing  = j===i; }); },
        setDefaultShipping(i) { this.persons.forEach(function(p,j){ p.default_shipping = j===i; }); }
    };
}

function qaAddressesComp() {
    return {
        addresses: [],
        addAddress() {
            this.addresses.push({
                address_name:'Address '+(this.addresses.length+1),
                street_address:'',city:'',postcode:'',country:'Malaysia',state:'',
                default_billing:false,default_shipping:false
            });
        },
        removeAddress(i) { this.addresses.splice(i,1); },
        setDefaultBilling(i)  { this.addresses.forEach(function(a,j){ a.default_billing  = j===i; }); },
        setDefaultShipping(i) { this.addresses.forEach(function(a,j){ a.default_shipping = j===i; }); }
    };
}

function qaPhoneDrop(target) { return { open: false, q: '' }; }

function qaContactInfoComp() {
    return {
        emails: [], newEmail: '', emailError: '',
        firstPhone: { country_code:'+60', number:'' },
        contacts: [],
        addEmail() {
            this.emailError = '';
            var em = this.newEmail.trim();
            if (!em) return;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { this.emailError = 'Invalid email address.'; return; }
            if (this.emails.includes(em)) { this.emailError = 'Email already added.'; return; }
            this.emails.push(em); this.newEmail = '';
        },
        removeEmail(i) { this.emails.splice(i,1); },
        addPhone() { this.contacts.push({country_code:'+60',number:''}); },
        removeContact(i) { this.contacts.splice(i,1); }
    };
}

// ── Panel open / close ─────────────────────────────────────────────
function openQuickAdd() {
    var dd = document.getElementById('customerDropdown');
    if (dd) dd.style.display = 'none';

    var backdrop = document.getElementById('qaBackdrop');
    var panel    = document.getElementById('qaPanel');

    // Make visible before animating so transition plays
    backdrop.style.pointerEvents = 'auto';
    panel.classList.remove('invisible');

    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            backdrop.style.opacity = '1';
            panel.style.transform  = 'translateX(0)';
        });
    });

    setTimeout(function() {
        var f = document.getElementById('qa_legalname');
        if (f) f.focus();
    }, 300);
    qaReset();
}

function openQuickEdit() {
    var c = window._qaLastCustomer;
    if (!c || !c.id) return;

    // Open panel first (shows loading state)
    var dd = document.getElementById('customerDropdown');
    if (dd) dd.style.display = 'none';
    var backdrop = document.getElementById('qaBackdrop');
    var panel    = document.getElementById('qaPanel');
    backdrop.style.pointerEvents = 'auto';
    panel.classList.remove('invisible');
    requestAnimationFrame(function() { requestAnimationFrame(function() {
        backdrop.style.opacity = '1';
        panel.style.transform  = 'translateX(0)';
    }); });

    qaReset();

    // Set edit mode UI — must be AFTER qaReset() which resets these to "new" state
    document.getElementById('qaPanelTitle').textContent = 'Edit Customer';
    document.getElementById('qaPanelSub').textContent   = 'Update details for ' + c.customer_name;
    document.getElementById('qaSaveBtn').textContent    = 'Save Changes';
    document.getElementById('qa_customer_id').value     = c.id;

    // Fetch full customer data
    fetch('customer_quickload.php?id=' + c.id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('qaErrorMsg').textContent = data.message || 'Failed to load customer.';
                document.getElementById('qaError').style.display = 'flex';
                return;
            }
            var cust = data.customer;

            // Basic fields
            var set = function(id, val) { var el = document.getElementById(id); if (el) el.value = val || ''; };
            set('qa_legalname',   cust.customer_name);
            set('qa_othername',   cust.other_name);
            set('qa_reg_no',      cust.reg_no);
            set('qa_old_reg_no',  cust.old_reg_no);
            set('qa_tin',         cust.tin);
            set('qa_sst_reg_no',  cust.sst_reg_no);
            set('qa_credit_limit',cust.credit_limit || '');
            set('qa_remarks',     cust.remarks);
            qaSetPaymentMode(cust.default_payment_mode || 'cash');

            // id_type dropdown
            var idTypeEl  = document.getElementById('qa_id_type');
            if (idTypeEl) idTypeEl.value = cust.id_type || 'BRN';
            var idTypeDd = document.getElementById('qa_id_type_dd');
            if (idTypeDd && idTypeDd._x_dataStack && idTypeDd._x_dataStack[0]) {
                idTypeDd._x_dataStack[0].val  = cust.id_type || 'BRN';
                idTypeDd._x_dataStack[0].open = false;
            }

            // Currency dropdown
            var QA_CURR_LABELS = {};
            (typeof QA_CURRENCIES !== 'undefined' ? QA_CURRENCIES : []).forEach(function(o){ QA_CURR_LABELS[o.c] = o.l; });
            var currEl = document.getElementById('qa_currency');
            if (currEl) currEl.value = cust.currency || 'MYR';
            var panel2 = document.getElementById('qaPanel');
            if (panel2) panel2.querySelectorAll('[x-data]').forEach(function(el) {
                if (!el._x_dataStack) return;
                var d = el._x_dataStack[0];
                if (typeof d.cVal !== 'undefined') {
                    d.cVal   = cust.currency || 'MYR';
                    d.cLabel = QA_CURR_LABELS[d.cVal] || d.cVal;
                    d.cQ = '';
                }
                if (typeof d.value !== 'undefined' && typeof d.options !== 'undefined') {
                    var hasIndiv = d.options.some(function(o){ return o.value === 'individual'; });
                    if (hasIndiv) d.value = cust.einvoice_control || 'individual';
                }
            });

            // Contact persons
            var personsSec = document.getElementById('qaPanel').querySelector('[x-data]');
            document.getElementById('qaPanel').querySelectorAll('[x-data]').forEach(function(el) {
                if (!el._x_dataStack) return;
                var d = el._x_dataStack[0];
                if (typeof d.persons !== 'undefined') {
                    d.persons = (data.persons || []).map(function(p) {
                        return {
                            first_name:       p.first_name || '',
                            last_name:        p.last_name  || '',
                            default_billing:  p.default_billing  == 1,
                            default_shipping: p.default_shipping == 1
                        };
                    });
                }
                if (typeof d.addresses !== 'undefined') {
                    d.addresses = (data.addresses || []).map(function(a) {
                        return {
                            address_name:     a.address_name    || '',
                            street_address:   a.street_address  || '',
                            city:             a.city            || '',
                            postcode:         a.postcode        || '',
                            state:            a.state           || '',
                            country:          a.country         || '',
                            default_billing:  a.default_billing  == 1,
                            default_shipping: a.default_shipping == 1
                        };
                    });
                }
                if (typeof d.emails !== 'undefined') {
                    d.emails     = data.emails || [];
                    d.newEmail   = '';
                    d.emailError = '';
                }
                if (typeof d.contacts !== 'undefined') {
                    var phones = data.phones || [];
                    d.firstPhone = phones.length > 0
                        ? { country_code: phones[0].country_code || '+60', number: phones[0].phone_number || '' }
                        : { country_code: '+60', number: '' };
                    d.contacts = phones.slice(1).map(function(p) {
                        return { country_code: p.country_code || '+60', number: p.phone_number || '' };
                    });
                }
            });

            setTimeout(function() {
                var f = document.getElementById('qa_legalname');
                if (f) f.focus();
            }, 100);
        })
        .catch(function() {
            document.getElementById('qaErrorMsg').textContent = 'Server error loading customer.';
            document.getElementById('qaError').style.display = 'flex';
        });
}

function closeQuickAdd() {
    var backdrop = document.getElementById('qaBackdrop');
    var panel    = document.getElementById('qaPanel');

    backdrop.style.opacity      = '0';
    backdrop.style.pointerEvents = 'none';
    panel.style.transform       = 'translateX(100%)';

    // Add invisible after transition completes so Alpine stays mounted
    setTimeout(function() {
        panel.classList.add('invisible');
    }, 300);
}

function qaSetPaymentMode(mode) {
    var cashBtn   = document.getElementById('qa_pm_cash');
    var creditBtn = document.getElementById('qa_pm_credit');
    var hiddenEl  = document.getElementById('qa_default_payment_mode');
    if (hiddenEl) hiddenEl.value = mode;
    var activeClass   = 'flex-1 flex items-center justify-center gap-1.5 px-3 transition-colors bg-indigo-600 text-white font-medium';
    var inactiveClass = 'flex-1 flex items-center justify-center gap-1.5 px-3 border-l border-slate-300 transition-colors bg-white text-slate-500 hover:bg-slate-50';
    var inactiveFirst = 'flex-1 flex items-center justify-center gap-1.5 px-3 transition-colors bg-white text-slate-500 hover:bg-slate-50';
    if (cashBtn && creditBtn) {
        cashBtn.className   = mode === 'cash'   ? activeClass    : inactiveFirst;
        creditBtn.className = mode === 'credit' ? activeClass    : inactiveClass;
    }
}

function qaReset() {
    // Reset mode to "new"
    var cidEl = document.getElementById('qa_customer_id');
    if (cidEl) cidEl.value = '0';
    document.getElementById('qaPanelTitle').textContent = 'New Customer';
    document.getElementById('qaPanelSub').textContent   = 'Full profile — same as Customers → New Customer.';
    document.getElementById('qaSaveBtn').textContent    = 'Save & Select';

    // Reset plain inputs
    ['qa_legalname','qa_othername','qa_reg_no','qa_old_reg_no',
     'qa_tin','qa_sst_reg_no','qa_credit_limit','qa_remarks'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.value = '';
    });

    // Reset hidden inputs
    var idTypeEl = document.getElementById('qa_id_type');
    if (idTypeEl) idTypeEl.value = 'BRN';
    // Reset id_type Alpine component
    var idTypeDd = document.getElementById('qa_id_type_dd');
    if (idTypeDd && idTypeDd._x_dataStack && idTypeDd._x_dataStack[0]) {
        idTypeDd._x_dataStack[0].val = 'BRN';
        idTypeDd._x_dataStack[0].open = false;
    }
    var currEl = document.getElementById('qa_currency');
    if (currEl) currEl.value = 'MYR';
    var einvEl = document.getElementById('qa_einvoice_control');
    if (einvEl) einvEl.value = 'individual';
    qaSetPaymentMode('cash'); // reset to default

    // Reset Alpine component states via $data
    var panel = document.getElementById('qaPanel');
    if (panel && panel._x_dataStack) {
        // Reset qaPersonsComp, qaAddressesComp, qaContactInfoComp on their section divs
        panel.querySelectorAll('[x-data]').forEach(function(el) {
            if (!el._x_dataStack) return;
            var d = el._x_dataStack[0];
            // Contact persons
            if (typeof d.persons !== 'undefined') d.persons = [];
            // Contact addresses
            if (typeof d.addresses !== 'undefined') d.addresses = [];
            // Contact info
            if (typeof d.emails !== 'undefined') { d.emails = []; d.newEmail = ''; d.emailError = ''; }
            if (typeof d.contacts !== 'undefined') { d.contacts = []; d.firstPhone = {country_code:'+60',number:''}; }
            // Reg No Type dropdown
            if (typeof d.value !== 'undefined' && typeof d.options !== 'undefined') {
                // id_type dropdown - reset to BRN
                var hasIdType = d.options.some(function(o){ return o.value === 'BRN'; });
                if (hasIdType) d.value = 'BRN';
                // einvoice dropdown - reset to individual
                var hasIndividual = d.options.some(function(o){ return o.value === 'individual'; });
                if (hasIndividual) d.value = 'individual';
            }
            // Currency dropdown
            if (typeof d.cVal !== 'undefined') {
                d.cVal = 'MYR';
                d.cLabel = 'MYR — Malaysian Ringgit';
                d.cQ = '';
            }
        });
    }

    // Error + button
    var err = document.getElementById('qaError');
    if (err) err.style.display = 'none';
    var btn = document.getElementById('qaSaveBtn');
    if (btn) { btn.disabled = false; btn.textContent = 'Save & Select'; }

    // Scroll body back to top
    var body = document.getElementById('qaPanelBody');
    if (body) body.scrollTop = 0;
}

// ── Collect contact section data ───────────────────────────────────
function qaCollectPersons() {
    // Read directly from Alpine state so checkboxes (default_billing/shipping) are included
    var sec = document.getElementById('qaPanel');
    if (sec) {
        var found = null;
        sec.querySelectorAll('[x-data]').forEach(function(el) {
            if (!el._x_dataStack) return;
            var d = el._x_dataStack[0];
            if (typeof d.persons !== 'undefined') found = d;
        });
        if (found) {
            return found.persons
                .filter(function(p) { return p.first_name || p.last_name; })
                .map(function(p) {
                    return {
                        first_name:       p.first_name || '',
                        last_name:        p.last_name  || '',
                        default_billing:  p.default_billing  ? 1 : 0,
                        default_shipping: p.default_shipping ? 1 : 0
                    };
                });
        }
    }
    // Fallback: DOM scrape
    var persons = [];
    document.querySelectorAll('[name^="qa_contact_persons"]').forEach(function(el) {
        var m = el.name.match(/qa_contact_persons\[(\d+)\]\[(\w+)\]/);
        if (!m) return;
        var i = parseInt(m[1]), field = m[2];
        if (!persons[i]) persons[i] = {};
        persons[i][field] = el.value;
    });
    return persons.filter(function(p) { return p && (p.first_name || p.last_name); });
}

function qaCollectAddresses() {
    // Read directly from Alpine state so checkboxes are included
    var sec = document.getElementById('qaPanel');
    if (sec) {
        var found = null;
        sec.querySelectorAll('[x-data]').forEach(function(el) {
            if (!el._x_dataStack) return;
            var d = el._x_dataStack[0];
            if (typeof d.addresses !== 'undefined') found = d;
        });
        if (found) {
            return found.addresses
                .filter(function(a) { return a.address_name; })
                .map(function(a) {
                    return {
                        address_name:     a.address_name   || '',
                        street_address:   a.street_address || '',
                        city:             a.city           || '',
                        postcode:         a.postcode       || '',
                        state:            a.state          || '',
                        country:          a.country        || '',
                        default_billing:  a.default_billing  ? 1 : 0,
                        default_shipping: a.default_shipping ? 1 : 0
                    };
                });
        }
    }
    // Fallback
    var addresses = [];
    document.querySelectorAll('[name^="qa_contact_addresses"]').forEach(function(el) {
        var m = el.name.match(/qa_contact_addresses\[(\d+)\]\[(\w+)\]/);
        if (!m) return;
        var i = parseInt(m[1]), field = m[2];
        if (!addresses[i]) addresses[i] = {};
        addresses[i][field] = el.value;
    });
    return addresses.filter(function(a) { return a && a.address_name; });
}

function qaCollectEmails() {
    var sec = document.getElementById('qaContactInfoSection');
    if (!sec || !sec._x_dataStack) return [];
    return sec._x_dataStack[0].emails || [];
}

function qaCollectPhones() {
    var sec = document.getElementById('qaContactInfoSection');
    if (!sec || !sec._x_dataStack) return [];
    var d = sec._x_dataStack[0];
    var phones = [];
    if (d.firstPhone && d.firstPhone.number.trim())
        phones.push({ country_code: d.firstPhone.country_code, number: d.firstPhone.number.trim() });
    (d.contacts || []).forEach(function(ct) {
        if (ct.number && ct.number.trim())
            phones.push({ country_code: ct.country_code, number: ct.number.trim() });
    });
    return phones;
}

// ── Submit ─────────────────────────────────────────────────────────
function submitQuickAdd() {
    var name = document.getElementById('qa_legalname').value.trim();
    if (!name) {
        document.getElementById('qaErrorMsg').textContent = 'Legal name is required.';
        document.getElementById('qaError').style.display = 'flex';
        document.getElementById('qa_legalname').focus();
        return;
    }
    var btn = document.getElementById('qaSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var isEdit = parseInt(document.getElementById('qa_customer_id').value || '0') > 0;

    var body = new URLSearchParams({
        qa_customer_id:      document.getElementById('qa_customer_id').value,
        qa_legalname:        name.toUpperCase(),
        qa_othername:        document.getElementById('qa_othername').value.trim(),
        qa_id_type:          document.getElementById('qa_id_type').value,
        qa_reg_no:           document.getElementById('qa_reg_no').value.trim().toUpperCase(),
        qa_old_reg_no:       document.getElementById('qa_old_reg_no').value.trim().toUpperCase(),
        qa_tin:              document.getElementById('qa_tin').value.trim().toUpperCase(),
        qa_sst_reg_no:       document.getElementById('qa_sst_reg_no').value.trim().toUpperCase(),
        qa_currency:              document.getElementById('qa_currency').value,
        qa_einvoice_control:      document.getElementById('qa_einvoice_control').value,
        qa_credit_limit:          document.getElementById('qa_credit_limit').value.trim(),
        qa_default_payment_mode:  document.getElementById('qa_default_payment_mode').value,
        qa_remarks:               document.getElementById('qa_remarks').value.trim(),
        qa_persons_json:     JSON.stringify(qaCollectPersons()),
        qa_addresses_json:   JSON.stringify(qaCollectAddresses()),
        qa_emails_json:      JSON.stringify(qaCollectEmails()),
        qa_phones_json:      JSON.stringify(qaCollectPhones()),
    });

    fetch('customer_quicksave.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            // Build the updated customer object for CUSTOMERS array and select()
            var updated = {
                id:                       data.id,
                customer_name:            data.customer_name,
                tin:                      data.tin            || '',
                reg_no:                   data.reg_no         || '',
                currency:                 data.currency       || 'MYR',
                default_payment_mode:     data.default_payment_mode || 'cash',
                email:                    data.email          || '',
                phone:                    data.phone          || '',
                address_line_0:           data.address_line_0 || '',
                address_line_1:           data.address_line_1 || '',
                city:                     data.city           || '',
                postal_code:              data.postal_code    || '',
                default_billing_person:   data.default_billing_person  || '',
                default_shipping_person:  data.default_shipping_person || '',
                default_billing_address:  data.default_billing_address  || null,
                default_shipping_address: data.default_shipping_address || null,
            };

            if (isEdit) {
                // Update existing entry in CUSTOMERS array
                var idx = CUSTOMERS.findIndex(function(c) { return c.id === data.id; });
                if (idx >= 0) { CUSTOMERS[idx] = updated; } else { CUSTOMERS.unshift(updated); }
            } else {
                CUSTOMERS.unshift(updated);
            }

            // Re-select to refresh all invoice fields
            var wrap = document.getElementById('customerWrap') ? document.getElementById('customerWrap').closest('[x-data]') : null;
            if (wrap && wrap._x_dataStack && wrap._x_dataStack[0] && wrap._x_dataStack[0].select) {
                wrap._x_dataStack[0].select(updated);
            } else {
                var inp = document.getElementById('customerSearchInput');
                if (inp) inp.value = updated.customer_name;
                document.getElementById('f_customer_name').value   = updated.customer_name;
                document.getElementById('f_customer_tin').value    = updated.tin;
                document.getElementById('f_customer_reg_no').value = updated.reg_no;
            }

            closeQuickAdd();
            showToast(isEdit ? 'Customer updated.' : 'Customer created and selected.', true);
        } else {
            document.getElementById('qaErrorMsg').textContent = data.message || 'Save failed.';
            document.getElementById('qaError').style.display = 'flex';
            btn.disabled = false; btn.textContent = 'Save & Select';
        }
    })
    .catch(function() {
        document.getElementById('qaErrorMsg').textContent = 'Server error. Please try again.';
        document.getElementById('qaError').style.display = 'flex';
        btn.disabled = false; btn.textContent = 'Save & Select';
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('qaPanel').style.display !== 'none') {
        closeQuickAdd();
    }
    if (e.key === 'Escape' && document.getElementById('apPanel') && !document.getElementById('apPanel').classList.contains('invisible')) {
        closeAddProduct();
    }
});
</script>

<!-- ══════════════════════════════════════════════════════════════
     ADD PRODUCT PANEL (slide-in from right)
     ══════════════════════════════════════════════════════════════ -->

<!-- Backdrop -->
<div id="apBackdrop" onclick="closeAddProduct()"
     style="opacity:0;pointer-events:none;transition:opacity 0.25s ease"
     class="fixed inset-0 bg-black/40 z-[9998]"></div>

<!-- Slide panel -->
<div id="apPanel"
     style="transform:translateX(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1)"
     class="fixed top-0 right-0 h-screen w-[780px] bg-white shadow-2xl z-[9999] flex flex-col border-l border-slate-200 invisible">

    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
        <div>
            <h2 class="text-base font-semibold text-slate-800">New Product</h2>
            <p class="text-xs text-slate-400 mt-0.5">Create a new product and add it to this invoice.</p>
        </div>
        <button type="button" onclick="closeAddProduct()"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl font-light">&times;</button>
    </div>

    <!-- Scrollable body -->
    <div class="flex-1 overflow-y-auto" id="apPanelBody">

        <!-- Error banner -->
        <div id="apError" style="display:none"
             class="mx-6 mt-4 flex items-center gap-2.5 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            <span id="apErrorMsg"></span>
        </div>

        <!-- ── Basic Information ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Basic Information</h3>
            <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <label class="<?= t('label') ?>">Name <span class="text-red-400">*</span></label>
                    <input type="text" id="ap_name" autocomplete="new-password"
                           class="<?= t('input') ?>" placeholder="Product name">
                </div>
                <div>
                    <label class="<?= t('label') ?>">SKU / Code</label>
                    <input type="text" id="ap_sku" autocomplete="new-password"
                           class="<?= t('input') ?>" placeholder="900324719A">
                </div>
                <div>
                    <label class="<?= t('label') ?>">Barcode</label>
                    <input type="text" id="ap_barcode" autocomplete="new-password"
                           class="<?= t('input') ?>" placeholder="0799439112766">
                </div>
                <div></div>
            </div>
        </div>

        <!-- ── Classification Code ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Classification Code</h3>
            <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <label class="<?= t('label') ?>">LHDN Classification</label>
                    <div class="relative" x-data="apLhdnComp()">
                        <input type="text" x-ref="inp"
                               id="ap_class_input"
                               :value="open ? q : displayLabel"
                               @focus="onFocus()"
                               @input="q=$event.target.value"
                               @blur="onBlur()"
                               @keydown.escape="open=false;q=''"
                               @keydown.arrow-down.prevent="moveDown()"
                               @keydown.arrow-up.prevent="moveUp()"
                               @keydown.enter.prevent="pickActive()"
                               placeholder="Select Classification Code"
                               autocomplete="off"
                               class="<?= t('input') ?> pr-8">
                        <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                             :class="open?'rotate-180':''"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        <div x-show="open" @mousedown.prevent style="display:none"
                             class="absolute z-[10000] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="max-h-56 overflow-y-auto py-1" x-ref="list">
                                <template x-for="(o,i) in filteredCodes" :key="o.v">
                                    <li>
                                        <button type="button" @mousedown.prevent="pickItem(o)"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="i===activeIdx?'bg-indigo-50 text-indigo-700 font-medium':(value===o.v?'bg-slate-50 text-slate-700 font-medium':'text-slate-700 hover:bg-slate-50')">
                                            <span x-text="o.l"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" id="ap_classification_code" :value="value">
                    </div>
                </div>
                <div></div>
            </div>
        </div>

        <!-- ── Sale Information ── -->
        <div class="px-6 pt-5 pb-4 border-b border-slate-100">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Sale Information</h3>
            <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <label class="<?= t('label') ?>">Sale Price</label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 text-sm text-slate-500 font-medium">MYR</span>
                        <input type="number" id="ap_sale_price" min="0" step="0.01" placeholder="0.00"
                               class="h-9 flex-1 border border-slate-300 rounded-r-lg px-3 text-sm text-black bg-white focus:outline-none focus:border-indigo-500 transition">
                    </div>
                </div>
                <div>
                    <label class="<?= t('label') ?>">Sales Tax</label>
                    <div class="relative" x-data="{open:false,value:'',options:<?= json_encode(array_merge([['value'=>'','text'=>'— None —']], array_map(fn($t)=>['value'=>(string)$t['id'],'text'=>$t['name'].' ('.number_format((float)$t['rate'],2).'%)'], $taxRates)), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>}">
                        <button type="button" @click="open=!open" @keydown.escape="open=false"
                                class="<?= t('input') ?> text-left flex items-center justify-between">
                            <span x-text="options.find(o=>o.value===value)?.text||'— None —'" :class="value?'text-black':'text-slate-400'"></span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" @click.outside="open=false" style="display:none"
                             class="absolute z-[10000] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="max-h-48 overflow-y-auto py-1">
                                <template x-for="o in options" :key="o.value">
                                    <li>
                                        <button type="button" @click="value=o.value;open=false;document.getElementById('ap_sales_tax').value=o.value"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="value===o.value?'bg-indigo-50 text-indigo-700 font-medium':'text-slate-700 hover:bg-slate-50'">
                                            <span x-text="o.text"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" id="ap_sales_tax" value="">
                    </div>
                </div>
                <div class="col-span-2">
                    <label class="<?= t('label') ?>">Description</label>
                    <textarea id="ap_sale_description" rows="3" placeholder="Description"
                              class="<?= t('input') ?> h-auto py-2 resize-none"></textarea>
                </div>
            </div>
        </div>

        <!-- ── Other Information ── -->
        <div class="px-6 pt-5 pb-6">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Other Information</h3>
            <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <label class="<?= t('label') ?>">Base Unit Label</label>
                    <input type="text" id="ap_base_unit_label" value="unit" autocomplete="new-password"
                           class="<?= t('input') ?>" placeholder="unit">
                </div>
                <div></div>
                <div class="col-span-2">
                    <label class="<?= t('label') ?>">Remarks</label>
                    <textarea id="ap_remarks" rows="3" placeholder="Remarks"
                              class="<?= t('input') ?> h-auto py-2 resize-none"></textarea>
                </div>
            </div>
        </div>

    </div><!-- /scrollable body -->

    <!-- Footer -->
    <div class="shrink-0 border-t border-slate-100 px-6 py-4 flex items-center justify-between gap-3 bg-white">
        <p class="text-xs text-slate-400">Product will be saved and added as a new line item.</p>
        <div class="flex gap-2 shrink-0">
            <button type="button" onclick="closeAddProduct()"
                    class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button type="button" onclick="submitAddProduct()" id="apSaveBtn"
                    class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Save &amp; Add to Invoice</button>
        </div>
    </div>

</div><!-- /apPanel -->

<script>
// ── apPos: position fixed dropdown within the transformed apPanel ──
function apPos(trigger, panel) {
    var panelEl = document.getElementById('apPanel');
    var pr = panelEl ? panelEl.getBoundingClientRect() : {top:0,left:0};
    var r  = trigger.getBoundingClientRect();
    panel.style.top   = (r.bottom - pr.top + 4) + 'px';
    panel.style.left  = (r.left   - pr.left)     + 'px';
    panel.style.width = r.width + 'px';
}

// Re-position open AP dropdowns on panel scroll
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var body = document.getElementById('apPanelBody');
        if (body) body.addEventListener('scroll', function() {
            var panelEl = document.getElementById('apPanel');
            if (!panelEl) return;
            var pr = panelEl.getBoundingClientRect();
            document.querySelectorAll('[data-ap-dd]').forEach(function(dd) {
                if (dd.style.display === 'none') return;
                var trigger = dd.previousElementSibling;
                if (!trigger) return;
                var r = trigger.getBoundingClientRect();
                dd.style.top  = (r.bottom - pr.top + 4) + 'px';
                dd.style.left = (r.left   - pr.left)     + 'px';
            });
        }, {passive: true});
    });
})();

// ── LHDN combobox for Add Product panel ──
function apLhdnComp() {
    var all = [{v:'', l:'— No classification —'}].concat(
        (typeof LHDN_CODES !== 'undefined' ? LHDN_CODES : []).filter(function(o){ return o.v; })
    );
    return {
        value: '', q: '', open: false, activeIdx: -1,
        get displayLabel() {
            if (!this.value) return '';
            var f = all.find(function(o){ return o.v === this.value; }.bind(this));
            return f ? f.l : this.value;
        },
        get filteredCodes() {
            var q = this.q.trim().toLowerCase();
            return q ? all.filter(function(o){ return o.l.toLowerCase().includes(q); }) : all;
        },
        onFocus() { this.q=''; this.open=true; this.activeIdx=-1; },
        pickItem(o) {
            this.value=o.v; this.q=''; this.open=false; this.activeIdx=-1;
            if (this.$refs && this.$refs.inp) this.$refs.inp.blur();
        },
        pickActive() { if(this.activeIdx>=0&&this.filteredCodes[this.activeIdx]) this.pickItem(this.filteredCodes[this.activeIdx]); },
        moveDown() { this.activeIdx=Math.min(this.activeIdx+1,this.filteredCodes.length-1); this.scrollActive(); },
        moveUp()   { this.activeIdx=Math.max(this.activeIdx-1,0); this.scrollActive(); },
        scrollActive() {
            var self=this;
            this.$nextTick(function(){ var l=self.$refs.list; if(!l)return; var li=l.querySelectorAll('li')[self.activeIdx]; if(li)li.scrollIntoView({block:'nearest'}); });
        },
        onBlur() { var self=this; setTimeout(function(){ if(self.open){self.open=false;self.q='';self.activeIdx=-1;} },200); }
    };
}

// ── Open / Close ──
function openAddProduct() {
    var backdrop = document.getElementById('apBackdrop');
    var panel    = document.getElementById('apPanel');
    backdrop.style.pointerEvents = 'auto';
    panel.classList.remove('invisible');
    requestAnimationFrame(function() { requestAnimationFrame(function() {
        backdrop.style.opacity = '1';
        panel.style.transform  = 'translateX(0)';
    }); });
    setTimeout(function() {
        var f = document.getElementById('ap_name');
        if (f) f.focus();
    }, 300);
    apReset();
}

function closeAddProduct() {
    var backdrop = document.getElementById('apBackdrop');
    var panel    = document.getElementById('apPanel');
    backdrop.style.opacity       = '0';
    backdrop.style.pointerEvents = 'none';
    panel.style.transform        = 'translateX(100%)';
    setTimeout(function() { panel.classList.add('invisible'); }, 300);
}

function apReset() {
    ['ap_name','ap_sku','ap_barcode','ap_sale_price','ap_sale_description','ap_remarks'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('ap_base_unit_label').value = 'unit';
    document.getElementById('ap_sales_tax').value = '';
    document.getElementById('ap_classification_code').value = '';

    // Reset LHDN Alpine component
    var panel = document.getElementById('apPanel');
    if (panel) {
        panel.querySelectorAll('[x-data]').forEach(function(el) {
            if (!el._x_dataStack) return;
            var d = el._x_dataStack[0];
            if (typeof d.value !== 'undefined' && typeof d.filteredCodes !== 'undefined') {
                d.value = ''; d.q = ''; d.open = false; d.activeIdx = -1;
            }
            // Reset the sales tax dropdown
            if (typeof d.value !== 'undefined' && typeof d.options !== 'undefined' && !d.filteredCodes) {
                d.value = ''; d.open = false;
            }
        });
    }

    var err = document.getElementById('apError');
    if (err) err.style.display = 'none';
    var btn = document.getElementById('apSaveBtn');
    if (btn) { btn.disabled = false; btn.textContent = 'Save & Add to Invoice'; }

    var body = document.getElementById('apPanelBody');
    if (body) body.scrollTop = 0;
}

// ── Submit ──
function submitAddProduct() {
    var name = (document.getElementById('ap_name').value || '').trim();
    if (!name) {
        document.getElementById('apErrorMsg').textContent = 'Product name is required.';
        document.getElementById('apError').style.display = 'flex';
        document.getElementById('ap_name').focus();
        return;
    }

    var btn = document.getElementById('apSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var classCode = document.getElementById('ap_classification_code').value || '';
    // Read from Alpine if available
    var panel = document.getElementById('apPanel');
    if (panel) {
        panel.querySelectorAll('[x-data]').forEach(function(el) {
            if (!el._x_dataStack) return;
            var d = el._x_dataStack[0];
            if (typeof d.value !== 'undefined' && typeof d.filteredCodes !== 'undefined') {
                classCode = d.value || classCode;
            }
        });
    }

    var body = new URLSearchParams({
        ajax:                '1',
        name:                name,
        sku:                 (document.getElementById('ap_sku').value || '').trim(),
        barcode:             (document.getElementById('ap_barcode').value || '').trim(),
        classification_code: classCode,
        sale_price:          (document.getElementById('ap_sale_price').value || '').trim(),
        sales_tax:           document.getElementById('ap_sales_tax').value || '',
        sale_description:    (document.getElementById('ap_sale_description').value || '').trim(),
        base_unit_label:     (document.getElementById('ap_base_unit_label').value || 'unit').trim(),
        remarks:             (document.getElementById('ap_remarks').value || '').trim(),
        selling:             '1',
        buying:              '1',
        track_inventory:     '1',
        multiple_uoms:       '0',
    });

    fetch('product_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var newProduct = {
                id:                  data.id,
                name:                name,
                sku:                 document.getElementById('ap_sku').value.trim(),
                sale_price:          document.getElementById('ap_sale_price').value.trim(),
                sale_description:    document.getElementById('ap_sale_description').value.trim(),
                classification_code: classCode,
            };

            // Add product to PRODUCTS array for future searches
            PRODUCTS.unshift(newProduct);

            // Fill the item row that triggered "Add Product", or add a new row
            if (_apTargetInput) {
                itemDdSelect(_apTargetInput, newProduct);
                _apTargetInput = null;
            } else {
                addRow();
                var newRows = document.querySelectorAll('#itemsBody .item-row');
                var newDescInput = newRows[newRows.length - 1].querySelector('.item-desc-input');
                if (newDescInput) itemDdSelect(newDescInput, newProduct);
            }

            closeAddProduct();
            showToast('Product created and added to invoice.', true);
        } else {
            document.getElementById('apErrorMsg').textContent = data.message || 'Save failed.';
            document.getElementById('apError').style.display = 'flex';
            btn.disabled = false; btn.textContent = 'Save & Add to Invoice';
        }
    })
    .catch(function() {
        document.getElementById('apErrorMsg').textContent = 'Server error. Please try again.';
        document.getElementById('apError').style.display = 'flex';
        btn.disabled = false; btn.textContent = 'Save & Add to Invoice';
    });
}
</script>

<?php endif; ?>

<?php layoutClose(); ?>
