-- ═══════════════════════════════════════════════
-- e-Invoice Portal — Full Database Schema
-- Run this file once to set up all tables.
-- ═══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS einvoice_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE einvoice_db;

-- ─────────────────────────────────────────────
-- USERS & AUTH
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(128) NOT NULL UNIQUE,
    expire_at  DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- COMPANY PROFILE (single row, id=1)
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS company_profiles (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name     VARCHAR(255) NOT NULL DEFAULT '',
    company_tin      VARCHAR(50)  NOT NULL DEFAULT '',
    id_type          ENUM('NRIC','BRN','ARMY','PASSPORT') DEFAULT 'BRN',
    id_no            VARCHAR(100) NOT NULL DEFAULT '',
    sst_no           VARCHAR(100) DEFAULT '',
    tourism_tax_no   VARCHAR(100) DEFAULT '',
    msic_code        VARCHAR(20)  NOT NULL DEFAULT '',
    business_activity VARCHAR(255) NOT NULL DEFAULT '',
    -- Address
    address_line_0   VARCHAR(255) DEFAULT '',
    address_line_1   VARCHAR(255) DEFAULT '',
    address_line_2   VARCHAR(255) DEFAULT '',
    postal_code      VARCHAR(20)  DEFAULT '',
    city             VARCHAR(100) DEFAULT '',
    state_code       VARCHAR(5)   DEFAULT '',
    country_code     VARCHAR(5)   DEFAULT 'MYS',
    -- Contact
    phone            VARCHAR(50)  DEFAULT '',
    company_email    VARCHAR(255) DEFAULT '',
    contact_email    VARCHAR(255) DEFAULT '',
    -- LHDN API credentials
    client_id        VARCHAR(255) DEFAULT '',
    client_secret_1  VARCHAR(255) DEFAULT '',
    client_secret_2  VARCHAR(255) DEFAULT '',
    -- Logo
    logo_path        VARCHAR(500) DEFAULT '',
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default empty company profile
INSERT IGNORE INTO company_profiles (id) VALUES (1);

-- ─────────────────────────────────────────────
-- CUSTOMERS
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS customers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    tin           VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'LHDN TIN',
    id_type       ENUM('NRIC','BRN','ARMY','PASSPORT','NA') DEFAULT 'BRN',
    reg_no        VARCHAR(100) DEFAULT '' COMMENT 'SSM / IC No',
    sst_reg_no    VARCHAR(100) DEFAULT '',
    email         VARCHAR(255) DEFAULT '',
    phone         VARCHAR(50)  DEFAULT '',
    address_line_0 VARCHAR(255) DEFAULT '',
    address_line_1 VARCHAR(255) DEFAULT '',
    city          VARCHAR(100) DEFAULT '',
    postal_code   VARCHAR(20)  DEFAULT '',
    state_code    VARCHAR(5)   DEFAULT '',
    country_code  VARCHAR(5)   DEFAULT 'MYS',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- INVOICES
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS invoices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no      VARCHAR(50)   NOT NULL UNIQUE COMMENT 'e.g. INV-2026-0001',
    customer_id     INT UNSIGNED  DEFAULT NULL,
    -- Snapshot of customer at time of invoice (in case customer changes later)
    customer_name   VARCHAR(255)  NOT NULL DEFAULT '',
    customer_tin    VARCHAR(50)   DEFAULT '',
    customer_reg_no VARCHAR(100)  DEFAULT '',
    customer_email  VARCHAR(255)  DEFAULT '',
    customer_phone  VARCHAR(50)   DEFAULT '',
    customer_address TEXT,
    -- Invoice dates
    invoice_date    DATE          NOT NULL,
    due_date        DATE          DEFAULT NULL,
    -- Amounts
    subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_type        ENUM('none','sst6','sst10','service6') NOT NULL DEFAULT 'none',
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency        CHAR(3)       NOT NULL DEFAULT 'MYR',
    -- Invoice meta
    notes           TEXT,
    status          ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    pdf_path        VARCHAR(500)  DEFAULT NULL,
    -- LHDN fields
    lhdn_invoice_type VARCHAR(5)  DEFAULT '01' COMMENT '01=Invoice,02=Credit Note,03=Debit Note',
    msic_code       VARCHAR(20)   DEFAULT '',
    payment_method  VARCHAR(5)    DEFAULT '01' COMMENT '01=Cash,02=Cheque,03=Transfer',
    -- Timestamps
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- INVOICE LINE ITEMS
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS invoice_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED  NOT NULL,
    description     VARCHAR(500)  NOT NULL,
    quantity        DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_type        ENUM('none','sst6','sst10','service6') NOT NULL DEFAULT 'none',
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    classification  VARCHAR(20)   DEFAULT '' COMMENT 'LHDN product/service classification',
    sort_order      SMALLINT      NOT NULL DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- INVOICE NUMBER SEQUENCE
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS invoice_sequences (
    prefix    VARCHAR(10)  NOT NULL DEFAULT 'INV',
    year      YEAR         NOT NULL,
    next_no   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- LHDN SUBMISSIONS LOG
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS lhdn_submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED  NOT NULL,
    environment     ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    submission_uid  VARCHAR(100)  DEFAULT NULL COMMENT 'UUID from LHDN /documentsubmissions',
    document_uuid   VARCHAR(100)  DEFAULT NULL COMMENT 'Per-document UUID',
    long_id         VARCHAR(255)  DEFAULT NULL COMMENT 'For QR code URL',
    status          ENUM('pending','valid','invalid','cancelled') NOT NULL DEFAULT 'pending',
    error_message   TEXT          DEFAULT NULL,
    raw_request     LONGTEXT      DEFAULT NULL,
    raw_response    LONGTEXT      DEFAULT NULL,
    submitted_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    validated_at    DATETIME      DEFAULT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
-- DEFAULT ADMIN USER
-- Password: admin123  (bcrypt hash)
-- Change this immediately after first login!
-- ─────────────────────────────────────────────

INSERT IGNORE INTO users (id, name, email, password) VALUES (
    1,
    'Admin',
    'admin@company.com',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
);

-- ─────────────────────────────────────────────
-- AUDIT LOGS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  DEFAULT NULL,
    user_name   VARCHAR(255)  DEFAULT NULL,
    action      VARCHAR(100)  NOT NULL,
    table_name  VARCHAR(100)  DEFAULT NULL,
    record_id   INT UNSIGNED  DEFAULT NULL,
    old_value   LONGTEXT      DEFAULT NULL COMMENT 'JSON of old data',
    new_value   LONGTEXT      DEFAULT NULL COMMENT 'JSON of new data',
    ip_address  VARCHAR(45)   DEFAULT NULL,
    user_agent  VARCHAR(500)  DEFAULT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user    (user_id),
    INDEX idx_table   (table_name, record_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
