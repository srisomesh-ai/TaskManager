-- ============================================================
-- BHARAT GPS TASK MANAGER - COMPLETE OPTIMIZED SCHEMA v2
-- Run this in Hostinger phpMyAdmin → SQL tab
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100)  NOT NULL,
    email         VARCHAR(150)  UNIQUE NOT NULL,
    password      VARCHAR(255)  NOT NULL,
    role          ENUM('admin','assigner','technician') DEFAULT 'technician',
    phone         VARCHAR(20),
    is_active     TINYINT(1)    DEFAULT 1,
    auth_token    VARCHAR(64)   NULL DEFAULT NULL,
    last_active   TIMESTAMP     NULL DEFAULT NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role        (role),
    INDEX idx_auth_token  (auth_token),
    INDEX idx_is_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: tasks
-- ============================================================
CREATE TABLE IF NOT EXISTS tasks (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    task_id                   VARCHAR(20)    UNIQUE NOT NULL,
    customer_name             VARCHAR(150)   NOT NULL,
    contact_number            VARCHAR(20)    NOT NULL,
    email                     VARCHAR(150),
    location                  VARCHAR(200),

    -- Lead & Job
    lead_type                 ENUM(
                                'New Lead','Demo','3rd Party Lead','Troubleshoot',
                                'Existing Customer Lead','Outstation Lead',
                                'Re-Adding','Vehicle to Vehicle Change'
                              ) DEFAULT 'New Lead',
    device_details            VARCHAR(255),
    device_qty                INT            DEFAULT 1,

    -- Pricing
    price_to_collect          DECIMAL(10,2)  DEFAULT 0,
    payment_mode              VARCHAR(50)    DEFAULT '',
    payment_status            ENUM('Pending','Collected','Partial') DEFAULT 'Pending',
    amount_collected          DECIMAL(10,2)  DEFAULT 0,
    payment_reminder_date     DATE           NULL,

    -- Assignment
    assigned_to               INT            NULL,
    transferred_from          INT            NULL,
    created_by                INT            NOT NULL,

    -- Status
    task_status               ENUM(
                                'Open','In Progress','Task Pending','Demo Sent',
                                'Awaiting Approval','Closed','Cancelled','Transferred'
                              ) DEFAULT 'Open',
    is_urgent                 TINYINT(1)     DEFAULT 0,
    is_outstation             TINYINT(1)     DEFAULT 0,
    customer_requested_delay  TINYINT(1)     DEFAULT 0,

    -- Notes & Dates
    general_notes             TEXT,
    reminder_date             DATE           NULL,
    closed_at                 TIMESTAMP      NULL,
    created_at                TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (assigned_to)    REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (transferred_from) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)     REFERENCES users(id),

    -- Indexes for fast queries
    INDEX idx_task_status     (task_status),
    INDEX idx_assigned_to     (assigned_to),
    INDEX idx_created_at      (created_at),
    INDEX idx_updated_at      (updated_at),
    INDEX idx_is_urgent       (is_urgent),
    INDEX idx_lead_type       (lead_type),
    INDEX idx_reminder        (reminder_date),
    INDEX idx_payment_status  (payment_status),
    INDEX idx_contact         (contact_number),
    FULLTEXT idx_search       (customer_name, location, contact_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: task_activities
-- ============================================================
CREATE TABLE IF NOT EXISTS task_activities (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    task_id       INT         NOT NULL,
    user_id       INT         NOT NULL,
    remark        TEXT        NOT NULL,
    activity_type ENUM(
                    'remark','status_change','payment_update',
                    'assignment','document_upload','install','system'
                  ) DEFAULT 'remark',
    created_at    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id)  REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id),
    INDEX idx_task_id   (task_id),
    INDEX idx_user_id   (user_id),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: task_documents
