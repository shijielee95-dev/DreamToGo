<?php
/**
 * setup.php — Run ONCE in browser, then DELETE.
 */
require_once 'config/bootstrap.php';

$errors  = [];
$success = [];
$pdo     = db();

// ── 1. Create tables ──────────────────────────

$tables = [

'users' => "CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'user_sessions' => "CREATE TABLE IF NOT EXISTS user_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(128) NOT NULL UNIQUE,
    expire_at  DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'company_profiles' => "CREATE TABLE IF NOT EXISTS company_profiles (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name      VARCHAR(255) NOT NULL DEFAULT '',
    company_tin       VARCHAR(50)  NOT NULL DEFAULT '',
    id_type           ENUM('NRIC','BRN','ARMY','PASSPORT') DEFAULT 'BRN',
    id_no             VARCHAR(100) NOT NULL DEFAULT '',
    sst_no            VARCHAR(100) DEFAULT '',
    tourism_tax_no    VARCHAR(100) DEFAULT '',
    msic_code         VARCHAR(20)  NOT NULL DEFAULT '',
    business_activity VARCHAR(255) NOT NULL DEFAULT '',
    address_line_0    VARCHAR(255) DEFAULT '',
    address_line_1    VARCHAR(255) DEFAULT '',
    address_line_2    VARCHAR(255) DEFAULT '',
    postal_code       VARCHAR(20)  DEFAULT '',
    city              VARCHAR(100) DEFAULT '',
    state_code        VARCHAR(5)   DEFAULT '',
    country_code      VARCHAR(5)   DEFAULT 'MYS',
    phone             VARCHAR(50)  DEFAULT '',
    company_email     VARCHAR(255) DEFAULT '',
    contact_email     VARCHAR(255) DEFAULT '',
    client_id         VARCHAR(255) DEFAULT '',
    client_secret_1   VARCHAR(255) DEFAULT '',
    client_secret_2   VARCHAR(255) DEFAULT '',
    logo_path         VARCHAR(500) DEFAULT '',
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'customers' => "CREATE TABLE IF NOT EXISTS customers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name  VARCHAR(255) NOT NULL,
    tin            VARCHAR(50)  NOT NULL DEFAULT '',
    id_type        ENUM('NRIC','BRN','ARMY','PASSPORT','NA') DEFAULT 'BRN',
    reg_no         VARCHAR(100) DEFAULT '',
    sst_reg_no     VARCHAR(100) DEFAULT '',
    email          VARCHAR(255) DEFAULT '',
    phone          VARCHAR(50)  DEFAULT '',
    address_line_0 VARCHAR(255) DEFAULT '',
    address_line_1 VARCHAR(255) DEFAULT '',
    city           VARCHAR(100) DEFAULT '',
    postal_code    VARCHAR(20)  DEFAULT '',
    state_code     VARCHAR(5)   DEFAULT '',
    country_code   VARCHAR(5)   DEFAULT 'MYS',
    remarks        TEXT         DEFAULT NULL,
    other_name        VARCHAR(255) DEFAULT '',
    old_reg_no        VARCHAR(100) DEFAULT '',
    currency          CHAR(3)      NOT NULL DEFAULT 'MYR',
    einvoice_control  ENUM('individual','consolidate') NOT NULL DEFAULT 'consolidate',
    credit_limit      DECIMAL(15,2) DEFAULT NULL,
    default_payment_mode ENUM('cash','credit') NOT NULL DEFAULT 'cash' COMMENT 'Default payment mode for invoices',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoices' => "CREATE TABLE IF NOT EXISTS invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no          VARCHAR(50)   NOT NULL UNIQUE,
    reference_no        VARCHAR(100)  DEFAULT '',
    customer_id         INT UNSIGNED  DEFAULT NULL,
    customer_name       VARCHAR(255)  NOT NULL DEFAULT '',
    customer_tin        VARCHAR(50)   DEFAULT '',
    customer_reg_no     VARCHAR(100)  DEFAULT '',
    customer_email      VARCHAR(255)  DEFAULT '',
    customer_phone      VARCHAR(50)   DEFAULT '',
    customer_address    TEXT,
    billing_attention   VARCHAR(255)  DEFAULT '',
    shipping_attention  VARCHAR(255)  DEFAULT '',
    shipping_address    TEXT,
    shipping_reference  VARCHAR(255)  DEFAULT '',
    invoice_date        DATE          NOT NULL,
    due_date            DATE          DEFAULT NULL,
    subtotal            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_type            VARCHAR(20) NOT NULL DEFAULT '',
    tax_amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    rounding_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency            CHAR(3)       NOT NULL DEFAULT 'MYR',
    tax_mode            ENUM('inclusive','exclusive') NOT NULL DEFAULT 'exclusive',
    description         VARCHAR(500)  DEFAULT '',
    internal_note       VARCHAR(500)  DEFAULT '',
    notes               TEXT,
    status              ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    pdf_path            VARCHAR(500)  DEFAULT NULL,
    lhdn_invoice_type   VARCHAR(5)    DEFAULT '01',
    msic_code           VARCHAR(20)   DEFAULT '',
    payment_method      VARCHAR(5)    DEFAULT '01',
    payment_mode        ENUM('cash','credit') NOT NULL DEFAULT 'cash' COMMENT 'Cash Sales or Credit Sales',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoice_items' => "CREATE TABLE IF NOT EXISTS invoice_items (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id     INT UNSIGNED  NOT NULL,
    description    VARCHAR(500)  NOT NULL,
    quantity       DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    unit_price     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_type       VARCHAR(20) NOT NULL DEFAULT '',
    tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    item_description VARCHAR(500)  DEFAULT '',
    discount_mode    ENUM('pct','fixed') NOT NULL DEFAULT 'pct',
    classification   VARCHAR(20)   DEFAULT '',
    sort_order     SMALLINT      NOT NULL DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoice_sequences' => "CREATE TABLE IF NOT EXISTS invoice_sequences (
    prefix   VARCHAR(10)  NOT NULL DEFAULT 'INV',
    year     YEAR         NOT NULL,
    next_no  INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'lhdn_submissions' => "CREATE TABLE IF NOT EXISTS lhdn_submissions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id     INT UNSIGNED  NOT NULL,
    environment    ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    submission_uid VARCHAR(100)  DEFAULT NULL,
    document_uuid  VARCHAR(100)  DEFAULT NULL,
    long_id        VARCHAR(255)  DEFAULT NULL,
    status         ENUM('pending','valid','invalid','cancelled') NOT NULL DEFAULT 'pending',
    error_message  TEXT          DEFAULT NULL,
    raw_request    LONGTEXT      DEFAULT NULL,
    raw_response   LONGTEXT      DEFAULT NULL,
    submitted_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    validated_at   DATETIME      DEFAULT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoice_attachments' => "CREATE TABLE IF NOT EXISTS invoice_attachments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id    INT UNSIGNED  NOT NULL,
    original_name VARCHAR(500)  NOT NULL,
    stored_name   VARCHAR(500)  NOT NULL,
    uploaded_by   INT UNSIGNED  DEFAULT NULL,
    uploaded_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoice_payments' => "CREATE TABLE IF NOT EXISTS invoice_payments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id     INT UNSIGNED  NOT NULL,
    payment_method VARCHAR(5)    NOT NULL DEFAULT '01' COMMENT '01=Cash,02=Cheque,03=Bank Transfer,04=Online Banking,05=Credit/Debit Card,06=Others',
    amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    reference_no   VARCHAR(100)  DEFAULT '',
    notes          TEXT,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'audit_logs' => "CREATE TABLE IF NOT EXISTS audit_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED  DEFAULT NULL,
    user_name  VARCHAR(255)  DEFAULT NULL,
    action     VARCHAR(100)  NOT NULL,
    table_name VARCHAR(100)  DEFAULT NULL,
    record_id  INT UNSIGNED  DEFAULT NULL,
    old_value  LONGTEXT      DEFAULT NULL,
    new_value  LONGTEXT      DEFAULT NULL,
    ip_address VARCHAR(45)   DEFAULT NULL,
    user_agent VARCHAR(500)  DEFAULT NULL,
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user  (user_id),
    INDEX idx_table (table_name, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ── Customer module tables ─────────────────────────────────────────────────

'customer_contact_persons' => "CREATE TABLE IF NOT EXISTS customer_contact_persons (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id       INT UNSIGNED NOT NULL,
    first_name        VARCHAR(100) DEFAULT NULL,
    last_name         VARCHAR(100) DEFAULT NULL,
    default_billing   TINYINT(1)  NOT NULL DEFAULT 0,
    default_shipping  TINYINT(1)  NOT NULL DEFAULT 0,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'customer_contact_addresses' => "CREATE TABLE IF NOT EXISTS customer_contact_addresses (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id       INT UNSIGNED NOT NULL,
    address_name      VARCHAR(200) DEFAULT NULL,
    street_address    VARCHAR(500) DEFAULT NULL,
    city              VARCHAR(100) DEFAULT NULL,
    postcode          VARCHAR(20)  DEFAULT NULL,
    country           VARCHAR(100) DEFAULT NULL,
    state             VARCHAR(100) DEFAULT NULL,
    default_billing   TINYINT(1)  NOT NULL DEFAULT 0,
    default_shipping  TINYINT(1)  NOT NULL DEFAULT 0,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'customer_emails' => "CREATE TABLE IF NOT EXISTS customer_emails (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT UNSIGNED NOT NULL,
    email        VARCHAR(320) NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'customer_attachments' => "CREATE TABLE IF NOT EXISTS customer_attachments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT UNSIGNED NOT NULL,
    original_name VARCHAR(500) NOT NULL,
    stored_name   VARCHAR(500) NOT NULL,
    uploaded_by   INT UNSIGNED DEFAULT NULL,
    uploaded_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'customer_phones' => "CREATE TABLE IF NOT EXISTS customer_phones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT UNSIGNED NOT NULL,
    country_code VARCHAR(10)  NOT NULL DEFAULT '+60',
    phone_number VARCHAR(50)  NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'payment_terms' => "CREATE TABLE IF NOT EXISTS payment_terms (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(100) NOT NULL,
    description          TEXT         DEFAULT NULL,
    type                 ENUM('days','day_of_month','day_of_foll_month','end_of_month','days_after_month') NOT NULL DEFAULT 'days',
    value                INT          NOT NULL DEFAULT 0,
    late_interest_active TINYINT(1)   NOT NULL DEFAULT 0,
    late_interest_rate   DECIMAL(8,4) DEFAULT NULL,
    payment_mode         ENUM('cash','credit') NOT NULL DEFAULT 'cash',
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'price_levels' => "CREATE TABLE IF NOT EXISTS price_levels (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'tax_rates' => "CREATE TABLE IF NOT EXISTS tax_rates (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    rate       DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    details    TEXT         DEFAULT NULL,
    is_default TINYINT(1)  NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'number_formats' => "CREATE TABLE IF NOT EXISTS number_formats (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_type   VARCHAR(50)  NOT NULL,
    format     VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_type (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'products' => "CREATE TABLE IF NOT EXISTS products (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                   VARCHAR(255) NOT NULL,
    sku                    VARCHAR(100) DEFAULT '',
    barcode                VARCHAR(100) DEFAULT '',
    image_path             VARCHAR(500) DEFAULT NULL,
    classification_code    VARCHAR(10)  DEFAULT '',
    track_inventory        TINYINT(1)   NOT NULL DEFAULT 1,
    low_stock_level        DECIMAL(10,2) DEFAULT NULL,
    selling                TINYINT(1)   NOT NULL DEFAULT 1,
    sale_price             DECIMAL(15,2) DEFAULT NULL,
    sales_tax              VARCHAR(20)  DEFAULT '',
    sale_description       TEXT         DEFAULT NULL,
    buying                 TINYINT(1)   NOT NULL DEFAULT 1,
    purchase_price         DECIMAL(15,2) DEFAULT NULL,
    purchase_description   TEXT         DEFAULT NULL,
    base_unit_label        VARCHAR(50)  NOT NULL DEFAULT 'unit',
    multiple_uoms          TINYINT(1)   NOT NULL DEFAULT 0,
    uom_base_default_sales     TINYINT(1) NOT NULL DEFAULT 0,
    uom_base_default_purchase  TINYINT(1) NOT NULL DEFAULT 0,
    remarks                TEXT         DEFAULT NULL,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'product_uoms' => "CREATE TABLE IF NOT EXISTS product_uoms (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id       INT UNSIGNED NOT NULL,
    label            VARCHAR(50)  NOT NULL,
    rate             DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    sale_price       DECIMAL(15,2) DEFAULT NULL,
    purchase_price   DECIMAL(15,2) DEFAULT NULL,
    default_sales    TINYINT(1)   NOT NULL DEFAULT 0,
    default_purchase TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'currencies' => "CREATE TABLE IF NOT EXISTS currencies (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(10)  NOT NULL UNIQUE,
    name       VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $success[] = "Table <strong>{$name}</strong> — created / verified";
    } catch (PDOException $e) {
        $errors[] = "Table <strong>{$name}</strong>: " . $e->getMessage();
    }
}

// ── 1b. ALTER customers table (safe) ──────────────────────────────────────
$alterCustomers = [
    'remarks'              => "ALTER TABLE customers ADD COLUMN remarks              TEXT          DEFAULT NULL",
    'other_name'           => "ALTER TABLE customers ADD COLUMN other_name           VARCHAR(255)  DEFAULT ''",
    'old_reg_no'           => "ALTER TABLE customers ADD COLUMN old_reg_no           VARCHAR(100)  DEFAULT ''",
    'currency'             => "ALTER TABLE customers ADD COLUMN currency             CHAR(3)       NOT NULL DEFAULT 'MYR'",
    'einvoice_control'     => "ALTER TABLE customers ADD COLUMN einvoice_control     ENUM('individual','consolidate') NOT NULL DEFAULT 'consolidate'",
    'credit_limit'         => "ALTER TABLE customers ADD COLUMN credit_limit         DECIMAL(15,2) DEFAULT NULL",
    'default_payment_mode' => "ALTER TABLE customers ADD COLUMN default_payment_mode ENUM('cash','credit') NOT NULL DEFAULT 'cash'",
];
foreach ($alterCustomers as $col => $sql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME=?");
    $check->execute([$col]);
    if (!(int)$check->fetchColumn()) {
        try { $pdo->exec($sql); $success[] = "Column <strong>customers.{$col}</strong> — added"; }
        catch (PDOException $e) { $errors[] = "Column <strong>customers.{$col}</strong>: ".$e->getMessage(); }
    } else {
        $success[] = "Column <strong>customers.{$col}</strong> — already exists, skipped";
    }
}

// ── 1c. Widen invoice_sequences.prefix for format-based keys ──────────────
try {
    $pdo->exec("ALTER TABLE invoice_sequences MODIFY COLUMN prefix VARCHAR(100) NOT NULL DEFAULT 'INV'");
    $success[] = "invoice_sequences.prefix — widened to VARCHAR(100)";
} catch (PDOException $e) {
    // ignore if already widened
}

// ── 2. ALTER existing invoices table (safe — adds only if column missing) ──

$alterColumns = [
    'reference_no'        => "ALTER TABLE invoices ADD COLUMN reference_no        VARCHAR(100)  DEFAULT '' AFTER invoice_no",
    'billing_attention'   => "ALTER TABLE invoices ADD COLUMN billing_attention   VARCHAR(255)  DEFAULT ''",
    'shipping_attention'  => "ALTER TABLE invoices ADD COLUMN shipping_attention  VARCHAR(255)  DEFAULT ''",
    'shipping_address'    => "ALTER TABLE invoices ADD COLUMN shipping_address    TEXT",
    'shipping_reference'  => "ALTER TABLE invoices ADD COLUMN shipping_reference  VARCHAR(255) DEFAULT ''",
    'description'         => "ALTER TABLE invoices ADD COLUMN description         VARCHAR(500)  DEFAULT ''",
    'internal_note'       => "ALTER TABLE invoices ADD COLUMN internal_note       VARCHAR(500)  DEFAULT ''",
    'rounding_adjustment' => "ALTER TABLE invoices ADD COLUMN rounding_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'tax_mode'            => "ALTER TABLE invoices ADD COLUMN tax_mode            ENUM('inclusive','exclusive') NOT NULL DEFAULT 'exclusive'",
    'payment_mode'        => "ALTER TABLE invoices ADD COLUMN payment_mode        ENUM('cash','credit') NOT NULL DEFAULT 'cash'",
    'due_date'            => "ALTER TABLE invoices ADD COLUMN due_date            DATE          DEFAULT NULL",
    'payment_term_id'     => "ALTER TABLE invoices ADD COLUMN payment_term_id     INT UNSIGNED  DEFAULT NULL",
];
foreach ($alterColumns as $col => $sql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME=?");
    $check->execute([$col]);
    if (!(int)$check->fetchColumn()) {
        try { $pdo->exec($sql); $success[] = "Column <strong>invoices.{$col}</strong> — added"; }
        catch (PDOException $e) { $errors[] = "Column <strong>invoices.{$col}</strong>: ".$e->getMessage(); }
    } else {
        $success[] = "Column <strong>invoices.{$col}</strong> — already exists, skipped";
    }
}

// ── ALTER invoice_items (safe — adds only if missing) ──────────────
$alterItems = [
    'item_description' => "ALTER TABLE invoice_items ADD COLUMN item_description VARCHAR(500) DEFAULT '' AFTER description",
    'discount_mode'    => "ALTER TABLE invoice_items ADD COLUMN discount_mode ENUM('pct','fixed') NOT NULL DEFAULT 'pct'",
    'classification'   => "ALTER TABLE invoice_items MODIFY COLUMN classification VARCHAR(20) DEFAULT ''",
];

foreach ($alterItems as $col => $sql) {
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'invoice_items'
          AND COLUMN_NAME  = ?
    ");
    $check->execute([$col]);
    if (!(int)$check->fetchColumn()) {
        try {
            $pdo->exec($sql);
            $success[] = "Column <strong>invoice_items.{$col}</strong> — added";
        } catch (PDOException $e) {
            $errors[] = "Column <strong>invoice_items.{$col}</strong>: " . $e->getMessage();
        }
    } else {
        $success[] = "Column <strong>invoice_items.{$col}</strong> — already exists, skipped";
    }
}

$alterColumns = [
    'invoice_format_id' => "ALTER TABLE invoices ADD COLUMN invoice_format_id INT UNSIGNED DEFAULT NULL AFTER invoice_no",
    'customer_id'       => "ALTER TABLE invoices ADD COLUMN customer_id INT UNSIGNED DEFAULT NULL",
];

foreach ($alterColumns as $col => $sql) {
    // Check if column already exists before attempting ALTER
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'invoices'
          AND COLUMN_NAME  = ?
    ");
    $check->execute([$col]);
    $exists = (int)$check->fetchColumn();

    if (!$exists) {
        try {
            $pdo->exec($sql);
            $success[] = "Column <strong>invoices.{$col}</strong> — added";
        } catch (PDOException $e) {
            $errors[] = "Column <strong>invoices.{$col}</strong>: " . $e->getMessage();
        }
    } else {
        $success[] = "Column <strong>invoices.{$col}</strong> — already exists, skipped";
    }
}

// ── 2b. Migrate tax_type ENUMs to VARCHAR (safe) ──────────────────
$taxAlters = [
    'invoices_tax_type'      => "ALTER TABLE invoices MODIFY COLUMN tax_type VARCHAR(20) NOT NULL DEFAULT ''",
    'invoice_items_tax_type' => "ALTER TABLE invoice_items MODIFY COLUMN tax_type VARCHAR(20) NOT NULL DEFAULT ''",
];
foreach ($taxAlters as $key => $sql) {
    try { $pdo->exec($sql); $success[] = "Column <strong>{$key}</strong> — migrated to VARCHAR"; }
    catch (PDOException $e) { $errors[] = "Column <strong>{$key}</strong>: ".$e->getMessage(); }
}

// ── 2c. ALTER payment_terms (add new columns if missing) ──────────
$alterPaymentTerms = [
    'description'          => "ALTER TABLE payment_terms ADD COLUMN description          TEXT         DEFAULT NULL",
    'type'                 => "ALTER TABLE payment_terms ADD COLUMN type                 ENUM('days','day_of_month','day_of_foll_month','end_of_month','days_after_month') NOT NULL DEFAULT 'days'",
    'value'                => "ALTER TABLE payment_terms ADD COLUMN value                INT          NOT NULL DEFAULT 0",
    'late_interest_active' => "ALTER TABLE payment_terms ADD COLUMN late_interest_active TINYINT(1)   NOT NULL DEFAULT 0",
    'late_interest_rate'   => "ALTER TABLE payment_terms ADD COLUMN late_interest_rate   DECIMAL(8,4) DEFAULT NULL",
    'payment_mode'         => "ALTER TABLE payment_terms ADD COLUMN payment_mode         ENUM('cash','credit') NOT NULL DEFAULT 'cash'",
    'is_active'            => "ALTER TABLE payment_terms ADD COLUMN is_active            TINYINT(1)   NOT NULL DEFAULT 1",
    'updated_at'           => "ALTER TABLE payment_terms ADD COLUMN updated_at           DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];
foreach ($alterPaymentTerms as $col => $sql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_terms' AND COLUMN_NAME=?");
    $check->execute([$col]);
    if (!(int)$check->fetchColumn()) {
        try { $pdo->exec($sql); $success[] = "Column <strong>payment_terms.{$col}</strong> — added"; }
        catch (PDOException $e) { $errors[] = "Column <strong>payment_terms.{$col}</strong>: ".$e->getMessage(); }
    } else {
        $success[] = "Column <strong>payment_terms.{$col}</strong> — already exists, skipped";
    }
}

// ── 2d. ALTER invoice_payments (add payment_term_id, drop payment_method) ──
$alterInvPayments = [
    'payment_term_id' => "ALTER TABLE invoice_payments ADD COLUMN payment_term_id INT UNSIGNED DEFAULT NULL",
];
foreach ($alterInvPayments as $col => $sql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND COLUMN_NAME=?");
    $check->execute([$col]);
    if (!(int)$check->fetchColumn()) {
        try { $pdo->exec($sql); $success[] = "Column <strong>invoice_payments.{$col}</strong> — added"; }
        catch (PDOException $e) { $errors[] = "Column <strong>invoice_payments.{$col}</strong>: ".$e->getMessage(); }
    } else {
        $success[] = "Column <strong>invoice_payments.{$col}</strong> — already exists, skipped";
    }
}
// Drop payment_method — replaced by payment_term_id
$checkDrop = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND COLUMN_NAME='payment_method'");
$checkDrop->execute();
if ((int)$checkDrop->fetchColumn()) {
    try {
        $pdo->exec("ALTER TABLE invoice_payments DROP COLUMN payment_method");
        $success[] = "Column <strong>invoice_payments.payment_method</strong> — dropped (replaced by payment_term_id)";
    } catch (PDOException $e) {
        $errors[] = "Drop <strong>invoice_payments.payment_method</strong>: " . $e->getMessage();
    }
} else {
    $success[] = "Column <strong>invoice_payments.payment_method</strong> — already removed, skipped";
}

// ── 3. Company profile default row ────────────
try {
    $pdo->exec("INSERT IGNORE INTO company_profiles (id) VALUES (1)");
    $success[] = "Company profile row — OK";
} catch (PDOException $e) {
    $errors[] = "Company profile: " . $e->getMessage();
}

// ── 3b. Seed payment_terms if empty ───────────
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM payment_terms")->fetchColumn();
    if ($cnt == 0) {
        $pdo->exec("INSERT INTO payment_terms (name, description, type, value, payment_mode) VALUES
            ('COD',   'Cash on delivery (Due on the same day).',                                              'days',         0,  'cash'),
            ('DOM25', 'Due on the 25th of invoice month or following month (if already past).',               'day_of_month', 25, 'credit'),
            ('EOFM',  'Due at the end of following month.',                                                   'end_of_month', 1,  'credit'),
            ('EOM',   'Due at the end of month.',                                                             'end_of_month', 0,  'credit'),
            ('NET14', 'Due in 14 days.',                                                                      'days',         14, 'credit'),
            ('NET30', 'Due in 30 days.',                                                                      'days',         30, 'credit'),
            ('NET60', 'Due in 60 days.',                                                                      'days',         60, 'credit')
        ");
        $success[] = "payment_terms — seeded with defaults";
    } else {
        $success[] = "payment_terms — already has data, skipped";
    }
} catch (PDOException $e) {
    $errors[] = "payment_terms seed: " . $e->getMessage();
}

// ── 3c. Seed price_levels if empty ────────────
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM price_levels")->fetchColumn();
    if ($cnt == 0) {
        $pdo->exec("INSERT INTO price_levels (name) VALUES ('Retail'),('Wholesale'),('VIP')");
        $success[] = "price_levels — seeded with defaults";
    } else {
        $success[] = "price_levels — already has data, skipped";
    }
} catch (PDOException $e) {
    $errors[] = "price_levels seed: " . $e->getMessage();
}

// ── 3d. Seed currencies if empty ──────────────
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM currencies")->fetchColumn();
    if ($cnt == 0) {
        $pdo->exec("INSERT INTO currencies (code, name) VALUES ('MYR','Malaysian Ringgit'),('USD','United States Dollar'),('SGD','Singapore Dollar'),('EUR','Euro'),('GBP','British Pound')");
        $success[] = "currencies — seeded with defaults";
    } else {
        $success[] = "currencies — already has data, skipped";
    }
} catch (PDOException $e) {
    $errors[] = "currencies seed: " . $e->getMessage();
}

// ── 4. Admin user with correct hash ───────────
$adminName     = 'Admin';
$adminEmail    = 'admin@company.com';
$adminPassword = 'admin123';
$adminHash     = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$adminEmail]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare("UPDATE users SET password = ? WHERE email = ?")->execute([$adminHash, $adminEmail]);
        $success[] = "Admin user password <strong>reset</strong> — OK";
    } else {
        $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)")
            ->execute([$adminName, $adminEmail, $adminHash]);
        $success[] = "Admin user <strong>created</strong> — OK";
    }
} catch (PDOException $e) {
    $errors[] = "Admin user: " . $e->getMessage();
}

