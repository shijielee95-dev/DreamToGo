<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';   // list | new | edit
$cid    = (int)($_GET['id'] ?? 0);

// ── Shared: load customer for edit ────────────────────────────────
$customer = [
    'id'=>'', 'customer_name'=>'', 'tin'=>'', 'id_type'=>'BRN',
    'reg_no'=>'', 'sst_reg_no'=>'', 'email'=>'', 'phone'=>'',
    'address_line_0'=>'', 'address_line_1'=>'', 'city'=>'',
    'postal_code'=>'', 'state_code'=>'', 'country_code'=>'MYS', 'remarks'=>'',
    'other_name'=>'', 'old_reg_no'=>'',
    'currency'=>'MYR', 'einvoice_control'=>'individual', 'credit_limit'=>'',
    'default_payment_mode'=>'cash', 'payment_term_id'=>'',
];
$db_contact_persons = $db_contact_addresses = $db_emails = $db_phones = [];

// Load payment terms grouped by payment_mode for the combobox
$allPaymentTerms = $pdo->query("SELECT id, name, payment_mode FROM payment_terms ORDER BY name")->fetchAll();
$ptByCash   = array_values(array_filter($allPaymentTerms, function($r){ return $r['payment_mode'] === 'cash';   }));
$ptByCredit = array_values(array_filter($allPaymentTerms, function($r){ return $r['payment_mode'] === 'credit'; }));

if ($action === 'edit' && $cid > 0) {
    $row = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $row->execute([$cid]);
    $row = $row->fetch();
    if (!$row) { flash('error', 'Customer not found.'); redirect('customer.php'); }
    $customer = array_merge($customer, $row);

    $s = $pdo->prepare("SELECT * FROM customer_contact_persons WHERE customer_id=? ORDER BY id");
    $s->execute([$cid]); $db_contact_persons = $s->fetchAll();

    $s = $pdo->prepare("SELECT * FROM customer_contact_addresses WHERE customer_id=? ORDER BY id");
    $s->execute([$cid]); $db_contact_addresses = $s->fetchAll();

    $s = $pdo->prepare("SELECT email FROM customer_emails WHERE customer_id=? ORDER BY id");
    $s->execute([$cid]); $db_emails = array_column($s->fetchAll(), 'email');

    $s = $pdo->prepare("SELECT country_code, phone_number FROM customer_phones WHERE customer_id=? ORDER BY id");
    $s->execute([$cid]); $db_phones = $s->fetchAll();

    // Attachments
    try {
        $aStmt = $pdo->prepare("SELECT * FROM customer_attachments WHERE customer_id=? ORDER BY uploaded_at");
        $aStmt->execute([$cid]);
        $existingAttachments = $aStmt->fetchAll();
    } catch (Exception $e) { $existingAttachments = []; }
} else {
    $existingAttachments = [];
}

// ── Page title ────────────────────────────────────────────────────
if ($action === 'new') {
    $pageTitle = 'New Customer';
    $pageSub   = 'Fill in customer details';
} elseif ($action === 'edit') {
    $pageTitle = 'Edit Customer';
    $pageSub   = e($customer['customer_name']);
} else {
    $total     = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $pageTitle = 'Customers';
    $pageSub   = $total . ' total';
}

layoutOpen($pageTitle, $pageSub);

// ══════════════════════════════════════════════════════════════════
// VIEW: LIST
// ══════════════════════════════════════════════════════════════════
if ($action === 'list'):

$customers = $pdo->query("SELECT id, customer_name, tin, sst_reg_no FROM customers ORDER BY customer_name ASC")->fetchAll();
?>

<?php $btnNewCust = t('btn_base') . ' ' . t('btn_primary'); ?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="customer.php?action=new" class="<?= $btnNewCust ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New Customer</a>';
</script>

<!-- Search -->
<div class="mb-4" x-data="customerSearch()">
    <div class="relative max-w-sm">
        <input type="text" placeholder="Search customers..."
               x-model="query" @input.debounce.300ms="search()"
               class="<?= t('input') ?> pl-9" autocomplete="new-password">
        <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none"
             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
        </svg>
        <ul x-show="results.length > 0" x-transition @click.outside="results=[]"
            class="absolute z-30 w-full bg-white border border-slate-200 mt-1 rounded-xl max-h-60 overflow-y-auto shadow-lg">
            <template x-for="item in results" :key="item.id">
                <li class="flex items-center justify-between px-4 py-2.5 hover:bg-indigo-50 cursor-pointer"
                    @click="window.location.href='customer.php?action=edit&id='+item.id">
                    <span class="text-sm font-medium text-slate-800 truncate" x-text="item.customer_name"></span>
                    <span class="text-xs text-slate-400 font-mono shrink-0 ml-3" x-text="item.tin"></span>
                </li>
            </template>
        </ul>
    </div>
</div>

