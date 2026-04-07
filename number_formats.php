<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';

$pdo = db();

// ── Document type options ──────────────────────────────────────────
$typeOptions = [
    'invoice'        => 'Invoice',
    'quotation'      => 'Quotation',
    'sales_order'    => 'Sales Order',
    'delivery_order' => 'Delivery Order',
    'credit_note'    => 'Credit Note',
    'purchase_order' => 'Purchase Order',
    'goods_received' => 'Goods Received Note',
    'bill'           => 'Bill',
    'purchase_credit'=> 'Purchase Credit Note',
    'official_receipt'=> 'Official Receipt',
    'payment_voucher'=> 'Payment Voucher',
    'bank_transfer'  => 'Bank Transfer',
];

$typeLabels = $typeOptions;

// ── Load all formats ───────────────────────────────────────────────
$formats = $pdo->query("SELECT * FROM number_formats ORDER BY doc_type, id")->fetchAll();

layoutOpen('Number Formats', 'Manage the number formats for your transactions.');
?>

<?php
$btnNew = t('btn_base') . ' ' . t('btn_primary');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<button type="button" onclick="openPanel()" class="<?= $btnNew ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New</button>';
</script>

<!-- ── Table ──────────────────────────────────────────────────────── -->
<div class="<?= t('table_wrap') ?>">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="<?= t('th') ?>">Format</th>
                <th class="<?= t('th') ?>">Type</th>
                <th class="<?= t('th') ?>">Preview</th>
                <th class="<?= t('th') ?> text-center">Action</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (empty($formats)): ?>
            <tr>
                <td colspan="4" class="px-4 py-12 text-center text-slate-400 text-sm">
                    No number formats yet. Click <strong class="text-slate-600">New</strong> to add one.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($formats as $f): ?>
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="<?= t('td') ?> font-mono font-medium"><?= e($f['format']) ?></td>
                <td class="<?= t('td') ?>">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-700">
                        <?= e($typeLabels[$f['doc_type']] ?? $f['doc_type']) ?>
                    </span>
                </td>
                <td class="<?= t('td') ?> font-mono text-slate-500 text-xs"><?= e(previewFormat($f['format'])) ?></td>
                <td class="<?= t('td') ?> text-center">
                    <div class="flex items-center justify-center gap-2">
                        <button type="button"
                                onclick="openPanel(<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>)"
                                class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-7 text-xs px-3">Edit</button>
                        <button type="button"
                                onclick="confirmDelete(<?= $f['id'] ?>, '<?= e(addslashes($f['format'])) ?>')"
                                class="<?= t('btn_base') ?> h-7 text-xs px-3 bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 rounded-lg">Delete</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ── Delete modal ───────────────────────────────────────────────── -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelete()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl shadow-2xl p-6 mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Format</h3>
                <p class="text-xs text-slate-400 mt-0.5">This action cannot be undone.</p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Delete format <strong id="deleteFormatName" class="text-slate-900"></strong>?</p>
        <div class="flex gap-2 justify-end">
            <button onclick="closeDelete()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button id="confirmDeleteBtn" class="<?= t('btn_base') ?> <?= t('btn_danger') ?> h-9">Delete</button>
        </div>
    </div>
</div>

<!-- ── Slide panel ────────────────────────────────────────────────── -->
<div id="nfBackdrop" onclick="closePanel()"
     style="opacity:0;pointer-events:none;transition:opacity 0.25s ease"
     class="fixed inset-0 bg-black/40 z-[9998]"></div>