$allGood = empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — e-Invoice Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="min-h-screen bg-slate-100 flex items-start justify-center p-6 pt-12">

<div class="bg-white rounded-2xl shadow-sm border border-slate-200 w-full max-w-xl p-8">

    <div class="w-14 h-14 rounded-2xl <?= $allGood ? 'bg-green-100' : 'bg-red-100' ?> flex items-center justify-center mx-auto mb-5">
        <?php if ($allGood): ?>
            <svg class="w-7 h-7 text-green-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
        <?php else: ?>
            <svg class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <?php endif; ?>
    </div>

    <h1 class="text-xl font-semibold text-slate-800 text-center mb-1">
        <?= $allGood ? 'Setup Complete' : 'Setup Finished with Errors' ?>
    </h1>
    <p class="text-sm text-slate-400 text-center mb-6">e-Invoice Portal — Database Setup</p>

    <div class="space-y-1.5 mb-6 max-h-80 overflow-y-auto">
        <?php foreach ($success as $msg): ?>
        <div class="flex items-start gap-2 text-xs text-green-700 bg-green-50 rounded-lg px-3 py-2">
            <svg class="w-3.5 h-3.5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
            <span><?= $msg ?></span>
        </div>
        <?php endforeach; ?>
        <?php foreach ($errors as $msg): ?>
        <div class="flex items-start gap-2 text-xs text-red-700 bg-red-50 rounded-lg px-3 py-2">
            <svg class="w-3.5 h-3.5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
            <span><?= $msg ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($allGood): ?>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-5">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Default Login</p>
        <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-slate-400">Email</span><code class="text-slate-800 font-medium">admin@company.com</code></div>
            <div class="flex justify-between"><span class="text-slate-400">Password</span><code class="text-slate-800 font-medium">admin123</code></div>
        </div>
    </div>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-5 text-xs text-amber-700 flex items-start gap-2">
        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <span><strong>Delete this file</strong> from your server immediately after logging in. Change your password on first login.</span>
    </div>
    <a href="login.php" class="block w-full text-center py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition-colors">
        Go to Login
    </a>
    <?php else: ?>
    <p class="text-sm text-slate-500 text-center">Fix the errors above and refresh to try again. Check <code class="text-slate-700">config/db.php</code>.</p>
    <?php endif; ?>

</div>
</body>
</html>