<!-- Table -->
<div class="<?= t('table_wrap') ?>">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="<?= t('th') ?>">Customer Name</th>
                <th class="<?= t('th') ?>">TIN</th>
                <th class="<?= t('th') ?>">SST Reg No</th>
                <th class="<?= t('th') ?> text-center">Actions</th>
            </tr>
        </thead>
        <tbody id="customerTableBody" class="divide-y divide-slate-100">
            <?php if (empty($customers)): ?>
            <tr>
                <td colspan="4" class="px-4 py-12 text-center text-slate-400 text-sm">
                    No customers yet. Click <strong class="text-slate-600">New Customer</strong> to add one.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($customers as $c): ?>
            <tr class="hover:bg-slate-50 transition-colors" id="row-<?= $c['id'] ?>">
                <td class="<?= t('td') ?> font-medium text-slate-800"><?= e($c['customer_name']) ?></td>
                <td class="<?= t('td') ?> font-mono text-xs uppercase"><?= e($c['tin'] ?: '—') ?></td>
                <td class="<?= t('td') ?>"><?= e($c['sst_reg_no'] ?: '—') ?></td>
                <td class="<?= t('td') ?> text-center">
                    <div class="flex items-center justify-center gap-2">
                        <a href="customer.php?action=edit&id=<?= $c['id'] ?>"
                           class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-7 text-xs px-3">Edit</a>
                        <button type="button"
                                onclick="confirmDelete(<?= $c['id'] ?>, '<?= e(addslashes($c['customer_name'])) ?>')"
                                class="<?= t('btn_base') ?> h-7 text-xs px-3 bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 rounded-lg">
                            Delete
                        </button>
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
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl shadow-2xl p-6 mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Customer</h3>
                <p class="text-xs text-slate-400 mt-0.5">This action cannot be undone.</p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Delete <strong id="deleteCustomerName" class="text-slate-900"></strong>?</p>
        <div class="flex gap-2 justify-end">
            <button onclick="closeDeleteModal()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button id="confirmDeleteBtn" class="<?= t('btn_base') ?> <?= t('btn_danger') ?> h-9">Delete</button>
        </div>
    </div>
</div>

<script>
function customerSearch() {
    return {
        query: '', results: [],
        search() {
            if (!this.query.trim()) { this.results = []; return; }
            fetch('customer_search.php?keyword=' + encodeURIComponent(this.query))
                .then(function(r){ return r.json(); })
                .then(function(d){ this.results = d; }.bind(this))
                .catch(function(){ this.results = []; }.bind(this));
        }
    };
}

var _deleteId = null;
function confirmDelete(id, name) {
    _deleteId = id;
    document.getElementById('deleteCustomerName').textContent = name;
    var m = document.getElementById('deleteModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeDeleteModal() {
    _deleteId = null;
    var m = document.getElementById('deleteModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!_deleteId) return;
    fetch('customer_delete.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + _deleteId
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        closeDeleteModal();
        if (data.success) {
            var row = document.getElementById('row-' + _deleteId);
            if (row) row.remove();
            var tbody = document.getElementById('customerTableBody');
            if (tbody && !tbody.querySelector('tr[id]')) {
                tbody.innerHTML = '<tr><td colspan="4" class="px-4 py-12 text-center text-slate-400 text-sm">No customers yet.</td></tr>';
            }
            showToast('Customer deleted.', true);
        } else {
            showToast(data.message || 'Failed to delete.', false);
        }
    })
    .catch(function(){ closeDeleteModal(); showToast('Server error.', false); });
});
</script>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: NEW / EDIT  (shared form)
// ══════════════════════════════════════════════════════════════════
else: // action === 'new' or 'edit'

$isEdit = ($action === 'edit');
$idOpts = ['NRIC'=>'NRIC','BRN'=>'BRN','ARMY'=>'Army No','PASSPORT'=>'Passport No','NA'=>'N/A'];
$idVal  = $customer['id_type'] ?? 'BRN';
$idLbl  = $idOpts[$idVal] ?? 'BRN';
?>

<?php
$btnCancel = t('btn_base') . ' ' . t('btn_ghost');
$btnSave   = t('btn_base') . ' ' . t('btn_primary');
$btnLabel  = $isEdit ? 'Save Changes' : 'Create Customer';
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="customer.php" class="<?= $btnCancel ?> h-9">Cancel</a>' +
    '<button type="button" onclick="submitCustomer()" class="<?= $btnSave ?> h-9"><?= $btnLabel ?></button>';
</script>

<?php
$showSaved = isset($_GET['saved']) && $_GET['saved'] == '1';
$showError = isset($_GET['error']);
$errorType = $_GET['error'] ?? '';
?>
<?php if ($showSaved): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showToast('Customer saved successfully.', true);
    var url = new URL(window.location); url.searchParams.delete('saved'); history.replaceState({}, '', url);
});
</script>
<?php elseif ($showError): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var msgs = { name_required: 'Customer name is required.', save_failed: 'Failed to save. Please try again.' };
    showToast(msgs['<?= e($errorType) ?>'] || 'An error occurred.', false);
});
</script>
<?php endif; ?>

<form method="POST" action="customer_save.php" id="customerForm" enctype="multipart/form-data" autocomplete="off" novalidate>
<input type="hidden" name="id"            value="<?= e($customer['id']) ?>">
<input type="hidden" name="redirect_back" value="customer.php">
<input type="hidden" name="emails_json"   id="emails_json_input">
<input type="hidden" name="phones_json"        id="phones_json_input">
<input type="hidden" name="deleted_attachments" id="deleted_attachments_input" value="">

