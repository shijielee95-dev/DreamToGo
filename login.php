<?php
/**
 * login.php
 * ─────────────────────────────────────────────
 * Public page — no requireAuth().
 * Redirects to dashboard if already logged in.
 * ─────────────────────────────────────────────
 */
require_once 'config/bootstrap.php';

// Already logged in? Go to dashboard
$token = $_COOKIE['login_token'] ?? null;
if ($token) {
    $stmt = db()->prepare("
        SELECT u.id FROM user_sessions us
        JOIN users u ON u.id = us.user_id
        WHERE us.token = ? AND us.expire_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    if ($stmt->fetch()) redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= e($theme['app_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        input:-webkit-autofill { -webkit-box-shadow: 0 0 0 100px white inset; }
    </style>
</head>

<body class="h-screen flex overflow-hidden bg-slate-900">

<!-- LEFT — branded panel -->
<div class="hidden md:flex flex-col flex-1 relative overflow-hidden">

    <!-- Background image with overlay -->
    <div class="absolute inset-0 bg-cover bg-center"
         style="background-image: url('uploads/login_background.jpg')"></div>
    <div class="absolute inset-0 bg-gradient-to-br from-slate-900/80 via-indigo-900/60 to-slate-900/70"></div>

    <!-- Content -->
    <div class="relative z-10 flex flex-col h-full p-12">

        <!-- Logo -->
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center">
                <span class="text-white text-sm font-bold">eI</span>
            </div>
            <div>
                <div class="text-white font-semibold text-sm"><?= e($theme['app_name']) ?></div>
                <div class="text-indigo-300 text-xs"><?= e($theme['app_version']) ?></div>
            </div>
        </div>

        <!-- Center tagline -->
        <div class="flex-1 flex flex-col justify-center max-w-md">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 text-xs text-white/80 mb-6 w-fit">
                <div class="w-1.5 h-1.5 rounded-full bg-green-400"></div>
                LHDN MyInvois Compliant
            </div>
            <h1 class="text-4xl font-bold text-white leading-tight mb-4">
                Smart invoicing<br>for Malaysian<br>businesses.
            </h1>
            <p class="text-slate-300 text-sm leading-relaxed">
                Create, manage and submit e-invoices directly to LHDN — all in one place. Built for compliance, designed for speed.
            </p>
        </div>

        <!-- Footer -->
        <div class="text-slate-500 text-xs">
            © <?= date('Y') ?> <?= e($theme['app_name']) ?>. All rights reserved.
        </div>

    </div>
</div>

<!-- RIGHT — login form -->
<div class="w-full md:w-[380px] bg-white flex flex-col justify-center px-10 shrink-0">

    <!-- Logo (mobile only) -->
    <div class="md:hidden flex items-center gap-2 mb-8">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center">
            <span class="text-white text-xs font-bold">eI</span>
        </div>
        <span class="font-semibold text-slate-800"><?= e($theme['app_name']) ?></span>
    </div>

    <div class="mb-8">
        <h2 class="text-2xl font-bold text-slate-900">Welcome back</h2>
        <p class="text-slate-400 text-sm mt-1">Sign in to your account to continue</p>
    </div>

    <!-- Login form -->
    <form id="loginForm" class="space-y-4" novalidate>

        <!-- Email -->
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Email Address</label>
            <input type="email"
                   id="emailInput"
                   name="email"
                   autocomplete="username"
                   placeholder="you@company.com"
                   class="w-full h-10 border border-slate-200 rounded-lg px-3.5 text-sm text-slate-800 placeholder-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition">
            <p id="emailError" class="text-xs text-red-500 mt-1 hidden"></p>
        </div>

        <!-- Password -->
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Password</label>
            <div class="relative">
                <input type="password"
                       id="passwordInput"
                       name="password"
                       autocomplete="current-password"
                       placeholder="••••••••"
                       class="w-full h-10 border border-slate-200 rounded-lg px-3.5 pr-10 text-sm text-slate-800 placeholder-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition">
                <button type="button" id="togglePwd"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-slate-500 transition-colors">
                    <svg id="eyeOpen" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eyeClosed" class="w-4 h-4 hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"/>
                    </svg>
                </button>
            </div>
            <p id="passwordError" class="text-xs text-red-500 mt-1 hidden"></p>
        </div>

        <!-- Remember me -->
        <div class="flex items-center gap-2">
            <input type="checkbox" id="remember" name="remember" value="1"
                   class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-400 cursor-pointer">
            <label for="remember" class="text-sm text-slate-500 cursor-pointer select-none">
                Remember me for 7 days
            </label>
        </div>

        <!-- Submit -->
        <button type="submit" id="submitBtn"
                class="w-full h-10 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition-colors flex items-center justify-center gap-2">
            <span id="submitText">Sign In</span>
            <svg id="submitSpinner" class="w-4 h-4 animate-spin hidden" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
        </button>

        <!-- Global error -->
        <p id="globalError" class="text-sm text-red-500 text-center hidden"></p>

    </form>

    <p class="text-center text-xs text-slate-300 mt-8">
        Having trouble? Contact your administrator.
    </p>

</div>

<script>
const emailInput    = document.getElementById('emailInput');
const passwordInput = document.getElementById('passwordInput');
const emailError    = document.getElementById('emailError');
const passwordError = document.getElementById('passwordError');
const globalError   = document.getElementById('globalError');
const submitBtn     = document.getElementById('submitBtn');
const submitText    = document.getElementById('submitText');
const submitSpinner = document.getElementById('submitSpinner');

// Password toggle
document.getElementById('togglePwd').addEventListener('click', () => {
    const isText = passwordInput.type === 'text';
    passwordInput.type = isText ? 'password' : 'text';
    document.getElementById('eyeOpen').classList.toggle('hidden', !isText);
    document.getElementById('eyeClosed').classList.toggle('hidden', isText);
});

// Clear errors on input
emailInput.addEventListener('input', () => {
    emailError.classList.add('hidden');
    emailInput.classList.remove('border-red-400');
});
passwordInput.addEventListener('input', () => {
    passwordError.classList.add('hidden');
    passwordInput.classList.remove('border-red-400');
    globalError.classList.add('hidden');
});

function setLoading(on) {
    submitBtn.disabled = on;
    submitText.textContent = on ? 'Signing in...' : 'Sign In';
    submitSpinner.classList.toggle('hidden', !on);
}

function showFieldError(field, el, msg) {
    el.textContent = msg;
    el.classList.remove('hidden');
    field.classList.add('border-red-400');
    field.focus();
}

document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Reset
    [emailError, passwordError, globalError].forEach(el => el.classList.add('hidden'));
    [emailInput, passwordInput].forEach(el => el.classList.remove('border-red-400'));

    const email    = emailInput.value.trim();
    const password = passwordInput.value;

    // Client-side validation
    if (!email) return showFieldError(emailInput, emailError, 'Email address is required.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showFieldError(emailInput, emailError, 'Please enter a valid email address.');
    if (!password) return showFieldError(passwordInput, passwordError, 'Password is required.');

    setLoading(true);

    try {
        const res  = await fetch('login_process.php', { method: 'POST', body: new FormData(this) });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'dashboard.php';
            return;
        }

        if (data.field === 'email')    return showFieldError(emailInput, emailError, data.message);
        if (data.field === 'password') return showFieldError(passwordInput, passwordError, data.message);

        globalError.textContent = data.message || 'Login failed. Please try again.';
        globalError.classList.remove('hidden');

    } catch {
        globalError.textContent = 'Connection error. Please try again.';
        globalError.classList.remove('hidden');
    } finally {
        setLoading(false);
    }
});
</script>

</body>
</html>