<div id="nfPanel"
     style="transform:translateX(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1)"
     class="fixed top-0 right-0 h-screen w-[480px] bg-white shadow-2xl z-[9999] flex flex-col border-l border-slate-200 invisible">

    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
        <div>
            <h2 id="nfPanelTitle" class="text-base font-semibold text-slate-800">New Number Format</h2>
            <p class="text-xs text-slate-400 mt-0.5">Define the format pattern for a document type.</p>
        </div>
        <button type="button" onclick="closePanel()"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl font-light">&times;</button>
    </div>

    <!-- Body -->
    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">

        <!-- Error -->
        <div id="nfError" style="display:none"
             class="flex items-center gap-2.5 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            <span id="nfErrorMsg"></span>
        </div>

        <input type="hidden" id="nf_id" value="0">

        <!-- Type -->
        <div>
            <label class="<?= t('label') ?>">Type <span class="text-red-400">*</span></label>
            <div id="nfTypeDd" class="relative" x-data="nfTypeComp()">
                <button type="button" @click="open=!open" @keydown.escape="open=false" style="outline:none"
                        class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                    <span x-text="label" :class="val?'text-black':'text-slate-400'"></span>
                    <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" @click.outside="open=false" style="display:none"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                     class="absolute z-50 left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                    <ul class="max-h-56 overflow-y-auto py-1">
                        <template x-for="o in options" :key="o.v">
                            <li>
                                <button type="button" @click="val=o.v;label=o.l;open=false;updatePreview()"
                                        class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                        :class="val===o.v?'bg-indigo-50 text-indigo-700 font-medium':'text-black hover:bg-slate-50'">
                                    <span x-text="o.l"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Format -->
        <div>
            <label class="<?= t('label') ?>">Format <span class="text-red-400">*</span></label>
            <input type="text" id="nf_format" oninput="updatePreview()"
                   placeholder="e.g. INV-[YYYY]-[5DIGIT]"
                   autocomplete="off"
                   class="<?= t('input') ?> font-mono uppercase">
        </div>

        <!-- Preview -->
        <div id="nfPreviewWrap" class="bg-slate-50 rounded-lg px-4 py-3 border border-slate-200">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Preview</p>
            <p id="nfPreview" class="text-sm font-mono text-slate-800">—</p>
        </div>

        <!-- Placeholder hints -->
        <div>
            <p class="text-xs font-medium text-slate-500 mb-2">Date Placeholders</p>
            <div class="flex flex-wrap gap-2 mb-4">
                <?php foreach (['[YYYY]','[YY]','[MM]','[DD]'] as $ph): ?>
                <button type="button" onclick="insertPlaceholder('<?= $ph ?>')"
                        class="px-2.5 py-1 text-xs font-mono rounded-md border border-slate-300 bg-white text-slate-700 hover:border-indigo-400 hover:text-indigo-600 transition-colors">
                    <?= $ph ?>
                </button>
                <?php endforeach; ?>
            </div>
            <p class="text-xs font-medium text-slate-500 mb-2">Number Placeholders <span class="text-red-400 font-normal">(required)</span></p>
            <div class="flex flex-wrap gap-2">
                <?php foreach (['[2DIGIT]','[3DIGIT]','[4DIGIT]','[5DIGIT]','[6DIGIT]','[7DIGIT]','[8DIGIT]'] as $ph): ?>
                <button type="button" onclick="insertPlaceholder('<?= $ph ?>')"
                        class="px-2.5 py-1 text-xs font-mono rounded-md border border-slate-300 bg-white text-slate-700 hover:border-indigo-400 hover:text-indigo-600 transition-colors">
                    <?= $ph ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Footer -->
    <div class="shrink-0 border-t border-slate-100 px-6 py-4 flex justify-end gap-2">
        <button type="button" onclick="closePanel()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
        <button type="button" onclick="saveFormat()" id="nfSaveBtn" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Save</button>
    </div>

</div>

<script>
// ── Type component ─────────────────────────────────────────────────
var NF_TYPES = [
    <?php foreach ($typeOptions as $v => $l): ?>
    {v:'<?= $v ?>', l:'<?= addslashes($l) ?>'},
    <?php endforeach; ?>
];

function nfTypeComp() {
    return { open:false, val:'', label:'Select Type', options: NF_TYPES };
}

// ── Preview ────────────────────────────────────────────────────────
function updatePreview() {
    var fmt = (document.getElementById('nf_format').value || '').toUpperCase();
    document.getElementById('nfPreview').textContent = fmt ? previewFmt(fmt) : '—';
}

function previewFmt(fmt) {
    var now = new Date();
    var yyyy = now.getFullYear().toString();
    var yy   = yyyy.slice(-2);
    var mm   = String(now.getMonth()+1).padStart(2,'0');
    var dd   = String(now.getDate()).padStart(2,'0');
    var out  = fmt
        .replace(/\[YYYY\]/g, yyyy)
        .replace(/\[YY\]/g,   yy)
        .replace(/\[MM\]/g,   mm)
        .replace(/\[DD\]/g,   dd);
    for (var n = 2; n <= 8; n++) {
        out = out.replace(new RegExp('\\['+n+'DIGIT\\]','g'), '1'.padStart(n,'0'));
    }
    return out;
}

// ── Insert placeholder into format input ───────────────────────────
function insertPlaceholder(ph) {
    var inp = document.getElementById('nf_format');
    var pos = inp.selectionStart;
    var val = inp.value;
    inp.value = (val.slice(0,pos) + ph + val.slice(inp.selectionEnd)).toUpperCase();
    inp.selectionStart = inp.selectionEnd = pos + ph.length;
    inp.focus();
    updatePreview();
}