<div class="bg-white rounded-xl border border-slate-200 mb-6">

    <!-- ═══ Basic Information ═══ -->
    <div class="grid grid-cols-[340px_1fr]">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Basic Information</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Customer legal details for e-Invoice.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                <!-- Legal Name -->
                <div>
                    <label class="<?= t('label') ?>">Legal Name <span class="text-red-400">*</span></label>
                    <input type="text" name="cust_legalname" data-required="1"
                           value="<?= e($customer['customer_name']) ?>"
                           autocomplete="new-password" class="<?= t('input') ?> uppercase">
                </div>
                <!-- Other Name -->
                <div>
                    <label class="<?= t('label') ?>">Other Name</label>
                    <input type="text" name="cust_othername" value="<?= e($customer['other_name'] ?? '') ?>"
                           autocomplete="new-password" class="<?= t('input') ?>">
                </div>

                <!-- Registration No. Type — left col, full width -->
                <div class="w-full">
                    <label class="<?= t('label') ?>">Registration No. Type</label>
                    <div class="w-full">
                    <?php renderDropdown('id_type', [
                        'NA'       => 'None',
                        'BRN'      => 'BRN',
                        'NRIC'     => 'NRIC',
                        'PASSPORT' => 'Passport',
                        'ARMY'     => 'Army',
                    ], $customer['id_type'] ?? 'NA', 'Select...', false, 'w-full'); ?>
                    </div>
                </div>
                <div></div>

                <!-- Registration No -->
                <div>
                    <label class="<?= t('label') ?>">Registration No.</label>
                    <input type="text" name="cust_regNo" value="<?= e($customer['reg_no']) ?>"
                           autocomplete="new-password" class="<?= t('input') ?> uppercase">
                </div>
                <!-- Old Registration No -->
                <div>
                    <label class="<?= t('label') ?>">Old Registration No.</label>
                    <input type="text" name="cust_oldRegNo" value="<?= e($customer['old_reg_no'] ?? '') ?>"
                           autocomplete="new-password" class="<?= t('input') ?> uppercase">
                </div>

                <!-- TIN -->
                <div>
                    <label class="<?= t('label') ?>">TIN</label>
                    <input type="text" name="cust_tin" value="<?= e($customer['tin']) ?>"
                           autocomplete="new-password" class="<?= t('input') ?> uppercase">
                </div>
                <!-- SST Registration No -->
                <div>
                    <label class="<?= t('label') ?>">SST Registration No.</label>
                    <input type="text" name="cust_sstRegNo" value="<?= e($customer['sst_reg_no']) ?>"
                           autocomplete="new-password" class="<?= t('input') ?> uppercase">
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ═══ Contact Persons ═══ -->
    <div class="grid grid-cols-[340px_1fr]" x-data="contactPersonsComp()">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Contact Persons</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Contact persons in the organisation.</p>
        </div>
        <div class="p-6">
            <template x-for="(p, i) in persons" :key="i">
                <div class="pb-3 mb-3" :class="i>0?'border-t border-slate-100 pt-3':''">
                    <!-- Name row: First | Last | Delete -->
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <label class="<?= t('label') ?>">First Name</label>
                            <input type="text" :name="'contact_persons['+i+'][first_name]'"
                                   :id="'cp_first_'+i"
                                   x-model="p.first_name"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-1.5 cursor-pointer text-sm text-slate-600">
                                <input type="checkbox" :name="'contact_persons['+i+'][default_billing]'"
                                       :checked="p.default_billing" @change="setDefaultBilling(i)"
                                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
                                Default Billing
                            </label>
                        </div>
                        <div class="flex-1">
                            <label class="<?= t('label') ?>">Last Name</label>
                            <input type="text" :name="'contact_persons['+i+'][last_name]'"
                                   x-model="p.last_name"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-1.5 cursor-pointer text-sm text-slate-600">
                                <input type="checkbox" :name="'contact_persons['+i+'][default_shipping]'"
                                       :checked="p.default_shipping" @change="setDefaultShipping(i)"
                                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
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
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ═══ Contact Addresses ═══ -->
    <div class="grid grid-cols-[340px_1fr]" x-data="contactAddressesComp()">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Contact Addresses</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Billing and shipping addresses.</p>
        </div>
        <div class="p-6">
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
                            <input type="text" :name="'contact_addresses['+i+'][address_name]'" x-model="a.address_name"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">Street Address</label>
                            <input type="text" :name="'contact_addresses['+i+'][street_address]'" x-model="a.street_address"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">City</label>
                            <input type="text" :name="'contact_addresses['+i+'][city]'" x-model="a.city"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">Postcode</label>
                            <input type="text" :name="'contact_addresses['+i+'][postcode]'" x-model="a.postcode"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">State</label>
                            <input type="text" :name="'contact_addresses['+i+'][state]'" x-model="a.state"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-2 cursor-pointer text-xs text-slate-500">
                                <input type="checkbox" :name="'contact_addresses['+i+'][default_billing]'" x-model="a.default_billing" @change="setDefaultBilling(i)"
                                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
                                Default Billing
                            </label>
                        </div>
                        <div>
                            <label class="<?= t('label') ?>">Country</label>
                            <input type="text" :name="'contact_addresses['+i+'][country]'" x-model="a.country"
                                   autocomplete="new-password" class="<?= t('input') ?>">
                            <label class="flex items-center gap-2 mt-2 cursor-pointer text-xs text-slate-500">
                                <input type="checkbox" :name="'contact_addresses['+i+'][default_shipping]'" x-model="a.default_shipping" @change="setDefaultShipping(i)"
                                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
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
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ═══ Contact Information ═══ -->
    <div class="grid grid-cols-[340px_1fr]" x-data="contactInfoComp()" x-ref="contactInfoEl" id="contactInfoSection">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Contact Information</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Email addresses and phone numbers.</p>
        </div>
        <div class="p-6 grid grid-cols-2 gap-6">
            <!-- Emails -->
            <div>
                <label class="<?= t('label') ?>">Email Addresses</label>
                <input type="text" x-model="newEmail" autocomplete="new-password" @keydown.enter.prevent="addEmail()"
                       placeholder="Type email and press Enter"
                       autocomplete="new-password" class="<?= t('input') ?> mb-1">
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
                        <div class="relative shrink-0" x-data="phoneCountryDrop(firstPhone)" style="width:110px">
                            <button type="button" @click="open=!open" @keydown.escape="open=false"
                                    class="w-full h-9 border border-slate-200 rounded-lg px-2 text-sm font-medium text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400 transition flex items-center justify-between gap-1">
                                <span x-text="firstPhone.country_code"></span>
                                <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" @click.outside="open=false" style="display:none"
                                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                                 class="fixed z-50 bg-white border border-slate-200 rounded-xl shadow-xl"
                                 style="width:220px"
                                 x-init="$watch('open',function(v){if(v){var r=$el.previousElementSibling.getBoundingClientRect();$el.style.top=(r.bottom+4)+'px';$el.style.left=r.left+'px';q='';}})">
                                <div class="p-2 border-b border-slate-100">
                                    <input type="text" x-model="q" placeholder="Search country..."
                                           class="w-full h-7 border border-slate-200 rounded-lg px-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                </div>
                                <div class="max-h-48 overflow-y-auto py-1">
                                    <template x-for="cc in COUNTRY_CODES.filter(function(c){return !q||c.label.toLowerCase().includes(q.toLowerCase())||c.code.includes(q);})" :key="cc.code">
                                        <button type="button"
                                                @click="firstPhone.country_code=cc.code;open=false"
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
                               class="phone-number-input <?= t('input') ?>">
                    </div>
                    <template x-for="(ct, i) in contacts" :key="i">
                        <div class="flex gap-2 items-center">
                            <div class="relative shrink-0" x-data="phoneCountryDrop(ct)" style="width:110px">
                                <button type="button" @click="open=!open" @keydown.escape="open=false"
                                        class="w-full h-9 border border-slate-200 rounded-lg px-2 text-sm font-medium text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400 transition flex items-center justify-between gap-1">
                                    <span x-text="ct.country_code"></span>
                                    <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="open" @click.outside="open=false" style="display:none"
                                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                                     class="fixed z-50 bg-white border border-slate-200 rounded-xl shadow-xl"
                                     style="width:220px"
                                     x-init="$watch('open',function(v){if(v){var r=$el.previousElementSibling.getBoundingClientRect();$el.style.top=(r.bottom+4)+'px';$el.style.left=r.left+'px';q='';}})">
                                    <div class="p-2 border-b border-slate-100">
                                        <input type="text" x-model="q" placeholder="Search country..."
                                               class="w-full h-7 border border-slate-200 rounded-lg px-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                    </div>
                                    <div class="max-h-48 overflow-y-auto py-1">
                                        <template x-for="cc in COUNTRY_CODES.filter(function(c){return !q||c.label.toLowerCase().includes(q.toLowerCase())||c.code.includes(q);})" :key="cc.code">
                                            <button type="button"
                                                    @click="ct.country_code=cc.code;open=false"
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
                                   class="phone-number-input <?= t('input') ?>">
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

    <div class="border-t border-slate-100"></div>

    <!-- ═══ Default Settings ═══ -->
    <div class="grid grid-cols-[340px_1fr]">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Default Settings</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Override settings for contact during transaction creation.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-x-8 gap-y-4">

                <!-- Currency — invoice.php style: type-to-search combobox -->
                <?php $currVal = $customer['currency'] ?? 'MYR'; ?>
                <div>
                    <label class="<?= t('label') ?>">Currency</label>
                    <div id="custCurrencyDd" x-data="custCurrencyComp('<?= e($currVal) ?>')" class="relative">
                        <div class="relative">
                            <input type="text" id="custCurrencyInput"
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
                        <input type="hidden" name="cust_currency" :value="selected.code">
                    </div>
                </div>

                                <!-- e-Invoice Control -->
                <div class="w-full">
                    <label class="<?= t('label') ?>">e-Invoice Control</label>
                    <div class="w-full">
                    <?php renderDropdown('cust_einvoice_control', [
                        'consolidate' => 'Consolidate',
                        'individual'  => 'Individual',
                    ], $customer['einvoice_control'] ?? 'consolidate', 'Select...', false, 'w-full'); ?>
                    </div>
                </div>

                <!-- Credit Limit -->
                <div>
                    <label class="<?= t('label') ?>">Credit Limit</label>
                    <input type="text" name="cust_credit_limit"
                           value="<?= e($customer['credit_limit'] ?? '') ?>"
                           placeholder="0.00"
                           class="<?= t('input') ?>"
                           onblur="if(this.value.trim()!=='')this.value=parseFloat(this.value.replace(/[^0-9.]/g,'')||0).toFixed(2)">
                </div>

                <!-- Default Payment Mode -->
                <?php $dpm = $customer['default_payment_mode'] ?? 'cash'; ?>
                <div x-data="{mode:'<?= e($dpm) ?>'}"
                     x-init="$watch('mode', function(v){ $dispatch('payment-mode-changed', {mode: v}); })">
                    <label class="<?= t('label') ?>">Default Payment Mode</label>
                    <div class="flex rounded-lg border border-slate-200 overflow-hidden text-sm h-9">
                        <button type="button"
                                @click="mode='cash'"
                                class="flex-1 flex items-center justify-center gap-1.5 px-3 transition-colors"
                                :class="mode==='cash' ? 'bg-indigo-600 text-white font-medium' : 'bg-white text-slate-500 hover:bg-slate-50'">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M22 10H2M6 14h.01"/></svg>
                            Cash Sales
                        </button>
                        <button type="button"
                                @click="mode='credit'"
                                class="flex-1 flex items-center justify-center gap-1.5 px-3 border-l border-slate-200 transition-colors"
                                :class="mode==='credit' ? 'bg-indigo-600 text-white font-medium' : 'bg-white text-slate-500 hover:bg-slate-50'">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            Credit Sales
                        </button>
                    </div>
                    <input type="hidden" name="cust_default_payment_mode" :value="mode">
                </div>

                <!-- Payment Term — invoice.php style: type-to-search combobox, filtered by payment mode -->
                <div class="w-full"
                     x-data="custPtComp()"
                     x-init="init()"
                     @payment-mode-changed.window="onModeChange($event.detail.mode)">
                    <label class="<?= t('label') ?>">Payment Term</label>
                    <div class="relative w-full">
                        <input type="text" id="custPtInput"
                               :value="open ? q : (selectedId ? selectedName : '')"
                               @focus="onFocus()"
                               @input="q=$event.target.value; activeIdx=-1"
                               @blur="onBlur()"
                               @keydown.escape="open=false; q=''"
                               @keydown.arrow-down.prevent="moveDown()"
                               @keydown.arrow-up.prevent="moveUp()"
                               @keydown.enter.prevent="pickActive()"
                               placeholder="Search payment term..."
                               autocomplete="off"
                               class="<?= t('input') ?> pr-8 w-full">
                        <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                             :class="open ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M19 9l-7 7-7-7"/>
                        </svg>
                        <div x-show="open" @mousedown.prevent style="display:none"
                             class="absolute z-[9996] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="max-h-52 overflow-y-auto py-1" x-ref="ptList">
                                <!-- None option -->
                                <li>
                                    <button type="button"
                                            @mousedown.prevent="pick('', '')"
                                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                            :class="selectedId==='' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-400 hover:bg-slate-50'">
                                        — None —
                                    </button>
                                </li>
                                <template x-for="(pt, i) in filtered" :key="pt.id">
                                    <li>
                                        <button type="button"
                                                @mousedown.prevent="pick(pt.id, pt.name)"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="i===activeIdx ? 'bg-indigo-50 text-indigo-700 font-medium' : (String(pt.id)===String(selectedId) ? 'bg-slate-50 text-slate-700 font-medium' : 'text-slate-800 hover:bg-slate-50')">
                                            <span x-text="pt.name"></span>
                                        </button>
                                    </li>
                                </template>
                                <li x-show="filtered.length === 0">
                                    <span class="block px-3 py-2 text-xs text-slate-400">No payment terms for this mode.</span>
                                </li>
                            </ul>
                        </div>
                        <input type="hidden" name="cust_payment_term_id" :value="selectedId">
                    </div>
                </div>

                <!-- Receivable Account -->
                <div class="w-full">
                    <label class="<?= t('label') ?>">Receivable Account</label>
                    <div class="w-full">
                    <?php renderDropdown('cust_receivable_account', [''=>'— Coming soon —'], '', '— Coming soon —', false, 'w-full'); ?>
                    </div>
                </div>

                <!-- Income Account -->
                <div class="w-full">
                    <label class="<?= t('label') ?>">Income Account</label>
                    <div class="w-full">
                    <?php renderDropdown('cust_income_account', [''=>'— Coming soon —'], '', '— Coming soon —', false, 'w-full'); ?>
                    </div>
                </div>

                <!-- Expenses Account -->
                <div class="w-full">
                    <label class="<?= t('label') ?>">Expenses Account</label>
                    <div class="w-full">
                    <?php renderDropdown('cust_expenses_account', [''=>'— Coming soon —'], '', '— Coming soon —', false, 'w-full'); ?>
                    </div>
                </div>

                <!-- Price Level -->
                <div class="w-full">
                    <label class="<?= t('label') ?>">Price Level</label>
                    <div class="w-full">
                    <?php renderDropdown('cust_price_level', [''=>'— Coming soon —'], '', '— Coming soon —', false, 'w-full'); ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- ═══ Remarks ═══ -->
    <div class="grid grid-cols-[340px_1fr]">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Remarks</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Additional notes for this customer.</p>
        </div>
        <div class="p-6">
            <label class="<?= t('label') ?>">Notes</label>
            <textarea name="cust_remarks" rows="4" placeholder="Internal notes..."
                      class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($customer['remarks'] ?? '') ?></textarea>
        </div>
    </div>


    <div class="border-t border-slate-100"></div>

    <!-- ═══ Attachments ═══ -->
    <div class="grid grid-cols-[340px_1fr]">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-800 mb-1">Attachments</h3>
            <p class="text-sm text-slate-400 leading-relaxed">Supporting documents for this customer.</p>
        </div>
        <div class="p-6">
            <div id="dropZone"
                 class="border-2 border-dashed border-slate-200 rounded-xl p-8 text-center transition-colors hover:border-indigo-300 hover:bg-indigo-50/30 cursor-pointer"
                 onclick="document.getElementById('custFileInput').click()"
                 ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropAccumulate(event)">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600 mb-1">Drop files to upload</p>
                <p class="text-xs text-slate-400">or <span class="text-indigo-500 font-medium">click to browse</span></p>
                <p class="text-[10px] text-slate-300 mt-2">PDF, JPG, PNG, DOC up to 10MB each</p>
            </div>
            <input type="file" id="custFileInput" name="attachments[]" multiple
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" onchange="accumulateFiles(this)">
            <!-- Saved attachments -->
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
                       class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                    <?php endif; ?>
                    <button type="button"
                       onclick="deleteSavedAttachment(this, <?= $att['id'] ?>, <?= $cid ?>)"
                       class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>


