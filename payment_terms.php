<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';
$pid    = (int)($_GET['id'] ?? 0);

// ── Type options ──────────────────────────────────────────────────
$typeOptions = [
    'days'            => 'In Days',
    'day_of_month'    => 'Day of Month',
    'day_of_foll_month' => 'Day of Following Month',
    'end_of_month'    => 'End of Month',
    'days_after_month'=> 'Days after Month',
];

$typeDescriptions = [
    'days'              => 'Due in number of days. Put 0 as same day.',
    'day_of_month'      => 'A specific day of the invoice month (e.g. 25 = due on the 25th).',
    'day_of_foll_month' => 'A specific day of the following month.',
    'end_of_month'      => 'Due at the end of the invoice month.',
    'days_after_month'  => 'Due in number of days after the end of the invoice month.',
];

// ── Defaults ──────────────────────────────────────────────────────
$term = [
    'id' => '', 'name' => '', 'description' => '',
    'type' => 'days', 'value' => 0,
    'payment_mode' => 'cash',
    'late_interest_active' => 0, 'late_interest_rate' => '',
    'is_active' => 1,
];

if (($action === 'edit' || $action === 'view') && $pid > 0) {
    $row = $pdo->prepare("SELECT * FROM payment_terms WHERE id = ?");
    $row->execute([$pid]);
    $row = $row->fetch();
    if (!$row) { flash('error', 'Payment term not found.'); redirect('payment_terms.php'); }
    $term = array_merge($term, $row);
}

// ══════════════════════════════════════════════════════════════════
// LIST
// ══════════════════════════════════════════════════════════════════
if ($action === 'list'):
    // Fetch all — JS paginates based on available VH
    $terms = $pdo->query("SELECT * FROM payment_terms ORDER BY name")->fetchAll();
    $termsJson = json_encode(array_values(array_map(function($pt) use ($typeOptions) {
        return [
            'id'           => (int)$pt['id'],
            'name'         => $pt['name'],
            'description'  => $pt['description'] ?? '',
            'type'         => $typeOptions[$pt['type']] ?? $pt['type'],
            'value'        => ($pt['value'] !== null && $pt['value'] !== '') ? (int)$pt['value'] : null,
            'payment_mode' => $pt['payment_mode'] ?? 'cash',
        ];
    }, $terms)), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);

    layoutOpen('Payment Terms', 'Payment terms determine when a payment is due.');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="payment_terms.php?action=new" class="<?= t('btn_base').' '.t('btn_primary') ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New</a>';
// Stop main from scrolling so table controls its own height
document.querySelector('main').style.overflow = 'hidden';
document.querySelector('main').style.display  = 'flex';
document.querySelector('main').style.flexDirection = 'column';
</script>

