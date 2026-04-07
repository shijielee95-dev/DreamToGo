<?php
/**
 * includes/header.php
 */
$user = authUser();
?>

<!-- Toast container -->
<div id="toastContainer" class="fixed top-5 left-1/2 -translate-x-1/2 z-[99999] flex flex-col items-center gap-2 pointer-events-none"></div>

<!-- Header -->
<header class="sticky top-0 <?= t('header_height') ?> <?= t('header_bg') ?> border-b <?= t('header_border') ?> z-[9997] flex items-center px-6 gap-4">
    <div id="headerTitle" class="flex-1"></div>
    <div class="flex items-center gap-2">
        <!-- Notification bell -->
        <button class="w-9 h-9 flex items-center justify-center rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
        </button>
        <!-- User dropdown -->
        <div class="relative" id="userMenuWrap">
            <button id="userMenuBtn" class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-slate-100 transition-colors text-slate-700 text-sm font-medium">
                <div id="headerAvatar" class="w-7 h-7 rounded-full bg-indigo-600 flex items-center justify-center text-white text-xs font-semibold">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <span id="headerUsername"><?= e($user['name']) ?></span>
                <svg class="w-3.5 h-3.5 text-slate-400 transition-transform" id="userArrow" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
            </button>
            <!-- Dropdown -->
            <div id="userDropdown" class="hidden absolute right-0 top-full mt-2 w-52 bg-white border border-slate-200 rounded-xl shadow-xl py-1.5 z-50">
                <div class="px-4 py-2 border-b border-slate-100 mb-1">
                    <div id="dropdownName" class="text-sm font-medium text-slate-800"><?= e($user['name']) ?></div>
                    <div id="dropdownEmail" class="text-xs text-slate-400"><?= e($user['email']) ?></div>
                </div>
                <button id="openAccountPanel" class="w-full text-left flex items-center gap-2.5 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c2-4 14-4 16 0"/></svg>
                    My Account
                </button>
                <button id="openPasswordPanel" class="w-full text-left flex items-center gap-2.5 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 118 0v4"/></svg>
                    Change Password
                </button>
                <div class="my-1 border-t border-slate-100"></div>
                <a href="logout.php" class="flex items-center gap-2.5 px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M21 3v18"/></svg>
                    Logout
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Shared overlay -->
<div id="panelOverlay" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden opacity-0 transition-opacity duration-300 z-[9998]"></div>

<!-- My Account Panel -->
<div id="accountPanel" class="fixed top-0 right-0 h-full w-96 bg-white shadow-2xl translate-x-full transition-transform duration-300 flex flex-col z-[9999]">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
        <h2 class="text-base font-semibold text-slate-800">My Account</h2>
        <button id="closeAccountPanel" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 rounded-lg hover:bg-slate-100 transition-colors text-xl">&times;</button>
    </div>
    <form id="accountForm" class="flex flex-col flex-1">
        <div class="flex-1 p-6 space-y-4 overflow-y-auto">
            <div>
                <label class="<?= t('label') ?>">Name</label>
                <input type="text" name="name" id="accountName" value="<?= e($user['name']) ?>" class="<?= t('input') ?>">
            </div>
            <div>
                <label class="<?= t('label') ?>">Email</label>
                <input type="email" name="email" id="accountEmail" value="<?= e($user['email']) ?>" class="<?= t('input') ?>">
            </div>
        </div>
        <div class="p-4 border-t border-slate-100">
            <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> w-full justify-center h-9">Save Changes</button>
        </div>
    </form>
</div>

<!-- Change Password Panel -->
<div id="passwordPanel" class="fixed top-0 right-0 h-full w-96 bg-white shadow-2xl translate-x-full transition-transform duration-300 flex flex-col z-[9999]">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
        <h2 class="text-base font-semibold text-slate-800">Change Password</h2>
        <button id="closePasswordPanel" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 rounded-lg hover:bg-slate-100 transition-colors text-xl">&times;</button>
    </div>
    <form id="passwordForm" class="flex flex-col flex-1">
        <div class="flex-1 p-6 space-y-4 overflow-y-auto">
            <div>
                <label class="<?= t('label') ?>">Current Password</label>
                <input type="password" name="current_password" class="<?= t('input') ?>" required>
            </div>
            <div>
                <label class="<?= t('label') ?>">New Password</label>
                <input type="password" name="new_password" id="newPwd" class="<?= t('input') ?>" required>
                <p id="pwdStrength" class="text-xs mt-1 text-slate-400">Minimum 8 characters</p>
            </div>
            <div>
                <label class="<?= t('label') ?>">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirmPwd" class="<?= t('input') ?>" required>
                <p id="pwdMatch" class="text-xs mt-1"></p>
            </div>
        </div>
        <div class="p-4 border-t border-slate-100">
            <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> w-full justify-center h-9">Update Password</button>
        </div>
    </form>
</div>

<script>
// ── User dropdown ──
const _btn = document.getElementById('userMenuBtn');
const _dd  = document.getElementById('userDropdown');
const _arr = document.getElementById('userArrow');
_btn.addEventListener('click', () => {
    const open = !_dd.classList.contains('hidden');
    _dd.classList.toggle('hidden', open);
    _arr.style.transform = open ? '' : 'rotate(180deg)';
});
document.addEventListener('click', e => {
    if (!document.getElementById('userMenuWrap').contains(e.target)) {
        _dd.classList.add('hidden');
        _arr.style.transform = '';
    }
});