</div>
</form>

<script>
const PRELOAD = {
    contactPersons:   <?= json_encode(array_values($db_contact_persons)) ?>,
    contactAddresses: <?= json_encode(array_values($db_contact_addresses)) ?>,
    emails:           <?= json_encode($db_emails) ?>,
    phones:           <?= json_encode($db_phones) ?>
};

function submitCustomer() {
    var form = document.getElementById('customerForm');
    // Clear old errors
    form.querySelectorAll('.error-msg').forEach(function(el){ el.remove(); });
    form.querySelectorAll('.ring-red-400').forEach(function(el){ el.classList.remove('ring-2','ring-red-400'); });

    var firstError = null;
    form.querySelectorAll('[data-required="1"]').forEach(function(field) {
        if (!field.value.trim()) {
            field.classList.add('ring-2','ring-red-400');
            var p = document.createElement('p');
            p.className = 'error-msg text-red-500 text-xs mt-1';
            p.textContent = 'This field is required.';
            field.after(p);
            if (!firstError) firstError = field;
        }
    });
    if (firstError) { firstError.focus(); return; }

    // Collect phones & emails from Alpine component data
    var infoSection = document.getElementById('contactInfoSection');
    if (infoSection && infoSection._x_dataStack && infoSection._x_dataStack[0]) {
        var d = infoSection._x_dataStack[0];
        // Emails
        document.getElementById('emails_json_input').value = JSON.stringify(d.emails || []);
        // Phones: first phone + contacts
        var phones = [];
        var fp = d.firstPhone;
        if (fp && fp.number && fp.number.trim()) phones.push({ country_code: fp.country_code || '+60', number: fp.number.trim() });
        (d.contacts || []).forEach(function(ct) {
            if (ct.number && ct.number.trim()) phones.push({ country_code: ct.country_code || '+60', number: ct.number.trim() });
        });
        document.getElementById('phones_json_input').value = JSON.stringify(phones);
    } else {
        // Fallback: read from DOM inputs directly
        var allNums = document.querySelectorAll('.phone-number-input');
        var phones = [];
        allNums.forEach(function(inp) {
            var num = inp.value.trim();
            if (!num) return;
            var wrap = inp.closest('.flex.gap-2');
            var ccBtn = wrap ? wrap.querySelector('button span:first-child') : null;
            var cc = ccBtn ? ccBtn.textContent.trim() : '+60';
            phones.push({ country_code: cc, number: num });
        });
        document.getElementById('phones_json_input').value = JSON.stringify(phones);
    }

    form.submit();
}

