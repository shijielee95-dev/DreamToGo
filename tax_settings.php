<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';

$pdo  = db();
$taxes = $pdo->query("SELECT * FROM tax_rates ORDER BY is_default DESC, name")->fetchAll();

layoutOpen('Tax Settings', 'Manage tax rates for your invoices and purchases.');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<button type="button" onclick="openPanel()" class="<?= t('btn_base').' '.t('btn_primary') ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New Tax</button>';
</script>

<!-- ── Table ──────────────────────────────────────────────────────── -->
<div class="<?= t('table_wrap') ?>">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="<?= t('th') ?>">Tax Name</th>
                <th class="<?= t('th') ?>">Rate</th>
                <th class="<?= t('th') ?>">Tax Details</th>
                <th class="<?= t('th') ?> text-center">Default</th>
                <th class="<?= t('th') ?> text-center">Action</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (empty($taxes)): ?>
            <tr>
                <td colspan="5" class="px-4 py-12 text-center text-slate-400 text-sm">
                    No tax rates yet. Click <strong class="text-slate-600">New Tax</strong> to add one.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($taxes as $t): ?>
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="<?= tax_td() ?> font-semibold">
                    <?= e($t['name']) ?>
                </td>
                <td class="<?= tax_td() ?>">
                    <span class="font-mono font-semibold text-indigo-700"><?= number_format((float)$t['rate'], 2) ?>%</span>
                </td>
                <td class="<?= tax_td() ?> text-slate-500 max-w-xs truncate"><?= e($t['details']) ?></td>
                <td class="<?= tax_td() ?> text-center">
                    <?php if ($t['is_default']): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-green-50 text-green-700">Default</span>
                    <?php else: ?>
                    <span class="text-slate-300">—</span>
                    <?php endif; ?>
                </td>
                <td class="<?= tax_td() ?> text-center">
                    <div class="flex items-center justify-center gap-2">
                        <button type="button"
                                onclick="openPanel(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)"
                                class="<?= tax_btn_ghost() ?> h-7 text-xs px-3">Edit</button>
                        <button type="button"
                                onclick="confirmDelete(<?= $t['id'] ?>, '<?= e(addslashes($t['name'])) ?>')"
                                class="<?= tax_btn_base() ?> h-7 text-xs px-3 bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 rounded-lg">Delete</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ── Delete modal ──────────────────────────────────────────────── -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelete()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl shadow-2xl p-6 mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Tax Rate</h3>
                <p class="text-xs text-slate-400 mt-0.5">This cannot be undone.</p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Delete <strong id="deleteTaxName" class="text-slate-900"></strong>?</p>
        <div class="flex gap-2 justify-end">
            <button onclick="closeDelete()" class="<?= tax_btn_ghost() ?> h-9">Cancel</button>
            <button id="confirmDeleteBtn" class="<?= tax_btn_danger() ?> h-9">Delete</button>
        </div>
    </div>
</div>

<!-- ── Slide panel ────────────────────────────────────────────────── -->
<div id="taxBackdrop" onclick="closePanel()"
     style="opacity:0;pointer-events:none;transition:opacity 0.25s ease"
     class="fixed inset-0 bg-black/40 z-[9998]"></div>

<div id="taxPanel"
     style="transform:translateX(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1)"
     class="fixed top-0 right-0 h-screen w-[480px] bg-white shadow-2xl z-[9999] flex flex-col border-l border-slate-200 invisible">

    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
        <div>
            <h2 id="taxPanelTitle" class="text-base font-semibold text-slate-800">New Tax Rate</h2>
            <p class="text-xs text-slate-400 mt-0.5">Define a tax rate for use in transactions.</p>
        </div>
        <button type="button" onclick="closePanel()"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl font-light">&times;</button>
    </div>

    <!-- Body -->
    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">

        <!-- Error -->
        <div id="taxError" style="display:none"
             class="flex items-center gap-2.5 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            <span id="taxErrorMsg"></span>
        </div>

        <input type="hidden" id="tax_id" value="0">

        <!-- Tax Name -->
        <div>
            <label class="<?= tax_label() ?>">Tax Name <span class="text-red-400">*</span></label>
            <input type="text" id="tax_name"
                   placeholder="e.g. SST 6%, Service Tax"
                   class="<?= tax_input() ?>">
        </div>

        <!-- Rate -->
        <div>
            <label class="<?= tax_label() ?>">Rate (%) <span class="text-red-400">*</span></label>
            <div class="relative">
                <input type="number" id="tax_rate" min="0" max="100" step="0.01"
                       placeholder="0.00"
                       class="<?= tax_input() ?> pr-8">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 font-medium pointer-events-none">%</span>
            </div>
        </div>

        <!-- Tax Details -->
        <div>
            <label class="<?= tax_label() ?>">Tax Details</label>
            <textarea id="tax_details" rows="3"
                      placeholder="Optional description, e.g. Sales and Services Tax at 6% as per..."
                      class="<?= tax_input() ?> h-auto py-2 resize-none"></textarea>
        </div>

        <!-- Default -->
        <div class="flex items-center gap-3" x-data="{isDefault: false}" id="taxDefaultWrap">
            <button type="button" @click="isDefault=!isDefault" id="taxDefaultBtn"
                    class="relative w-10 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                    :class="isDefault?'bg-indigo-500':'bg-slate-200'">
                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                     :class="isDefault?'translate-x-5':''"></div>
            </button>
            <span class="text-sm text-slate-600">Set as Default Tax</span>
        </div>

    </div>

    <!-- Footer -->
    <div class="shrink-0 border-t border-slate-100 px-6 py-4 flex justify-end gap-2">
        <button type="button" onclick="closePanel()" class="<?= tax_btn_ghost() ?> h-9">Cancel</button>
        <button type="button" onclick="saveTax()" id="taxSaveBtn" class="<?= tax_btn_primary() ?> h-9">Save</button>
    </div>