<!-- Outer wrapper: flex column, fills remaining main height, no overflow -->
<div id="ptWrap" class="flex flex-col flex-1 min-h-0">

    <!-- Card: one table rendered as grid — thead is a fixed row, tbody is the only scrolling row.
         Because it is a single table, colgroup applies to both, so columns are always perfectly aligned.
         The scrollbar lives inside tbody's grid row and never overlaps the header. -->
    <div class="bg-white rounded-xl border border-slate-200 flex-1 min-h-0 flex flex-col">
        <table id="ptTable" class="w-full text-sm flex-1 min-h-0 flex flex-col" style="table-layout:fixed">
            <colgroup>
                <col style="width:180px">
                <col>
                <col style="width:150px">
                <col style="width:160px">
                <col style="width:70px">
                <col style="width:140px">
            </colgroup>
            <thead id="ptThead" class="shrink-0 bg-slate-50 border-b border-slate-200 block w-full">
                <tr class="flex w-full">
                    <th class="<?= t('th') ?>" style="width:180px;flex-shrink:0">Term</th>
                    <th class="<?= t('th') ?> flex-1 min-w-0">Description</th>
                    <th class="<?= t('th') ?>" style="width:150px;flex-shrink:0">Payment Mode</th>
                    <th class="<?= t('th') ?>" style="width:160px;flex-shrink:0">Type</th>
                    <th class="<?= t('th') ?>" style="width:70px;flex-shrink:0">Value</th>
                    <th class="<?= t('th') ?> text-center" style="width:157px;flex-shrink:0">Action</th>
                </tr>
            </thead>
            <tbody id="ptBody" class="block flex-1 w-full" style="overflow-y:auto">
                <!-- Populated by JS -->
            </tbody>
        </table>
    </div>

    <!-- Pagination bar — always takes up h-9 space so table height is stable -->
    <div id="ptPager" class="flex items-center justify-between mt-3 shrink-0 h-9">
        <!-- Left: info + per-page dropdown -->
        <div class="flex items-center gap-3 h-full">
            <span id="ptInfo" class="text-sm text-slate-500 whitespace-nowrap"></span>
            <div class="flex items-center gap-1.5 h-full"
                 x-data="{open:false,value:'20',options:[{v:'20',l:'20'},{v:'50',l:'50'},{v:'100',l:'100'}]}"
                 x-init="$watch('value', function(v){ ptPerPage=parseInt(v,10); ptPage=1; ptRender(); })">
                <span class="text-xs text-slate-400">Show</span>
                <!-- Trigger -->
                <div class="relative">
                    <button type="button"
                        @click="open=!open"
                        @keydown.escape="open=false"
                        class="h-9 px-3 rounded-lg bg-white border border-slate-300 flex items-center gap-2 text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400"
                        style="min-width:4rem">
                        <span x-text="value" class="text-slate-700"></span>
                        <svg class="w-3.5 h-3.5 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <!-- Panel -->
                    <div x-show="open" @click.outside="open=false" style="display:none"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         class="absolute left-0 bottom-full mb-1 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden z-50" style="min-width:4rem">
                        <ul class="py-1">
                            <template x-for="opt in options" :key="opt.v">
                                <li>
                                    <button type="button"
                                        @click="value=opt.v; open=false"
                                        class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                        :class="value===opt.v ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-700 hover:bg-slate-50'">
                                        <span x-text="opt.l"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
                <span class="text-xs text-slate-400">records</span>
            </div>
        </div>
        <!-- Right: page buttons -->
        <div id="ptPages" class="flex items-center gap-1 h-full"></div>
    </div>
</div>

<!-- Delete modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelete()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl shadow-2xl p-6 mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Payment Term</h3>
                <p class="text-xs text-slate-400 mt-0.5">This cannot be undone.</p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Delete <strong id="deleteName" class="text-slate-900"></strong>?</p>
        <div class="flex gap-2 justify-end">
            <button onclick="closeDelete()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button id="confirmDeleteBtn" class="<?= t('btn_base') ?> <?= t('btn_danger') ?> h-9">Delete</button>
        </div>
    </div>
</div>

<script>
var PT_DATA    = <?= $termsJson ?>;
var PT_TOTAL   = PT_DATA.length;
var ptPage     = 1;
var ptPerPage  = 20;

// ── Escape helper (data already server-escaped via JSON_HEX) ──────
function ptEsc(s) { return s == null ? '' : String(s); }