function contactPersonsComp() {
    return {
        persons: PRELOAD.contactPersons.map(function(p) {
            return { first_name:p.first_name||'', last_name:p.last_name||'',
                     default_billing:p.default_billing==1, default_shipping:p.default_shipping==1 };
        }),
        addPerson() { var idx=this.persons.length; this.persons.push({first_name:'',last_name:'',default_billing:false,default_shipping:false}); this.$nextTick(function(){ var el=document.getElementById('cp_first_'+idx); if(el) el.focus(); }); },
        removePerson(i)  { this.persons.splice(i,1); },
        setDefaultBilling(i)  { this.persons.forEach(function(p,j){ p.default_billing  = j===i; }); },
        setDefaultShipping(i) { this.persons.forEach(function(p,j){ p.default_shipping = j===i; }); }
    };
}

function contactAddressesComp() {
    return {
        addresses: PRELOAD.contactAddresses.map(function(a) {
            return { address_name:a.address_name||'', street_address:a.street_address||'',
                     city:a.city||'', postcode:a.postcode||'', country:a.country||'', state:a.state||'',
                     default_billing:a.default_billing==1, default_shipping:a.default_shipping==1 };
        }),
        addAddress() {
            var idx = this.addresses.length;
            this.addresses.push({ address_name:'Address '+(idx+1),
                street_address:'', city:'', postcode:'', country:'Malaysia', state:'',
                default_billing:false, default_shipping:false });
            this.$nextTick(function() {
                var inputs = document.querySelectorAll('[name="contact_addresses['+idx+'][street_address]"]');
                if (inputs.length) inputs[inputs.length-1].focus();
            });
        },
        removeAddress(i)     { this.addresses.splice(i,1); },
        setDefaultBilling(i)  { this.addresses.forEach(function(a,j){ a.default_billing  = j===i; }); },
        setDefaultShipping(i) { this.addresses.forEach(function(a,j){ a.default_shipping = j===i; }); }
    };
}