// ── Panel helpers ──
const _overlay = document.getElementById('panelOverlay');
function openPanel(id) {
    _dd.classList.add('hidden');
    _arr.style.transform = '';
    _overlay.classList.remove('hidden');
    setTimeout(() => _overlay.classList.remove('opacity-0'), 10);
    document.getElementById(id).classList.remove('translate-x-full');
}
function closeAllPanels() {
    _overlay.classList.add('opacity-0');
    ['accountPanel','passwordPanel'].forEach(id =>
        document.getElementById(id).classList.add('translate-x-full')
    );
    setTimeout(() => _overlay.classList.add('hidden'), 300);
}
document.getElementById('openAccountPanel').addEventListener('click', () => openPanel('accountPanel'));
document.getElementById('closeAccountPanel').addEventListener('click', closeAllPanels);
document.getElementById('openPasswordPanel').addEventListener('click', () => openPanel('passwordPanel'));
document.getElementById('closePasswordPanel').addEventListener('click', closeAllPanels);
_overlay.addEventListener('click', closeAllPanels);

// ── Toast system ──
function showToast(message, type = 'default', duration = 3000) {
    const colors = { success:'bg-green-500', error:'bg-red-500', warning:'bg-amber-500', info:'bg-blue-500', default:'bg-slate-600' };
    const icons  = { success:'✔', error:'✖', warning:'⚠', info:'ℹ', default:'ℹ' };
    const c = document.getElementById('toastContainer');
    const el = document.createElement('div');
    el.className = 'pointer-events-auto w-80 bg-white shadow-lg rounded-lg overflow-hidden border border-slate-200 flex flex-col';
    el.innerHTML = `
        <div class="flex items-center justify-between px-3 py-2 gap-2">
            <div class="flex items-center gap-2 text-sm text-slate-700">
                <span class="w-5 h-5 rounded-full ${colors[type]||colors.default} text-white flex items-center justify-center text-xs font-bold shrink-0">${icons[type]||icons.default}</span>
                <span>${message}</span>
            </div>
            <button class="text-slate-300 hover:text-slate-500 text-lg leading-none closeToast">&times;</button>
        </div>
        <div class="h-0.5 ${colors[type]||colors.default} progressBar" style="width:100%;transition:width linear ${duration}ms"></div>`;
    c.appendChild(el);
    el.querySelector('.closeToast').onclick = () => el.remove();
    requestAnimationFrame(() => requestAnimationFrame(() => {
        el.querySelector('.progressBar').style.width = '0%';
    }));
    setTimeout(() => el.remove(), duration + 300);
}

// ── Account form — instant UI update on save ──
document.getElementById('accountForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd   = new FormData(this);
    const name  = fd.get('name').trim();
    const email = fd.get('email').trim();

    fetch('update_account.php', { method:'POST', body: fd })
        .then(r => r.text()).then(d => {
            if (d.trim() === 'success') {
                // ① Header button name
                document.getElementById('headerUsername').textContent = name;
                // ② Dropdown info
                document.getElementById('dropdownName').textContent  = name;
                document.getElementById('dropdownEmail').textContent = email;
                // ③ Avatar initial
                document.getElementById('headerAvatar').textContent  = name.charAt(0).toUpperCase();
                // ④ Sidebar footer name & email
                const sbName  = document.getElementById('sidebarUserName');
                const sbEmail = document.getElementById('sidebarUserEmail');
                const sbInit  = document.getElementById('sidebarUserInitial');
                if (sbName)  sbName.textContent  = name;
                if (sbEmail) sbEmail.textContent = email;
                if (sbInit)  sbInit.textContent  = name.charAt(0).toUpperCase();

                showToast('Profile updated successfully!', 'success');
                closeAllPanels();
            } else {
                showToast(d.trim() || 'Error updating profile.', 'error');
            }
        }).catch(() => showToast('Server error.', 'error'));
});

// ── Password form ──
const _np = document.getElementById('newPwd');
const _cp = document.getElementById('confirmPwd');
_np.addEventListener('input', () => {
    const el = document.getElementById('pwdStrength');
    const weak = _np.value.length > 0 && _np.value.length < 8;
    el.textContent = weak ? 'Too short — minimum 8 characters' : 'Minimum 8 characters';
    el.className   = 'text-xs mt-1 ' + (weak ? 'text-red-500' : 'text-slate-400');
});
_cp.addEventListener('input', () => {
    const m = document.getElementById('pwdMatch');
    m.textContent = (_cp.value && _cp.value !== _np.value) ? 'Passwords do not match' : '';
    m.className   = 'text-xs mt-1 text-red-500';
});
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (_np.value.length < 8)    return showToast('Password must be at least 8 characters.', 'warning');
    if (_np.value !== _cp.value) return showToast('Passwords do not match.', 'error');
    fetch('update_password.php', { method:'POST', body: new FormData(this) })
        .then(r => r.text()).then(d => {
            if (d.trim() === 'success') {
                showToast('Password updated!', 'success');
                closeAllPanels();
                document.getElementById('passwordForm').reset();
            } else showToast(d.trim() || 'Error updating password.', 'error');
        }).catch(() => showToast('Server error.', 'error'));
});
</script>