-- ============================================================
CREATE TABLE IF NOT EXISTS task_documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    task_id       INT         NOT NULL,
    doc_type      ENUM('aadhar','rc','selfie','other') NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    original_name VARCHAR(255),
    uploaded_by   INT         NOT NULL,
    uploaded_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id)     REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_task_id (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: payments
-- ============================================================
CREATE TABLE IF NOT EXISTS payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    task_id         INT            NOT NULL,
    amount          DECIMAL(10,2)  NOT NULL,
    payment_mode    ENUM('Cash','UPI','Bank Transfer') NOT NULL,
    transaction_ref VARCHAR(100),
    collected_by    INT            NOT NULL,
    notes           TEXT,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id)      REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (collected_by) REFERENCES users(id),
    INDEX idx_task_id   (task_id),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sync_log  ← NEW: tracks last update for auto-refresh
-- ============================================================
CREATE TABLE IF NOT EXISTS sync_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    event_type  VARCHAR(50)  NOT NULL,
    task_id     INT          NULL,
    user_id     INT          NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFAULT USERS
-- Password for all: Bharat@123
-- Hash: $2y$10$YourHashHere (update via admin panel after setup)
-- ============================================================
INSERT IGNORE INTO users (name, email, password, role, phone) VALUES
('Somesh',          'somesh9346220090@gmail.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',    '9346220090'),
('Office Staff',    'office@bharatgps.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'assigner', ''),
('Md Khaja',        'khaja@bharatgps.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician',''),
('Diddi Ganapathi', 'ganapathi@bharatgps.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician',''),
('Sheikh Ali',      'ali@bharatgps.com',           '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician',''),
('Kiran',           'kiran@bharatgps.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician',''),
('Ravindra',        'ravindra@bharatgps.com',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician',''),
('Pilli Srinu',     'srinu@bharatgps.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician',''),
('Jakeer',          'jakeer@bharatgps.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician','');

-- ============================================================
-- AUTO-MIGRATIONS (safe to run on existing DB)
-- ============================================================
ALTER TABLE users  ADD COLUMN IF NOT EXISTS auth_token  VARCHAR(64)  NULL DEFAULT NULL;
ALTER TABLE users  ADD COLUMN IF NOT EXISTS last_active TIMESTAMP    NULL DEFAULT NULL;
ALTER TABLE tasks  ADD COLUMN IF NOT EXISTS payment_reminder_date DATE NULL;
ALTER TABLE tasks  ADD COLUMN IF NOT EXISTS transferred_from      INT  NULL;
ALTER TABLE tasks  ADD COLUMN IF NOT EXISTS is_urgent             TINYINT(1) DEFAULT 0;
ALTER TABLE tasks  MODIFY COLUMN IF EXISTS task_status ENUM(
    'Open','In Progress','Task Pending','Demo Sent',
    'Awaiting Approval','Closed','Cancelled','Transferred'
) DEFAULT 'Open';
ALTER TABLE tasks  MODIFY COLUMN IF EXISTS payment_mode VARCHAR(50) DEFAULT '';
ALTER TABLE task_activities MODIFY COLUMN IF EXISTS activity_type ENUM(
    'remark','status_change','payment_update',
    'assignment','document_upload','install','system'
) DEFAULT 'remark';

-- ============================================================
-- USEFUL VIEWS (optional, for reporting)
-- ============================================================

CREATE OR REPLACE VIEW v_task_summary AS
SELECT
    t.id, t.task_id, t.customer_name, t.contact_number,
    t.location, t.lead_type, t.device_details, t.device_qty,
    t.price_to_collect, t.payment_mode, t.payment_status, t.amount_collected,
    t.task_status, t.is_urgent, t.is_outstation,
    t.general_notes, t.reminder_date, t.payment_reminder_date,
    t.created_at, t.updated_at, t.closed_at,
    u.name  AS technician_name,
    u.phone AS technician_phone,
    cb.name AS created_by_name,
    TIMESTAMPDIFF(HOUR, t.created_at, NOW()) AS age_hours
FROM tasks t
LEFT JOIN users u  ON t.assigned_to = u.id
LEFT JOIN users cb ON t.created_by  = cb.id;

CREATE OR REPLACE VIEW v_daily_stats AS
SELECT
    DATE(created_at)                                      AS date,
    COUNT(*)                                              AS total_tasks,
    SUM(task_status = 'Closed')                           AS closed,
    SUM(task_status = 'Open')                             AS open,
    SUM(task_status = 'Awaiting Approval')                AS awaiting,
    SUM(task_status = 'Cancelled')                        AS cancelled,
    SUM(price_to_collect = 0)                             AS free_services,
    SUM(payment_status = 'Collected')                     AS paid,
    SUM(device_details = 'Engine Status')                 AS engine_status,
    SUM(device_details = 'Troubleshoot/Offline')          AS troubleshoot,
    SUM(device_details = 'Demonstration')                 AS demos,
    SUM(COALESCE(price_to_collect,0))                     AS total_value,
    SUM(COALESCE(amount_collected,0))                     AS total_collected
FROM tasks
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- ============================================================
-- DONE
-- ============================================================
SELECT 'Schema setup complete ✅' AS status;

-- ============================================================
-- BALANCE SHEET TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS balance_sheet_entries (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    type                        ENUM('sales','license') NOT NULL DEFAULT 'sales',
    profile                     ENUM('BGPT','SBGT') NOT NULL DEFAULT 'BGPT',
    task_id                     VARCHAR(20) NULL,                -- links to tasks.task_id
    task_db_id                  INT NULL,                        -- links to tasks.id
    date                        DATE NOT NULL,
    invoice_no                  VARCHAR(50),
    gps_serial_no               VARCHAR(100),                    -- IMEI / VIN
    customer_type               VARCHAR(50),
    name_on_server              TEXT,                            -- comma separated names
    server_name                 VARCHAR(50),
    device_model                VARCHAR(100),
    service_type                VARCHAR(100),
    license_plan                VARCHAR(100),
    qty                         DECIMAL(10,2) DEFAULT 1,
    unit_price                  DECIMAL(10,2) DEFAULT 0,
    gst                         DECIMAL(10,2) DEFAULT 0,
    total_price                 DECIMAL(10,2) DEFAULT 0,
    payment_status              VARCHAR(50),
    payment_received            DECIMAL(10,2) DEFAULT 0,
    pending_payment             DECIMAL(10,2) DEFAULT 0,
    payment_mode                VARCHAR(50),
    payment_received_on         DATE NULL,
    payment_transaction_details TEXT,
    pending_reason              VARCHAR(100),
    discount_given              DECIMAL(10,2) DEFAULT 0,
    discount_reason             TEXT,
    discount_incharge           VARCHAR(100),
    payment_reminder_date       DATE NULL,
    technician_name             VARCHAR(100),
    location                    VARCHAR(200),
    remarks                     TEXT,
    created_by_code             VARCHAR(50),
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_task_id       (task_id),
    INDEX idx_task_db_id    (task_db_id),
    INDEX idx_type          (type),
    INDEX idx_profile       (profile),
    INDEX idx_date          (date),
    INDEX idx_payment_status(payment_status),
    FOREIGN KEY (task_db_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EXTRA COLUMNS FOR TASKS TABLE (balance sheet linkage)
-- ============================================================
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS gps_serial_no              VARCHAR(100) NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS name_on_server             TEXT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS server_name                VARCHAR(50) NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS invoice_no                 VARCHAR(50) NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS payment_received_on        DATE NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS payment_transaction_details TEXT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS gst_amount                 DECIMAL(10,2) DEFAULT 0;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS pending_reason             VARCHAR(100) NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS discount_reason            TEXT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS discount_incharge          VARCHAR(100) NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS profile                    ENUM('BGPT','SBGT') DEFAULT 'BGPT';
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id                INT NULL COMMENT 'Linked balance sheet entry';