// Accumulate files so multiple selections don't overwrite each other
var _accumulatedFiles = new DataTransfer();
function accumulateFiles(input) {
    var newFiles = [];
    Array.from(input.files).forEach(function(f) {
        var exists = false;
        for (var i=0; i<_accumulatedFiles.files.length; i++) {
            if (_accumulatedFiles.files[i].name===f.name && _accumulatedFiles.files[i].size===f.size) { exists=true; break; }
        }
        if (!exists) { _accumulatedFiles.items.add(f); newFiles.push(f); }
    });
    // Reassign full list to input for form submission
    input.files = _accumulatedFiles.files;
    // Only show the newly added files in the list, not all accumulated
    if (newFiles.length) handleFiles(newFiles);
}
function handleDropAccumulate(e) {
    e.preventDefault();
    handleDragLeave(e);
    var dt = new DataTransfer();
    // Merge existing + dropped
    for (var i=0;i<_accumulatedFiles.files.length;i++) dt.items.add(_accumulatedFiles.files[i]);
    Array.from(e.dataTransfer.files).forEach(function(f){
        var exists=false;
        for(var i=0;i<dt.files.length;i++){if(dt.files[i].name===f.name&&dt.files[i].size===f.size){exists=true;break;}}
        if(!exists) dt.items.add(f);
    });
    var newDropped = [];
    Array.from(e.dataTransfer.files).forEach(function(f){
        var wasNew=true;
        for(var i=0;i<_accumulatedFiles.files.length;i++){if(_accumulatedFiles.files[i].name===f.name&&_accumulatedFiles.files[i].size===f.size){wasNew=false;break;}}
        if(wasNew) newDropped.push(f);
    });
    _accumulatedFiles = dt;
    var inp = document.getElementById('custFileInput');
    inp.files = _accumulatedFiles.files;
    if (newDropped.length) handleFiles(newDropped);
}

// Track attachment IDs to delete on save
var _deletedAttachments = [];
function deleteSavedAttachment(btn, attId, custId) {
    var row = btn.closest('.group');
    row.style.display = 'none'; // hide immediately
    _deletedAttachments.push(attId);
    document.getElementById('deleted_attachments_input').value = _deletedAttachments.join(',');
}

