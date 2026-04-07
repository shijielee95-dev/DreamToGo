<?php
/**
 * config/app.php
 */
date_default_timezone_set('Asia/Kuala_Lumpur');

define('APP_ROOT',    dirname(__DIR__));
define('APP_URL',     'http://localhost/einvoice');  // no trailing slash
define('STORAGE_PDF', APP_ROOT . '/storage/pdfs');
define('STORAGE_LOG', APP_ROOT . '/storage/logs');
define('UPLOAD_DIR',  APP_ROOT . '/uploads');

// ── Cookie path scoped to THIS app only ───────
// Prevents sessions from bleeding into other apps on the same server.
// Derive path from APP_URL so it works on any subdirectory.
$_appUrlParts  = parse_url(APP_URL);
define('COOKIE_PATH', rtrim($_appUrlParts['path'] ?? '/', '/') . '/');

// Ensure storage folders exist
foreach ([STORAGE_PDF, STORAGE_LOG, UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}