// ── Render current page ───────────────────────────────────────────
function ptRender() {
    var start  = (ptPage - 1) * ptPerPage;
    var end    = Math.min(start + ptPerPage, PT_TOTAL);
    var slice  = PT_DATA.slice(start, end);
    var tbody  = document.getElementById('ptBody');

    if (PT_TOTAL === 0) {
        tbody.innerHTML =
            '<tr class="flex w-full"><td colspan="6" class="flex-1 px-4 py-12 text-center text-slate-400 text-sm">' +
            'No payment terms yet. <a href="payment_terms.php?action=new" class="text-indigo-600 hover:underline">Add one</a>.' +
            '</td></tr>';
    } else {
        var tdBase  = '<?= t('td') ?>';
        var btnBase = '<?= t('btn_base') ?>';
        var btnGhost = '<?= t('btn_ghost') ?>';
        tbody.innerHTML = slice.map(function(pt) {
            var val = pt.value !== null
                ? String(pt.value)
                : '<span class="text-slate-300">\u2014</span>';
            var modeLabel = pt.payment_mode === 'credit' ? 'Credit Sales' : 'Cash Sales';
            var modeCls   = pt.payment_mode === 'credit'
                ? 'bg-blue-50 text-blue-700'
                : 'bg-green-50 text-green-700';
            return '<tr class="flex w-full items-center hover:bg-slate-50 transition-colors">' +
                '<td class="' + tdBase + ' font-semibold text-slate-800 truncate overflow-hidden whitespace-nowrap" style="width:180px;flex-shrink:0">' + ptEsc(pt.name) + '</td>' +
                '<td class="' + tdBase + ' text-slate-500 truncate overflow-hidden whitespace-nowrap flex-1 min-w-0">' + ptEsc(pt.description) + '</td>' +
                '<td class="' + tdBase + ' whitespace-nowrap" style="width:150px;flex-shrink:0">' +
                    '<span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium ' + modeCls + '">' + modeLabel + '</span>' +
                '</td>' +
                '<td class="' + tdBase + ' whitespace-nowrap" style="width:160px;flex-shrink:0">' +
                    '<span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-600">' + ptEsc(pt.type) + '</span>' +
                '</td>' +
                '<td class="' + tdBase + ' text-slate-700 whitespace-nowrap" style="width:70px;flex-shrink:0">' + val + '</td>' +
                '<td class="' + tdBase + ' text-center" style="width:157px;flex-shrink:0">' +
                    '<div class="flex items-center justify-center gap-1">' +
                        '<a href="payment_terms.php?action=edit&id=' + pt.id + '" class="' + btnBase + ' ' + btnGhost + ' h-7 text-xs px-3">Edit</a>' +
                        '<button type="button" onclick="confirmDelete(' + pt.id + ',\'' + ptEsc(pt.name).replace(/'/g,"\\'") + '\')" ' +
                            'class="' + btnBase + ' h-7 text-xs px-3 bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 rounded-lg">Delete</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    ptRenderPager(start + 1, end);
}

// ── Render pagination bar ─────────────────────────────────────────
function ptRenderPager(from, to) {
    var totalPages = Math.max(1, Math.ceil(PT_TOTAL / ptPerPage));
    var pager = document.getElementById('ptPager');

    // Pager is always visible (fixed h-9 height keeps table stable)
    pager.style.visibility = 'visible';

    document.getElementById('ptInfo').textContent =
        PT_TOTAL === 0 ? '' : from + '\u2013' + to + ' of ' + PT_TOTAL + ' items';

    if (totalPages <= 1) {
        document.getElementById('ptPages').innerHTML = '';
        return;
    }

    var B = 'w-8 h-8 flex items-center justify-center rounded-lg border text-xs transition-colors cursor-pointer ';
    var active   = B + 'bg-indigo-600 border-indigo-600 text-white font-semibold';
    var normal   = B + 'border-slate-200 hover:border-indigo-400 hover:text-indigo-600';
    var disabled = B + 'border-slate-100 text-slate-300 cursor-default';
    var chevL = '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>';
    var chevR = '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>';

    var h = '';
    h += ptPage > 1
        ? '<span class="' + normal + '" onclick="ptGo(' + (ptPage-1) + ')">' + chevL + '</span>'
        : '<span class="' + disabled + '">' + chevL + '</span>';

    var ps = Math.max(1, ptPage - 2), pe = Math.min(totalPages, ptPage + 2);
    if (ps > 1) {
        h += '<span class="' + normal + '" onclick="ptGo(1)">1</span>';
        if (ps > 2) h += '<span class="px-1 text-slate-300">\u2026</span>';
    }
    for (var p = ps; p <= pe; p++) {
        h += '<span class="' + (p === ptPage ? active : normal) + '"' +
             (p !== ptPage ? ' onclick="ptGo(' + p + ')"' : '') + '>' + p + '</span>';
    }
    if (pe < totalPages) {
        if (pe < totalPages - 1) h += '<span class="px-1 text-slate-300">\u2026</span>';
        h += '<span class="' + normal + '" onclick="ptGo(' + totalPages + ')">' + totalPages + '</span>';
    }
    h += ptPage < totalPages
        ? '<span class="' + normal + '" onclick="ptGo(' + (ptPage+1) + ')">' + chevR + '</span>'
        : '<span class="' + disabled + '">' + chevR + '</span>';

    document.getElementById('ptPages').innerHTML = h;
}

function ptGo(p) { ptPage = p; ptRender(); }

// ── Set tbody scroll height + sync scrollbar spacer ─────────────
function ptSetWrapHeight() {
    var tbody  = document.getElementById('ptBody');
    var ptWrap = document.getElementById('ptWrap');
    if (!tbody || !ptWrap) return;

    var ptWrapTop = ptWrap.getBoundingClientRect().top;
    var thead     = document.querySelector('#ptTable thead');
    var theadH    = thead ? thead.offsetHeight : 40;
    var pagerH    = 48; // h-9 (36px) + mt-3 (12px) — fixed so height never shifts
    var mainPad   = 24;
    var available = window.innerHeight - ptWrapTop - theadH - pagerH - mainPad;
    tbody.style.height = Math.max(60, available) + 'px';
}

// ── Init ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                ptSetWrapHeight();
                ptRender();
            });
        });
    });

});
window.addEventListener('resize', function() {
    ptSetWrapHeight();
});