function handleDragOver(e) { e.preventDefault(); document.getElementById('dropZone').classList.add('border-indigo-400','bg-indigo-50/40'); }
function handleDragLeave(e) { document.getElementById('dropZone').classList.remove('border-indigo-400','bg-indigo-50/40'); }
function handleDrop(e) { e.preventDefault(); handleDragLeave(e); handleFiles(e.dataTransfer.files); }
function handleFiles(files) {
    var list = document.getElementById('fileList');
    Array.from(files).forEach(function(file) {
        var ext    = file.name.split('.').pop().toUpperCase();
        var size   = file.size < 1048576 ? Math.round(file.size/1024)+'KB' : (file.size/1048576).toFixed(1)+'MB';
        var objUrl = URL.createObjectURL(file);
        var canView = ['PDF','JPG','JPEG','PNG'].includes(ext);
        var div = document.createElement('div');
        div.className = 'flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200 group';
        var nameEl = canView
            ? '<a href="'+objUrl+'" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline truncate">'+file.name+'</a>'
            : '<div class="text-xs font-medium text-slate-700 truncate">'+file.name+'</div>';
        var openBtn = canView
            ? '<a href="'+objUrl+'" target="_blank" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>' : '';
        div.innerHTML =
            '<div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0"><span class="text-[9px] font-bold text-indigo-600">'+ext+'</span></div>'+
            '<div class="flex-1 min-w-0">'+nameEl+'<div class="text-[10px] text-slate-400">'+size+'</div></div>'+
            openBtn+
            '<button type="button" onclick="this.closest(&quot;.group&quot;).remove()" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>';
        list.appendChild(div);
    });
}

// ── Country codes ────────────────────────────────────────────────
var COUNTRY_CODES = [
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
    {code:'+47',label:'Norway'},{code:'+45',label:'Denmark'},{code:'+358',label:'Finland'},
    {code:'+41',label:'Switzerland'},{code:'+43',label:'Austria'},{code:'+32',label:'Belgium'},
    {code:'+351',label:'Portugal'},{code:'+48',label:'Poland'},{code:'+7',label:'Russia'},
    {code:'+55',label:'Brazil'},{code:'+52',label:'Mexico'},{code:'+54',label:'Argentina'},
    {code:'+27',label:'South Africa'},{code:'+234',label:'Nigeria'},{code:'+254',label:'Kenya'},
    {code:'+20',label:'Egypt'},{code:'+212',label:'Morocco'},{code:'+92',label:'Pakistan'},
    {code:'+94',label:'Sri Lanka'},{code:'+880',label:'Bangladesh'},{code:'+977',label:'Nepal'},
    {code:'+98',label:'Iran'},{code:'+90',label:'Turkey'},{code:'+972',label:'Israel'},
];

function phoneCountryDrop(target) {
    return { open: false, q: '' };
}

function contactInfoComp() {
    var phones = PRELOAD.phones || [];
    return {
        emails: PRELOAD.emails || [],
        newEmail: '', emailError: '',
        firstPhone: phones.length > 0
            ? { country_code:phones[0].country_code||'+60', number:phones[0].phone_number||'' }
            : { country_code:'+60', number:'' },
        contacts: phones.slice(1).map(function(p){
            return { country_code:p.country_code||'+60', number:p.phone_number||'' };
        }),
        addEmail() {
            this.emailError = '';
            var em = this.newEmail.trim();
            if (!em) return;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { this.emailError = 'Invalid email address.'; return; }
            if (this.emails.includes(em)) { this.emailError = 'Email already added.'; return; }
            this.emails.push(em); this.newEmail = '';
            document.getElementById('emails_json_input').value = JSON.stringify(this.emails);
        },
        removeEmail(i) {
            this.emails.splice(i,1);
            document.getElementById('emails_json_input').value = JSON.stringify(this.emails);
        },
        addPhone() {
            this.contacts.push({country_code:'+60',number:''});
            this.$nextTick(function() {
                var nums = document.querySelectorAll('.phone-number-input');
                if (nums.length) nums[nums.length-1].focus();
            });
        },
        removeContact(i) { this.contacts.splice(i,1); }
    };
}

// ── Customer currency combobox (invoice.php style) ──────────────
var CUST_CURRENCIES = [
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
    {code:'EGP',name:'Egyptian Pound'},{code:'ZAR',name:'South African Rand'},
    {code:'NGN',name:'Nigerian Naira'},{code:'GHS',name:'Ghanaian Cedi'},
    {code:'KES',name:'Kenyan Shilling'},{code:'MAD',name:'Moroccan Dirham'},
    {code:'TRY',name:'Turkish Lira'},{code:'ILS',name:'Israeli Shekel'},
    {code:'IRR',name:'Iranian Rial'},{code:'RUB',name:'Russian Ruble'},
    {code:'PLN',name:'Polish Zloty'},{code:'CZK',name:'Czech Koruna'},
    {code:'HUF',name:'Hungarian Forint'},{code:'RON',name:'Romanian Leu'},
    {code:'BGN',name:'Bulgarian Lev'},{code:'HRK',name:'Croatian Kuna'},
    {code:'ISK',name:'Icelandic Krona'},{code:'MKD',name:'Macedonian Denar'},
    {code:'RSD',name:'Serbian Dinar'},{code:'UAH',name:'Ukrainian Hryvnia'},
    {code:'GEL',name:'Georgian Lari'},{code:'AMD',name:'Armenian Dram'},
    {code:'AZN',name:'Azerbaijani Manat'},{code:'KZT',name:'Kazakhstani Tenge'},
    {code:'UZS',name:'Uzbekistani Som'},{code:'MNT',name:'Mongolian Tugrik'},
    {code:'NPR',name:'Nepalese Rupee'},{code:'AFN',name:'Afghan Afghani'},
    {code:'BRL',name:'Brazilian Real'},{code:'MXN',name:'Mexican Peso'},
    {code:'ARS',name:'Argentine Peso'},{code:'CLP',name:'Chilean Peso'},
    {code:'COP',name:'Colombian Peso'},{code:'PEN',name:'Peruvian Sol'},
    {code:'UYU',name:'Uruguayan Peso'},{code:'BOB',name:'Bolivian Boliviano'},
    {code:'PYG',name:'Paraguayan Guarani'},{code:'GTQ',name:'Guatemalan Quetzal'},
    {code:'HNL',name:'Honduran Lempira'},{code:'CRC',name:'Costa Rican Colon'},
    {code:'DOP',name:'Dominican Peso'},{code:'JMD',name:'Jamaican Dollar'},
    {code:'TTD',name:'Trinidad Dollar'},{code:'FJD',name:'Fijian Dollar'},
    {code:'PGK',name:'Papua New Guinean Kina'},{code:'XCD',name:'East Caribbean Dollar'},
    {code:'XOF',name:'West African CFA'},{code:'XAF',name:'Central African CFA'},
];

