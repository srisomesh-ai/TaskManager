<?php
// ============================================================
// BHARAT GPS TASK MANAGER — DB CONNECTION (PHP 7 compatible)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'u943205660_bharatgps');
define('DB_USER', 'u943205660_bharatgps');
define('DB_PASS', 'kTrV>Le6+');

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            )
        );
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET time_zone='+05:30'");

        $migrations = array(
            // Users
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_token VARCHAR(64) NULL DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active TIMESTAMP NULL DEFAULT NULL",
            // Tasks — existing
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS payment_reminder_date DATE NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS transferred_from INT NULL DEFAULT NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS is_urgent TINYINT(1) DEFAULT 0",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS star_rating TINYINT(1) DEFAULT NULL",
            // Tasks — balance sheet linkage fields
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS gps_serial_no VARCHAR(100) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS name_on_server TEXT NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS server_name VARCHAR(50) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(50) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS payment_received_on DATE NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS payment_transaction_details TEXT NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS gst_amount DECIMAL(10,2) DEFAULT 0",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS pending_reason VARCHAR(100) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS discount_reason TEXT NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS discount_incharge VARCHAR(100) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS profile VARCHAR(10) DEFAULT 'BGPT'",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id INT NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_location VARCHAR(200) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_travel_paid_by VARCHAR(20) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_customer_travel_amount DECIMAL(10,2) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_claim_cap DECIMAL(10,2) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_claim_submitted DECIMAL(10,2) NULL",
            "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_claim_status VARCHAR(20) NULL",
            // System tables
            "CREATE TABLE IF NOT EXISTS sync_log (id INT AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(50) NOT NULL, task_id INT NULL, user_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS used_tokens (id INT AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(64) UNIQUE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // Balance sheet entries table
            "CREATE TABLE IF NOT EXISTS balance_sheet_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('sales','license') NOT NULL DEFAULT 'sales',
                profile VARCHAR(10) NOT NULL DEFAULT 'BGPT',
                task_id VARCHAR(20) NULL,
                task_db_id INT NULL,
                date DATE NOT NULL,
                invoice_no VARCHAR(50),
                gps_serial_no VARCHAR(100),
                customer_type VARCHAR(50),
                name_on_server TEXT,
                server_name VARCHAR(50),
                device_model VARCHAR(100),
                service_type VARCHAR(100),
                license_plan VARCHAR(100),
                qty DECIMAL(10,2) DEFAULT 1,
                unit_price DECIMAL(10,2) DEFAULT 0,
                gst DECIMAL(10,2) DEFAULT 0,
                total_price DECIMAL(10,2) DEFAULT 0,
                payment_status VARCHAR(50),
                payment_received DECIMAL(10,2) DEFAULT 0,
                pending_payment DECIMAL(10,2) DEFAULT 0,
                payment_mode VARCHAR(50),
                payment_received_on DATE NULL,
                payment_transaction_details TEXT,
                pending_reason VARCHAR(100),
                discount_given DECIMAL(10,2) DEFAULT 0,
                discount_reason TEXT,
                discount_incharge VARCHAR(100),
                payment_reminder_date DATE NULL,
                technician_name VARCHAR(100),
                location VARCHAR(200),
                remarks TEXT,
                created_by_code VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_task_id (task_id),
                INDEX idx_date (date),
                INDEX idx_profile (profile),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // ── INVOICING TABLES ──────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS inv_items (
                id VARCHAR(40) PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                hsn VARCHAR(50),
                code VARCHAR(100),
                unit VARCHAR(20) DEFAULT 'PCS',
                category VARCHAR(100),
                description TEXT,
                mrp DECIMAL(12,2) DEFAULT 0,
                sale_price DECIMAL(12,2) DEFAULT 0,
                purchase_price DECIMAL(12,2) DEFAULT 0,
                gst_rate DECIMAL(5,2) DEFAULT 18,
                opening_stock INT DEFAULT 0,
                stock_in INT DEFAULT 0,
                stock_out INT DEFAULT 0,
                low_stock_alert INT DEFAULT 5,
                location VARCHAR(200),
                is_service TINYINT(1) DEFAULT 0,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS inv_parties (
                id VARCHAR(40) PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                phone VARCHAR(30),
                email VARCHAR(200),
                gstin VARCHAR(20),
                gst_type VARCHAR(50) DEFAULT 'Unregistered/Consumer',
                billing_address TEXT,
                state VARCHAR(100),
                opening_balance DECIMAL(12,2) DEFAULT 0,
                balance_type ENUM('receivable','payable') DEFAULT 'receivable',
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS inv_invoices (
                id VARCHAR(40) PRIMARY KEY,
                inv_no VARCHAR(50) NOT NULL,
                inv_type ENUM('sale','estimate','proforma','purchase','payin','payout','saleorder','challan') DEFAULT 'sale',
                date DATE NOT NULL,
                due_date DATE,
                party_id VARCHAR(40),
                customer VARCHAR(200),
                billing_name VARCHAR(200),
                po_no VARCHAR(100),
                gstin VARCHAR(20),
                gst_type VARCHAR(50),
                state VARCHAR(100),
                billing_address TEXT,
                pay_mode VARCHAR(50),
                cash_sale TINYINT(1) DEFAULT 0,
                gst_split VARCHAR(20) DEFAULT 'GST',
                cgst DECIMAL(12,2) DEFAULT 0,
                sgst DECIMAL(12,2) DEFAULT 0,
                igst DECIMAL(12,2) DEFAULT 0,
                items_json LONGTEXT,
                sub_total DECIMAL(12,2) DEFAULT 0,
                discount_total DECIMAL(12,2) DEFAULT 0,
                gst_total DECIMAL(12,2) DEFAULT 0,
                grand_total DECIMAL(12,2) DEFAULT 0,
                amount_received DECIMAL(12,2) DEFAULT 0,
                terms TEXT,
                notes TEXT,
                task_id_ref INT DEFAULT NULL,
                status ENUM('draft','active','cancelled') DEFAULT 'active',
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_inv_no (inv_no),
                INDEX idx_date (date),
                INDEX idx_type (inv_type),
                INDEX idx_customer (customer),
                INDEX idx_task (task_id_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS inv_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value LONGTEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS price_list (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                product_name VARCHAR(200) NOT NULL,
                category    VARCHAR(100) NOT NULL DEFAULT 'GPS Device',
                server_name VARCHAR(100) DEFAULT NULL,
                description TEXT         DEFAULT NULL,
                buying_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
                price_excl_gst DECIMAL(10,2) NOT NULL DEFAULT 0,
                gst_percent    DECIMAL(5,2)  NOT NULL DEFAULT 18,
                price_incl_gst DECIMAL(10,2) NOT NULL DEFAULT 0,
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                sort_order  INT          NOT NULL DEFAULT 0,
                created_by  VARCHAR(100) DEFAULT NULL,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE price_list ADD COLUMN IF NOT EXISTS buying_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER description",

            "CREATE TABLE IF NOT EXISTS stock_items (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(150) NOT NULL,
                category    VARCHAR(50)  NOT NULL,
                model       VARCHAR(100) DEFAULT NULL,
                unit        VARCHAR(20)  NOT NULL DEFAULT 'Pcs',
                opening_bal INT          NOT NULL DEFAULT 0,
                min_stock   INT          NOT NULL DEFAULT 5,
                notes       TEXT         DEFAULT NULL,
                created_by  VARCHAR(100) DEFAULT NULL,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS stock_movements (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                item_id     INT          NOT NULL,
                move_type   VARCHAR(20)  NOT NULL,
                qty         INT          NOT NULL DEFAULT 1,
                tech_name   VARCHAR(100) DEFAULT NULL,
                ref_note    VARCHAR(255) DEFAULT NULL,
                move_date   DATE         NOT NULL,
                done_by     VARCHAR(100) DEFAULT NULL,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* ignore — safe */ }
        }

    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(array('success' => false, 'error' => 'DB error: ' . $e->getMessage())));
    }
    return $pdo;
}

function logSync($pdo, $event, $taskId = null, $userId = null) {
    try {
        $pdo->prepare("INSERT INTO sync_log (event_type,task_id,user_id) VALUES (?,?,?)")
            ->execute(array($event, $taskId, $userId));
        $pdo->exec("DELETE FROM sync_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    } catch (Exception $e) {}
}

function touchUserActive($pdo, $userId) {
    try {
        $pdo->prepare("UPDATE users SET last_active=NOW() WHERE id=?")->execute(array($userId));
    } catch (Exception $e) {}
}