// ── Delete ───────────────────────────────────────────────────────
var _delId = null;
function confirmDelete(id, name) {
    _delId = id;
    document.getElementById('deleteName').textContent = name;
    var m = document.getElementById('deleteModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeDelete() {
    _delId = null;
    var m = document.getElementById('deleteModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!_delId) return;
    fetch('payment_terms_save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'delete=1&id=' + _delId
    }).then(function(r){ return r.json(); }).then(function(d) {
        closeDelete();
        if (d.success) {
            PT_DATA  = PT_DATA.filter(function(pt){ return pt.id !== _delId; });
            PT_TOTAL = PT_DATA.length;
            var totalPages = Math.max(1, Math.ceil(PT_TOTAL / ptPerPage));
            if (ptPage > totalPages) ptPage = totalPages;
            ptRender();
            showToast('Payment term deleted.', true);
        } else {
            showToast(d.message || 'Failed.', false);
        }
    }).catch(function(){ closeDelete(); showToast('Server error.', false); });
});
</script>
<style>
/* Hide scrollbar completely — scroll still works, no gutter reserved */
#ptBody::-webkit-scrollbar { display: none; }
#ptBody { scrollbar-width: none; -ms-overflow-style: none; }
/* Single full-width border on tr, not per-td */
#ptBody tr { border-bottom: 1px solid #e2e8f0; }
#ptBody td { border-bottom: none !important; }
</style>
<?php layoutClose();

// ══════════════════════════════════════════════════════════════════
// NEW / EDIT
// ══════════════════════════════════════════════════════════════════
else:
    $isEdit    = ($action === 'edit') && $pid > 0;
    $pageTitle = $isEdit ? 'Edit Payment Term' : 'New Payment Term';
    layoutOpen($pageTitle, $isEdit ? e($term['name']) : 'Fill in the details below.');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="payment_terms.php" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9">← Back</a>';
</script>

<form id="ptForm" method="POST" action="payment_terms_save.php">
<?php if ($isEdit): ?>
<input type="hidden" name="id" value="<?= $pid ?>">
<?php endif; ?>

