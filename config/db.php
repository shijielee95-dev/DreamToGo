<?php
/**
 * config/db.php
 * ─────────────────────────────────────────────
 * Database connection — update these values.
 * Uses a singleton so PDO is created only once.
 * ─────────────────────────────────────────────
 */

date_default_timezone_set('Asia/Kuala_Lumpur');

define('DB_HOST',    'localhost');
define('DB_NAME',    'ssisbcom_einvoice');
define('DB_USER',    'ssisbcom_einvoice');
define('DB_PASS',    'Shijieyu@0312');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
