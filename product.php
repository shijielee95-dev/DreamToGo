<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';
$pid    = (int)($_GET['id'] ?? 0);

// ── Sales tax options ──────────────────────────────────────────────
// Load tax rates from DB
try {
    $_taxRows = $pdo->query("SELECT id, name, rate FROM tax_rates ORDER BY is_default DESC, name")->fetchAll();
    $taxOptions = ['' => '— None —'];
    foreach ($_taxRows as $_t) {
        $taxOptions[(string)$_t['id']] = e($_t['name']).' ('.number_format((float)$_t['rate'],2).'%)';
    }
} catch (Exception $e) {
    $taxOptions = ['' => '— None —'];
}

// ── Load product for edit ──────────────────────────────────────────
$product = [
    'id'=>'','name'=>'','sku'=>'','barcode'=>'','image_path'=>'',
    'classification_code'=>'',
    'track_inventory'=>1,'low_stock_level'=>'',
    'selling'=>1,'sale_price'=>'','sales_tax'=>'','sale_description'=>'',
    'buying'=>1,'purchase_price'=>'','purchase_description'=>'',
    'base_unit_label'=>'unit','multiple_uoms'=>0,
    'remarks'=>'',
];
$product_uoms = [];

if ($action === 'edit' && $pid > 0) {
    $row = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $row->execute([$pid]);
    $row = $row->fetch();
    if (!$row) { flash('error','Product not found.'); redirect('product.php'); }
    $product = array_merge($product, $row);
    $s = $pdo->prepare("SELECT * FROM product_uoms WHERE product_id=? ORDER BY id");
    $s->execute([$pid]); $product_uoms = $s->fetchAll();
}

// ── List ───────────────────────────────────────────────────────────
if ($action === 'list'):
    $search = trim($_GET['search'] ?? '');
    $where = $search ? "WHERE name LIKE ? OR sku LIKE ?" : "";
    $params = $search ? ["%$search%","%$search%"] : [];
    $products = $pdo->prepare("SELECT * FROM products $where ORDER BY name");
    $products->execute($params);
    $products = $products->fetchAll();

    layoutOpen('Products','Manage your products and services.');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="product.php?action=new" class="<?= t('btn_base').' '.t('btn_primary') ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New Product</a>';
</script>

<!-- Search bar -->
<form method="get" class="mb-4 flex gap-2">
    <input type="hidden" name="action" value="list">
    <input type="text" name="search" value="<?= e($search) ?>"
           placeholder="Search by name or SKU…"
           class="<?= t('input') ?>">
    <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Search</button>
    <?php if ($search): ?>
    <a href="product.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Clear</a>
    <?php endif; ?>
</form>

<div class="<?= t('table_wrap') ?>">
    <table class="w-full text-sm" style="table-layout:fixed">
        <thead>
            <tr>
                <th class="<?= t('th') ?>">Name</th>
                <th class="<?= t('th') ?>">SKU / Code</th>
                <th class="<?= t('th') ?>">Sale Price</th>
                <th class="<?= t('th') ?>">Purchase Price</th>
                <th class="<?= t('th') ?> text-center">Selling</th>
                <th class="<?= t('th') ?> text-center">Buying</th>
                <th class="<?= t('th') ?> text-center">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
            <tr><td colspan="7" class="px-4 py-12 text-center text-slate-400">
                No products yet. <a href="product.php?action=new" class="text-indigo-600 hover:underline">Add one</a>.
            </td></tr>
            <?php else: ?>
            <?php foreach ($products as $p): ?>
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="<?= t('td') ?> font-medium">
                    <?= e($p['name']) ?>
                    <?php if ($p['sku']): ?><span class="ml-1 text-xs text-slate-400"><?= e($p['sku']) ?></span><?php endif; ?>
                </td>
                <td class="<?= t('td') ?> font-mono text-slate-500"><?= e($p['sku']) ?></td>
                <td class="<?= t('td') ?>"><?= $p['sale_price'] !== '' ? 'MYR '.number_format((float)$p['sale_price'],2) : '—' ?></td>
                <td class="<?= t('td') ?>"><?= $p['purchase_price'] !== '' ? 'MYR '.number_format((float)$p['purchase_price'],2) : '—' ?></td>
                <td class="<?= t('td') ?> text-center">
                    <?= $p['selling'] ? '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-green-50 text-green-700">Yes</span>' : '<span class="text-slate-300">—</span>' ?>
                </td>
                <td class="<?= t('td') ?> text-center">
                    <?= $p['buying'] ? '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-blue-50 text-blue-700">Yes</span>' : '<span class="text-slate-300">—</span>' ?>
                </td>
                <td class="<?= t('td') ?> text-center">
                    <div class="flex items-center justify-center gap-2">
                        <a href="product.php?action=edit&id=<?= $p['id'] ?>"
                           class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-7 text-xs px-3">Edit</a>
                        <button type="button" onclick="confirmDelete(<?= $p['id'] ?>,'<?= e(addslashes($p['name'])) ?>')"
                                class="<?= t('btn_base') ?> h-7 text-xs px-3 bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 rounded-lg">Delete</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Delete modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelete()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl shadow-2xl p-6 mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Product</h3>
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
    fetch('product_save.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'delete=1&id='+_delId
    }).then(r=>r.json()).then(d=>{
        closeDelete();
        if (d.success) { showToast('Product deleted.',true); setTimeout(()=>location.reload(),600); }
        else showToast(d.message||'Failed.',false);
    }).catch(()=>{ closeDelete(); showToast('Server error.',false); });
});
</script>