// ── Panel open / close ─────────────────────────────────────────────
function openPanel(row) {
    var backdrop = document.getElementById('nfBackdrop');
    var panel    = document.getElementById('nfPanel');
    backdrop.style.pointerEvents = 'auto';
    panel.classList.remove('invisible');
    requestAnimationFrame(function() { requestAnimationFrame(function() {
        backdrop.style.opacity = '1';
        panel.style.transform  = 'translateX(0)';
    }); });

    // Reset
    document.getElementById('nf_id').value    = '0';
    document.getElementById('nf_format').value = '';
    document.getElementById('nfPreview').textContent = '—';
    document.getElementById('nfError').style.display = 'none';
    document.getElementById('nfPanelTitle').textContent = 'New Number Format';
    document.getElementById('nfSaveBtn').textContent = 'Save';
    document.getElementById('nfSaveBtn').disabled = false;

    // Reset type Alpine component
    var ddEl = document.getElementById('nfTypeDd');
    if (ddEl && ddEl._x_dataStack && ddEl._x_dataStack[0]) {
        ddEl._x_dataStack[0].val   = '';
        ddEl._x_dataStack[0].label = 'Select Type';
        ddEl._x_dataStack[0].open  = false;
    }

    // If editing, populate fields
    if (row) {
        document.getElementById('nf_id').value     = row.id;
        document.getElementById('nf_format').value  = row.format;
        document.getElementById('nfPanelTitle').textContent = 'Edit Number Format';
        updatePreview();
        if (ddEl && ddEl._x_dataStack && ddEl._x_dataStack[0]) {
            var opt = NF_TYPES.find(function(o){ return o.v === row.doc_type; });
            ddEl._x_dataStack[0].val   = row.doc_type;
            ddEl._x_dataStack[0].label = opt ? opt.l : row.doc_type;
        }
    }

    setTimeout(function() { document.getElementById('nf_format').focus(); }, 300);
}

function closePanel() {
    var backdrop = document.getElementById('nfBackdrop');
    var panel    = document.getElementById('nfPanel');
    backdrop.style.opacity       = '0';
    backdrop.style.pointerEvents = 'none';
    panel.style.transform        = 'translateX(100%)';
    setTimeout(function() { panel.classList.add('invisible'); }, 300);
}

// ── Save ───────────────────────────────────────────────────────────
function saveFormat() {
    var id     = document.getElementById('nf_id').value;
    var fmt    = document.getElementById('nf_format').value.trim().toUpperCase();
    var ddEl   = document.getElementById('nfTypeDd');
    var docType = ddEl && ddEl._x_dataStack && ddEl._x_dataStack[0] ? ddEl._x_dataStack[0].val : '';

    // Validate
    if (!docType) {
        showErr('Please select a document type.'); return;
    }
    if (!fmt) {
        showErr('Format is required.'); return;
    }
    if (!/\[\d+DIGIT\]/.test(fmt)) {
        showErr('Format must include a number placeholder e.g. [5DIGIT].'); return;
    }

    var btn = document.getElementById('nfSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    fetch('number_format_save.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({id: id, doc_type: docType, format: fmt}).toString()
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) {
            closePanel();
            showToast(id > 0 ? 'Format updated.' : 'Format created.', true);
            setTimeout(function(){ location.reload(); }, 600);
        } else {
            showErr(d.message || 'Save failed.');
            btn.disabled = false; btn.textContent = 'Save';
        }
    })
    .catch(function(){
        showErr('Server error.'); btn.disabled = false; btn.textContent = 'Save';
    });
}

function showErr(msg) {
    document.getElementById('nfErrorMsg').textContent = msg;
    document.getElementById('nfError').style.display = 'flex';
}

// ── Delete ─────────────────────────────────────────────────────────
var _deleteId = null;
function confirmDelete(id, name) {
    _deleteId = id;
    document.getElementById('deleteFormatName').textContent = name;
    var m = document.getElementById('deleteModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeDelete() {
    _deleteId = null;
    var m = document.getElementById('deleteModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!_deleteId) return;
    fetch('number_format_save.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'delete=1&id=' + _deleteId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        closeDelete();
        if (d.success) { showToast('Format deleted.', true); setTimeout(function(){ location.reload(); }, 600); }
        else showToast(d.message || 'Failed.', false);
    })
    .catch(function(){ closeDelete(); showToast('Server error.', false); });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePanel();
});
</script>

<?php layoutClose(); ?>

<?php
function previewFormat(string $fmt): string {
    $now  = new DateTime();
    $out  = $fmt;
    $out  = str_replace(['[YYYY]','[YY]','[MM]','[DD]'],
                        [$now->format('Y'), $now->format('y'), $now->format('m'), $now->format('d')],
                        $out);
    for ($n = 2; $n <= 8; $n++) {
        $out = str_replace("[{$n}DIGIT]", str_pad('1', $n, '0', STR_PAD_LEFT), $out);
    }
    return $out;
}
?>