<div x-data="{mode:'<?= e($term['payment_mode'] ?? 'cash') ?>'}" class="bg-white rounded-xl border border-slate-200 mb-24">

    <!-- ── General ── -->
    <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-2 cursor-pointer select-none" onclick="toggleSection('sGeneral','cGeneral')">
        <svg id="cGeneral" class="w-4 h-4 text-slate-400 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
        <h3 class="text-sm font-semibold text-slate-800">General</h3>
    </div>
    <div id="sGeneral">
        <div class="p-6 grid grid-cols-[1fr_auto] gap-8">
            <div class="space-y-4 max-w-xl">

                <!-- Payment Mode -->
                <?php $dpm = $term['payment_mode'] ?? 'cash'; ?>
                <div class="grid grid-cols-[140px_1fr] items-center gap-4">
                    <label class="text-sm text-slate-600 text-right">Payment Mode <span class="text-red-400">*</span></label>
                    <div>
                        <div class="flex rounded-lg border border-slate-200 overflow-hidden text-sm h-9 w-64">
                            <button type="button" @click="mode='cash'"
                                    class="flex-1 flex items-center justify-center gap-1.5 px-3 transition-colors"
                                    :class="mode==='cash' ? 'bg-indigo-600 text-white font-medium' : 'bg-white text-slate-500 hover:bg-slate-50'">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M22 10H2M6 14h.01"/></svg>
                                Cash Sales
                            </button>
                            <button type="button" @click="mode='credit'"
                                    class="flex-1 flex items-center justify-center gap-1.5 px-3 border-l border-slate-200 transition-colors"
                                    :class="mode==='credit' ? 'bg-indigo-600 text-white font-medium' : 'bg-white text-slate-500 hover:bg-slate-50'">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                                Credit Sales
                            </button>
                        </div>
                        <input type="hidden" name="payment_mode" :value="mode">
                    </div>
                </div>

                <!-- Name -->
                <div class="grid grid-cols-[140px_1fr] items-center gap-4">
                    <label class="text-sm text-slate-600 text-right">Name <span class="text-red-400">*</span></label>
                    <input type="text" name="name" required value="<?= e($term['name']) ?>"
                           placeholder="NET 30"
                           class="<?= t('input') ?>">
                </div>

                <!-- Description -->
                <div class="grid grid-cols-[140px_1fr] items-start gap-4">
                    <label class="text-sm text-slate-600 text-right pt-2">Description</label>
                    <textarea name="description" rows="3" placeholder="Description of the payment term"
                              class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($term['description']) ?></textarea>
                </div>

                <!-- Type (credit only) -->
                <div class="grid grid-cols-[140px_1fr] items-center gap-4" x-show="mode==='credit'">
                    <label class="text-sm text-slate-600 text-right">Type <span class="text-red-400">*</span></label>
                    <div class="relative w-full" x-data="ptTypeDropdown()">
                        <button type="button"
                            @click="open=!open"
                            @keydown.escape="open=false"
                            class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">
                            <span x-text="options.find(function(o){return o.value===value}) ? options.find(function(o){return o.value===value}).text : 'Select...'" class="text-black"></span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="open" @click.outside="open=false" style="display:none"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             class="fixed z-[9999] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden"
                             x-init="$watch('open', function(v){ if(v){ var r=$el.previousElementSibling.getBoundingClientRect(); $el.style.top=(r.bottom+4)+'px'; $el.style.left=r.left+'px'; $el.style.width=r.width+'px'; } })">
                            <ul class="max-h-56 overflow-y-auto py-1">
                                <template x-for="item in options" :key="item.value">
                                    <li>
                                        <button type="button"
                                            @click="value=item.value; open=false; updateTypeHint(item.value)"
                                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                            :class="value===item.value ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-black hover:bg-slate-50'">
                                            <span x-text="item.text"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <select name="type" x-model="value"
                                class="absolute opacity-0 pointer-events-none w-0 h-0 top-0 left-0"
                                tabindex="-1" aria-hidden="true">
                            <?php foreach ($typeOptions as $val => $label): ?>
                            <option value="<?= e($val) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Value (credit only) -->
                <div class="grid grid-cols-[140px_1fr] items-center gap-4" x-show="mode==='credit'">
                    <label class="text-sm text-slate-600 text-right">Value <span class="text-red-400">*</span></label>
                    <div class="flex items-center gap-4">
                        <input type="number" name="value" min="0" step="1"
                               value="<?= e($term['value'] ?? 0) ?>"
                               class="<?= t('input') ?> w-32 text-right shrink-0">
                        <span class="text-xs text-slate-400 flex-1" id="typeHint">
                            <?= e($typeDescriptions[$term['type']] ?? '') ?>
                        </span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="border-t border-slate-100" x-show="mode==='credit'"></div>

    <!-- ── Late Interest (credit only) ── -->
    <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-2 cursor-pointer select-none" x-show="mode==='credit'" onclick="toggleSection('sLateInterest','cLateInterest')">
        <svg id="cLateInterest" class="w-4 h-4 text-slate-400 transition-transform -rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
        <h3 class="text-sm font-semibold text-slate-800">Late Interest</h3>
    </div>
    <div id="sLateInterest" style="display:none" x-show="mode==='credit'" x-data="{active:<?= $term['late_interest_active'] ? 'true' : 'false' ?>}">
        <div class="p-6 max-w-xl space-y-4">

            <!-- Activate -->
            <div class="grid grid-cols-[140px_1fr] items-center gap-4">
                <label class="text-sm text-slate-600 text-right">Activate</label>
                <div class="flex items-center gap-3">
                    <button type="button" @click="active=!active"
                            class="relative w-10 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                            :class="active?'bg-indigo-500':'bg-slate-200'">
                        <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                             :class="active?'translate-x-5':''"></div>
                    </button>
                    <span class="text-sm text-slate-500" x-text="active ? 'Late interest is enabled' : 'Late interest is disabled'"></span>
                    <input type="hidden" name="late_interest_active" :value="active?1:0">
                </div>
            </div>

            <!-- Interest Rate -->
            <div class="grid grid-cols-[140px_1fr] items-center gap-4" x-show="active">
                <label class="text-sm text-slate-600 text-right">Interest Rate</label>
                <div class="flex gap-3 items-center">
                    <div class="flex">
                        <input type="number" name="late_interest_rate" min="0" step="0.01"
                               value="<?= e($term['late_interest_rate'] ?? '') ?>"
                               placeholder="3"
                               class="h-9 w-24 border border-slate-300 rounded-l-lg px-3 text-sm text-right focus:outline-none focus:border-indigo-500 transition no-spin">
                        <span class="inline-flex items-center px-3 border border-l-0 border-slate-300 bg-slate-50 rounded-r-lg text-sm text-slate-500">%</span>
                    </div>
                    <span class="text-xs text-slate-400">Interest rate per annum, calculated on daily basis.</span>
                </div>
            </div>

        </div>
    </div>