</div>

<script>
// ── Panel open / close ─────────────────────────────────────────────
function openPanel(row) {
    var backdrop = document.getElementById('taxBackdrop');
    var panel    = document.getElementById('taxPanel');
    backdrop.style.pointerEvents = 'auto';
    panel.classList.remove('invisible');
    requestAnimationFrame(function() { requestAnimationFrame(function() {
        backdrop.style.opacity = '1';
        panel.style.transform  = 'translateX(0)';
    }); });

    // Reset
    document.getElementById('tax_id').value      = '0';
    document.getElementById('tax_name').value    = '';
    document.getElementById('tax_rate').value    = '';
    document.getElementById('tax_details').value = '';
    document.getElementById('taxError').style.display = 'none';
    document.getElementById('taxPanelTitle').textContent = 'New Tax Rate';
    document.getElementById('taxSaveBtn').textContent = 'Save';
    document.getElementById('taxSaveBtn').disabled = false;

    // Reset default toggle
    var wrap = document.getElementById('taxDefaultWrap');
    if (wrap && wrap._x_dataStack && wrap._x_dataStack[0]) {
        wrap._x_dataStack[0].isDefault = false;
    }

    if (row) {
        document.getElementById('tax_id').value      = row.id;
        document.getElementById('tax_name').value    = row.name;
        document.getElementById('tax_rate').value    = row.rate;
        document.getElementById('tax_details').value = row.details || '';
        document.getElementById('taxPanelTitle').textContent = 'Edit Tax Rate';
        if (wrap && wrap._x_dataStack && wrap._x_dataStack[0]) {
            wrap._x_dataStack[0].isDefault = row.is_default == 1;
        }
    }

    setTimeout(function() { document.getElementById('tax_name').focus(); }, 300);
}

function closePanel() {
    var backdrop = document.getElementById('taxBackdrop');
    var panel    = document.getElementById('taxPanel');
    backdrop.style.opacity       = '0';
    backdrop.style.pointerEvents = 'none';
    panel.style.transform        = 'translateX(100%)';
    setTimeout(function() { panel.classList.add('invisible'); }, 300);
}

// ── Save ───────────────────────────────────────────────────────────
function saveTax() {
    var id      = document.getElementById('tax_id').value;
    var name    = document.getElementById('tax_name').value.trim();
    var rate    = document.getElementById('tax_rate').value.trim();
    var details = document.getElementById('tax_details').value.trim();
    var wrap    = document.getElementById('taxDefaultWrap');
    var isDefault = (wrap && wrap._x_dataStack && wrap._x_dataStack[0])
                    ? (wrap._x_dataStack[0].isDefault ? 1 : 0) : 0;

    if (!name) { showErr('Tax name is required.'); return; }
    if (rate === '' || isNaN(parseFloat(rate))) { showErr('Rate is required.'); return; }

    var btn = document.getElementById('taxSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    fetch('tax_save.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({id, name, rate, details, is_default: isDefault}).toString()
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) {
            closePanel();
            showToast(id > 0 ? 'Tax rate updated.' : 'Tax rate created.', true);
            setTimeout(function(){ location.reload(); }, 600);
        } else {
            showErr(d.message || 'Save failed.');
            btn.disabled = false; btn.textContent = 'Save';
        }
    })
    .catch(function(){ showErr('Server error.'); btn.disabled = false; btn.textContent = 'Save'; });
}

function showErr(msg) {
    document.getElementById('taxErrorMsg').textContent = msg;
    document.getElementById('taxError').style.display = 'flex';
}

// ── Delete ─────────────────────────────────────────────────────────
var _deleteId = null;
function confirmDelete(id, name) {
    _deleteId = id;
    document.getElementById('deleteTaxName').textContent = name;
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
    fetch('tax_save.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'delete=1&id=' + _deleteId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        closeDelete();
        if (d.success) { showToast('Tax rate deleted.', true); setTimeout(function(){ location.reload(); }, 600); }
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
// ── Token shorthand helpers (avoid repeating t() calls inline) ──
function tax_td()          { return 'px-4 py-3 text-sm font-medium text-slate-900 border-b border-slate-100'; }
function tax_label()       { return 'block text-sm font-normal text-black mb-1'; }
function tax_input()       { return 'w-full h-9 border border-slate-300 rounded-lg px-3 text-sm font-normal text-black bg-white focus:outline-none focus:border-indigo-500 transition'; }
function tax_btn_base()    { return 'inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors'; }
function tax_btn_primary() { return tax_btn_base().' bg-indigo-600 hover:bg-indigo-700 text-white'; }
function tax_btn_ghost()   { return tax_btn_base().' bg-slate-100 hover:bg-slate-200 text-slate-600'; }
function tax_btn_danger()  { return tax_btn_base().' bg-red-600 hover:bg-red-700 text-white'; }
?>