function custCurrencyComp(initialCode) {
    var sorted = CUST_CURRENCIES.slice().sort(function(a,b){ return a.name.localeCompare(b.name); });
    sorted = sorted.filter(function(c){ return c.code !== 'MYR'; });
    sorted.unshift(CUST_CURRENCIES.find(function(c){ return c.code === 'MYR'; }));
    var def = sorted.find(function(c){ return c.code === initialCode; }) || sorted[0];
    return {
        q: '', open: false, activeIdx: -1,
        selected: { code: def.code, label: def.code + ' — ' + def.name },
        currencies: sorted,
        get filtered() {
            var q = this.q.trim().toLowerCase();
            if (!q) return this.currencies;
            return this.currencies.filter(function(c){
                return c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q);
            });
        },
        onFocus() { this.q = ''; this.open = true; this.activeIdx = -1;
            var self = this; this.$nextTick(function(){ var el=document.getElementById('custCurrencyInput'); if(el) el.select(); }); },
        pick(c) { this.selected={code:c.code,label:c.code+' — '+c.name}; this.q=''; this.open=false; this.activeIdx=-1;
            var inp=document.getElementById('custCurrencyInput'); if(inp) inp.blur(); },
        pickActive() { if(this.activeIdx<0) return; var list=this.filtered; if(list[this.activeIdx]) this.pick(list[this.activeIdx]); },
        moveDown() { this.activeIdx=Math.min(this.activeIdx+1,this.filtered.length-1); this.scrollActive(); },
        moveUp()   { this.activeIdx=Math.max(this.activeIdx-1,0); this.scrollActive(); },
        scrollActive() { var self=this; this.$nextTick(function(){ var list=self.$refs.list; if(!list) return; var active=list.querySelectorAll('li')[self.activeIdx]; if(active) active.scrollIntoView({block:'nearest'}); }); },
        onBlur() { var self=this; setTimeout(function(){ if(self.open){self.open=false;self.q='';self.activeIdx=-1;} },200); }
    };
}

// ── Customer payment term combobox (invoice.php style) ───────────
var CUST_PT_ALL = {
    cash:   <?= json_encode($ptByCash,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    credit: <?= json_encode($ptByCredit, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>
};

function custPtComp() {
    return {
        q: '', open: false, activeIdx: -1,
        mode: '<?= e($customer['default_payment_mode'] ?? 'cash') ?>',
        selectedId: '<?= e($customer['payment_term_id'] ?? '') ?>',
        selectedName: '',
        get list() { return CUST_PT_ALL[this.mode] || []; },
        get filtered() {
            var q = this.q.trim().toLowerCase();
            return this.list.filter(function(pt){ return !q || pt.name.toLowerCase().includes(q); });
        },
        init() {
            var all = (CUST_PT_ALL.cash||[]).concat(CUST_PT_ALL.credit||[]);
            var self = this;
            var found = all.find(function(pt){ return String(pt.id)===String(self.selectedId); });
            if (found) this.selectedName = found.name;
        },
        onModeChange(newMode) {
            this.mode = newMode;
            var self = this;
            var stillValid = this.list.find(function(pt){ return String(pt.id)===String(self.selectedId); });
            if (!stillValid) { this.selectedId=''; this.selectedName=''; }
        },
        onFocus() { this.q=''; this.open=true; this.activeIdx=-1;
            var self=this; this.$nextTick(function(){ var el=document.getElementById('custPtInput'); if(el) el.select(); }); },
        pick(id, name) { this.selectedId=id; this.selectedName=name; this.q=''; this.open=false; this.activeIdx=-1;
            var inp=document.getElementById('custPtInput'); if(inp) inp.blur(); },
        pickActive() { if(this.activeIdx<0) return; var list=this.filtered; if(list[this.activeIdx]) this.pick(list[this.activeIdx].id, list[this.activeIdx].name); },
        moveDown() { this.activeIdx=Math.min(this.activeIdx+1,this.filtered.length-1); this.scrollActive(); },
        moveUp()   { this.activeIdx=Math.max(this.activeIdx-1,0); this.scrollActive(); },
        scrollActive() { var self=this; this.$nextTick(function(){ var list=self.$refs.ptList; if(!list) return; var items=list.querySelectorAll('li'); var active=items[self.activeIdx+1]; if(active) active.scrollIntoView({block:'nearest'}); }); },
        onBlur() { var self=this; setTimeout(function(){ if(self.open){self.open=false;self.q='';self.activeIdx=-1;} },200); }
    };
}

</script>

<?php endif; // end action switch ?>

<?php layoutClose(); ?>