</div><!-- /card -->

<!-- Sticky footer -->
<div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-end gap-3 px-8 py-3" style="left:224px">
    <a href="payment_terms.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</a>
    <button type="submit" form="ptForm" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">
        <?= $isEdit ? 'Update' : 'Save' ?>
    </button>
</div>
</form>

<script>
// Type dropdown data function — avoids inline JSON quoting issues
var PT_TYPE_OPTIONS = <?= json_encode(array_map(function($v,$l){ return ['value'=>$v,'text'=>$l]; }, array_keys($typeOptions), array_values($typeOptions)), JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
var PT_TYPE_HINTS   = <?= json_encode($typeDescriptions, JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
var PT_TYPE_INIT    = <?= json_encode($term['type'] ?? 'days', JSON_HEX_TAG|JSON_HEX_QUOT) ?>;

function ptTypeDropdown() {
    return {
        open:    false,
        value:   PT_TYPE_INIT,
        options: PT_TYPE_OPTIONS,
        updateTypeHint: function(v) {
            var h = document.getElementById('typeHint');
            if (h) h.textContent = PT_TYPE_HINTS[v] || '';
        }
    };
}

// Section collapse
function toggleSection(sectionId, chevronId) {
    var s = document.getElementById(sectionId);
    var c = document.getElementById(chevronId);
    var hidden = s.style.display === 'none';
    s.style.display = hidden ? '' : 'none';
    c.style.transform = hidden ? '' : 'rotate(-90deg)';
}


</script>

<!-- No-spin CSS -->
<style>
.no-spin::-webkit-outer-spin-button,
.no-spin::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.no-spin { -moz-appearance:textfield; }
</style>

<?php layoutClose(); endif; ?>