<?php layoutClose();

// ── New / Edit ────────────────────────────────────────────────────
else:
    $isEdit   = $action === 'edit' && $pid > 0;
    $pageTitle = $isEdit ? 'Edit Product' : 'New Product';

    layoutOpen($pageTitle, $isEdit ? e($product['name']) : 'Fill in the details below');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="product.php" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9">← Back</a>';
</script>

<form id="productForm" method="post" action="product_save.php" enctype="multipart/form-data">
<?php if ($isEdit): ?>
<input type="hidden" name="id" value="<?= $pid ?>">
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 mb-24">

    <!-- ── Basic Information ── -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Basic Information</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Core product details.</p>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?= t('label') ?>">Name <span class="text-red-400">*</span></label>
                    <input type="text" name="name" required value="<?= e($product['name']) ?>"
                           placeholder="Hansaplast 100 Strips" class="<?= t('input') ?>">
                </div>
                <div>
                    <label class="<?= t('label') ?>">SKU / Code
                    </label>
                    <input type="text" name="sku" value="<?= e($product['sku']) ?>"
                           placeholder="900324719A" class="<?= t('input') ?>">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?= t('label') ?>">Barcode</label>
                    <input type="text" name="barcode" value="<?= e($product['barcode']) ?>"
                           placeholder="0799439112766" class="<?= t('input') ?>">
                </div>
                <div></div>
            </div>
            <div>
                <label class="<?= t('label') ?>">Product Image
                </label>
                <div class="flex items-start gap-4">
                    <!-- Saved image (edit mode) -->
                    <?php if (!empty($product['image_path'])): ?>
                    <a href="<?= e($product['image_path']) ?>" target="_blank" rel="noopener"
                       class="w-20 h-20 rounded-lg border border-slate-200 overflow-hidden shrink-0 group relative block">
                        <img src="<?= e($product['image_path']) ?>" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </div>
                    </a>
                    <?php endif; ?>
                    <!-- New image preview slot (shown after file selected) -->
                    <a id="imageNewPreview" href="#" target="_blank" rel="noopener" style="display:none"
                       class="w-20 h-20 rounded-lg border border-slate-200 overflow-hidden shrink-0 group relative block">
                        <img id="imageNewPreviewImg" class="w-full h-full object-cover" src="">
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </div>
                    </a>
                    <!-- Upload button -->
                    <div class="flex flex-col gap-1.5">
                        <label class="w-20 h-20 flex flex-col items-center justify-center gap-1 border-2 border-dashed border-slate-300 rounded-lg cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/30 transition-colors text-slate-400 hover:text-indigo-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                            <span class="text-xs font-medium">Upload</span>
                            <input type="file" name="image" accept="image/*" class="hidden" onchange="showImagePreview(this)">
                        </label>
                        <span id="imageFileName" class="text-xs text-slate-400 truncate max-w-[80px]"></span>
                    </div>
                </div>
                <script>
                function showImagePreview(input) {
                    if (!input.files || !input.files[0]) return;
                    var file = input.files[0];
                    document.getElementById('imageFileName').textContent = file.name;
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var preview = document.getElementById('imageNewPreview');
                        var img     = document.getElementById('imageNewPreviewImg');
                        preview.href = e.target.result;
                        img.src      = e.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
                </script>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ── Classification Code ── -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Classification Code</h3>
            <p class="text-xs text-slate-400 leading-relaxed">LHDN e-Invoice classification.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">
            <div>
            <label class="<?= t('label') ?>">Classification Code</label>
            <div class="relative" x-data="lhdnProdComp('<?= e($product['classification_code']) ?>')">
                <input type="text" x-ref="inp"
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
                       class="<?= t('input') ?> pr-14">
                <div class="absolute right-0 top-0 h-full flex items-center gap-0.5 pr-2">
                    <svg class="w-4 h-4 text-slate-400 pointer-events-none transition-transform" :class="open?'rotate-180':''"
                         fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                </div>
                <div x-show="open" @mousedown.prevent style="display:none"
                     class="absolute z-[9996] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
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
                <input type="hidden" name="classification_code" :value="value">
            </div>
            </div><!-- /col -->
            <div></div><!-- empty right col -->
            </div><!-- /grid -->
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ── Inventory Tracking ── -->
    <div class="grid grid-cols-[200px_1fr]" x-data="{trackInv: <?= $product['track_inventory'] ? 'true' : 'false' ?>}">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Inventory Tracking</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Track stock levels for this product.</p>
        </div>
        <div class="p-6">
            <div class="flex items-center gap-6">
                <div class="flex items-center gap-3">
                    <button type="button" @click="trackInv=!trackInv"
                            class="relative w-10 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                            :class="trackInv?'bg-indigo-500':'bg-slate-200'">
                        <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                             :class="trackInv?'translate-x-5':''"></div>
                    </button>
                    <span class="text-sm text-slate-600">Track Inventory</span>
                    <input type="hidden" name="track_inventory" :value="trackInv?1:0">
                </div>
                <div x-show="trackInv" x-transition class="flex items-center gap-3">
                    <label class="text-sm text-slate-600 whitespace-nowrap">Low Stock Level</label>
                    <input type="number" name="low_stock_level" min="0"
                           value="<?= e($product['low_stock_level']) ?>"
                           placeholder="0"
                           class="h-9 w-36 border border-slate-300 rounded-lg px-3 text-sm text-black bg-white focus:outline-none focus:border-indigo-500 transition">
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ── Sale Information ── -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Sale Information</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Details when selling this product.</p>
        </div>
        <div class="p-6 space-y-4">
            <input type="hidden" name="selling" value="1">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?= t('label') ?>">Sale Price</label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 text-sm text-slate-500 font-medium">MYR</span>
                        <input type="number" name="sale_price" min="0" step="0.01"
                               value="<?= e($product['sale_price']) ?>"
                               placeholder="0.00"
                               class="h-9 flex-1 border border-slate-300 rounded-r-lg px-3 text-sm text-black bg-white focus:outline-none focus:border-indigo-500 transition">
                    </div>
                </div>
                <div>
                    <label class="<?= t('label') ?>">Sales Tax</label>
                    <?php renderDropdown('sales_tax', $taxOptions, $product['sales_tax']); ?>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="<?= t('label') ?>">Description</label>
                    <textarea name="sale_description" rows="3" placeholder="Description"
                              class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($product['sale_description']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ── Purchase Information ── -->
    <div class="grid grid-cols-[200px_1fr]" x-data="{buying: <?= $product['buying'] ? 'true' : 'false' ?>}">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Purchase Information</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Details when buying this product.</p>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex items-center gap-3">
                <button type="button" @click="buying=!buying"
                        class="relative w-10 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                        :class="buying?'bg-indigo-500':'bg-slate-200'">
                    <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                         :class="buying?'translate-x-5':''"></div>
                </button>
                <span class="text-sm text-slate-600">I'm Buying</span>
                <input type="hidden" name="buying" :value="buying?1:0">
            </div>
            <div x-show="buying" x-transition>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="<?= t('label') ?>">Purchase Price</label>
                        <div class="flex">
                            <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 text-sm text-slate-500 font-medium">MYR</span>
                            <input type="number" name="purchase_price" min="0" step="0.01"
                                   value="<?= e($product['purchase_price']) ?>"
                                   placeholder="0.00"
                                   class="h-9 flex-1 border border-slate-300 rounded-r-lg px-3 text-sm text-black bg-white focus:outline-none focus:border-indigo-500 transition">
                        </div>
                    </div>
                    <div></div>
                    <div class="col-span-2">
                        <label class="<?= t('label') ?>">Description</label>
                        <textarea name="purchase_description" rows="3" placeholder="Description"
                                  class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($product['purchase_description']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ── Unit of Measurements ── -->
    <div class="grid grid-cols-[200px_1fr]" x-data="{multiUom: <?= $product['multiple_uoms'] ? 'true' : 'false' ?>}">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Unit of Measurements</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Define how this product is measured and sold.</p>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?= t('label') ?>">Base Unit Label <span class="text-red-400">*</span></label>
                    <input type="text" name="base_unit_label" required
                           value="<?= e($product['base_unit_label'] ?: 'unit') ?>"
                           placeholder="unit" class="<?= t('input') ?>">
                </div>
                <div></div>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" @click="multiUom=!multiUom"
                        class="relative w-10 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                        :class="multiUom?'bg-indigo-500':'bg-slate-200'">
                    <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                         :class="multiUom?'translate-x-5':''"></div>
                </button>
                <span class="text-sm text-slate-600">Multiple UOMs
                </span>
                <input type="hidden" name="multiple_uoms" :value="multiUom?1:0">
            </div>

            <!-- UOM Table -->
            <div x-show="multiUom" x-transition>
                <div class="<?= t('table_wrap') ?> mb-3">
                    <table class="w-full text-sm" style="table-layout:fixed">
                        <colgroup>
                            <col style="width:4%">
                            <col style="width:22%">
                            <col style="width:12%">
                            <col style="width:18%">
                            <col style="width:18%">
                            <col style="width:22%">
                            <col style="width:4%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="<?= t('th') ?>">#</th>
                                <th class="<?= t('th') ?>">Label</th>
                                <th class="<?= t('th') ?>">Rate</th>
                                <th class="<?= t('th') ?>">Sale Price</th>
                                <th class="<?= t('th') ?>">Purchase Price</th>
                                <th class="<?= t('th') ?>">Default UOM</th>
                                <th class="<?= t('th') ?>"></th>
                            </tr>
                        </thead>
                        <tbody id="uomBody">
                            <!-- base row (always row 1) -->
                            <tr>
                                <td class="<?= t('td') ?> text-slate-400">1</td>
                                <td class="<?= t('td') ?>" style="overflow:hidden">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="font-medium text-slate-700 truncate" id="uomBaseLabel"><?= e($product['base_unit_label'] ?: 'unit') ?></span>
                                        <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-slate-100 text-slate-500 shrink-0">Base</span>
                                    </div>
                                </td>
                                <td class="<?= t('td') ?>">1.00</td>
                                <td class="<?= t('td') ?>">
                                    <span id="uomBaseSalePrice" class="text-slate-400">—</span>
                                </td>
                                <td class="<?= t('td') ?>">
                                    <span id="uomBasePurchasePrice" class="text-slate-400">—</span>
                                </td>
                                <td class="<?= t('td') ?>">
                                    <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer">
                                        <input type="checkbox" name="uom_base_default_sales" value="1" <?= !empty($product['uom_base_default_sales']) ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600"> Sales
                                    </label>
                                    <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer mt-0.5">
                                        <input type="checkbox" name="uom_base_default_purchase" value="1" <?= !empty($product['uom_base_default_purchase']) ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600"> Purchases
                                    </label>
                                </td>
                                <td class="<?= t('td') ?>"></td>
                            </tr>
                            <?php foreach ($product_uoms as $idx => $uom): ?>
                            <tr class="uom-row">
                                <td class="<?= t('td') ?> text-slate-400"><?= $idx + 2 ?></td>
                                <td class="<?= t('td') ?>">
                                    <input type="text" name="uoms[<?= $idx ?>][label]" value="<?= e($uom['label']) ?>" required
                                           placeholder="e.g. box" class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition">
                                </td>
                                <td class="<?= t('td') ?>">
                                    <input type="number" name="uoms[<?= $idx ?>][rate]" value="<?= e($uom['rate']) ?>" min="0.0001" step="0.0001" required
                                           placeholder="1.00" class="w-24 h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition">
                                </td>
                                <td class="<?= t('td') ?>">
                                    <input type="number" name="uoms[<?= $idx ?>][sale_price]" value="<?= e($uom['sale_price']) ?>" min="0" step="0.01"
                                           placeholder="—" class="w-24 h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition">
                                </td>
                                <td class="<?= t('td') ?>">
                                    <input type="number" name="uoms[<?= $idx ?>][purchase_price]" value="<?= e($uom['purchase_price']) ?>" min="0" step="0.01"
                                           placeholder="—" class="w-24 h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition">
                                </td>
                                <td class="<?= t('td') ?>">
                                    <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer">
                                        <input type="checkbox" name="uoms[<?= $idx ?>][default_sales]" value="1" <?= !empty($uom['default_sales']) ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600"> Sales
                                    </label>
                                    <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer mt-0.5">
                                        <input type="checkbox" name="uoms[<?= $idx ?>][default_purchase]" value="1" <?= !empty($uom['default_purchase']) ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600"> Purchases
                                    </label>
                                </td>
                                <td class="<?= t('td') ?>">
                                    <button type="button" onclick="removeUom(this)"
                                            class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="addUom()"
                            class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-8 text-xs">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                        + UOM
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ── Other Information ── -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Other Information</h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="<?= t('label') ?>">Remarks</label>
                    <textarea name="remarks" rows="3" placeholder="Remarks"
                              class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($product['remarks']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

</div><!-- /card -->

<!-- Sticky footer -->
<div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-end gap-3 px-8 py-3" style="left:224px">
    <a href="product.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</a>
    <button type="submit" form="productForm" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">
        <?= $isEdit ? 'Update Product' : 'Save Product' ?>
    </button>
</div>

</form>

<script>
// Sync base label live
// Sync base UOM sale/purchase price display
function syncUomBasePrices() {
    var sp = parseFloat(document.querySelector('[name="sale_price"]')?.value) || 0;
    var pp = parseFloat(document.querySelector('[name="purchase_price"]')?.value) || 0;
    var spEl = document.getElementById('uomBaseSalePrice');
    var ppEl = document.getElementById('uomBasePurchasePrice');
    if (spEl) spEl.textContent = sp > 0 ? sp.toFixed(2) : '—';
    if (ppEl) ppEl.textContent = pp > 0 ? pp.toFixed(2) : '—';
    // Update class
    if (spEl) spEl.className = sp > 0 ? 'text-slate-700 font-medium' : 'text-slate-400';
    if (ppEl) ppEl.className = pp > 0 ? 'text-slate-700 font-medium' : 'text-slate-400';
}
// Listen to sale/purchase price inputs
document.querySelector('[name="sale_price"]')?.addEventListener('input', syncUomBasePrices);
document.querySelector('[name="purchase_price"]')?.addEventListener('input', syncUomBasePrices);
// Also run on page load
syncUomBasePrices();

document.querySelector('[name="base_unit_label"]').addEventListener('input', function() {
    document.getElementById('uomBaseLabel').textContent = this.value || 'unit';
});

// UOM rows
var uomIdx = <?= max(count($product_uoms), 0) ?>;
function addUom() {
    var i = uomIdx++;
    var tbody = document.getElementById('uomBody');
    var rowCount = tbody.querySelectorAll('tr').length + 1;
    var tr = document.createElement('tr');
    tr.className = 'uom-row';
    tr.innerHTML =
        '<td class="<?= t('td') ?> text-slate-400">' + rowCount + '</td>'+
        '<td class="<?= t('td') ?>"><input type="text" name="uoms['+i+'][label]" required placeholder="e.g. box" class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500 transition"></td>'+
        '<td class="<?= t('td') ?>"><input type="number" name="uoms['+i+'][rate]" min="0.0001" step="0.0001" value="1.00" required placeholder="1.00" class="w-24 h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition"></td>'+
        '<td class="<?= t('td') ?>"><input type="number" name="uoms['+i+'][sale_price]" min="0" step="0.01" placeholder="—" class="w-24 h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition"></td>'+
        '<td class="<?= t('td') ?>"><input type="number" name="uoms['+i+'][purchase_price]" min="0" step="0.01" placeholder="—" class="w-24 h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right focus:outline-none focus:border-indigo-500 transition"></td>'+
        '<td class="<?= t('td') ?>">'+
            '<label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer"><input type="checkbox" name="uoms['+i+'][default_sales]" value="1" class="rounded border-slate-300 text-indigo-600"> Sales</label>'+
            '<label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer mt-0.5"><input type="checkbox" name="uoms['+i+'][default_purchase]" value="1" class="rounded border-slate-300 text-indigo-600"> Purchases</label>'+
        '</td>'+
        '<td class="<?= t('td') ?>"><button type="button" onclick="removeUom(this)" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg></button></td>';
    tbody.appendChild(tr);
    renumberUoms();
}
function removeUom(btn) {
    btn.closest('tr').remove();
    renumberUoms();
}
function renumberUoms() {
    document.querySelectorAll('#uomBody tr').forEach(function(tr, i) {
        var numCell = tr.querySelector('td:first-child');
        if (numCell) numCell.textContent = i + 1;
    });
}

// LHDN classification for product page
var LHDN_CODES_PROD = typeof LHDN_CODES !== 'undefined' ? LHDN_CODES : <?php
    $lhdnDesc = ['001'=>'Breastfeeding equipment','002'=>'Child care centres and kindergartens fees','003'=>'Computer, smartphone or tablet','004'=>'Consolidated e-Invoice','005'=>'Construction materials','006'=>'Disbursement','007'=>'Donation','008'=>'e-Commerce - e-Invoice to buyer/purchaser','009'=>'e-Commerce - Self-billed e-Invoice','010'=>'Education fees','011'=>'Goods on consignment (Consignor)','012'=>'Goods on consignment (Consignee)','013'=>'Gym membership','014'=>'Insurance - Education and medical benefits','015'=>'Insurance - Takaful or life insurance','016'=>'Interest and financing expenses','017'=>'Internet subscription','018'=>'Land and building','019'=>'Medical examination for learning disabilities','020'=>'Medical examination or vaccination expenses','021'=>'Medical expenses for serious diseases','022'=>'Others','023'=>'Petroleum operations','024'=>'Private retirement scheme','025'=>'Motor vehicle','026'=>'Subscription of books/journals/magazines','027'=>'Reimbursement','028'=>'Rental of motor vehicle','029'=>'EV charging facilities','030'=>'Repair and maintenance','031'=>'Research and development','032'=>'Foreign income','033'=>'Self-billed - Betting and gaming','034'=>'Self-billed - Importation of goods','035'=>'Self-billed - Importation of services','036'=>'Self-billed - Others','037'=>'Self-billed - Monetary payment to agents','038'=>'Sports equipment and facilities','039'=>'Supporting equipment for disabled person','040'=>'Voluntary contribution to approved provident fund','041'=>'Dental examination or treatment','042'=>'Fertility treatment','043'=>'Treatment and home care nursing','044'=>'Vouchers, gift cards, loyalty points','045'=>'Self-billed - Non-monetary payment to agents'];
    $lhdnArr = array_map(fn($v,$l)=>['v'=>$v,'l'=>$v.' - '.$l], array_keys($lhdnDesc), $lhdnDesc);
    echo json_encode($lhdnArr, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
?>;

function lhdnProdComp(initialCode) {
    var all = [{v:'', l:'— No classification —'}].concat(LHDN_CODES_PROD.filter(function(o){ return o.v; }));
    var def = all.find(function(o){ return o.v === initialCode; }) || all[0];
    return {
        value: def.v, q: '', open: false, activeIdx: -1,
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
            if (this.$refs.inp) this.$refs.inp.blur();
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
</script>

<?php layoutClose(); endif; ?>
