<?php
/**
 * includes/layout.php
 */

function layoutOpen(string $pageTitle = '', string $pageSubtitle = ''): void {
    global $theme;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($theme['app_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; font-size: 14px; font-weight: 400; color: #000000; }
        input, textarea, select, button { font-family: 'Roboto', sans-serif; font-size: 14px; font-weight: 400; color: #000000; -webkit-font-smoothing: antialiased; }
        input::placeholder, textarea::placeholder { color: #94a3b8; font-weight: 400; }
        .sidebar-scroll { scrollbar-width: thin; scrollbar-color: rgba(148,163,184,0.3) transparent; }
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.3); border-radius: 99px; }
        .sidebar-scroll::-webkit-scrollbar-button { display: none; }
        main { scrollbar-width: thin; scrollbar-color: rgba(148,163,184,0.4) transparent; }
        main::-webkit-scrollbar { width: 5px; }
        main::-webkit-scrollbar-track { background: transparent; }
        main::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.4); border-radius: 99px; }
        @keyframes toastIn { from { transform:translateY(-8px); opacity:0; } to { transform:translateY(0); opacity:1; } }
        #toastContainer > div { animation: toastIn 0.2s ease-out; }
        input:focus, textarea:focus, select:focus, button:focus { outline: none; border-color: #6366f1; box-shadow: none; }
        input:-webkit-autofill { -webkit-box-shadow: 0 0 0 100px white inset; -webkit-text-fill-color: #1e293b; }
        /* Hide the × cancel button Chrome adds to type=search */
        input[type="search"]::-webkit-search-decoration,
        input[type="search"]::-webkit-search-cancel-button,
        input[type="search"]::-webkit-search-results-button,
        input[type="search"]::-webkit-search-results-decoration { display: none; -webkit-appearance: none; }
        input[type="search"] { -webkit-appearance: none; appearance: none; }
        @media print { aside, header, #panelOverlay { display:none !important; } main { padding:0 !important; overflow:visible !important; } body { overflow:visible !important; height:auto !important; } }
    </style>
    <script>
    // Definitively kill Chrome autofill — type="search" is the only input
    // type Chrome never shows saved data suggestions for.
    // We switch text/email/tel inputs to type="search", style them identically,
    // then switch back to correct type just before form submit.
    (function() {
        var SKIP = ['checkbox','radio','submit','reset','button','hidden','file','password','search','range','color'];
        function makeSearch(el) {
            if (!el || SKIP.indexOf(el.type) !== -1) return;
            el._origType = el.type || 'text';
            el.type = 'search';
            el.setAttribute('autocomplete', 'off');
            el.setAttribute('autocorrect', 'off');
            el.setAttribute('autocapitalize', 'off');
            el.setAttribute('spellcheck', 'false');
        }
        function restoreType(el) {
            if (el._origType) el.type = el._origType;
        }
        function processAll(root) {
            (root || document).querySelectorAll('input').forEach(makeSearch);
        }
        document.addEventListener('DOMContentLoaded', function() {
            processAll(document);
            // Watch for dynamically added inputs (Alpine x-for)
            new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(function(node) {
                        if (node.nodeType !== 1) return;
                        if (node.matches && node.matches('input')) makeSearch(node);
                        else if (node.querySelectorAll) processAll(node);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
            // Restore original types on submit so validation and data work correctly
            document.querySelectorAll('form').forEach(function(f) {
                f.setAttribute('autocomplete', 'off');
                f.addEventListener('submit', function() {
                    f.querySelectorAll('input').forEach(restoreType);
                });
            });
        });
    })();
    </script>
</head>
<body class="<?= $theme['body_bg'] ?> h-screen overflow-hidden">

<div class="flex h-screen">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Right column — header is sticky ONLY within this column, sidebar is untouched -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

        <?php include __DIR__ . '/header.php'; ?>

        <?php if ($pageTitle): ?>
        <div class="h-14 px-6 flex items-center justify-between border-b border-slate-200 bg-white shrink-0 relative z-[9997]">
            <div>
                <h1 class="text-base font-semibold text-slate-800"><?= htmlspecialchars($pageTitle) ?></h1>
                <?php if ($pageSubtitle): ?>
                <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <div id="pageActions" class="flex items-center gap-2"></div>
        </div>
        <?php endif; ?>

        <main class="flex-1 overflow-y-auto p-6">
    <?php

    $flash = getFlash();
    if ($flash) {
        $colors = [
            'success' => 'bg-green-50 border-green-200 text-green-800',
            'error'   => 'bg-red-50 border-red-200 text-red-800',
            'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
            'info'    => 'bg-blue-50 border-blue-200 text-blue-800',
        ];
        $cls = $colors[$flash['type']] ?? $colors['info'];
        echo "<div class=\"mb-5 px-4 py-3 rounded-lg border text-sm {$cls}\">" . htmlspecialchars($flash['message']) . "</div>";
    }
}

function layoutClose(): void {
    ?>
        </main>
    </div>
</div>
</body>
</html>
    <?php
}
