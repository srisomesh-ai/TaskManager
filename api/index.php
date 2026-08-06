<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');


header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/fcm_send.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($body, $_POST);
}

$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_GET['token'] ?? '';

$pdo = getDB();
// Migration: admin_viewed_at per task (track when admin last viewed)
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN admin_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
try {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS demo_interest VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS demo_followup_date DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS demo_converted_at DATETIME DEFAULT NULL");
    // Full demo report fields — saved so the form can be re-opened read-only with exact prior answers
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS demo_report_json TEXT DEFAULT NULL");
} catch(Exception $e){}
// ── Migration: cash deposit columns ──────────────────────────────────────
try {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_status VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_method VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_handover_to VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_date DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_ref VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_notes TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_submitted_at DATETIME DEFAULT NULL");
} catch(Exception $e){}




// Auth
$skipAuth = ['login','ping','verify_pin'];
$cu = null; $userId = null; $userRole = null;
if (!in_array($action, $skipAuth)) {
    if ($token) {
        // Multi-session: match the token against active sessions first (lets the same
        // account stay logged in on multiple PCs simultaneously).
        try {
            $ss = $pdo->prepare("SELECT u.* FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND u.is_active=1 LIMIT 1");
            $ss->execute([$token]);
            $cu = $ss->fetch() ?: null;
            if ($cu) {
                try { $pdo->prepare("UPDATE auth_sessions SET last_active=NOW() WHERE token=?")->execute([$token]); } catch(Exception $e){}
            }
        } catch(Exception $e){ $cu = null; }
        // Fallback for older sessions / clients still using users.auth_token
        if (!$cu) {
            $s = $pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");
            $s->execute([$token]);
            $cu = $s->fetch() ?: null;
        }
    }
    if (!$cu) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
    $userId   = $cu['id'];
    $userRole = $cu['role'];
}

// ── Coin / earnings helpers ──
function _ensureCoinLedger($pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS coin_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        task_id INT NULL,
        coins INT NOT NULL,
        reason VARCHAR(190) NOT NULL,
        event_key VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        UNIQUE KEY uniq_event (event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Award (or deduct) coins. event_key makes it idempotent — same key won't double-award.
// Balance sheet category:
//   License  -> known service/renewal job types (troubleshoot, offline, demo, remove, re-adding, v2v/vehicle change)
//   Sales    -> everything else = a new device installation (hardware sale)
// NOTE: a normal installation task does NOT store the word "install" in device_details
// (it holds the device model, or is blank). The app itself treats any device_details that
// is not a known service type as an installation. We mirror that here.
function _discountBudgetEnsureTable($pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS discount_budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        approver_name VARCHAR(100) NOT NULL UNIQUE,
        monthly_limit DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_by VARCHAR(100) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Sum of discounts given under an approver's name in the last 7 days (rolling window).
// Optionally exclude a specific task id (so editing a task does not count its own old value twice).
function _discountUsed7d($pdo, $approver, $excludeTaskId=null){
    if($approver===null || $approver==='') return 0.0;
    $sql = "SELECT COALESCE(SUM(discount_given),0) FROM tasks
            WHERE discount_incharge = ? AND COALESCE(discount_given,0) > 0
            AND created_at >= (NOW() - INTERVAL 7 DAY)";
    $params = [$approver];
    if($excludeTaskId){ $sql .= " AND id <> ?"; $params[] = intval($excludeTaskId); }
    $st = $pdo->prepare($sql); $st->execute($params);
    return floatval($st->fetchColumn());
}
// Returns the monthly limit for an approver, or null if none set (null = unlimited).
function _discountMonthlyLimit($pdo, $approver){
    if($approver===null || $approver==='') return null;
    try {
        $st = $pdo->prepare("SELECT monthly_limit FROM discount_budgets WHERE approver_name=?");
        $st->execute([$approver]);
        $v = $st->fetchColumn();
        if($v===false || $v===null) return null;
        return floatval($v);
    } catch(Exception $e){ return null; }
}
// Check whether $approver can give $amount now. Returns [allowed(bool), reason(string), info(array)].
// Rule: weekly cap = monthly/4; block if (used last 7 days + new amount) > weekly cap.
function _discountCheck($pdo, $approver, $amount, $excludeTaskId=null){
    $amount = floatval($amount);
    if($amount <= 0) return [true, '', []];
    _discountBudgetEnsureTable($pdo);
    $monthly = _discountMonthlyLimit($pdo, $approver);
    if($monthly === null || $monthly <= 0){
        return [true, '', ['limit_set'=>false]]; // no budget set => unlimited
    }
    $weekly = round($monthly / 4, 2);
    $used   = _discountUsed7d($pdo, $approver, $excludeTaskId);
    $remaining = round($weekly - $used, 2);
    $info = ['limit_set'=>true,'monthly'=>$monthly,'weekly'=>$weekly,'used_7d'=>$used,'remaining'=>$remaining];
    if(($used + $amount) > $weekly + 0.001){
        $reason = 'Discount limit for '.$approver.' is finished for now — this discount cannot be approved under this name. Please choose another approver or a smaller amount.';
        return [false, $reason, $info];
    }
    return [true, '', $info];
}
function _renewalEnsureTable($pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ref VARCHAR(30),
        status VARCHAR(20) DEFAULT 'pending',
        server_id INT NOT NULL,
        server_name VARCHAR(100),
        device_id VARCHAR(40) NOT NULL,
        device_name VARCHAR(190),
        imei VARCHAR(50),
        plate VARCHAR(100),
        owner VARCHAR(190),
        current_expiry DATE NULL,
        months INT DEFAULT 12,
        label VARCHAR(40),
        new_expiry DATE NULL,
        price_item VARCHAR(190),
        amount DECIMAL(10,2) DEFAULT 0,
        gst DECIMAL(10,2) DEFAULT 0,
        requested_by INT,
        requested_by_name VARCHAR(190),
        requested_role VARCHAR(30),
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_by VARCHAR(190),
        approved_at DATETIME NULL,
        notes TEXT,
        payment_screenshot VARCHAR(255),
        bs_entry_id INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE renewal_requests ADD COLUMN payment_screenshot VARCHAR(255) NULL"); } catch(Exception $e){}
}

function _readdingEnsureTable($pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS readding_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ref VARCHAR(30),
        status VARCHAR(20) DEFAULT 'pending',
        name VARCHAR(190) NOT NULL,
        model VARCHAR(100),
        imei VARCHAR(50) NOT NULL,
        vin VARCHAR(100),
        sim VARCHAR(50),
        plate_number VARCHAR(100),
        registration_number VARCHAR(100),
        object_owner VARCHAR(190),
        server_id INT NOT NULL,
        requested_by INT,
        requested_by_name VARCHAR(190),
        requested_role VARCHAR(30),
        device_id VARCHAR(50),
        approved_by VARCHAR(190),
        approved_at DATETIME NULL,
        cancelled_by VARCHAR(190),
        cancelled_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Upgrade older tables that predate the new fields
    try { $pdo->exec("ALTER TABLE readding_requests ADD COLUMN plate_number VARCHAR(100) NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE readding_requests ADD COLUMN registration_number VARCHAR(100) NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE readding_requests ADD COLUMN object_owner VARCHAR(190) NULL"); } catch(Exception $e){}
}

function bs_type_for_task($deviceDetails){
    $j = strtolower(trim($deviceDetails ?? ''));
    $serviceKeywords = ['troubleshoot','offline','demo','demonstration','remove','re-add','readd','re add','vehicle change','v2v','vehicle to vehicle','renewal','renew'];
    foreach ($serviceKeywords as $kw) {
        if (strpos($j, $kw) !== false) return 'license';
    }
    // Not a recognised service job -> it is a new installation -> Sales
    return 'sales';
}

// ── APPRECIATIONS (for ZERO-VALUE tasks) ───────────────────────────────────
// Zero-price tasks (Troubleshoot / Demo / free service) earn NO coins, because coins are
// real withdrawable money and those jobs bring no revenue. Instead they earn Appreciations:
// +1 on-time, -1 when neglected. Every 10 appreciations auto-converts to 1 PAID LEAVE.
function _ensureAppreciationTables($pdo){
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS appreciation_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, task_id INT NULL,
            points INT NOT NULL DEFAULT 0, reason VARCHAR(200) DEFAULT NULL,
            event_key VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_appr_event (event_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS paid_leaves (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, days DECIMAL(5,2) NOT NULL DEFAULT 1,
            source VARCHAR(120) DEFAULT 'appreciations', note VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Track consumption: admin marks a leave as used when the technician actually takes it.
        try { $pdo->exec("ALTER TABLE paid_leaves ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'available'"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE paid_leaves ADD COLUMN used_on DATE DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE paid_leaves ADD COLUMN used_note VARCHAR(200) DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE paid_leaves ADD COLUMN marked_by VARCHAR(100) DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE paid_leaves ADD COLUMN marked_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    } catch(Exception $e){}
}
function appreciation_balance($pdo, $userId){
    try { _ensureAppreciationTables($pdo);
        $s=$pdo->prepare("SELECT COALESCE(SUM(points),0) FROM appreciation_ledger WHERE user_id=?");
        $s->execute([$userId]); return intval($s->fetchColumn());
    } catch(Exception $e){ return 0; }
}
function paid_leave_total($pdo, $userId){
    try { _ensureAppreciationTables($pdo);
        // Only leaves still AVAILABLE (not yet consumed) count as balance.
        $s=$pdo->prepare("SELECT COALESCE(SUM(days),0) FROM paid_leaves WHERE user_id=? AND COALESCE(status,'available')<>'used'");
        $s->execute([$userId]); return floatval($s->fetchColumn());
    } catch(Exception $e){ return 0; }
}
function paid_leave_used_total($pdo, $userId){
    try { _ensureAppreciationTables($pdo);
        $s=$pdo->prepare("SELECT COALESCE(SUM(days),0) FROM paid_leaves WHERE user_id=? AND status='used'");
        $s->execute([$userId]); return floatval($s->fetchColumn());
    } catch(Exception $e){ return 0; }
}
// Award (or deduct) appreciations. Auto-converts every 10 into 1 paid leave.
function award_appreciation($pdo, $userId, $points, $reason, $taskId = null, $eventKey = null, $pushTitle = null, $pushBody = null){
    try {
        if (!$userId || !$points) return false;
        _ensureAppreciationTables($pdo);
        $inserted = false;
        if ($eventKey) {
            $st=$pdo->prepare("INSERT IGNORE INTO appreciation_ledger (user_id,task_id,points,reason,event_key) VALUES (?,?,?,?,?)");
            $st->execute([$userId,$taskId,$points,$reason,$eventKey]);
            $inserted = $st->rowCount() > 0;
        } else {
            $st=$pdo->prepare("INSERT INTO appreciation_ledger (user_id,task_id,points,reason) VALUES (?,?,?,?)");
            $st->execute([$userId,$taskId,$points,$reason]);
            $inserted = true;
        }
        if (!$inserted) return true; // duplicate event, already counted

        $bal = appreciation_balance($pdo, $userId);
        // Auto-convert: every 10 appreciations => 1 paid leave (deduct 10, add 1 leave)
        $converted = 0;
        while ($bal >= 10) {
            $pdo->prepare("INSERT INTO appreciation_ledger (user_id,task_id,points,reason) VALUES (?,?,?,?)")
                ->execute([$userId, null, -10, 'Converted 10 appreciations to 1 paid leave']);
            $pdo->prepare("INSERT INTO paid_leaves (user_id,days,source,note) VALUES (?,?,?,?)")
                ->execute([$userId, 1, 'appreciations', 'Earned from 10 appreciations']);
            $converted++; $bal -= 10;
        }
        if (function_exists('fcm_send_to_user')) {
            try {
                if ($converted > 0) {
                    fcm_send_to_user($pdo,$userId,'🏆 Paid Leave Earned!',
                        'You earned '.$converted.' paid leave from 10 appreciations. Appreciations left: '.$bal.'.',
                        ['type'=>'appreciation','url'=>'earnings.html']);
                } else {
                    if ($pushTitle === null) $pushTitle = $points>=0 ? ('👏 +'.$points.' Appreciation') : ('⚠️ '.$points.' Appreciation');
                    if ($pushBody === null)  $pushBody  = $reason.' · Total: '.$bal.'/10 toward a paid leave';
                    fcm_send_to_user($pdo,$userId,$pushTitle,$pushBody,['type'=>'appreciation','url'=>'earnings.html']);
                }
            } catch(Exception $e){}
        }
        return true;
    } catch(Exception $e){ error_log('award_appreciation: '.$e->getMessage()); return false; }
}
// Is this task a zero-value (free) job? Zero price => appreciation instead of coins.
function task_is_zero_value($pdo, $taskId){
    try {
        $s=$pdo->prepare("SELECT COALESCE(price_to_collect,0) FROM tasks WHERE id=?");
        $s->execute([$taskId]);
        return floatval($s->fetchColumn()) <= 0;
    } catch(Exception $e){ return false; }
}
// Is this task a Handover? Handover = device given to customer to self-install; NO reward at all.
function task_is_handover($pdo, $taskId){
    try {
        $s=$pdo->prepare("SELECT lead_type FROM tasks WHERE id=?");
        $s->execute([$taskId]);
        return strcasecmp(trim((string)$s->fetchColumn()), 'Handover') === 0;
    } catch(Exception $e){ return false; }
}
// Router: paid task -> coins (real money). Zero-value task -> appreciations. Handover -> nothing.
function award_task_reward($pdo, $userId, $coins, $reason, $taskId = null, $eventKey = null, $pushTitle = null, $pushBody = null){
    // Handover tasks never award coins or appreciations to anyone (no visit, self-installed).
    if ($taskId && task_is_handover($pdo, $taskId)) { return false; }
    if ($taskId && task_is_zero_value($pdo, $taskId)) {
        $pts = $coins > 0 ? 1 : ($coins < 0 ? -1 : 0);
        if ($pts === 0) { return award_coins($pdo,$userId,0,$reason,$taskId,$eventKey,$pushTitle,$pushBody); } // warnings stay as-is
        $ak = $eventKey ? ('appr_'.$eventKey) : null;
        return award_appreciation($pdo,$userId,$pts,$reason.' (free service)',$taskId,$ak);
    }
    return award_coins($pdo,$userId,$coins,$reason,$taskId,$eventKey,$pushTitle,$pushBody);
}

function award_coins($pdo, $userId, $coins, $reason, $taskId = null, $eventKey = null, $pushTitle = null, $pushBody = null){
    try {
        if (!$userId) return false;
        _ensureCoinLedger($pdo);
        $inserted = false;
        if ($eventKey) {
            $st = $pdo->prepare("INSERT IGNORE INTO coin_ledger (user_id,task_id,coins,reason,event_key) VALUES (?,?,?,?,?)");
            $st->execute([$userId, $taskId, $coins, $reason, $eventKey]);
            $inserted = $st->rowCount() > 0;
        } else {
            $st = $pdo->prepare("INSERT INTO coin_ledger (user_id,task_id,coins,reason) VALUES (?,?,?,?)");
            $st->execute([$userId, $taskId, $coins, $reason]);
            $inserted = true;
        }
        // Send a push alert only when coins were actually awarded (not a duplicate/idempotent skip)
        if ($inserted && function_exists('fcm_send_to_user')) {
            if ($pushTitle === null) {
                $pushTitle = $coins >= 0 ? ('🎉 +'.$coins.' coins!') : ('⚠️ '.$coins.' coins');
            }
            if ($pushBody === null) $pushBody = $reason;
            try {
                fcm_send_to_user($pdo, $userId, $pushTitle, $pushBody, [
                    'type'    => 'coins',
                    'coins'   => (string)$coins,
                    'task_id' => (string)($taskId ?? ''),
                    'url'     => 'earnings.html',
                ]);
            } catch(Exception $e){}
        }
        return true;
    } catch(Exception $e){ error_log('award_coins: '.$e->getMessage()); return false; }
}

// ── Cash-deposit penalty ────────────────────────────────────────────────────
// Rules: a technician who holds cash pending deposit for MORE than 4 days is penalised
// 50 coins per 6-hour DAYTIME window (08:00 and 14:00 slots — two windows per day) until
// the deposit is confirmed. 50 total per window regardless of how many tasks. Idempotent
// per window via a deterministic event_key so it never double-charges.
// Returns the technician's oldest pending age in DAYS (0 if none / not overdue).

// How many days of cash the technician has been holding (oldest pending, tasks + manual entries).
function cash_oldest_pending_days($pdo, $techId){
    if(!$techId) return 0;
    $oldest = null;
    try {
        $r = $pdo->prepare("SELECT MIN(cash_pending_at) FROM tasks
            WHERE assigned_to=? AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending'
              AND COALESCE(amount_collected,0)>0 AND cash_pending_at IS NOT NULL");
        $r->execute([$techId]); $v=$r->fetchColumn(); if($v) $oldest=$v;
    } catch(Exception $e){}
    try {
        $r2 = $pdo->prepare("SELECT MIN(date) FROM balance_sheet_entries
            WHERE technician_id=? AND COALESCE(pending_payment,0)>0 AND (task_db_id IS NULL OR task_db_id=0)");
        $r2->execute([$techId]); $v2=$r2->fetchColumn();
        if($v2){ if(!$oldest || $v2<$oldest) $oldest=$v2; }
    } catch(Exception $e){}
    if(!$oldest) return 0;
    $days = (int)floor((time()-strtotime($oldest))/86400);
    return max(0,$days);
}

// Apply any owed penalty windows for one technician. Safe to call often (idempotent).
function apply_cash_penalty($pdo, $techId){
    if(!$techId) return;
    try {
        $r = $pdo->prepare("SELECT MIN(cash_pending_at) oldest FROM tasks
            WHERE assigned_to=? AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending'
              AND COALESCE(amount_collected,0)>0 AND cash_pending_at IS NOT NULL");
        $r->execute([$techId]); $ot=$r->fetchColumn();
        $om=null;
        $r2 = $pdo->prepare("SELECT MIN(date) FROM balance_sheet_entries
            WHERE technician_id=? AND COALESCE(pending_payment,0)>0 AND (task_db_id IS NULL OR task_db_id=0)");
        $r2->execute([$techId]); $om=$r2->fetchColumn();
        $oldest=null;
        if($ot) $oldest=$ot;
        if($om && (!$oldest || $om<$oldest)) $oldest=$om;
        if(!$oldest) return; // nothing pending
        $startTs = strtotime($oldest);
        $graceEnd = $startTs + 4*86400;           // penalty begins only AFTER 4 full days
        $now = time();
        if($now <= $graceEnd) return;             // still within grace
        // Walk each DAYTIME 6-hour window (08:00 and 14:00) from grace end to now.
        // For each elapsed window, deduct 50 once (idempotent by event_key).
        $dayStart = strtotime(date('Y-m-d 00:00:00', $graceEnd));
        $maxWindows = 400; // safety cap
        $count=0;
        for($d=$dayStart; $d <= $now && $count<$maxWindows; $d += 86400){
            foreach ([8,14] as $h){                // 08:00 and 14:00 daytime slots
                $winTs = $d + $h*3600;
                if($winTs <= $graceEnd) continue;  // before penalties start
                if($winTs > $now) continue;        // future window
                $count++;
                $key = 'cashpen_'.$techId.'_'.date('Ymd_H', $winTs);
                award_coins($pdo, $techId, -50, 'Cash deposit overdue penalty ('.date('d M H:i',$winTs).')', null, $key,
                    '⚠️ -50 coins', 'Cash deposit is overdue. Deposit now to stop further penalty.');
            }
        }
    } catch(Exception $e){ error_log('apply_cash_penalty: '.$e->getMessage()); }
}

// Current coin balance for a user.
function coin_balance($pdo, $userId){
    try { _ensureCoinLedger($pdo); $s=$pdo->prepare("SELECT COALESCE(SUM(coins),0) FROM coin_ledger WHERE user_id=?"); $s->execute([$userId]); return intval($s->fetchColumn()); }
    catch(Exception $e){ return 0; }
}

// Expenses table (manual operating expenses)
// Ensure the shared expenses table exists (same schema used by the P&L/accounts feature).
function _ensureExpensesTable($pdo){
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS expenses(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',date DATE,category VARCHAR(50),description TEXT,amount DECIMAL(10,2),payment_mode VARCHAR(50),paid_to VARCHAR(100),reference VARCHAR(100),receipt_note TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e){}
}

function _ensureYearlyTables($pdo){
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS yearly_bills (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, category VARCHAR(60), amount DECIMAL(12,2) DEFAULT 0, due_month VARCHAR(3) DEFAULT '1', due_day VARCHAR(6) DEFAULT '1', vendor VARCHAR(150) NULL, payment_mode VARCHAR(50) NULL, active TINYINT(1) DEFAULT 1, created_by VARCHAR(60), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS yearly_bill_payments (id INT AUTO_INCREMENT PRIMARY KEY, bill_id INT NOT NULL, yr VARCHAR(4) NOT NULL, expense_id INT NULL, paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_bill_year (bill_id, yr)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e){}
}

function _ensureMonthlyTables($pdo){
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_bills (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, category VARCHAR(60), amount DECIMAL(12,2) DEFAULT 0, due_day VARCHAR(6) DEFAULT '1', vendor VARCHAR(150) NULL, payment_mode VARCHAR(50) NULL, active TINYINT(1) DEFAULT 1, created_by VARCHAR(60), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_bill_payments (id INT AUTO_INCREMENT PRIMARY KEY, bill_id INT NOT NULL, ym VARCHAR(7) NOT NULL, expense_id INT NULL, paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_bill_month (bill_id, ym)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e){}
}

// ── Block-time tracking (for payroll absent calculation) ───────────────────────
// When a technician crosses into the blocked state (cash > 4 days overdue) we stamp the exact
// time the block STARTED (today onward — never backdated). When they deposit, the period closes.
// Accumulated blocked minutes are summed; every full 24h (1440 min) = 1 absent day.
function _ensureBlockLog($pdo){
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tech_block_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            blocked_at DATETIME NOT NULL,
            unblocked_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e){}
}

// Is this technician currently blocked? (cash > 4 days overdue with a real pending amount)
function is_tech_blocked($pdo, $techId){
    if(!$techId) return false;
    $days = cash_oldest_pending_days($pdo, $techId);
    if($days <= 4) return false;
    $amt = 0.0;
    try { $r=$pdo->prepare("SELECT COALESCE(SUM(amount_collected),0) FROM tasks WHERE assigned_to=? AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending' AND COALESCE(amount_collected,0)>0"); $r->execute([$techId]); $amt+=floatval($r->fetchColumn()); } catch(Exception $e){}
    try { $r2=$pdo->prepare("SELECT COALESCE(SUM(pending_payment),0) FROM balance_sheet_entries WHERE technician_id=? AND COALESCE(pending_payment,0)>0 AND (task_db_id IS NULL OR task_db_id=0)"); $r2->execute([$techId]); $amt+=floatval($r2->fetchColumn()); } catch(Exception $e){}
    return ($amt > 0);
}

// Open a block period when blocked, close it when unblocked. Stamps start time NOW (not backdated).
function sync_block_log($pdo, $techId){
    if(!$techId) return;
    _ensureBlockLog($pdo);
    try {
        $open = $pdo->prepare("SELECT id FROM tech_block_log WHERE user_id=? AND unblocked_at IS NULL ORDER BY id DESC LIMIT 1");
        $open->execute([$techId]); $openId = $open->fetchColumn();
        $blocked = is_tech_blocked($pdo, $techId);
        if($blocked && !$openId){
            $pdo->prepare("INSERT INTO tech_block_log (user_id, blocked_at) VALUES (?, NOW())")->execute([$techId]);
        } elseif(!$blocked && $openId){
            $pdo->prepare("UPDATE tech_block_log SET unblocked_at=NOW() WHERE id=?")->execute([intval($openId)]);
        }
    } catch(Exception $e){ error_log('sync_block_log: '.$e->getMessage()); }
}

// Total accumulated blocked minutes (closed periods + any currently-open one).
function tech_block_minutes($pdo, $techId){
    if(!$techId) return ['minutes'=>0,'blocked_now'=>false,'started_at'=>null];
    _ensureBlockLog($pdo);
    try {
        $c = $pdo->prepare("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, blocked_at, unblocked_at)),0) FROM tech_block_log WHERE user_id=? AND unblocked_at IS NOT NULL");
        $c->execute([$techId]); $mins = intval($c->fetchColumn());
        $o = $pdo->prepare("SELECT blocked_at FROM tech_block_log WHERE user_id=? AND unblocked_at IS NULL ORDER BY id DESC LIMIT 1");
        $o->execute([$techId]); $startedAt = $o->fetchColumn();
        $blockedNow = false;
        if($startedAt){
            $blockedNow = true;
            $mins += max(0, (int)floor((time()-strtotime($startedAt))/60));
        }
        return ['minutes'=>$mins, 'blocked_now'=>$blockedNow, 'started_at'=>$startedAt?:null];
    } catch(Exception $e){ return ['minutes'=>0,'blocked_now'=>false,'started_at'=>null]; }
}

// Count DAYTIME hours (08:00–20:00) elapsed between two unix timestamps. Overnight time is paused.
function daytime_hours_between($fromTs, $toTs){
    if($toTs <= $fromTs) return 0;
    $DAY_START = 8; $DAY_END = 20;   // 8 AM to 8 PM
    $secs = 0;
    // Walk day by day.
    $cur = strtotime(date('Y-m-d 00:00:00', $fromTs));
    $guard = 0;
    while($cur <= $toTs && $guard < 400){
        $guard++;
        $winStart = $cur + $DAY_START*3600;
        $winEnd   = $cur + $DAY_END*3600;
        $s = max($winStart, $fromTs);
        $e = min($winEnd, $toTs);
        if($e > $s) $secs += ($e - $s);
        $cur += 86400;
    }
    return $secs / 3600.0;
}

// Penalise a technician if a task was OPENED but NO action was taken (no activity, still active).
// A warning is sent first; penalties (-50 per 6 daytime-hour window) start only AFTER the warning,
// and the clock never starts before the rollout date so pre-existing tasks are counted from today onward.
define('OPENED_NOACTION_ROLLOUT', '2026-07-16 00:00:00');  // count from today onwards
function apply_opened_no_action_penalty($pdo, $taskId){
    try {
        $t = $pdo->prepare("SELECT id, assigned_to, tech_viewed_at, task_status FROM tasks WHERE id=?");
        $t->execute([$taskId]); $row = $t->fetch();
        if(!$row) return;
        if(empty($row['assigned_to'])) return;
        if(empty($row['tech_viewed_at'])) return;                       // not opened yet — handled elsewhere
        if(in_array($row['task_status'], ['Closed','Cancelled','Awaiting Approval','Task Pending'])) return;
        // Any technician activity means action was taken → no penalty.
        $ac = $pdo->prepare("SELECT COUNT(*) FROM task_activities WHERE task_id=? AND user_id=?");
        $ac->execute([$taskId, $row['assigned_to']]);
        if(intval($ac->fetchColumn()) > 0) return;
        // Clock starts at the later of (opened time, rollout date).
        $openedTs = strtotime($row['tech_viewed_at']);
        $rolloutTs = strtotime(OPENED_NOACTION_ROLLOUT);
        $startTs = max($openedTs, $rolloutTs);
        $dh = daytime_hours_between($startTs, time());
        // First: a one-time WARNING at 3 daytime hours (no coin deduction yet).
        if($dh >= 3){
            award_coins($pdo, intval($row['assigned_to']), 0, 'Warning: task opened but no action taken', $taskId, 'noact_warn_'.$taskId,
                '⚠️ Action required', 'You opened a task but took no action. Update it now (call / status / install) or coins will start reducing.');
        }
        // Then: -50 per 6 daytime-hour window AFTER the warning point (starting 6h after the 3h warning = 9h+).
        if($dh >= 9){
            $windowsPassed = intval(floor(($dh - 3) / 6));   // windows after the 3h warning
            for($w=1; $w<=$windowsPassed; $w++){
                $winHour = 3 + ($w*6);
                $key = 'noact_'.$taskId.'_w'.$w;
                award_task_reward($pdo, intval($row['assigned_to']), -50, 'No action on opened task ('.$winHour.'h)', $taskId, $key,
                    '⚠️ -50 coins', 'A task you opened still has no action. Please update it immediately to stop further penalty.');
            }
        }
    } catch(Exception $e){ error_log('apply_opened_no_action_penalty: '.$e->getMessage()); }
}

// Penalise a technician -50 ONCE if a task assigned to them was not opened within 3 DAYTIME hours.
// Idempotent per task via event_key. Only applies while the task is still unopened & active.
function apply_unopened_penalty($pdo, $taskId){
    try {
        $t = $pdo->prepare("SELECT id, assigned_to, tech_viewed_at, assigned_at, created_at, task_status FROM tasks WHERE id=?");
        $t->execute([$taskId]); $row = $t->fetch();
        if(!$row) return;
        if(empty($row['assigned_to'])) return;
        if(!empty($row['tech_viewed_at'])) return;         // already opened — no penalty
        if(in_array($row['task_status'], ['Closed','Cancelled'])) return;
        $startStr = !empty($row['assigned_at']) ? $row['assigned_at'] : $row['created_at'];
        if(!$startStr) return;
        $startTs = strtotime($startStr);
        $dh = daytime_hours_between($startTs, time());
        if($dh >= 3){
            $key = 'unopened_'.$taskId;   // one-time per task
            award_task_reward($pdo, intval($row['assigned_to']), -50, 'Task not opened within 3 daytime hours', $taskId, $key,
                '⚠️ -50 coins', 'A task assigned to you was not opened within 3 hours. Please check your tasks promptly.');
        }
    } catch(Exception $e){ error_log('apply_unopened_penalty: '.$e->getMessage()); }
}

// Sweep all unopened assigned tasks and apply the not-opened penalty where due.
function sweep_unopened_penalties($pdo){
    try {
        $rows = $pdo->query("SELECT id FROM tasks
            WHERE assigned_to IS NOT NULL AND tech_viewed_at IS NULL
              AND task_status NOT IN ('Closed','Cancelled')
              AND COALESCE(assigned_at, created_at) < (NOW() - INTERVAL 3 HOUR)")->fetchAll(PDO::FETCH_COLUMN);
        foreach($rows as $tid){ apply_unopened_penalty($pdo, intval($tid)); }
    } catch(Exception $e){ error_log('sweep_unopened_penalties: '.$e->getMessage()); }
}

// Sweep tasks that were OPENED but have no technician action yet (active statuses only).
function sweep_opened_no_action($pdo){
    try {
        $rows = $pdo->query("SELECT id FROM tasks
            WHERE assigned_to IS NOT NULL AND tech_viewed_at IS NOT NULL
              AND task_status IN ('Open','In Progress')
              AND tech_viewed_at < (NOW() - INTERVAL 3 HOUR)")->fetchAll(PDO::FETCH_COLUMN);
        foreach($rows as $tid){ apply_opened_no_action_penalty($pdo, intval($tid)); }
    } catch(Exception $e){ error_log('sweep_opened_no_action: '.$e->getMessage()); }
}

// ── Device sync helpers ──
function _devEnsureTables($pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS server_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imei VARCHAR(40) NOT NULL,
        device_name VARCHAR(190) DEFAULT '',
        status VARCHAR(20) DEFAULT '',
        technician VARCHAR(120) DEFAULT '',
        server VARCHAR(60) DEFAULT '',
        device_id VARCHAR(40) DEFAULT '',
        synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_imei (imei)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS received_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imei VARCHAR(40) NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        note VARCHAR(190) DEFAULT '',
        UNIQUE KEY uq_rimei (imei)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS device_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imei VARCHAR(40) NOT NULL,
        device_name VARCHAR(190) DEFAULT '',
        model VARCHAR(60) DEFAULT '',
        server VARCHAR(60) DEFAULT '',
        technician VARCHAR(120) DEFAULT '',
        technician_id INT DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'with_tech',
        assigned_by VARCHAR(120) DEFAULT '',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_aimei (imei)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // add model column if table pre-existed without it
    try { $pdo->exec("ALTER TABLE device_assignments ADD COLUMN model VARCHAR(60) DEFAULT '' AFTER device_name"); } catch(Exception $e){}
}
function _devNorm($imei){ return preg_replace('/\D/', '', (string)$imei); } // digits only

// Ensure every task with installed devices has a balance-sheet entry (billing only installed devices).
// Safe to run repeatedly — creates missing entries, updates existing ones to match installed count.
function _bsSyncInstalls($pdo, $cuName){
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id INT NULL"); } catch(Exception $e){}
    $rows = $pdo->query("SELECT DISTINCT t.id FROM tasks t
                         JOIN task_device_installs di ON di.task_id=t.id
                         WHERE di.gps_serial_no IS NOT NULL AND di.gps_serial_no != ''")->fetchAll(PDO::FETCH_COLUMN);
    $created=0; $updated=0;
    foreach ($rows as $tid) {
        $tr = $pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
        $tr->execute([$tid]); $t2 = $tr->fetch();
        if (!$t2) continue;
        $di = $pdo->prepare("SELECT gps_serial_no,name_on_server,server_name FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL AND gps_serial_no != '' ORDER BY device_index ASC");
        $di->execute([$tid]); $installs = $di->fetchAll();
        $installedCount = count($installs);
        if ($installedCount < 1) continue;
        // Free services (zero price or Troubleshoot/Demo) never belong in the balance sheet.
        $syncLead = strtolower(trim((string)($t2['lead_type'] ?? '')));
        if (floatval($t2['price_to_collect'] ?? 0) <= 0 || in_array($syncLead, ['troubleshoot','demo'])) continue;
        $allSerials = implode(', ', array_filter(array_column($installs,'gps_serial_no')));
        $allNames   = implode(', ', array_filter(array_column($installs,'name_on_server')));
        $serverName = $installs[0]['server_name'] ?? $t2['server_name'] ?? null;
        // Mark each installed device's assignment as installed (leaves with-tech stock)
        foreach ($installs as $ins) {
            $im = preg_replace('/\D/','',(string)($ins['gps_serial_no']??''));
            if ($im!=='') { try { $pdo->prepare("UPDATE device_assignments SET status='installed' WHERE imei=? AND status='with_tech'")->execute([$im]); } catch(Exception $e){} }
        }
        $fullQty   = intval($t2['device_qty']??1); if($fullQty<1)$fullQty=1;
        $fullTotal = floatval($t2['price_to_collect']??0);
        // Use the per-device unit_price captured at creation; fall back to total÷qty for old tasks.
        $storedUnit = isset($t2['unit_price']) ? floatval($t2['unit_price']) : 0;
        $unit2     = $storedUnit>0 ? $storedUnit : ($fullQty>0 ? $fullTotal/$fullQty : $fullTotal);
        $billQty   = $installedCount;
        $billTotal = round($unit2*$billQty, 2);
        $recv2     = floatval($t2['amount_collected']??0); if($recv2>$billTotal)$recv2=$billTotal;
        // CASH is only truly RECEIVED once the technician has deposited it and admin confirmed
        // (cash_deposit_status='deposited'). While pending/submitted, the cash is still with the
        // technician, so the balance sheet must show it as PENDING, not received.
        $isCash2 = strtolower((string)($t2['payment_mode']??''))==='cash';
        if ($isCash2 && ($t2['cash_deposit_status']??'') !== 'deposited') { $recv2 = 0; }
        $pend2     = max(0, $billTotal-$recv2);
        $pStatus   = ($recv2>=$billTotal && $billTotal>0) ? 'paid' : ($recv2>0 ? 'partially_paid' : 'pending');
        $profile2  = !empty($t2['profile']) ? $t2['profile'] : 'BGPT';

        // FREE service (price 0) — do not create a balance sheet entry.
        // If one was created earlier, remove it and clear the link.
        if ($billTotal <= 0) {
            if (!empty($t2['bs_entry_id'])) {
                try { $pdo->prepare("DELETE FROM balance_sheet_entries WHERE id=?")->execute([intval($t2['bs_entry_id'])]); } catch(Exception $e){}
                try { $pdo->prepare("UPDATE tasks SET bs_entry_id=NULL WHERE id=?")->execute([$tid]); } catch(Exception $e){}
            }
            continue;
        }

        if (!empty($t2['bs_entry_id'])) {
            $pdo->prepare("UPDATE balance_sheet_entries SET gps_serial_no=?,name_on_server=?,server_name=?,qty=?,unit_price=?,total_price=?,payment_received=?,pending_payment=?,payment_status=?,updated_at=NOW() WHERE id=?")
                ->execute([$allSerials?:null,$allNames?:null,$serverName,$billQty,$unit2,$billTotal,$recv2,$pend2,$pStatus,intval($t2['bs_entry_id'])]);
            $updated++;
        } else {
            $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code) VALUES (?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([bs_type_for_task($t2['device_details']??''),$profile2,$t2['task_id'],$tid,$allSerials?:null,$t2['lead_type']??null,$allNames?:null,$serverName,$t2['device_details']??null,$billQty,$unit2,floatval($t2['gst_amount']??0),$billTotal,$pStatus,$recv2,$pend2,$t2['payment_mode']??null,$t2['tech_name']??null,$t2['location']??null,$t2['general_notes']??null,$cuName??'system']);
            $bsId=$pdo->lastInsertId();
            if($bsId){ $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$tid]); }
            $created++;
        }
    }
    return ['created'=>$created,'updated'=>$updated,'scanned'=>count($rows)];
}

function _ensureTripColumns($pdo){
    $cols = [
        "cust_loc_lat DECIMAL(10,6) DEFAULT NULL",
        "cust_loc_lng DECIMAL(10,6) DEFAULT NULL",
        "cust_loc_at DATETIME DEFAULT NULL",
        "loc_token VARCHAR(64) DEFAULT NULL",
        "trip_start_lat DECIMAL(10,6) DEFAULT NULL",
        "trip_start_lng DECIMAL(10,6) DEFAULT NULL",
        "trip_start_at DATETIME DEFAULT NULL",
        "trip_reach_lat DECIMAL(10,6) DEFAULT NULL",
        "trip_reach_lng DECIMAL(10,6) DEFAULT NULL",
        "trip_reach_at DATETIME DEFAULT NULL",
        "trip_km DECIMAL(8,2) DEFAULT NULL",
        "trip_minutes INT DEFAULT NULL",
    ];
    foreach($cols as $c){ try { $pdo->exec("ALTER TABLE tasks ADD COLUMN $c"); } catch(Exception $e){} }
}
function _haversineKm($la1,$lo1,$la2,$lo2){
    $R=6371; $dLat=deg2rad($la2-$la1); $dLng=deg2rad($lo2-$lo1);
    $a=sin($dLat/2)**2 + cos(deg2rad($la1))*cos(deg2rad($la2))*sin($dLng/2)**2;
    return round($R*2*atan2(sqrt($a),sqrt(1-$a)),2);
}

switch ($action) {

// ---- PING ----
case 'ping':
    echo json_encode(['ok'=>true]);
    break;

// ---- LOGIN ----
case 'make_req_link':
    require_once __DIR__.'/req_token.php';
    $rtype = trim($body['type'] ?? $_GET['type'] ?? '');
    $allowed = ['troubleshoot','vehicle-change','gps-remove','re-adding'];
    if(!in_array($rtype, $allowed)){ echo json_encode(['error'=>'Invalid type']); break; }
    $rprice = isset($body['price']) ? floatval($body['price']) : 0;
    $rgst   = !empty($body['gst']) ? 1 : 0;
    $tok = reqMakeToken($rtype, 6, $rprice, $rgst);
    echo json_encode(['success'=>true,'token'=>$tok,'type'=>$rtype,'price'=>$rprice,'gst'=>$rgst,'expires_hours'=>6]);
    break;

case 'login':
    $email = trim($body['email'] ?? '');
    $pass  = $body['password'] ?? '';
    $s = $pdo->prepare("SELECT * FROM users WHERE email=? AND is_active=1");
    $s->execute([$email]);
    $user = $s->fetch();
    if ($user && password_verify($pass, $user['password'])) {
        $tok = bin2hex(random_bytes(32));
        // Multi-session: store each login as its own session so the same account
        // can be open on several PCs at once without logging each other out.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(80) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_active DATETIME NULL,
                UNIQUE KEY uniq_token (token),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT INTO auth_sessions (user_id, token, last_active) VALUES (?,?,NOW())")->execute([$user['id'], $tok]);
            // Housekeeping: drop sessions older than 30 days
            $pdo->prepare("DELETE FROM auth_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->execute();
        } catch(Exception $e){ error_log('auth_sessions login: '.$e->getMessage()); }
        // Keep users.auth_token as the most-recent token for backward compatibility
        $pdo->prepare("UPDATE users SET auth_token=?, last_active=NOW() WHERE id=?")->execute([$tok, $user['id']]);
        echo json_encode(['success'=>true,'token'=>$tok,'user'=>[
            'id'=>$user['id'],'name'=>$user['name'],'role'=>$user['role'],'email'=>$user['email']
        ]]);
    } else {
        http_response_code(401);
        echo json_encode(['error'=>'Invalid email or password']);
    }
    break;

// ---- LOGOUT ----
case 'logout':
    // Remove only THIS session's token so other PCs on the same account stay logged in.
    if ($token) { try { $pdo->prepare("DELETE FROM auth_sessions WHERE token=?")->execute([$token]); } catch(Exception $e){} }
    // Only clear users.auth_token if it happens to be this exact token (don't kill others)
    if ($userId && $token) { $pdo->prepare("UPDATE users SET auth_token=NULL WHERE id=? AND auth_token=?")->execute([$userId, $token]); }
    echo json_encode(['success'=>true]);
    break;

// ---- ME ----
case 'me':
    echo json_encode(['user'=>['id'=>$cu['id'],'name'=>$cu['name'],'role'=>$cu['role'],'email'=>$cu['email']]]);
    break;

// ---- GET SYNC ----
case 'get_sync':
    $last = $pdo->query("SELECT MAX(updated_at) FROM tasks")->fetchColumn();
    $active = $pdo->query("SELECT name,role FROM users WHERE last_active > DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND is_active=1")->fetchAll();
    // Role-filtered counts
    if ($userRole === 'technician') {
        $taskCount = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=?"); $taskCount->execute([$userId]); $tc=$taskCount->fetchColumn();
        $urgentCount = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND task_status IN ('Open','In Progress') AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)"); $urgentCount->execute([$userId]); $uc=$urgentCount->fetchColumn();
    } else {
        $tc = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
        $uc = $pdo->query("SELECT COUNT(*) FROM tasks WHERE task_status IN ('Open','In Progress') AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
    echo json_encode(['last_task_update'=>$last,'active_users'=>$active,'server_time'=>date('Y-m-d H:i:s'),'task_count'=>intval($tc),'urgent_count'=>intval($uc)]);
    break;

// ---- GET STATS ----
case 'get_stats':
    $sql = "SELECT
        COUNT(*) total,
        SUM(task_status IN ('Open','In Progress','Task Pending')) open,
        SUM(task_status='Closed') closed,
        SUM(task_status='Cancelled') cancelled,
        SUM(task_status='Demo Sent') demo_sent,
        SUM(task_status='Demo Done') demo_done,
        SUM(task_status='Awaiting Approval') awaiting_approval,
        SUM(CASE WHEN task_status='Closed'
            AND device_details NOT IN ('Troubleshoot/Offline','Troubleshoot','Offline','Demo','Demonstration','Only Remove')
            THEN COALESCE(device_qty,1) ELSE 0 END) devices_installed,
        SUM(CASE WHEN task_status='Closed'
            AND device_details IN ('Troubleshoot/Offline','Troubleshoot','Offline')
            THEN 1 ELSE 0 END) troubleshoot_done,
        SUM(CASE WHEN task_status NOT IN ('Closed','Cancelled')
            AND amount_collected IS NOT NULL AND amount_collected < price_to_collect - 15
            AND EXISTS (SELECT 1 FROM task_device_installs di WHERE di.task_id=tasks.id AND di.gps_serial_no IS NOT NULL)
            THEN 1 ELSE 0 END) payment_pending
        FROM tasks";
    if($userRole === 'technician'){
        $st = $pdo->prepare($sql . " WHERE assigned_to=?");
        $st->execute([$userId]);
    } else {
        $st = $pdo->prepare($sql);
        $st->execute([]);
    }
    echo json_encode(['stats' => $st->fetch()]);
    break;

// ---- GET USERS ----
case 'get_users':
    $role = $_GET['role'] ?? '';
    if ($role && $role !== 'all') {
        // Specific role — only active (for dropdowns in task manager)
        $s = $pdo->prepare("SELECT id,name,email,role,phone,is_active,last_active FROM users WHERE role=? AND is_active=1 ORDER BY name");
        $s->execute([$role]);
    } elseif ($role === 'all') {
        // Admin panel — return ALL users including inactive
        $s = $pdo->query("SELECT id,name,email,role,phone,is_active,last_active FROM users ORDER BY role,name");
    } else {
        // No role filter — only active
        $s = $pdo->query("SELECT id,name,email,role,phone,is_active,last_active FROM users WHERE is_active=1 ORDER BY role,name");
    }
    echo json_encode(['users'=>$s->fetchAll()]);
    break;

// ---- SAVE USER ----
case 'save_user':
    if (!in_array($userRole,['admin'])) { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $uid  = intval($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $email= trim($body['email'] ?? '');
    $role = $body['role'] ?? 'technician';
    $phone= trim($body['phone'] ?? '');
    if (!$name||!$email) { echo json_encode(['error'=>'Name and email required']); break; }
    if ($uid) {
        $sets=[]; $vals=[];
        $sets[]='name=?';  $vals[]=$name;
        $sets[]='email=?'; $vals[]=$email;
        $sets[]='role=?';  $vals[]=$role;
        $sets[]='phone=?'; $vals[]=$phone;
        if (!empty($body['password'])) {
            $sets[]='password=?';
            $vals[]=password_hash($body['password'],PASSWORD_DEFAULT);
            $sets[]='auth_token=?';
            $vals[]=null; // Force user to re-login with new password
        }
        $vals[]=$uid;
        $pdo->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    } else {
        $hash = password_hash($body['password']??'Bharat@123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (name,email,password,role,phone,is_active) VALUES (?,?,?,?,?,1)")->execute([$name,$email,$hash,$role,$phone]);
    }
    echo json_encode(['success'=>true]);
    break;

// ---- RESET PASSWORD ----
case 'admin_reset_password':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $uid  = intval($body['id'] ?? 0);
    $pass = trim($body['password'] ?? '');
    if (!$uid || strlen($pass) < 6) { echo json_encode(['error'=>'User ID and password (min 6 chars) required']); break; }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password=?, auth_token=NULL WHERE id=?")->execute([$hash, $uid]);
    // Password changed → invalidate ALL of that user's sessions (security)
    try { $pdo->prepare("DELETE FROM auth_sessions WHERE user_id=?")->execute([$uid]); } catch(Exception $e){}
    echo json_encode(['success'=>true]);
    break;

// ---- DEACTIVATE USER ----
case 'deactivate_user':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $pdo->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([intval($body['id']??0)]);
    echo json_encode(['success'=>true]);
    break;

// ---- GET TASKS ----
case 'get_tasks':
    // Apply any due "not opened within 3 daytime hours" penalties (idempotent, cheap query).
    try { sweep_unopened_penalties($pdo); } catch(Exception $e){}
    // Apply "opened but no action taken" warnings/penalties (idempotent).
    try { sweep_opened_no_action($pdo); } catch(Exception $e){}
    $where=[]; $params=[];

    // ROLE-BASED FILTER — enforced server-side
    if ($userRole === 'technician') {
        $where[] = "t.assigned_to=?";
        $params[] = $userId;
    } else {
        // Admin/assigner filters
        if (!empty($_GET['assigned_to'])) { $where[]="t.assigned_to=?"; $params[]=$_GET['assigned_to']; }
        if (!empty($_GET['technician']))  { $where[]="t.assigned_to=?"; $params[]=$_GET['technician']; }
    }

    if (!empty($_GET['status']))    { $where[]="t.task_status=?"; $params[]=$_GET['status']; }
    if (!empty($_GET['lead_type'])) { $where[]="t.lead_type=?";   $params[]=$_GET['lead_type']; }
    if (!empty($_GET['date_from'])) { $where[]="DATE(t.created_at)>=?"; $params[]=$_GET['date_from']; }
    if (!empty($_GET['date_to']))   { $where[]="DATE(t.created_at)<=?"; $params[]=$_GET['date_to']; }
    // status_group filter: 'active' = exclude Closed/Cancelled
    if (!empty($_GET['status_group'])) {
        if($_GET['status_group'] === 'active'){
            $where[] = "t.task_status NOT IN ('Closed','Cancelled')";
        }
    }
    if (!empty($_GET['search']))    {
        $q='%'.$_GET['search'].'%';
        $where[]="(t.customer_name LIKE ? OR t.contact_number LIKE ? OR t.task_id LIKE ? OR t.location LIKE ?)";
        $params[]=$q; $params[]=$q; $params[]=$q; $params[]=$q;
    }

    $limit = min(intval($_GET['limit'] ?? 500), 1000);
    // Ensure admin_viewed_at column exists
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN admin_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_status VARCHAR(20) DEFAULT NULL");
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_method VARCHAR(50) DEFAULT NULL");
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_handover_to VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_date DATE DEFAULT NULL");
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_ref VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_deposit_notes TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS cash_submitted_at DATETIME DEFAULT NULL");
    } catch(Exception $e){}

    $sql = "SELECT t.*,u.name as tech_name,u.name as technician_name,u.phone as tech_phone,c.name as creator_name,
            (SELECT MAX(a.created_at) FROM task_activities a WHERE a.task_id=t.id AND a.activity_type='remark') as last_tech_activity,
            (CASE WHEN t.assigned_to IS NOT NULL AND t.tech_viewed_at IS NOT NULL
                   AND t.task_status IN ('Open','In Progress')
                   AND (SELECT COUNT(*) FROM task_activities a2 WHERE a2.task_id=t.id AND a2.user_id=t.assigned_to)=0
                  THEN 1 ELSE 0 END) as opened_no_action,
            t.admin_viewed_at,t.cash_deposit_status,t.cash_deposit_method,t.cash_handover_to,t.cash_deposit_date,t.cash_deposit_ref,t.cash_deposit_notes,t.cash_submitted_at
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to=u.id
            LEFT JOIN users c ON t.created_by=c.id"
         . ($where ? " WHERE ".implode(" AND ",$where) : "")
         . " ORDER BY t.created_at DESC LIMIT $limit";
    $s = $pdo->prepare($sql); $s->execute($params);
    $tasks = $s->fetchAll();

    // Build task ID list for bulk queries
    $taskIds = array_column($tasks, 'id');

    // Ensure device installs table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS task_device_installs (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, device_index INT DEFAULT 1, vehicle_number VARCHAR(50), vehicle_type VARCHAR(50), gps_serial_no VARCHAR(100), name_on_server VARCHAR(200), server_name VARCHAR(50), remarks TEXT, saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    // Bulk fetch: which tasks have device installs done + how many per task
    $addingDoneIds = [];
    $installCounts = [];
    if(!empty($taskIds)){
        try {
            $in = implode(',', array_map('intval', $taskIds));
            $diRows = $pdo->query("SELECT DISTINCT task_id FROM task_device_installs WHERE task_id IN ($in) AND gps_serial_no IS NOT NULL AND gps_serial_no != ''")->fetchAll(PDO::FETCH_ASSOC);
            $addingDoneIds = array_column($diRows, 'task_id');
            $cntRows = $pdo->query("SELECT task_id, COUNT(*) AS c FROM task_device_installs WHERE task_id IN ($in) AND gps_serial_no IS NOT NULL AND gps_serial_no != '' GROUP BY task_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach($cntRows as $cr){ $installCounts[$cr['task_id']] = intval($cr['c']); }
        } catch(Exception $e){ $addingDoneIds = []; }
    }

    // Compute workflow_state for each task
    foreach($tasks as &$task){
        $id           = $task['id'];
        $status       = $task['task_status']??'';
        $amtCollected = floatval($task['amount_collected']??0);
        $consentAt    = trim($task['customer_consent_at']??'');
        $consentToken = $task['consent_token']??'';
        $addingDone   = in_array($id, $addingDoneIds);
        $task['installed_count'] = $installCounts[$id] ?? 0;
        $lastActivity = $task['last_tech_activity']??null;
        $adminViewed  = $task['admin_viewed_at']??null;
        $hasUnseenUpdate = $lastActivity && (!$adminViewed || strcmp($lastActivity, $adminViewed) > 0);

        // Priority order: most actionable first
        $depositStatus = $task['cash_deposit_status']??'';
        if($status === 'Awaiting Approval' && $depositStatus === 'submitted'){
            $task['workflow_state'] = 'cash_submitted';      // Admin must verify deposit
        } elseif($status === 'Awaiting Approval' && $depositStatus === 'pending'){
            $task['workflow_state'] = 'cash_pending_deposit'; // Tech has cash, not deposited yet
        } elseif($status === 'Awaiting Approval'){
            $task['workflow_state'] = 'approve_now';          // Ready to approve
        } elseif($status === 'Demo Done'){
            $task['workflow_state'] = 'demo_done';
        } elseif($status === 'Demo Converted'){
            $task['workflow_state'] = '';
        } elseif($status === 'Closed' || $status === 'Cancelled'){
            $task['workflow_state'] = '';
        } elseif($addingDone && $amtCollected > 0 && ($task['cash_deposit_status']??'') === 'pending'){
            $task['workflow_state'] = 'cash_pending_deposit';
        } elseif($addingDone && $amtCollected <= 0){
            $task['workflow_state'] = 'payment_pending';
        } elseif($status === 'Task Pending' && $consentAt !== ''){
            // Postponed AFTER customer already consented — needs attention
            $task['workflow_state'] = 'postponed_after_consent';
        } elseif($consentAt !== '' && !$addingDone){
            $jobType = strtolower($task['device_details']??'');
            if(strpos($jobType,'demonstration')!==false || strpos($jobType,'demo')!==false){
                $task['workflow_state'] = 'ready_for_demo';
            } elseif(strpos($jobType,'troubleshoot')!==false || strpos($jobType,'offline')!==false){
                $task['workflow_state'] = 'ready_for_troubleshoot';
            } else {
                $task['workflow_state'] = 'ready_to_add';
            }
        } elseif($consentAt !== '' && $addingDone){
            $task['workflow_state'] = '';
        } elseif($consentToken && $consentToken !== 'USED' && $consentToken !== ''){
            $task['workflow_state'] = 'consent_sent';
        } elseif($hasUnseenUpdate){
            $task['workflow_state'] = 'tech_updated';
        } else {
            $task['workflow_state'] = '';
        }
    }
    unset($task);

    echo json_encode(['tasks'=>$tasks]);
    break;

// ---- GET TASK ----
case 'get_task':
    $id = intval($_GET['id'] ?? 0);
    $s = $pdo->prepare("SELECT t.*,u.name as technician_name,u.phone as tech_phone,c.name as creator_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN users c ON t.created_by=c.id WHERE t.id=?");
    $s->execute([$id]); $task=$s->fetch();
    if (!$task) { echo json_encode(['error'=>'Not found']); break; }
    // Technicians can only view their own assigned tasks
    if ($userRole === 'technician' && $task['assigned_to'] != $userId) {
        http_response_code(403); echo json_encode(['error'=>'Not authorized']); break;
    }
    // Stamp the first time the ASSIGNED technician opens the task (stops the 3-hour open timer).
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN tech_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    if ($userRole === 'technician' && $task['assigned_to'] == $userId && empty($task['tech_viewed_at'])) {
        try { $pdo->prepare("UPDATE tasks SET tech_viewed_at=NOW() WHERE id=? AND tech_viewed_at IS NULL")->execute([$id]); $task['tech_viewed_at']=date('Y-m-d H:i:s'); } catch(Exception $e){}
    }
    $a=$pdo->prepare("SELECT a.*,u.name as user_name FROM task_activities a LEFT JOIN users u ON a.user_id=u.id WHERE a.task_id=? ORDER BY a.created_at ASC"); $a->execute([$id]); $task['activities']=$a->fetchAll();
    $d=$pdo->prepare("SELECT * FROM task_documents WHERE task_id=?"); $d->execute([$id]); $task['documents']=$d->fetchAll();
    $p=$pdo->prepare("SELECT p.*,u.name as collector_name FROM payments p LEFT JOIN users u ON p.collected_by=u.id WHERE p.task_id=?"); $p->execute([$id]); $task['payments']=$p->fetchAll();
    // Include device installs so frontend knows if already added
    try {
        $di=$pdo->prepare("SELECT * FROM task_device_installs WHERE task_id=? ORDER BY device_index ASC");
        $di->execute([$id]); $task['device_installs']=$di->fetchAll();
    } catch(Exception $e){ $task['device_installs']=[]; }
    // SELF-HEAL: device_qty must never be less than the number of devices actually installed
    // (fixes tasks whose qty was wrongly reduced by older partial-finish logic).
    try {
        $instN = 0;
        foreach (($task['device_installs']??[]) as $diR) { if (!empty($diR['gps_serial_no'])) $instN++; }
        if ($instN > intval($task['device_qty']??1)) {
            $pdo->prepare("UPDATE tasks SET device_qty=? WHERE id=?")->execute([$instN, $id]);
            $task['device_qty'] = $instN;
        }
    } catch(Exception $e){}
    // ONE-TIME price correction for task ID-2026-1443 (original 6 devices @ Rs.3500 was lost when
    // the old partial-finish reduced the quantity, leaving an inflated 21000 total). Set the true
    // per-device price so it bills 3500 × installed. Runs once; harmless if already correct.
    try {
        if (($task['task_id']??'')==='ID-2026-1443' && floatval($task['unit_price']??0) != 3500) {
            try { $pdo->exec("ALTER TABLE tasks ADD COLUMN unit_price DECIMAL(10,2) DEFAULT NULL"); } catch(Exception $e2){}
            $instN2 = 0;
            foreach (($task['device_installs']??[]) as $diR2) { if (!empty($diR2['gps_serial_no'])) $instN2++; }
            if ($instN2 < 1) $instN2 = intval($task['device_qty']??1);
            $newTotal = 3500 * $instN2;
            $pdo->prepare("UPDATE tasks SET unit_price=3500, price_to_collect=? WHERE id=?")->execute([$newTotal, $id]);
            // Refresh its balance-sheet entry to match
            if (!empty($task['bs_entry_id'])) {
                $recvB = floatval($task['amount_collected']??0); if($recvB>$newTotal)$recvB=$newTotal;
                $pendB = max(0, $newTotal - $recvB);
                $stB   = ($recvB>=$newTotal && $newTotal>0)?'paid':($recvB>0?'partially_paid':'pending');
                $pdo->prepare("UPDATE balance_sheet_entries SET qty=?,unit_price=3500,total_price=?,payment_received=?,pending_payment=?,payment_status=?,updated_at=NOW() WHERE id=?")
                    ->execute([$instN2,$newTotal,$recvB,$pendB,$stB,intval($task['bs_entry_id'])]);
            }
            $task['unit_price']=3500; $task['price_to_collect']=$newTotal;
        }
    } catch(Exception $e){}
    echo json_encode(['task'=>$task]);
    break;

// ---- CREATE TASK ----
case 'create_task':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $year = date('Y');
    // Check for task ID offset (set via admin panel to start from a specific number)
    $idOffset = 0;
    try {
        $offRow = $pdo->query("SELECT key_value FROM app_settings WHERE key_name='task_id_offset'")->fetch();
        if($offRow) $idOffset = intval($offRow['key_value']);
    } catch(Exception $e){}
    // Use MAX(existing number) instead of COUNT() — COUNT breaks after deletions
    // (e.g. deleting tasks then creating new ones would reuse old IDs and collide).
    // MAX() always continues from the highest number ever issued this year, regardless of deletions.
    $maxRow = $pdo->query("SELECT MAX(CAST(SUBSTRING(task_id, 9) AS UNSIGNED)) AS maxnum FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetch();
    $maxNum = intval($maxRow['maxnum'] ?? 0);
    $nextNum = max($maxNum + 1, $idOffset + 1);
    $taskId = "ID-$year-".str_pad($nextNum,4,'0',STR_PAD_LEFT);
    // Safety: if this exact task_id somehow already exists (race condition), bump until free
    $guard = 0;
    while($guard < 20){
        $exists = $pdo->prepare("SELECT 1 FROM tasks WHERE task_id=? LIMIT 1");
        $exists->execute([$taskId]);
        if(!$exists->fetchColumn()) break;
        $nextNum++;
        $taskId = "ID-$year-".str_pad($nextNum,4,'0',STR_PAD_LEFT);
        $guard++;
    }
    $at  = !empty($body['assigned_to']) ? intval($body['assigned_to']) : null;
    $rd  = !empty($body['reminder_date']) ? $body['reminder_date'] : null;
    $prd = !empty($body['payment_reminder_date']) ? $body['payment_reminder_date'] : null;
    // Ensure discount columns exist
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN discount_given DECIMAL(10,2) DEFAULT 0"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN discount_reason VARCHAR(200) DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN discount_incharge VARCHAR(100) DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN vehicle_number VARCHAR(50) DEFAULT NULL"); } catch(Exception $e){}

    // Discount budget enforcement (hard block): approver's rolling-7-day discounts must stay within monthly/4.
    $dcAmt = floatval($body['discount_given'] ?? 0);
    $dcWho = trim($body['discount_incharge'] ?? '');
    if($dcAmt > 0 && $dcWho !== ''){
        list($dcOk, $dcReason) = _discountCheck($pdo, $dcWho, $dcAmt);
        if(!$dcOk){ http_response_code(400); echo json_encode(['error'=>$dcReason, 'discount_blocked'=>true]); break; }
    }

    try {
    $pdo->prepare("INSERT INTO tasks (task_id,customer_name,contact_number,email,location,lead_type,device_qty,price_to_collect,payment_mode,assigned_to,task_status,is_outstation,customer_requested_delay,is_urgent,general_notes,reminder_date,device_details,created_by,payment_reminder_date,profile,outstation_location,outstation_travel_paid_by,outstation_customer_travel_amount,outstation_claim_cap,discount_given,discount_reason,discount_incharge,feedback_token,vehicle_number)
        VALUES (?,?,?,?,?,?,?,?,?,?,'Open',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $taskId,
            trim($body['customer_name']??''), trim($body['contact_number']??''),
            trim($body['email']??''), trim($body['location']??''),
            $body['lead_type']??'New Lead', intval($body['device_qty']??1),
            floatval($body['price_to_collect']??0), $body['payment_mode']??'',
            $at, intval($body['is_outstation']??0), intval($body['customer_requested_delay']??0),
            intval($body['is_urgent']??0), trim($body['general_notes']??''),
            $rd, trim($body['device_details']??''), $userId, $prd,
            $body['profile']??'BGPT',
            $body['outstation_location']??null,
            $body['outstation_travel_paid_by']??null,
            $body['outstation_customer_travel_amount']??null,
            $body['outstation_claim_cap']??null,
            floatval($body['discount_given']??0),
            trim($body['discount_reason']??''),
            trim($body['discount_incharge']??''),
            $fbToken,
            trim($body['vehicle_number']??''),
        ]);
    } catch(Exception $insertEx){
        echo json_encode(['error'=>'Database error: '.$insertEx->getMessage()]);
        break;
    }
    // Capture the PER-DEVICE unit price at creation, so billing = unit_price × installed count
    // stays correct even if the quantity later changes (only some devices installed).
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN unit_price DECIMAL(10,2) DEFAULT NULL");
    } catch(Exception $e){}
    try {
        $newIdU = $pdo->lastInsertId();
        $qtyU   = intval($body['device_qty']??1); if($qtyU<1) $qtyU=1;
        $unitU  = isset($body['unit_price']) ? floatval($body['unit_price'])
                                             : (floatval($body['price_to_collect']??0) / $qtyU);
        $pdo->prepare("UPDATE tasks SET unit_price=? WHERE id=?")->execute([round($unitU,2), $newIdU]);
    } catch(Exception $e){}
    // Stamp assigned_at (start of the 3-hour open timer) when the task is created with a technician.
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN assigned_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    try {
        if (!empty($at)) { $pdo->prepare("UPDATE tasks SET assigned_at=NOW() WHERE id=? AND assigned_at IS NULL")->execute([$newIdU]); }
    } catch(Exception $e){}

    // ── HANDOVER: create the balance-sheet entry immediately at task creation ──
    // Normal tasks only get a BS entry after the device is installed (_bsSyncInstalls). A Handover
    // has no technician install, so we create its BS entry now using the manual price. When the
    // receipt/cash is later collected, update_task updates payment_received/pending on this entry.
    try {
        if (strcasecmp(trim($body['lead_type'] ?? ''), 'Handover') === 0) {
            $hoTotal = floatval($body['price_to_collect'] ?? 0);
            if ($hoTotal > 0) {
                $hoQty   = intval($body['device_qty'] ?? 1); if($hoQty<1) $hoQty=1;
                $hoUnit  = round($hoTotal / $hoQty, 2);
                $hoRecv  = floatval($body['amount_collected'] ?? 0);
                // Cash is only "received" once deposited & confirmed — at creation it's pending.
                if (strtolower((string)($body['payment_mode'] ?? '')) === 'cash') { $hoRecv = 0; }
                if ($hoRecv > $hoTotal) $hoRecv = $hoTotal;
                $hoPend  = max(0, $hoTotal - $hoRecv);
                $hoStat  = ($hoRecv >= $hoTotal && $hoTotal > 0) ? 'paid' : ($hoRecv > 0 ? 'partially_paid' : 'pending');
                $hoProfile = !empty($body['profile']) ? $body['profile'] : 'BGPT';
                $hoTechName = null;
                if (!empty($at)) { try { $tn=$pdo->prepare("SELECT name FROM users WHERE id=?"); $tn->execute([$at]); $hoTechName=$tn->fetchColumn() ?: null; } catch(Exception $e){} }
                $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,customer_type,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code) VALUES (?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        bs_type_for_task($body['device_details'] ?? ''), $hoProfile, $taskId, $newIdU,
                        'Handover', trim($body['device_details'] ?? ''), $hoQty, $hoUnit, 0, $hoTotal,
                        $hoStat, $hoRecv, $hoPend, $body['payment_mode'] ?? null, $hoTechName,
                        trim($body['location'] ?? ''), trim($body['general_notes'] ?? ''), $cu['name'] ?? 'system',
                    ]);
                $hoBsId = $pdo->lastInsertId();
                if ($hoBsId) { $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$hoBsId, $newIdU]); }
            }
        }
    } catch(Exception $e){ error_log('Handover BS create: '.$e->getMessage()); }

    // Override status for yellow outstation
    if (!empty($body['outstation_travel_paid_by']) && $body['outstation_travel_paid_by']==='COMPANY') {
        $newId2 = $pdo->lastInsertId();
        if (isset($body['task_status']) && $body['task_status']==='Pending Outstation Approval') {
            $pdo->prepare("UPDATE tasks SET task_status='Pending Outstation Approval' WHERE id=?")->execute([$newId2]);
            // Email admin
            try {
                require_once __DIR__.'/mailer.php';
                $adminEmail = 'somesh9346220090@gmail.com';
                $adminBody = emailTemplate('<div class="greeting">⚠️ Outstation Approval Required</div>
                <p style="font-size:14px;color:#4a5568">An outstation task needs your approval before assignment.</p>
                <div class="details">
                    <div class="row"><div class="label">Task ID</div><div class="value blue">'.$taskId.'</div></div>
                    <div class="row"><div class="label">Customer</div><div class="value">'.htmlspecialchars($body['customer_name']??'').'</div></div>
                    <div class="row"><div class="label">Location</div><div class="value">'.htmlspecialchars($body['outstation_location']??'').'</div></div>
                    <div class="row"><div class="label">Device</div><div class="value">'.htmlspecialchars($body['device_details']??'').' × '.$body['device_qty'].'</div></div>
                    <div class="row"><div class="label">Price</div><div class="value highlight">₹'.number_format($body['price_to_collect']??0).'</div></div>
                </div>
                <p style="margin-top:16px"><a href="https://salmon-goldfish-110661.hostingersite.com" style="background:#1a3a6b;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px">→ Review in Task Manager</a></p>');
                sendMail($adminEmail, 'Admin', '⚠️ Outstation Approval Required — '.$taskId, $adminBody);
            } catch(Exception $e) {}
        }
    }
    $newId = $pdo->lastInsertId();
    if (!empty($body['remark'])) $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")->execute([$newId,$userId,$body['remark']]);
    if ($at) {
        $tn=$pdo->prepare("SELECT name FROM users WHERE id=?"); $tn->execute([$at]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'assignment')")->execute([$newId,$userId,"Task assigned to ".$tn->fetchColumn()]);
    }

    // ── PUSH the assigned technician FIRST — before the response and before the mailer.
    // Previously this ran after echo + after require mailer.php, so a slow/failing mailer
    // meant the push never fired (coins/messages worked because they push before any echo).
    if ($at) {
        try {
            require_once __DIR__.'/fcm_send.php';
            if (function_exists('fcm_send_to_user')) {
                $pnRow = $pdo->prepare("SELECT customer_name,device_details,location FROM tasks WHERE id=?");
                $pnRow->execute([$newId]); $pnT = $pnRow->fetch(PDO::FETCH_ASSOC);
                $pnBody = trim(($pnT['customer_name'] ?? '').' · '.($pnT['device_details'] ?? 'GPS Installation'));
                if (!empty($pnT['location'])) $pnBody .= ' · '.$pnT['location'];
                fcm_send_to_user($pdo, $at, '🔔 New Task Assigned', $pnBody, [
                    'type'    => 'new_task',
                    'task_id' => (string)$newId,
                    'url'     => 'task.html?id='.$newId,
                ]);
            }
        } catch(Exception $e){ error_log('FCM new-task push error: '.$e->getMessage()); }
    }

    echo json_encode(['success'=>true,'task_id'=>$taskId,'id'=>$newId]);

    // Send emails — independently (customer and tech are separate)
    try {
        require_once __DIR__.'/mailer.php';
        $tr = $pdo->prepare("SELECT * FROM tasks WHERE id=?");
        $tr->execute([$newId]);
        $td = $tr->fetch();

        if($td){
            // Email technician if assigned
            if($at){
                $techQ = $pdo->prepare("SELECT name,email,phone FROM users WHERE id=?");
                $techQ->execute([$at]);
                $tc = $techQ->fetch();
                if($tc && !empty($tc['email'])){
                    sendTaskCreatedTech($td, $tc['email'], $tc['name']);
                }
            }

            // Email customer if email provided
            if(!empty($td['email'])){
                $techName  = '';
                $techPhone = '';
                if($at){
                    $techQ2 = $pdo->prepare("SELECT name,phone FROM users WHERE id=?");
                    $techQ2->execute([$at]);
                    $tc2 = $techQ2->fetch();
                    $techName  = $tc2['name']  ?? '';
                    $techPhone = $tc2['phone'] ?? '';
                }
                sendTaskCreatedCustomer($td, $techName, $techPhone);
            }
        }
    } catch(Exception $e){
        error_log('Create task email error: ' . $e->getMessage());
    }
    break;

// ---- UPDATE TASK ----
case 'update_task':
    $id = intval($body['id'] ?? 0);
    $ex = $pdo->prepare("SELECT * FROM tasks WHERE id=?"); $ex->execute([$id]); $existing=$ex->fetch();
    if (!$existing) { echo json_encode(['error'=>'Not found']); break; }
    // ── CASH DEPOSIT LOCK ──────────────────────────────────────────────
    // A technician holding cash undeposited for more than 4 days is blocked from moving ANY
    // task forward (attend, install, give access, collect, close). They can still open & read
    // the task, and log a call/update note. Penalty coins accrue separately.
    if (!in_array($userRole,['admin','assigner'])) {
        // Fields that are harmless (do not progress the task) — allow these even when locked.
        $harmlessOnly = ['id','general_notes','reminder_date','last_tech_activity','star_rating'];
        $touchesProgress = false;
        foreach (array_keys($body) as $bk) { if (!in_array($bk,$harmlessOnly)) { $touchesProgress = true; break; } }
        if ($touchesProgress) {
            try {
                // Overdue cash from tasks
                $lockChk = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount_collected),0) amt, MIN(cash_pending_at) oldest
                    FROM tasks
                    WHERE assigned_to=? AND id<>?
                      AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending'
                      AND cash_pending_at IS NOT NULL AND cash_pending_at < (NOW() - INTERVAL 4 DAY)");
                $lockChk->execute([$existing['assigned_to'], $id]);
                $lk = $lockChk->fetch();
                $cnt = intval($lk['c']??0); $amt = floatval($lk['amt']??0); $oldest = $lk['oldest']??null;
                // Overdue cash from manual balance-sheet entries linked to this technician
                try {
                    $lm = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(pending_payment),0) amt, MIN(date) oldest
                        FROM balance_sheet_entries WHERE technician_id=? AND COALESCE(pending_payment,0)>0
                          AND (task_db_id IS NULL OR task_db_id=0) AND date < (CURDATE() - INTERVAL 4 DAY)");
                    $lm->execute([$existing['assigned_to']]); $lmr=$lm->fetch();
                    $cnt += intval($lmr['c']??0); $amt += floatval($lmr['amt']??0);
                    if (!empty($lmr['oldest']) && (!$oldest || $lmr['oldest']<$oldest)) $oldest=$lmr['oldest'];
                } catch(Exception $e){}
                if ($cnt>0) {
                    $days = 0;
                    try { $days = (int)floor((time()-strtotime($oldest))/86400); } catch(Exception $e){}
                    // Make sure penalty windows are up to date the moment they hit the wall.
                    try { apply_cash_penalty($pdo, intval($existing['assigned_to'])); } catch(Exception $e){}
                    http_response_code(423);
                    echo json_encode([
                        'error'=>'Cash deposit overdue',
                        'cash_locked'=>true,
                        'pending_amount'=>$amt,
                        'pending_tasks'=>$cnt,
                        'oldest_days'=>$days,
                        'message'=>'🔒 Tasks locked — please deposit your pending cash. ₹'.number_format($amt).' pending for '.$days.' days. Deposit it and your tasks unlock automatically.'."\n".'🔒 మీ టాస్క్‌లు లాక్ అయ్యాయి — దయచేసి మీ దగ్గర ఉన్న క్యాష్ డిపాజిట్ చేయండి. ₹'.number_format($amt).', '.$days.' రోజులుగా పెండింగ్‌లో ఉంది. డిపాజిట్ చేయగానే మీ టాస్క్‌లు ఆటోమేటిక్‌గా అన్‌లాక్ అవుతాయి.'
                    ]);
                    break;
                }
            } catch(Exception $e){ /* never block on a check error */ }
        }
    }
    // Discount budget enforcement on update: if a discount amount exists and the approver is being
    // set/changed, re-check that approver's rolling-7-day budget (excluding this task's own value).
    {
        $dcAmt2 = array_key_exists('discount_given',$body) ? floatval($body['discount_given']) : floatval($existing['discount_given'] ?? 0);
        $dcWho2 = array_key_exists('discount_incharge',$body) ? trim($body['discount_incharge']) : trim($existing['discount_incharge'] ?? '');
        $whoChanged = array_key_exists('discount_incharge',$body) && trim($body['discount_incharge']) !== trim($existing['discount_incharge'] ?? '');
        $amtChanged = array_key_exists('discount_given',$body) && floatval($body['discount_given']) !== floatval($existing['discount_given'] ?? 0);
        if($dcAmt2 > 0 && $dcWho2 !== '' && ($whoChanged || $amtChanged)){
            list($dcOk2, $dcReason2) = _discountCheck($pdo, $dcWho2, $dcAmt2, $id);
            if(!$dcOk2){ http_response_code(400); echo json_encode(['error'=>$dcReason2, 'discount_blocked'=>true]); break; }
        }
    }
    if (array_key_exists('payment_verify_status',$body)) { try { $pdo->exec("ALTER TABLE tasks ADD COLUMN payment_verify_status VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){} }
    // partial_finish: technician collected payment for the installed devices but the ORIGINAL
    // device_qty is preserved so the balance devices can still be installed later on the same task.
    if (array_key_exists('partial_finish',$body)) { try { $pdo->exec("ALTER TABLE tasks ADD COLUMN partial_finish TINYINT(1) DEFAULT 0"); } catch(Exception $e){} }
    // Stamp when cash first goes pending (start of the 24h deposit clock)
    if (($body['cash_deposit_status'] ?? '') === 'pending' && ($existing['cash_deposit_status'] ?? '') !== 'pending') {
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN cash_pending_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->prepare("UPDATE tasks SET cash_pending_at=NOW() WHERE id=? AND cash_pending_at IS NULL")->execute([$id]); } catch(Exception $e){}
    }
    $fields = ['task_status','payment_status','amount_collected','payment_mode','device_details','general_notes','reminder_date','customer_requested_delay','is_outstation','payment_reminder_date','is_urgent','star_rating',
               // Balance sheet linkage fields
               'gps_serial_no','name_on_server','server_name','invoice_no','payment_received_on','payment_transaction_details','gst_amount','pending_reason','discount_reason','discount_incharge','profile',
               // Outstation fields
               'outstation_location','outstation_travel_paid_by','outstation_customer_travel_amount','outstation_claim_cap','outstation_claim_submitted','outstation_claim_status',
               // Cash deposit tracking
               'cash_deposit_status','partial_finish',
               // Feature #4: non-cash payment verification
               'payment_verify_status',
               // Consent reset (when vehicle unavailable after consent)
               'consent_token','customer_consent_at','customer_consent_name','customer_consent_mobile'];
    if (in_array($userRole,['admin','assigner'])) $fields=array_merge($fields,['customer_name','contact_number','email','location','lead_type','device_qty','price_to_collect','unit_price','assigned_to','vehicle_number']);
    // Ensure unit_price column exists if an admin is editing it
    if (array_key_exists('unit_price',$body)) { try { $pdo->exec("ALTER TABLE tasks ADD COLUMN unit_price DECIMAL(10,2) DEFAULT NULL"); } catch(Exception $e){} }
    // Guard: a technician cannot record an amount far above the price to collect (typo protection,
    // e.g. 45000 instead of 4500). Admins/managers may override (corrections, adjustments).
    if (array_key_exists('amount_collected',$body) && !in_array($userRole,['admin','assigner'])) {
        $priceChk = floatval($existing['price_to_collect'] ?? 0);
        $amtChk   = floatval($body['amount_collected']);
        if ($priceChk > 0 && $amtChk > $priceChk + 20) {
            echo json_encode(['error'=>'Amount ₹'.number_format($amtChk).' is more than the price to collect (₹'.number_format($priceChk).'). Please enter the correct amount.']);
            break;
        }
    }
    // Admin "Send Back": re-arm the cash deposit flow so a cash task with collected money shows the
    // technician the deposit + correct-amount screen again (instead of an empty/locked state).
    if (!empty($body['sent_back']) && in_array($userRole,['admin','assigner'])) {
        $pm = strtolower($existing['payment_mode'] ?? '');
        if ($pm === 'cash' && floatval($existing['amount_collected'] ?? 0) > 0) {
            $body['cash_deposit_status'] = 'pending';
        } else {
            $body['cash_deposit_status'] = '';
        }
    }
    $sets=[]; $vals=[];
    foreach ($fields as $f) {
        if (array_key_exists($f,$body)) {
            $sets[]="$f=?";
            $vals[]=($body[$f]===''&&in_array($f,['assigned_to','reminder_date']))?null:$body[$f];
        }
    }
    // Technician may REDUCE device_qty (never increase) — used when the customer
    // cancels remaining devices, so the task no longer waits on un-installed units.
    if (!in_array($userRole,['admin','assigner']) && array_key_exists('device_qty',$body)) {
        $newQty = intval($body['device_qty']);
        $curQty = intval($existing['device_qty'] ?? 1);
        if ($newQty >= 1 && $newQty < $curQty) { $sets[]="device_qty=?"; $vals[]=$newQty; }
    }
    if (isset($body['task_status'])&&$body['task_status']==='Closed'&&$existing['task_status']!=='Closed') $sets[]="closed_at=NOW()";
    if ($sets) { $vals[]=$id; $pdo->prepare("UPDATE tasks SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }

    // Auto-blacklist: task cancelled AFTER consent was given
    if(isset($body['task_status']) && $body['task_status']==='Cancelled'
       && $existing['task_status']!=='Cancelled'
       && !empty($existing['customer_consent_at'])){
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS blacklist_entries (id INT AUTO_INCREMENT PRIMARY KEY, customer_name VARCHAR(200) NULL, phone VARCHAR(20) NULL, email VARCHAR(200) NULL, task_id VARCHAR(20) NULL, task_db_id INT NULL, reason TEXT NULL, added_by VARCHAR(100) NULL, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, status VARCHAR(20) DEFAULT 'active', cleared_by VARCHAR(100) NULL, cleared_reason TEXT NULL, cleared_at TIMESTAMP NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $blChk=$pdo->prepare("SELECT id FROM blacklist_entries WHERE status='active' AND (phone=? OR (email=? AND email IS NOT NULL AND email != ''))");
            $blChk->execute([$existing['customer_contact']??'',$existing['customer_email']??'']);
            if(!$blChk->fetch()){
                $pdo->prepare("INSERT INTO blacklist_entries (customer_name,phone,email,task_id,task_db_id,reason,added_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        $existing['customer_name']??null,
                        $existing['customer_contact']??null,
                        $existing['customer_email']??null,
                        $existing['task_id']??null,
                        $id,
                        'Cancelled after consent — '.($body['cancel_reason']??'No reason given'),
                        $cu['name']??'System',
                    ]);
            }
        } catch(Exception $e){ error_log('Blacklist auto-add error: '.$e->getMessage()); }
    }

    // ── SYNC PAYMENT TO BS ENTRY ─────────────────────────────────────
    // Received is ONLY confirmed when task is Closed (management approved)
    try {
        $bsCheck = $pdo->prepare("SELECT bs_entry_id, price_to_collect, amount_collected, payment_mode, task_status FROM tasks WHERE id=?");
        $bsCheck->execute([$id]); $bsRow = $bsCheck->fetch();
        if (!empty($bsRow['bs_entry_id'])) {
            $total3  = array_key_exists('price_to_collect', $body)
                        ? floatval($body['price_to_collect'])
                        : floatval($bsRow['price_to_collect']??0);
            // Free service — remove the entry entirely, don't keep it in the balance sheet
            if ($total3 <= 0) {
                try { $pdo->prepare("DELETE FROM balance_sheet_entries WHERE id=?")->execute([intval($bsRow['bs_entry_id'])]); } catch(Exception $e){}
                try { $pdo->prepare("UPDATE tasks SET bs_entry_id=NULL WHERE id=?")->execute([$id]); } catch(Exception $e){}
            } else {
            $pmode3  = array_key_exists('payment_mode', $body)
                        ? $body['payment_mode']
                        : ($bsRow['payment_mode']??null);
            $newStatus = $body['task_status'] ?? $bsRow['task_status'] ?? '';

            // Only mark as received when management closes the task
            if ($newStatus === 'Closed') {
                $recv3 = array_key_exists('amount_collected', $body)
                            ? floatval($body['amount_collected'])
                            : floatval($bsRow['amount_collected']??0);
            } else {
                // Task not closed yet — keep received as 0, full amount pending
                $recv3 = 0;
            }
            $pend3 = max(0, $total3 - $recv3);
            if ($total3 <= 0 || $recv3 <= 0)    $ps3 = 'pending';
            elseif ($recv3 >= $total3 - 15)      $ps3 = 'paid';
            else                                 $ps3 = 'partially_paid';

            $pdo->prepare("UPDATE balance_sheet_entries SET
                payment_received=?, pending_payment=?, payment_status=?,
                payment_mode=?, total_price=?, updated_at=NOW()
                WHERE id=?")
                ->execute([$recv3, $pend3, $ps3, $pmode3, $total3, $bsRow['bs_entry_id']]);
            // If the technician was reassigned, update the balance-sheet technician name/id too.
            if (array_key_exists('assigned_to', $body)) {
                try {
                    try { $pdo->exec("ALTER TABLE balance_sheet_entries ADD COLUMN technician_id INT DEFAULT NULL"); } catch(Exception $e){}
                    $newTechId = intval($body['assigned_to']) ?: null;
                    $newTechName = null;
                    if ($newTechId) { $tnq=$pdo->prepare("SELECT name FROM users WHERE id=?"); $tnq->execute([$newTechId]); $newTechName=$tnq->fetchColumn() ?: null; }
                    // Update name first (always exists); id separately so a missing column can't block the name.
                    try { $pdo->prepare("UPDATE balance_sheet_entries SET technician_name=? WHERE id=?")->execute([$newTechName, $bsRow['bs_entry_id']]); } catch(Exception $e){}
                    try { $pdo->prepare("UPDATE balance_sheet_entries SET technician_id=? WHERE id=?")->execute([$newTechId, $bsRow['bs_entry_id']]); } catch(Exception $e){}
                } catch(Exception $e){ error_log('BS tech sync: '.$e->getMessage()); }
            }
            }
        }
    } catch(Exception $bsSync) {
        error_log('BS sync error: '.$bsSync->getMessage());
    }
    // ── END SYNC ─────────────────────────────────────────────────────
    if (!empty($body['remark'])) {
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")->execute([$id,$userId,$body['remark']]);
    }
    if (isset($body['task_status'])&&$body['task_status']!==$existing['task_status'])
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,"Status: {$existing['task_status']} → {$body['task_status']}"]);

    // ── PUSH NOTIFICATIONS to the assigned technician on key task events ──
    // Uses the same working fcm helper as messages/coins. Fully guarded so a push
    // failure can never affect the task update. Only meaningful changes push.
    try {
        require_once __DIR__.'/fcm_send.php';
        if (function_exists('fcm_send_to_user')) {
            // Re-read the task to get the current assigned tech + task_id string.
            $tpush = $pdo->prepare("SELECT task_id, assigned_to, customer_name FROM tasks WHERE id=?");
            $tpush->execute([$id]); $tprow = $tpush->fetch(PDO::FETCH_ASSOC);
            $techId = intval($tprow['assigned_to'] ?? 0);
            $tno    = $tprow['task_id'] ?? '';
            $cust   = $tprow['customer_name'] ?? '';
            $pdata  = ['task_id'=>(string)$id, 'url'=>'task.html?id='.$id];

            // 1) Reassigned to a DIFFERENT technician → notify the new tech.
            if (array_key_exists('assigned_to',$body)) {
                $newTech = intval($body['assigned_to']);
                $oldTech = intval($existing['assigned_to'] ?? 0);
                if ($newTech && $newTech !== $oldTech) {
                    fcm_send_to_user($pdo, $newTech, '🔔 New Task Assigned', trim($cust.' · '.$tno), array_merge($pdata,['type'=>'new_task']));
                }
            }

            // 2) Status change / close / cancel → notify the assigned technician.
            if ($techId && isset($body['task_status']) && $body['task_status']!==$existing['task_status']) {
                $ns = $body['task_status'];
                if ($ns === 'Closed') {
                    fcm_send_to_user($pdo, $techId, '✅ Task Closed', 'Task '.$tno.' has been closed.', array_merge($pdata,['type'=>'task_closed']));
                } elseif ($ns === 'Cancelled') {
                    fcm_send_to_user($pdo, $techId, '❌ Task Cancelled', 'Task '.$tno.' was cancelled.', array_merge($pdata,['type'=>'task_cancelled']));
                } else {
                    fcm_send_to_user($pdo, $techId, '🔄 Task Update', 'Task '.$tno.' is now '.$ns.'.', array_merge($pdata,['type'=>'status_change']));
                }
            }

            // 3) Payment received/confirmed → notify the assigned technician.
            if ($techId && array_key_exists('payment_status',$body)
                && strtolower((string)$body['payment_status'])==='paid'
                && strtolower((string)($existing['payment_status']??''))!=='paid') {
                fcm_send_to_user($pdo, $techId, '💰 Payment Received', 'Payment recorded for task '.$tno.'.', array_merge($pdata,['type'=>'payment_received']));
            }

            // 4) Cash deposit confirmed by admin → notify the assigned technician.
            if ($techId && array_key_exists('cash_deposit_status',$body)
                && strtolower((string)$body['cash_deposit_status'])==='deposited'
                && strtolower((string)($existing['cash_deposit_status']??''))!=='deposited') {
                fcm_send_to_user($pdo, $techId, '✅ Cash Deposit Confirmed', 'Your cash deposit for task '.$tno.' is confirmed.', array_merge($pdata,['type'=>'cash_confirmed']));
            }
        }
    } catch(Exception $e){ error_log('task-event push error: '.$e->getMessage()); }

    // Thank-you email if this update closes the task (only on transition into Closed)
    if (isset($body['task_status']) && $body['task_status']==='Closed' && $existing['task_status']!=='Closed') {
        try {
            $tc = $pdo->prepare("SELECT * FROM tasks WHERE id=?"); $tc->execute([$id]); $ct=$tc->fetch();
            if ($ct && !empty($ct['email'])) {
                require_once __DIR__.'/mailer.php';
                sendTaskThankYouCustomer($ct);
            }
        } catch(Exception $e){ error_log('Thank-you email (update) error: '.$e->getMessage()); }
    }

    // ── Respond to browser immediately — DB is updated, that is what matters ──
    echo json_encode(['success'=>true]);

    // ── Send email after responding — slow SMTP won't affect browser ──────
    if (!empty($body['remark'])) {
        try {
            require_once __DIR__.'/mailer.php';
            $taskForEmail = $pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
            $taskForEmail->execute([$id]);
            $taskData = $taskForEmail->fetch();
            if($taskData && !empty($taskData['email'])) {
                $remark = $body['remark'];

                // Filter: only email customer for relevant updates
                // ✅ Call/visit updates (📞) — only BEFORE device adding is done
                // ✅ Payment collected (💰) — always send
                // ❌ Device push to server (🛰), system entries, consent logs — never send
                $isPayment   = mb_strpos($remark,'💰')!==false;
                $isDevPush   = mb_strpos($remark,'🛰')!==false;
                $isSystem    = mb_strpos($remark,'⏰')!==false || mb_strpos($remark,'T+')!==false;
                $isCall      = mb_strpos($remark,'📞')!==false;
                $isPostpone  = mb_strpos($remark,'⏸️')!==false || mb_strpos($remark,'postponed')!==false;
                $isCancel    = mb_strpos($remark,'❌')!==false && mb_strpos($remark,'cancelled')!==false;

                // Check if adding is done
                $addChk = $pdo->prepare("SELECT COUNT(*) FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL");
                $addChk->execute([$id]);
                $addingDone = $addChk->fetchColumn() > 0;

                // Check if customer had consented (postpone email only relevant post-consent)
                $hadConsent = !empty($taskData['customer_consent_at']);

                $shouldEmail = false;
                if($isPayment)                        $shouldEmail = true;
                elseif($isDevPush)                    $shouldEmail = false;
                elseif($isSystem)                     $shouldEmail = false;
                elseif($isPostpone && $hadConsent)    $shouldEmail = true;  // Postpone after consent
                elseif($isCancel)                     $shouldEmail = true;  // Cancellation always
                elseif($isCall && !$addingDone)       $shouldEmail = true;

                if($shouldEmail){
                    if(empty($taskData['feedback_token']) || $taskData['feedback_token']==='USED'){
                        $newToken = bin2hex(random_bytes(24));
                        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN feedback_token VARCHAR(64) DEFAULT NULL")->execute(); } catch(Exception $ex){}
                        $pdo->prepare("UPDATE tasks SET feedback_token=? WHERE id=?")->execute([$newToken, $id]);
                        $taskData['feedback_token'] = $newToken;
                    }
                    $updaterName = $pdo->prepare("SELECT name FROM users WHERE id=?");
                    $updaterName->execute([$userId]);
                    $updater = $updaterName->fetch();
                    // Only send remark-type activities to customer (not system/push entries)
                    $actStmt = $pdo->prepare("SELECT a.*, u.name AS user_name FROM task_activities a LEFT JOIN users u ON a.user_id=u.id WHERE a.task_id=? AND a.activity_type='remark' AND (a.remark LIKE '%📞%' OR a.remark LIKE '%💰%') ORDER BY a.created_at ASC");
                    $actStmt->execute([$id]);
                    $allActivities = $actStmt->fetchAll();
                    // Use specific postpone template if this is a postponement
                    require_once __DIR__.'/mailer.php';
                    $techNm = $pdo->prepare("SELECT name FROM users WHERE id=?");
                    $techNm->execute([$taskData['assigned_to']??0]);
                    $tName  = $techNm->fetchColumn() ?: 'BharatGPS Technician';

                    if($isPostpone && $hadConsent){
                        $pReason=''; $pDetails=''; $pDate='';
                        if(preg_match('/Reason: ([^|]+)/', $remark, $m))          $pReason  = trim($m[1]);
                        if(preg_match('/Details: ([^|]+)/', $remark, $m))          $pDetails = trim($m[1]);
                        if(preg_match('/Reschedule date: ([^|]+)/', $remark, $m))  $pDate    = trim($m[1]);
                        sendPostponeCustomer($taskData, $pReason, $pDetails, $pDate, $tName);
                    } elseif($isCancel){
                        $cReason=''; $cDetails='';
                        if(preg_match('/Reason: ([^|]+)/', $remark, $m))   $cReason  = trim($m[1]);
                        if(preg_match('/Details: ([^|]+)/', $remark, $m))  $cDetails = trim($m[1]);
                        sendCancelCustomer($taskData, $cReason, $cDetails, $tName);
                    } else {
                        sendTaskUpdateCustomer($taskData, $remark, $updater['name'] ?? 'BharatGPS Team', $allActivities);
                    }
                }
            }
        } catch(Exception $e) {
            error_log('Update email error: ' . $e->getMessage());
        }
    }
    // Create BS entry when technician submits (Awaiting Approval) — shows installation done, payment with tech
    if (isset($body['task_status']) && $body['task_status']==='Awaiting Approval' && $existing['task_status']!=='Awaiting Approval') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Refresh task data
            $bt=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?"); $bt->execute([$id]); $btask=$bt->fetch();
            if ($btask && !$btask['bs_entry_id']) {
                $bqty=floatval($btask['device_qty']??1);
                $btotal=floatval($btask['price_to_collect']??0);
                $bunit=$bqty>0?$btotal/$bqty:$btotal;
                $brecv=floatval($btask['amount_collected']??0);
                $bpend=max(0,$btotal-$brecv);
                $bpayStatus=$brecv>=$btotal&&$btotal>0?'With Technician — Collected':'With Technician — Pending';
                $bprofile=!empty($btask['profile'])?$btask['profile']:'BGPT';
                $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code) VALUES (?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([bs_type_for_task($btask['device_details']??''),$bprofile,$btask['task_id'],$id,$btask['gps_serial_no']??null,$btask['lead_type']??null,$btask['name_on_server']??null,$btask['server_name']??null,$btask['device_details']??null,$bqty,$bunit,floatval($btask['gst_amount']??0),$btotal,$bpayStatus,$brecv,$bpend,$btask['payment_mode']??null,$btask['tech_name']??null,$btask['location']??null,$btask['general_notes']??null,$cu['name']]);
                $bsId=$pdo->lastInsertId();
                $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$id]);
            }
        } catch(Exception $e) { error_log('BS awaiting error: '.$e->getMessage()); }

        // ── Feature #2: 50 coins if technician submits within 24h of task creation ──
        try {
            $ct=$pdo->prepare("SELECT assigned_to, created_at FROM tasks WHERE id=?"); $ct->execute([$id]); $ctr=$ct->fetch();
            if ($ctr && $ctr['assigned_to'] && !empty($ctr['created_at'])) {
                $hrs=(time()-strtotime($ctr['created_at']))/3600;
                if ($hrs <= 24) {
                    award_task_reward($pdo, intval($ctr['assigned_to']), 50, 'On-time submission (within 24h)', $id, 'submit24_'.$id, '🎉 Congratulations! +50 coins', 'Task submitted within 24 hours. Great work — keep it up!');
                }
            }
        } catch(Exception $e) { error_log('coin submit24 error: '.$e->getMessage()); }
    }
    break;

// ---- DELETE TASK ----
case 'delete_task':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id=intval($body['id']??$_GET['id']??0);
    // Delete all linked data
    $pdo->prepare("DELETE FROM task_activities WHERE task_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM task_device_installs WHERE task_id=?")->execute([$id]);
    try { $pdo->prepare("DELETE FROM balance_sheet_entries WHERE task_db_id=?")->execute([$id]); } catch(Exception $e){}
    try { $pdo->prepare("DELETE FROM blacklist_entries WHERE task_db_id=?")->execute([$id]); } catch(Exception $e){}
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    break;

// ---- TRANSFER TASK ----
case 'transfer_task':
    $id=intval($body['task_id']??0); $toId=intval($body['to_user_id']??0);
    if (!$id||!$toId) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
    $ex=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $ex->execute([$id]); $task=$ex->fetch();
    if (!$task) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }
    $tu=$pdo->prepare("SELECT name FROM users WHERE id=? AND is_active=1"); $tu->execute([$toId]); $toName=$tu->fetchColumn();
    $pdo->prepare("UPDATE tasks SET assigned_to=?,transferred_from=?,task_status='Open' WHERE id=?")->execute([$toId,$task['assigned_to'],$id]);
    // New assignee gets a fresh 3-hour open window: reset the timers.
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN assigned_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN tech_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->prepare("UPDATE tasks SET assigned_at=NOW(), tech_viewed_at=NULL WHERE id=?")->execute([$id]); } catch(Exception $e){}
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'assignment')")->execute([$id,$userId,"Transferred to $toName".(!empty($body['note'])?": {$body['note']}":"")]);
    echo json_encode(['success'=>true]);
    break;

// ---- APPROVE TASK ----
// ── APPROVE PAYMENT but KEEP TASK OPEN (for partial installs) ──
// Payment is fully collected, but not all devices are installed yet. Verify the payment,
// mark it received (deposit confirmed, balance sheet updated), but DO NOT close the task —
// the technician keeps installing the remaining devices and closes it manually when done.
case 'approve_payment_keep_open':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id=intval($body['id']??0);
    $h=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?"); $h->execute([$id]); $t=$h->fetch();
    if (!$t) { echo json_encode(['error'=>'Task not found']); break; }
    $totalPrice = floatval($t['price_to_collect']??0);
    $collected  = floatval($t['amount_collected']??0);
    $pendingPay = max(0, $totalPrice - $collected);
    if ($pendingPay > 0) { echo json_encode(['error'=>'Cannot verify — ₹'.number_format($pendingPay,0).' still pending. Collect full payment first.']); break; }
    $payMode = strtolower($t['payment_mode']??'');
    // Cash must be deposited & confirmed first
    if ($payMode === 'cash' && ($t['cash_deposit_status']??'') !== 'deposited' && $collected > 0){
        echo json_encode(['error'=>'Cannot verify — cash collected but the deposit is not yet confirmed. Verify the cash deposit first.']); break;
    }
    // Mark non-cash payment verified
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN payment_verify_status VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
    $pdo->prepare("UPDATE tasks SET payment_verify_status='verified' WHERE id=?")->execute([$id]);
    // Move status to In Progress (open) so the technician can continue the remaining installs.
    if (in_array($t['task_status'], ['Awaiting Approval','Task Pending'])) {
        $pdo->prepare("UPDATE tasks SET task_status='In Progress' WHERE id=?")->execute([$id]);
    }
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")
        ->execute([$id,$userId,'Payment verified & received in full. Task kept OPEN — remaining device installation pending; technician to close after finishing.']);
    // Balance sheet: mark payment received (money is in), even though the task is still open.
    if (!empty($t['bs_entry_id'])) {
        $pdo->prepare("UPDATE balance_sheet_entries SET payment_status='paid',payment_received=?,pending_payment=0,payment_received_on=CURDATE() WHERE id=?")->execute([$collected,$t['bs_entry_id']]);
    }
    // Notify the technician to finish the remaining devices.
    try {
        require_once __DIR__.'/fcm_send.php';
        if (function_exists('fcm_send_to_user') && !empty($t['assigned_to'])) {
            fcm_send_to_user($pdo, intval($t['assigned_to']), '✅ Payment verified — task still open',
                'Payment for '.($t['task_id']??'').' is confirmed. Please complete the remaining device installation and close the task.',
                ['type'=>'status_change','task_id'=>(string)$id,'url'=>'task.html?id='.$id]);
        }
    } catch(Exception $e){}
    echo json_encode(['success'=>true,'kept_open'=>true]);
    break;

case 'approve_task':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id=intval($body['id']??0);
    // Fetch task first to check payment
    $h=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?"); $h->execute([$id]); $t=$h->fetch();
    if (!$t) { echo json_encode(['error'=>'Task not found']); break; }
    $totalPrice = floatval($t['price_to_collect']??0);
    $collected  = floatval($t['amount_collected']??0);
    $pending    = max(0, $totalPrice - $collected);
    // Hard block if payment pending
    if ($pending > 0) { echo json_encode(['error'=>'Cannot close — ₹'.number_format($pending,0).' still pending. Collect full payment first.','pending'=>$pending]); break; }
    // Hard block if cash collected but not yet deposited by technician
    $payMode       = strtolower($t['payment_mode']??'');
    $depositStatus = $t['cash_deposit_status']??'';
    if ($payMode === 'cash' && $depositStatus !== 'deposited' && floatval($t['amount_collected']??0) > 0){
        $msg = $depositStatus === 'submitted'
            ? 'Cannot close — cash deposit submitted by technician but not yet verified by admin. Please verify the deposit first.'
            : 'Cannot close — technician collected ₹'.number_format(floatval($t['amount_collected']),0).' cash but has not submitted the deposit yet.';
        echo json_encode(['error'=>$msg]);
        break;
    }
    // Feature #4: hard block if NON-CASH payment collected but screenshot not verified by admin
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN payment_verify_status VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
    $nonCashModes = ['upi','bank transfer','cheque'];
    if (in_array($payMode, $nonCashModes) && $collected > 0 && ($t['payment_verify_status']??'') !== 'verified') {
        echo json_encode(['error'=>'Cannot close — '.strtoupper($t['payment_mode']).' payment screenshot not yet verified by admin. Please verify the payment proof first.']);
        break;
    }
    // Close the task
    $pdo->prepare("UPDATE tasks SET task_status='Closed',closed_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,'Task approved and closed by manager. Full payment confirmed.']);
    // Thank-you email to customer (simple, generic; safe no-op if no email)
    try {
        if (!empty($t['email'])) {
            require_once __DIR__.'/mailer.php';
            sendTaskThankYouCustomer($t);
        }
    } catch(Exception $e){ error_log('Thank-you email error: '.$e->getMessage()); }
    // Star rating
    $hrs=(time()-strtotime($t['created_at']))/3600;
    $stars=$hrs<=12?5:($hrs<=24?4:($hrs<=48?3:($hrs<=72?2:1)));
    $pdo->prepare("UPDATE tasks SET star_rating=? WHERE id=? AND (star_rating IS NULL OR star_rating=0)")->execute([$stars,$id]);
    // On-time coins safety net: if the task was created & completed within 24h, credit the
    // technician here too (idempotent via submit24_ key, so it won't double-award if the
    // Awaiting-Approval step already gave them). Covers tasks that skipped that step.
    try {
        if (!empty($t['assigned_to']) && !empty($t['created_at']) && $hrs <= 24) {
            award_task_reward($pdo, intval($t['assigned_to']), 50, 'On-time submission (within 24h)', $id, 'submit24_'.$id, '🎉 Congratulations! +50 coins', 'Task completed within 24 hours. Great work — keep it up!');
        }
    } catch(Exception $e) { error_log('coin close24 error: '.$e->getMessage()); }
    // Update BS entry if exists — mark payment as received by company
    if ($t['bs_entry_id']) {
        $pdo->prepare("UPDATE balance_sheet_entries SET payment_status='paid',payment_received=?,pending_payment=0,payment_received_on=CURDATE() WHERE id=?")->execute([$collected,$t['bs_entry_id']]);
    } else {
        // Create BS entry now (fallback if not created at Awaiting Approval)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $qty2=floatval($t['device_qty']??1); $total2=floatval($t['price_to_collect']??0);
            $unit2=$qty2>0?$total2/$qty2:$total2;
            $taskProfile=!empty($t['profile'])?$t['profile']:'BGPT';
            $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code,payment_received_on) VALUES (?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())")
                ->execute([bs_type_for_task($t['device_details']??''),$taskProfile,$t['task_id'],$id,$t['gps_serial_no']??null,$t['lead_type']??null,$t['name_on_server']??null,$t['server_name']??null,$t['device_details']??null,$qty2,$unit2,floatval($t['gst_amount']??0),$total2,'paid',$collected,0,$t['payment_mode']??null,$t['tech_name']??null,$t['location']??null,$t['general_notes']??null,$cu['name']]);
            $bsId=$pdo->lastInsertId();
            $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$id]);
        } catch(Exception $e) { error_log('BS close error: '.$e->getMessage()); }
    }
    echo json_encode(['success'=>true]);
    break;

// ---- REJECT TASK ----
// ── ADMIN/MANAGER: post a comment/message to the technician on a task ──
// Logs in the activity log AND pushes the assigned technician so they see it.
case 'add_task_comment':
    if (!in_array($userRole,['admin','assigner','manager'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id  = intval($body['id'] ?? $body['task_id'] ?? 0);
    $txt = trim($body['comment'] ?? $body['message'] ?? '');
    if (!$id)  { echo json_encode(['error'=>'Missing task id']); break; }
    if ($txt === '') { echo json_encode(['error'=>'Write a message']); break; }
    try {
        $who = $cu['name'] ?? 'Office';
        // Log to the activity log (shows in the task view for both office and technician)
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
            ->execute([$id,$userId,'💬 '.$who.': '.$txt]);
        // Push the assigned technician
        $tq=$pdo->prepare("SELECT task_id, assigned_to FROM tasks WHERE id=?"); $tq->execute([$id]); $tr=$tq->fetch(PDO::FETCH_ASSOC);
        if ($tr && !empty($tr['assigned_to'])) {
            try {
                require_once __DIR__.'/fcm_send.php';
                if (function_exists('fcm_send_to_user')) {
                    fcm_send_to_user($pdo, intval($tr['assigned_to']),
                        '💬 Message from '.$who,
                        $txt.' (Task '.($tr['task_id']??'').')',
                        ['type'=>'tech_note','task_id'=>(string)$id,'url'=>'task.html?id='.$id]);
                }
            } catch(Exception $e){}
        }
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'reject_task':
    $id=intval($body['id']??0);
    if(!$id){ echo json_encode(['error'=>'Missing id']); break; }
    $rjReason = trim($body['reason'] ?? 'Needs revision');
    // Send the task back to the technician as genuinely editable again.
    // Flipping only the status leaves the collected amount + submission/cash flags in place,
    // so the technician app still shows it as "done" and they cannot fix the amount.
    // Reset the payment/submission state (but keep the install/device data) so they can redo it.
    try {
        $pdo->prepare("UPDATE tasks SET
                task_status='In Progress',
                amount_collected=NULL,
                payment_status=NULL,
                cash_deposit_status=NULL,
                cash_submitted_at=NULL,
                cash_pending_at=NULL,
                admin_viewed_at=NULL
            WHERE id=?")->execute([$id]);
    } catch(Exception $e) {
        // If some columns don't exist on this schema, fall back to the minimal reset.
        try { $pdo->prepare("UPDATE tasks SET task_status='In Progress', amount_collected=NULL WHERE id=?")->execute([$id]); } catch(Exception $e2){}
    }
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")
        ->execute([$id,$userId,'Sent back to technician (payment reset for correction): '.$rjReason]);
    // Notify the technician
    try {
        $tt=$pdo->prepare("SELECT task_id, assigned_to FROM tasks WHERE id=?"); $tt->execute([$id]); $trow=$tt->fetch(PDO::FETCH_ASSOC);
        if($trow && !empty($trow['assigned_to'])){
            require_once __DIR__.'/fcm_send.php';
            if(function_exists('fcm_send_to_user')){
                fcm_send_to_user($pdo, intval($trow['assigned_to']), '↩️ Task sent back: '.($trow['task_id']??''), 'Please correct and resubmit. '.$rjReason, ['type'=>'task_returned','task_id'=>(string)$id]);
            }
        }
    } catch(Exception $e){}
    echo json_encode(['success'=>true]);
    break;

// ---- ADD PAYMENT ----
case 'add_payment':
    $tid=intval($body['task_id']??0); $amt=floatval($body['amount']??0);
    if (!$tid||!$amt) { echo json_encode(['error'=>'Missing params']); break; }
    $pdo->prepare("INSERT INTO payments (task_id,amount,payment_mode,transaction_ref,collected_by) VALUES (?,?,?,?,?)")
        ->execute([$tid,$amt,$body['payment_mode']??'Cash',$body['transaction_ref']??'',$userId]);
    $total=$pdo->prepare("SELECT SUM(amount) FROM payments WHERE task_id=?"); $total->execute([$tid]); $col=$total->fetchColumn();
    $pdo->prepare("UPDATE tasks SET amount_collected=?,payment_status=IF(?>=price_to_collect,'Collected','Partial') WHERE id=?")->execute([$col,$col,$tid]);
    echo json_encode(['success'=>true,'total_collected'=>$col]);
    break;

// ---- GET URGENT TASKS ----
case 'get_urgent_tasks':
    $urgSql = "SELECT t.*, u.name as tech_name, u.name as technician_name,
        TIMESTAMPDIFF(HOUR, t.created_at, NOW()) as age_hours
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.task_status IN ('Open','In Progress','Task Pending')
        AND (t.is_urgent=1 OR t.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR))";
    if($userRole === 'technician'){
        $us = $pdo->prepare($urgSql . " AND t.assigned_to=? ORDER BY t.created_at ASC");
        $us->execute([$userId]);
    } else {
        $us = $pdo->prepare($urgSql . " ORDER BY t.created_at ASC");
        $us->execute([]);
    }
    echo json_encode(['tasks' => $us->fetchAll()]);
    break;

// ---- GET APPROVALS ----
case 'get_approvals':
    $apSql = "SELECT t.*, u.name as tech_name, u.name as technician_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.task_status = 'Awaiting Approval'
        ORDER BY t.updated_at DESC";
    if($userRole === 'technician'){
        $as = $pdo->prepare($apSql . " AND t.assigned_to=?");
        // Techs only see their own — rewrite
        $as = $pdo->prepare("SELECT t.*, u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.task_status='Awaiting Approval' AND t.assigned_to=? ORDER BY t.updated_at DESC");
        $as->execute([$userId]);
    } else {
        $as = $pdo->prepare($apSql);
        $as->execute([]);
    }
    echo json_encode(['tasks' => $as->fetchAll()]);
    break;

// ---- DAILY REPORT ----
case 'get_daily_report':
    $date = $_GET['date'] ?? date('Y-m-d');
    // Validate date format
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    try {
        $q = function($pdo, $sql, $params=[]) {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return $s;
        };

        // ── Summary ──────────────────────────────────────────
        $summary = [
            'tasks_created'  => $q($pdo,"SELECT COUNT(*) FROM tasks WHERE DATE(created_at)=?",[$date])->fetchColumn(),
            'installed'      => $q($pdo,"SELECT COUNT(*) FROM task_device_installs WHERE DATE(saved_at)=? AND gps_serial_no IS NOT NULL",[$date])->fetchColumn(),
            // Cash collected today = tasks CLOSED today with payment (management confirmed)
            'cash_collected' => $q($pdo,"SELECT COALESCE(SUM(amount_collected),0) FROM tasks WHERE DATE(closed_at)=? AND amount_collected>0",[$date])->fetchColumn(),
            'pending_tasks'  => $q($pdo,"SELECT COUNT(*) FROM tasks WHERE task_status IN ('Open','In Progress','Task Pending')")->fetchColumn(),
            'urgent_tasks'   => $q($pdo,"SELECT COUNT(*) FROM tasks WHERE task_status IN ('Open','In Progress','Task Pending') AND (is_urgent=1 OR created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR))")->fetchColumn(),
        ];

        // ── Technician performance ────────────────────────────
        $techs = $q($pdo,"SELECT id,name FROM users WHERE role='technician' AND is_active=1 ORDER BY name")->fetchAll();
        $techPerf = [];
        foreach($techs as $tech){
            $tid = $tech['id'];
            // Keep the block log in sync (opens/closes a block period based on current overdue state).
            try { sync_block_log($pdo, $tid); } catch(Exception $e){}
            $blk = tech_block_minutes($pdo, $tid);
            $bMin = intval($blk['minutes']);
            $techPerf[] = [
                'id'        => $tid,
                'name'      => $tech['name'],
                'assigned'  => intval($q($pdo,"SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND task_status NOT IN ('Closed','Cancelled')",[$tid])->fetchColumn()),
                'activities'=> intval($q($pdo,"SELECT COUNT(*) FROM task_activities a JOIN tasks t ON a.task_id=t.id WHERE t.assigned_to=? AND DATE(a.created_at)=?",[$tid,$date])->fetchColumn()),
                'visited'   => intval($q($pdo,"SELECT COUNT(DISTINCT a.task_id) FROM task_activities a JOIN tasks t ON a.task_id=t.id WHERE t.assigned_to=? AND DATE(a.created_at)=? AND (a.remark LIKE ? OR a.remark LIKE ?)",[$tid,$date,'%Visited%','%Called%'])->fetchColumn()),
                'installed' => intval($q($pdo,"SELECT COUNT(*) FROM task_device_installs di JOIN tasks t ON di.task_id=t.id WHERE t.assigned_to=? AND DATE(di.saved_at)=? AND di.gps_serial_no IS NOT NULL",[$tid,$date])->fetchColumn()),
                // Collected = tasks closed today by this technician (management confirmed)
                'collected'    => floatval($q($pdo,"SELECT COALESCE(SUM(t.amount_collected),0) FROM tasks t WHERE t.assigned_to=? AND DATE(t.closed_at)=? AND t.amount_collected>0",[$tid,$date])->fetchColumn()),
                // Cash holding = tech collected but task NOT yet closed (pending with tech)
                'cash_holding' => floatval($q($pdo,"SELECT COALESCE(SUM(t.amount_collected),0) FROM tasks t WHERE t.assigned_to=? AND t.task_status NOT IN ('Closed','Cancelled') AND t.amount_collected>0",[$tid])->fetchColumn()),
                // Block info for payroll: minutes blocked, whether blocked now, when it started, absent days
                'blocked_now'    => $blk['blocked_now'] ? 1 : 0,
                'block_started'  => $blk['started_at'],
                'block_minutes'  => $bMin,
                'block_hours'    => intval(floor(($bMin % 1440) / 60)),
                'block_mins_only'=> intval($bMin % 60),
                'block_days'     => intval(floor($bMin / 1440)),
            ];
        }

        // ── Payment summary ────────────────────────────────────
        // Only tasks CLOSED today = management confirmed payment received
        $payRows            = $q($pdo,"SELECT payment_mode, SUM(amount_collected) as total, COUNT(*) as cnt FROM tasks WHERE DATE(closed_at)=? AND amount_collected>0 GROUP BY payment_mode",[$date])->fetchAll();
        $cashPendingDeposit = $q($pdo,"SELECT COALESCE(SUM(amount_collected),0) FROM tasks WHERE cash_deposit_status='pending' AND payment_mode='Cash'")->fetchColumn();
        $balancePending     = $q($pdo,"SELECT COALESCE(SUM(price_to_collect - amount_collected),0) FROM tasks WHERE task_status NOT IN ('Closed','Cancelled') AND price_to_collect > amount_collected + 15")->fetchColumn();

        // ── New tasks today ────────────────────────────────────
        $newTasks = $q($pdo,"SELECT t.id,t.task_id,t.customer_name,t.device_details,u.name as tech_name,t.price_to_collect FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE DATE(t.created_at)=? ORDER BY t.created_at DESC",[$date])->fetchAll();

        // ── Activity log ───────────────────────────────────────
        $activities = $q($pdo,"SELECT a.created_at,a.remark,u.name as user_name,t.task_id,t.customer_name FROM task_activities a JOIN tasks t ON a.task_id=t.id LEFT JOIN users u ON a.user_id=u.id WHERE DATE(a.created_at)=? ORDER BY a.created_at DESC",[$date])->fetchAll();

        echo json_encode([
            'summary'              => $summary,
            'tech_perf'            => $techPerf,
            'pay_rows'             => $payRows,
            'cash_pending_deposit' => floatval($cashPendingDeposit),
            'balance_pending'      => floatval($balancePending),
            'new_tasks'            => $newTasks,
            'activities'           => $activities,
            'date'                 => $date,
        ]);
    } catch(Exception $e){
        echo json_encode(['error'=> $e->getMessage(), 'trace'=> $e->getTraceAsString()]);
    }
    break;


case 'daily_report':
    $date=$_GET['date']??date('Y-m-d');
    $s=$pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE DATE(t.created_at)=? ORDER BY t.created_at DESC"); $s->execute([$date]);
    $tasks=$s->fetchAll();
    $rev=$pdo->prepare("SELECT COALESCE(SUM(amount_collected),0) FROM tasks WHERE DATE(created_at)=?"); $rev->execute([$date]);
    echo json_encode(['tasks'=>$tasks,'revenue'=>$rev->fetchColumn()]);
    break;

// ---- GET TECH STATS ----
case 'get_tech_stats':
    $tid=intval($_GET['tech_id']??0);
    $s=$pdo->prepare("SELECT COUNT(*) total, SUM(task_status='Closed') closed, SUM(task_status='Awaiting Approval') awaiting, SUM(device_qty) devices_installed FROM tasks WHERE assigned_to=?");
    $s->execute([$tid]); $stats=$s->fetch();
    echo json_encode(['stats'=>$stats]);
    break;

// ---- BULK DELETE ----
case 'bulk_delete':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $status=$body['status']??'';
    if (!in_array($status,['Closed','Cancelled'])) { echo json_encode(['error'=>'Invalid status']); break; }
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM tasks WHERE task_status=?"); $cnt->execute([$status]); $count=$cnt->fetchColumn();
    $pdo->prepare("DELETE FROM tasks WHERE task_status=?")->execute([$status]);
    echo json_encode(['success'=>true,'count'=>$count]);
    break;

// ---- ERASE ALL TASKS ----
case 'erase_all_tasks':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $count=$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("TRUNCATE TABLE task_activities");
    $pdo->exec("TRUNCATE TABLE task_documents");
    $pdo->exec("TRUNCATE TABLE payments");
    $pdo->exec("TRUNCATE TABLE sync_log");
    $pdo->exec("TRUNCATE TABLE tasks");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo json_encode(['success'=>true,'count'=>$count]);
    break;

// ---- DELETE A TASK DOCUMENT (admin/manager only) ----
case 'delete_document':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $docId = intval($body['doc_id'] ?? 0);
    if(!$docId){ echo json_encode(['error'=>'Document ID required']); break; }
    try {
        $dr = $pdo->prepare("SELECT task_id, filename FROM task_documents WHERE id=?");
        $dr->execute([$docId]); $doc = $dr->fetch();
        if(!$doc){ echo json_encode(['error'=>'Document not found']); break; }
        // Remove the physical file if present
        if(!empty($doc['filename'])){
            $fp = __DIR__.'/../uploads/task_'.intval($doc['task_id']).'/'.$doc['filename'];
            if(is_file($fp)) @unlink($fp);
        }
        $pdo->prepare("DELETE FROM task_documents WHERE id=?")->execute([$docId]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- GENERATE CUSTOMER DOCUMENT-UPLOAD LINK ----
case 'gen_docs_token':
    // Admin/manager/technician: create (or reuse) a secure token for the customer document portal.
    $tid = intval($body['task_id'] ?? $body['id'] ?? 0);
    if(!$tid){ echo json_encode(['error'=>'Task ID required']); break; }
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN docs_token VARCHAR(64) NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN docs_received_at DATETIME NULL"); } catch(Exception $e){}
    try {
        $row = $pdo->prepare("SELECT docs_token, docs_received_at, contact_number, customer_name FROM tasks WHERE id=?");
        $row->execute([$tid]); $tk = $row->fetch();
        if(!$tk){ echo json_encode(['error'=>'Task not found']); break; }
        $token = $tk['docs_token'] ?? '';
        // New token if none, or if the old one was already used (docs received) — a fresh request.
        if(!$token || !empty($tk['docs_received_at'])){
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE tasks SET docs_token=?, docs_received_at=NULL WHERE id=?")->execute([$token,$tid]);
        }
        $base = 'https://salmon-goldfish-110661.hostingersite.com/customer-docs.php?token='.$token;
        echo json_encode(['success'=>true,'token'=>$token,'link'=>$base,'phone'=>preg_replace('/\D/','',(string)($tk['contact_number']??'')),'customer'=>$tk['customer_name']??'']);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- UPLOAD DOCUMENT ----
case 'upload_document':
    $tid=intval($body['task_id']??$_POST['task_id']??0);
    $dtype=trim($body['doc_type']??$_POST['doc_type']??'');
    // Empty string should not slip through — default a payment upload to payment_screenshot
    if($dtype===''){ $dtype='payment_screenshot'; }
    if (!$tid||!isset($_FILES['file'])) { echo json_encode(['error'=>'Missing file']); break; }
    $dir=__DIR__.'/../uploads/task_'.$tid.'/'; if(!is_dir($dir)) mkdir($dir,0755,true);
    $fn=time().'_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$_FILES['file']['name']);
    if (move_uploaded_file($_FILES['file']['tmp_name'],$dir.$fn)) {
        $pdo->prepare("INSERT INTO task_documents (task_id,doc_type,filename,original_name,uploaded_by) VALUES (?,?,?,?,?)")->execute([$tid,$dtype,$fn,$_FILES['file']['name'],$userId]);
        echo json_encode(['success'=>true,'filename'=>$fn]);
    } else { echo json_encode(['error'=>'Upload failed']); }
    break;

// ---- BALANCE SHEET ----
// ---- SAVE DEVICE INSTALL ----
case 'mark_access_given':
    $tid = intval($body['task_id']??0);
    $email = trim($body['email']??'');
    $devIdx = isset($body['device_index']) ? intval($body['device_index']) : 0;
    $imei = preg_replace('/\D/','',(string)($body['imei']??''));
    if(!$tid){ echo json_encode(['error'=>'Missing task_id']); break; }
    try {
        try { $pdo->exec("ALTER TABLE task_device_installs ADD COLUMN access_given TINYINT DEFAULT 0"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE task_device_installs ADD COLUMN access_email VARCHAR(190) NULL"); } catch(Exception $e){}
        $done = false;
        if($devIdx){
            $st = $pdo->prepare("UPDATE task_device_installs SET access_given=1, access_email=? WHERE task_id=? AND device_index=?");
            $st->execute([$email?:null,$tid,$devIdx]);
            if($st->rowCount() > 0) $done = true;
        }
        if(!$done && $imei !== ''){
            // match ignoring any non-digits stored in gps_serial_no
            $st = $pdo->prepare("UPDATE task_device_installs SET access_given=1, access_email=? WHERE task_id=? AND REPLACE(REPLACE(gps_serial_no,' ',''),'-','') LIKE ?");
            $st->execute([$email?:null,$tid,'%'.$imei.'%']);
            if($st->rowCount() > 0) $done = true;
        }
        if(!$done && !$devIdx && $imei===''){
            // mark all installed devices of this task
            $pdo->prepare("UPDATE task_device_installs SET access_given=1, access_email=? WHERE task_id=? AND gps_serial_no IS NOT NULL AND gps_serial_no != ''")->execute([$email?:null,$tid]);
        }
        echo json_encode(['success'=>true,'marked'=>$done]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- SAVE FCM DEVICE TOKEN (for push notifications) ----
// ---- PUSH AN URGENT REMINDER FOR A TASK (admin/manager -> assigned technician) ----
case 'push_task_reminder':
    if (!in_array($userRole, ['admin','assigner'])) { echo json_encode(['error'=>'Not allowed']); break; }
    $rtid = intval($body['task_id'] ?? 0);
    if (!$rtid) { echo json_encode(['error'=>'Missing task_id']); break; }
    try {
        $rt = $pdo->prepare("SELECT id, task_id, customer_name, location, assigned_to FROM tasks WHERE id=?");
        $rt->execute([$rtid]);
        $rtask = $rt->fetch(PDO::FETCH_ASSOC);
        if (!$rtask) { echo json_encode(['error'=>'Task not found']); break; }
        $atech = intval($rtask['assigned_to'] ?? 0);
        if (!$atech) { echo json_encode(['error'=>'This task is not assigned to any technician yet']); break; }
        $ttl = '🔔 Urgent: ' . ($rtask['task_id'] ?? 'Task');
        $bdy = trim($body['message'] ?? '');
        if ($bdy === '') {
            $bdy = 'Please attend this task now — ' . ($rtask['customer_name'] ?? '') .
                   (($rtask['location'] ?? '') !== '' ? (', ' . $rtask['location']) : '');
        }
        require_once __DIR__.'/fcm_send.php';
        $ok = false;
        if (function_exists('fcm_send_to_user')) {
            try {
                $ok = (bool) fcm_send_to_user($pdo, $atech, $ttl, $bdy, [
                    'type'    => 'task_reminder',
                    'task_id' => (string)$rtid,
                    'url'     => 'task.html?id=' . $rtid,
                ]);
            } catch(Exception $e){}
        }
        if ($ok) echo json_encode(['success'=>true]);
        else echo json_encode(['error'=>'Could not send — the technician may not have the app installed or notifications enabled.']);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- ADMIN: MANUALLY ADJUST A TECHNICIAN'S COINS (add or remove) ----
case 'admin_adjust_coins':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can adjust coins']); break; }
    $atech = intval($body['user_id'] ?? 0);
    $acoins = intval($body['coins'] ?? 0);           // may be positive (add) or negative (remove)
    $areason = trim($body['reason'] ?? '');
    if (!$atech)  { echo json_encode(['error'=>'Select a technician']); break; }
    if ($acoins === 0) { echo json_encode(['error'=>'Enter a non-zero coin amount']); break; }
    if ($areason === '') { echo json_encode(['error'=>'A reason is required']); break; }
    try {
        _ensureCoinLedger($pdo);
        // Direct ledger insert (not idempotent — a manual adjustment is a deliberate one-off entry).
        $adjReason = 'Manual adjustment by '.($cu['name']??'admin').': '.$areason;
        if (mb_strlen($adjReason) > 185) $adjReason = mb_substr($adjReason, 0, 185);
        $pdo->prepare("INSERT INTO coin_ledger (user_id,task_id,coins,reason) VALUES (?,?,?,?)")
            ->execute([$atech, null, $acoins, $adjReason]);
        $newBal = coin_balance($pdo, $atech);
        // Notify the technician of the change.
        try {
            require_once __DIR__.'/fcm_send.php';
            if (function_exists('fcm_send_to_user')) {
                $ttl = $acoins >= 0 ? ('🎉 +'.$acoins.' coins') : ('⚠️ '.$acoins.' coins');
                fcm_send_to_user($pdo, $atech, $ttl, 'Admin adjusted your coins. New balance: '.$newBal.' coins.', [
                    'type'=>'coins', 'coins'=>(string)$acoins
                ]);
            }
        } catch(Exception $e){}
        echo json_encode(['success'=>true, 'new_balance'=>$newBal]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- ADMIN: LIST TECHNICIANS WITH COIN BALANCES ----
case 'admin_coin_summary':
    if (!in_array($userRole, ['admin','assigner','manager'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureCoinLedger($pdo);
        $q = $pdo->query("SELECT u.id, u.name, u.role, COALESCE(SUM(c.coins),0) AS balance
                          FROM users u LEFT JOIN coin_ledger c ON c.user_id=u.id
                          WHERE u.role='technician'
                          GROUP BY u.id, u.name, u.role
                          ORDER BY u.name ASC");
        echo json_encode(['success'=>true, 'technicians'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- ADMIN: RECENT COIN LEDGER FOR A TECHNICIAN ----
case 'admin_coin_history':
    if (!in_array($userRole, ['admin','assigner','manager'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $htech = intval($_GET['user_id'] ?? $body['user_id'] ?? 0);
    if (!$htech) { echo json_encode(['error'=>'Missing user_id']); break; }
    try {
        _ensureCoinLedger($pdo);
        $h = $pdo->prepare("SELECT coins, reason, created_at FROM coin_ledger WHERE user_id=? ORDER BY id DESC LIMIT 40");
        $h->execute([$htech]);
        echo json_encode(['success'=>true, 'history'=>$h->fetchAll(PDO::FETCH_ASSOC), 'balance'=>coin_balance($pdo,$htech)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: list a technician's paid leaves (available + used) ──
case 'admin_leave_list':
    if (!in_array($userRole,['admin','assigner','manager'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $lUid = intval($_GET['user_id'] ?? $body['user_id'] ?? 0);
        if (!$lUid) { echo json_encode(['error'=>'Select a technician']); break; }
        $q=$pdo->prepare("SELECT id,days,source,note,status,used_on,used_note,marked_by,marked_at,created_at
                          FROM paid_leaves WHERE user_id=? ORDER BY id DESC");
        $q->execute([$lUid]);
        echo json_encode([
            'success'      => true,
            'leaves'       => $q->fetchAll(PDO::FETCH_ASSOC),
            'available'    => paid_leave_total($pdo,$lUid),
            'used'         => paid_leave_used_total($pdo,$lUid),
            'appreciations'=> appreciation_balance($pdo,$lUid),
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: mark a paid leave as USED (consumed by the technician) ──
// ── ADMIN: manually adjust a technician's APPRECIATIONS (+/-) ──
case 'admin_adjust_appreciation':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can adjust appreciations']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $aU = intval($body['user_id'] ?? 0);
        $aP = intval($body['points'] ?? 0);
        $aR = trim($body['reason'] ?? '');
        if (!$aU) { echo json_encode(['error'=>'Select a technician']); break; }
        if ($aP === 0) { echo json_encode(['error'=>'Enter a non-zero number']); break; }
        if ($aR === '') { echo json_encode(['error'=>'A reason is required']); break; }
        // Direct ledger write (deliberate one-off, no event key) — auto-converts at 10.
        award_appreciation($pdo, $aU, $aP, 'Manual adjustment by '.($cu['name'] ?? 'admin').': '.$aR, null, null);
        echo json_encode(['success'=>true,'balance'=>appreciation_balance($pdo,$aU),'paid_leaves'=>paid_leave_total($pdo,$aU)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: grant a paid leave manually (bonus / correction) ──
case 'admin_leave_grant':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can grant leave']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $gU = intval($body['user_id'] ?? 0);
        $gD = floatval($body['days'] ?? 1); if ($gD <= 0) $gD = 1;
        $gN = trim($body['note'] ?? '');
        if (!$gU) { echo json_encode(['error'=>'Select a technician']); break; }
        $pdo->prepare("INSERT INTO paid_leaves (user_id,days,source,note) VALUES (?,?,?,?)")
            ->execute([$gU, $gD, 'admin', ($gN !== '' ? $gN : 'Granted by '.($cu['name'] ?? 'admin'))]);
        try {
            require_once __DIR__.'/fcm_send.php';
            if (function_exists('fcm_send_to_user')) {
                fcm_send_to_user($pdo,$gU,'🎁 Paid Leave Granted','You received '.$gD.' paid leave. Total available: '.paid_leave_total($pdo,$gU).'.',['type'=>'leave_granted','url'=>'earnings.html']);
            }
        } catch(Exception $e){}
        echo json_encode(['success'=>true,'available'=>paid_leave_total($pdo,$gU)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: delete a paid leave row (added by mistake) ──
case 'admin_leave_delete':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can delete']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $dId = intval($body['id'] ?? 0);
        if (!$dId) { echo json_encode(['error'=>'Missing id']); break; }
        $g=$pdo->prepare("SELECT user_id FROM paid_leaves WHERE id=?"); $g->execute([$dId]); $dU=intval($g->fetchColumn());
        $pdo->prepare("DELETE FROM paid_leaves WHERE id=?")->execute([$dId]);
        echo json_encode(['success'=>true,'available'=>paid_leave_total($pdo,$dU),'used'=>paid_leave_used_total($pdo,$dU)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'admin_leave_mark_used':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can mark leave as used']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $lid = intval($body['id'] ?? 0);
        if (!$lid) { echo json_encode(['error'=>'Missing leave id']); break; }
        $g=$pdo->prepare("SELECT * FROM paid_leaves WHERE id=?"); $g->execute([$lid]); $lv=$g->fetch(PDO::FETCH_ASSOC);
        if (!$lv) { echo json_encode(['error'=>'Leave not found']); break; }
        if (strtolower((string)($lv['status'] ?? '')) === 'used') { echo json_encode(['error'=>'This leave is already marked used']); break; }
        $usedOn   = trim($body['used_on'] ?? '') ?: date('Y-m-d');
        $usedNote = trim($body['used_note'] ?? '');
        $pdo->prepare("UPDATE paid_leaves SET status='used', used_on=?, used_note=?, marked_by=?, marked_at=NOW() WHERE id=?")
            ->execute([$usedOn, $usedNote ?: null, $cu['name'] ?? 'admin', $lid]);
        // Notify the technician
        try {
            require_once __DIR__.'/fcm_send.php';
            if (function_exists('fcm_send_to_user')) {
                fcm_send_to_user($pdo, intval($lv['user_id']), '🗓️ Paid Leave Used',
                    'Your paid leave was marked as taken on '.$usedOn.'. Remaining: '.paid_leave_total($pdo,intval($lv['user_id'])).'.',
                    ['type'=>'leave_used','url'=>'earnings.html']);
            }
        } catch(Exception $e){}
        echo json_encode(['success'=>true,'available'=>paid_leave_total($pdo,intval($lv['user_id'])),'used'=>paid_leave_used_total($pdo,intval($lv['user_id']))]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: undo a wrongly-marked leave (put it back to available) ──
case 'admin_leave_undo_used':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can undo']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $lid = intval($body['id'] ?? 0);
        if (!$lid) { echo json_encode(['error'=>'Missing leave id']); break; }
        $g=$pdo->prepare("SELECT user_id FROM paid_leaves WHERE id=?"); $g->execute([$lid]); $uidL=intval($g->fetchColumn());
        $pdo->prepare("UPDATE paid_leaves SET status='available', used_on=NULL, used_note=NULL, marked_by=?, marked_at=NOW() WHERE id=?")
            ->execute([($cu['name'] ?? 'admin').' (undo)', $lid]);
        echo json_encode(['success'=>true,'available'=>paid_leave_total($pdo,$uidL),'used'=>paid_leave_used_total($pdo,$uidL)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── TECHNICIAN: full notification history (re-read missed/truncated pop-ups) ──
case 'my_notifications':
    try {
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS notification_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(200) DEFAULT NULL, body TEXT DEFAULT NULL, type VARCHAR(50) DEFAULT NULL, task_id VARCHAR(50) DEFAULT NULL, url VARCHAR(200) DEFAULT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_created (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
        $nUid = intval($_GET['user_id'] ?? $body['user_id'] ?? 0);
        if (!$nUid || $userRole === 'technician') $nUid = $userId;
        $lim = intval($_GET['limit'] ?? 100); if ($lim<1||$lim>300) $lim=100;
        $q=$pdo->prepare("SELECT id,title,body,type,task_id,url,is_read,created_at FROM notification_log WHERE user_id=? ORDER BY id DESC LIMIT $lim");
        $q->execute([$nUid]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $unread=0; foreach($rows as $r){ if(intval($r['is_read'])===0) $unread++; }
        echo json_encode(['success'=>true,'notifications'=>$rows,'unread'=>$unread]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'mark_notifications_read':
    try {
        $pdo->prepare("UPDATE notification_log SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$userId]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'my_appreciations':
    try {
        _ensureAppreciationTables($pdo);
        $aUid = intval($_GET['user_id'] ?? $body['user_id'] ?? 0);
        // Technicians can only see their own; admin/manager may pass a user_id.
        if (!$aUid || $userRole === 'technician') $aUid = $userId;
        $h=$pdo->prepare("SELECT points, reason, created_at FROM appreciation_ledger WHERE user_id=? ORDER BY id DESC LIMIT 40");
        $h->execute([$aUid]);
        echo json_encode([
            'success'      => true,
            'balance'      => appreciation_balance($pdo,$aUid),
            'paid_leaves'  => paid_leave_total($pdo,$aUid),
            'leaves_used'  => paid_leave_used_total($pdo,$aUid),
            'next_leave_at'=> 10,
            'history'      => $h->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'admin_appreciation_summary':
    if (!in_array($userRole,['admin','assigner','manager'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureAppreciationTables($pdo);
        $rows=$pdo->query("SELECT u.id,u.name,
                COALESCE((SELECT SUM(points) FROM appreciation_ledger a WHERE a.user_id=u.id),0) AS appreciations,
                COALESCE((SELECT SUM(days) FROM paid_leaves p WHERE p.user_id=u.id AND COALESCE(p.status,'available')<>'used'),0) AS paid_leaves,
                COALESCE((SELECT SUM(days) FROM paid_leaves p2 WHERE p2.user_id=u.id AND p2.status='used'),0) AS leaves_used
             FROM users u WHERE u.role='technician' ORDER BY u.name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'technicians'=>$rows]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ══════════════════════════════════════════════════════════════════════════
// OUTSTATION CLAIMS — technician claims travel costs for a task, admin approves per line,
// approved total is awarded as coins.
// ══════════════════════════════════════════════════════════════════════════
function _ensureOutstationTables($pdo){
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS outstation_claims (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_db_id INT NOT NULL,
            task_id VARCHAR(50) DEFAULT NULL,
            technician_id INT NOT NULL,
            customer_location VARCHAR(255) DEFAULT NULL,
            travel_distance VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            claimed_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            approved_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            submitted_at DATETIME DEFAULT NULL,
            decided_at DATETIME DEFAULT NULL,
            decided_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_task_claim (task_db_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS outstation_claim_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            claim_id INT NOT NULL,
            transport_mode VARCHAR(60) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            bill_file VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            approved_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_claim (claim_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e){ error_log('outstation tables: '.$e->getMessage()); }
}

// Technician: list MY tasks I can claim on (any task assigned to me, incl. closed), with claim status.
case 'os_my_taskable':
    try {
        _ensureOutstationTables($pdo);
        $rows=$pdo->prepare("SELECT t.id, t.task_id, t.customer_name, t.location, t.task_status, t.lead_type,
                    t.device_qty, (SELECT COUNT(*) FROM task_device_installs di WHERE di.task_id=t.id AND di.gps_serial_no IS NOT NULL AND di.gps_serial_no<>'') AS installed_count,
                    c.id AS claim_id, c.status AS claim_status
                FROM tasks t
                LEFT JOIN outstation_claims c ON c.task_db_id=t.id AND c.technician_id=?
                WHERE t.assigned_to=?
                ORDER BY t.id DESC LIMIT 300");
        $rows->execute([$userId,$userId]);
        echo json_encode(['success'=>true,'tasks'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Technician: create or fetch the claim for a task (one per task).
case 'os_get_or_create_claim':
    try {
        _ensureOutstationTables($pdo);
        $tdb=intval($body['task_db_id']??$_GET['task_db_id']??0);
        if(!$tdb){ echo json_encode(['error'=>'Missing task']); break; }
        $tk=$pdo->prepare("SELECT id,task_id,customer_name,location,assigned_to FROM tasks WHERE id=?"); $tk->execute([$tdb]); $tinfo=$tk->fetch(PDO::FETCH_ASSOC);
        if(!$tinfo){ echo json_encode(['error'=>'Task not found']); break; }
        if($userRole==='technician' && intval($tinfo['assigned_to'])!==intval($userId)){ echo json_encode(['error'=>'Not your task']); break; }
        $ex=$pdo->prepare("SELECT * FROM outstation_claims WHERE task_db_id=?"); $ex->execute([$tdb]); $claim=$ex->fetch(PDO::FETCH_ASSOC);
        if(!$claim){
            $pdo->prepare("INSERT INTO outstation_claims (task_db_id,task_id,technician_id,customer_location,status) VALUES (?,?,?,?, 'draft')")
                ->execute([$tdb,$tinfo['task_id'],$userId,$tinfo['location']??'']);
            $cid=$pdo->lastInsertId();
            $ex->execute([$tdb]); $claim=$ex->fetch(PDO::FETCH_ASSOC);
        }
        $ln=$pdo->prepare("SELECT * FROM outstation_claim_lines WHERE claim_id=? ORDER BY id ASC"); $ln->execute([$claim['id']]); 
        $installed=$pdo->prepare("SELECT COUNT(*) FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL AND gps_serial_no<>''"); $installed->execute([$tdb]);
        echo json_encode(['success'=>true,'claim'=>$claim,'lines'=>$ln->fetchAll(PDO::FETCH_ASSOC),'task'=>$tinfo,'installed_count'=>intval($installed->fetchColumn())]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Technician: save claim header (location, distance, notes) — only while draft.
case 'os_save_claim':
    try {
        _ensureOutstationTables($pdo);
        $cid=intval($body['claim_id']??0);
        $c=$pdo->prepare("SELECT * FROM outstation_claims WHERE id=?"); $c->execute([$cid]); $claim=$c->fetch(PDO::FETCH_ASSOC);
        if(!$claim){ echo json_encode(['error'=>'Claim not found']); break; }
        if($userRole==='technician' && intval($claim['technician_id'])!==intval($userId)){ echo json_encode(['error'=>'Not your claim']); break; }
        if($claim['status']!=='draft'){ echo json_encode(['error'=>'Claim already submitted']); break; }
        $pdo->prepare("UPDATE outstation_claims SET customer_location=?, travel_distance=?, notes=? WHERE id=?")
            ->execute([trim($body['customer_location']??''),trim($body['travel_distance']??''),trim($body['notes']??''),$cid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Technician: add a transport line (mode + amount + optional bill image as base64).
case 'os_add_line':
    try {
        _ensureOutstationTables($pdo);
        $cid=intval($body['claim_id']??0);
        $c=$pdo->prepare("SELECT * FROM outstation_claims WHERE id=?"); $c->execute([$cid]); $claim=$c->fetch(PDO::FETCH_ASSOC);
        if(!$claim){ echo json_encode(['error'=>'Claim not found']); break; }
        if($userRole==='technician' && intval($claim['technician_id'])!==intval($userId)){ echo json_encode(['error'=>'Not your claim']); break; }
        if($claim['status']!=='draft'){ echo json_encode(['error'=>'Claim already submitted']); break; }
        $mode=trim($body['transport_mode']??''); $amt=floatval($body['amount']??0);
        if($mode===''){ echo json_encode(['error'=>'Select a transport mode']); break; }
        if($amt<=0){ echo json_encode(['error'=>'Enter the amount']); break; }
        // Save bill image if provided (base64)
        $billFile=null;
        if(!empty($body['bill_base64'])){
            $dir=__DIR__.'/../uploads/outstation/'.$cid;
            if(!is_dir($dir)) @mkdir($dir,0775,true);
            $data=$body['bill_base64']; if(strpos($data,',')!==false) $data=substr($data,strpos($data,',')+1);
            $bin=base64_decode($data);
            if($bin!==false){ $fn='bill_'.time().'_'.rand(100,999).'.jpg'; if(@file_put_contents($dir.'/'.$fn,$bin)){ $billFile='outstation/'.$cid.'/'.$fn; } }
        }
        $pdo->prepare("INSERT INTO outstation_claim_lines (claim_id,transport_mode,amount,bill_file,status) VALUES (?,?,?,?, 'pending')")
            ->execute([$cid,$mode,$amt,$billFile]);
        // Recompute claimed total
        $pdo->prepare("UPDATE outstation_claims SET claimed_total=(SELECT COALESCE(SUM(amount),0) FROM outstation_claim_lines WHERE claim_id=?) WHERE id=?")->execute([$cid,$cid]);
        echo json_encode(['success'=>true,'line_id'=>intval($pdo->lastInsertId()),'bill_file'=>$billFile]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Technician: delete a transport line (draft only).
case 'os_delete_line':
    try {
        _ensureOutstationTables($pdo);
        $lid=intval($body['line_id']??0);
        $l=$pdo->prepare("SELECT ln.*, cl.technician_id, cl.status AS claim_status FROM outstation_claim_lines ln JOIN outstation_claims cl ON ln.claim_id=cl.id WHERE ln.id=?");
        $l->execute([$lid]); $line=$l->fetch(PDO::FETCH_ASSOC);
        if(!$line){ echo json_encode(['error'=>'Line not found']); break; }
        if($userRole==='technician' && intval($line['technician_id'])!==intval($userId)){ echo json_encode(['error'=>'Not your claim']); break; }
        if($line['claim_status']!=='draft'){ echo json_encode(['error'=>'Claim already submitted']); break; }
        $cid=intval($line['claim_id']);
        $pdo->prepare("DELETE FROM outstation_claim_lines WHERE id=?")->execute([$lid]);
        $pdo->prepare("UPDATE outstation_claims SET claimed_total=(SELECT COALESCE(SUM(amount),0) FROM outstation_claim_lines WHERE claim_id=?) WHERE id=?")->execute([$cid,$cid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Technician: submit the claim for approval.
case 'os_submit_claim':
    try {
        _ensureOutstationTables($pdo);
        $cid=intval($body['claim_id']??0);
        $c=$pdo->prepare("SELECT * FROM outstation_claims WHERE id=?"); $c->execute([$cid]); $claim=$c->fetch(PDO::FETCH_ASSOC);
        if(!$claim){ echo json_encode(['error'=>'Claim not found']); break; }
        if($userRole==='technician' && intval($claim['technician_id'])!==intval($userId)){ echo json_encode(['error'=>'Not your claim']); break; }
        if($claim['status']!=='draft'){ echo json_encode(['error'=>'Already submitted']); break; }
        $lc=$pdo->prepare("SELECT COUNT(*) FROM outstation_claim_lines WHERE claim_id=?"); $lc->execute([$cid]);
        if(intval($lc->fetchColumn())<1){ echo json_encode(['error'=>'Add at least one transport line first']); break; }
        $pdo->prepare("UPDATE outstation_claims SET status='submitted', submitted_at=NOW() WHERE id=?")->execute([$cid]);
        // Notify admins/managers
        try {
            require_once __DIR__.'/fcm_send.php';
            if(function_exists('fcm_send_to_user')){
                $techName=$currentUser['name']??'Technician';
                $admins=$pdo->query("SELECT id FROM users WHERE role IN ('admin','assigner','manager') AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
                foreach($admins as $aid){ fcm_send_to_user($pdo,intval($aid),'📍 Outstation claim submitted', $techName.' submitted an outstation claim for task '.($claim['task_id']??'').' (₹'.number_format($claim['claimed_total'],0).')', ['type'=>'outstation','url'=>'index.html']); }
            }
        } catch(Exception $e){}
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Admin/Manager: list submitted claims (with counts).
case 'os_admin_list':
    if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureOutstationTables($pdo);
        $filter=$_GET['status']??'submitted';
        $where = $filter==='all' ? "c.status<>'draft'" : "c.status=".$pdo->quote($filter);
        $rows=$pdo->query("SELECT c.*, u.name AS tech_name, t.customer_name, t.task_status,
                    (SELECT COUNT(*) FROM outstation_claim_lines l WHERE l.claim_id=c.id) AS line_count
                FROM outstation_claims c
                JOIN users u ON c.technician_id=u.id
                LEFT JOIN tasks t ON c.task_db_id=t.id
                WHERE $where ORDER BY c.submitted_at DESC, c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $pending=$pdo->query("SELECT COUNT(*) FROM outstation_claims WHERE status='submitted'")->fetchColumn();
        echo json_encode(['success'=>true,'claims'=>$rows,'pending_count'=>intval($pending)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Admin/Manager: full detail of one claim (header + lines + task info).
case 'os_admin_detail':
    if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureOutstationTables($pdo);
        $cid=intval($_GET['claim_id']??$body['claim_id']??0);
        $c=$pdo->prepare("SELECT c.*, u.name AS tech_name, t.customer_name, t.location AS task_location, t.task_status, t.device_qty,
                    (SELECT COUNT(*) FROM task_device_installs di WHERE di.task_id=c.task_db_id AND di.gps_serial_no IS NOT NULL AND di.gps_serial_no<>'') AS installed_count
                FROM outstation_claims c JOIN users u ON c.technician_id=u.id LEFT JOIN tasks t ON c.task_db_id=t.id WHERE c.id=?");
        $c->execute([$cid]); $claim=$c->fetch(PDO::FETCH_ASSOC);
        if(!$claim){ echo json_encode(['error'=>'Claim not found']); break; }
        $ln=$pdo->prepare("SELECT * FROM outstation_claim_lines WHERE claim_id=? ORDER BY id ASC"); $ln->execute([$cid]);
        echo json_encode(['success'=>true,'claim'=>$claim,'lines'=>$ln->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Admin: approve or reject a single bill line (with an approved amount).
case 'os_decide_line':
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can decide']); break; }
    try {
        _ensureOutstationTables($pdo);
        $lid=intval($body['line_id']??0);
        $decision=$body['decision']??'';
        $l=$pdo->prepare("SELECT * FROM outstation_claim_lines WHERE id=?"); $l->execute([$lid]); $line=$l->fetch(PDO::FETCH_ASSOC);
        if(!$line){ echo json_encode(['error'=>'Line not found']); break; }
        if($decision==='approve'){
            $appr=floatval($body['approved_amount']??$line['amount']);
            if($appr<0) $appr=0; if($appr>floatval($line['amount'])) $appr=floatval($line['amount']);
            $pdo->prepare("UPDATE outstation_claim_lines SET status='approved', approved_amount=?, note=? WHERE id=?")->execute([$appr,trim($body['note']??''),$lid]);
        } else {
            $pdo->prepare("UPDATE outstation_claim_lines SET status='rejected', approved_amount=0, note=? WHERE id=?")->execute([trim($body['note']??''),$lid]);
        }
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Admin: finalize the claim — sum approved lines, award that as coins to the technician.
case 'os_finalize_claim':
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can finalize']); break; }
    try {
        _ensureOutstationTables($pdo);
        $cid=intval($body['claim_id']??0);
        $c=$pdo->prepare("SELECT * FROM outstation_claims WHERE id=?"); $c->execute([$cid]); $claim=$c->fetch(PDO::FETCH_ASSOC);
        if(!$claim){ echo json_encode(['error'=>'Claim not found']); break; }
        if($claim['status']==='approved'){ echo json_encode(['error'=>'Already finalized']); break; }
        // Any line still pending?
        $pend=$pdo->prepare("SELECT COUNT(*) FROM outstation_claim_lines WHERE claim_id=? AND status='pending'"); $pend->execute([$cid]);
        if(intval($pend->fetchColumn())>0){ echo json_encode(['error'=>'Decide all lines first (some are still pending)']); break; }
        $sum=$pdo->prepare("SELECT COALESCE(SUM(approved_amount),0) FROM outstation_claim_lines WHERE claim_id=? AND status='approved'"); $sum->execute([$cid]);
        $approvedTotal=floatval($sum->fetchColumn());
        $pdo->prepare("UPDATE outstation_claims SET status='approved', approved_total=?, decided_at=NOW(), decided_by=? WHERE id=?")
            ->execute([$approvedTotal,$currentUser['name']??'admin',$cid]);
        // Award the approved total as coins (1 rupee = 1 coin), idempotent per claim.
        if($approvedTotal>0 && function_exists('award_coins')){
            award_coins($pdo, intval($claim['technician_id']), intval(round($approvedTotal)),
                'Outstation claim approved for task '.($claim['task_id']??''), null, 'os_claim_'.$cid,
                '🧾 Outstation approved: +'.intval(round($approvedTotal)).' coins', 'Your outstation claim for task '.($claim['task_id']??'').' was approved.');
        }
        echo json_encode(['success'=>true,'approved_total'=>$approvedTotal]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Technician: view my submitted claims + their status.
case 'os_my_claims':
    try {
        _ensureOutstationTables($pdo);
        $rows=$pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM outstation_claim_lines l WHERE l.claim_id=c.id) AS line_count FROM outstation_claims c WHERE c.technician_id=? ORDER BY c.id DESC LIMIT 100");
        $rows->execute([$userId]);
        echo json_encode(['success'=>true,'claims'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'save_fcm_token':
    $fcm = trim($body['fcm_token'] ?? '');
    $platform = trim($body['platform'] ?? 'android');
    if ($fcm === '') { echo json_encode(['error'=>'Missing fcm_token']); break; }
    try {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(255) NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN fcm_platform VARCHAR(20) NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN fcm_updated_at DATETIME NULL"); } catch(Exception $e){}
        $pdo->prepare("UPDATE users SET fcm_token=?, fcm_platform=?, fcm_updated_at=NOW() WHERE id=?")
            ->execute([$fcm, $platform, $userId]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- CLEAR FCM TOKEN (on logout) ----
case 'clear_fcm_token':
    try {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(255) NULL"); } catch(Exception $e){}
        $pdo->prepare("UPDATE users SET fcm_token=NULL WHERE id=?")->execute([$userId]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- MARK TASK OPENED (technician opened it — stops unopened reminders) ----
case 'mark_task_opened':
    $id = intval($body['id'] ?? $_GET['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    try {
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN opened_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        // Only stamp the first open, and only by the assigned technician
        $pdo->prepare("UPDATE tasks SET opened_at=NOW() WHERE id=? AND opened_at IS NULL AND (assigned_to=? OR ?=1)")
            ->execute([$id, $userId, in_array($userRole,['admin','assigner'])?1:0]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- MY EARNINGS (technician coin ledger) ----
case 'get_earnings':
    try {
        _ensureCoinLedger($pdo);
        $bal = $pdo->prepare("SELECT COALESCE(SUM(coins),0) FROM coin_ledger WHERE user_id=?");
        $bal->execute([$userId]);
        $total = intval($bal->fetchColumn());
        $earnedQ = $pdo->prepare("SELECT COALESCE(SUM(coins),0) FROM coin_ledger WHERE user_id=? AND coins>0");
        $earnedQ->execute([$userId]);
        $earned = intval($earnedQ->fetchColumn());
        $lostQ = $pdo->prepare("SELECT COALESCE(SUM(coins),0) FROM coin_ledger WHERE user_id=? AND coins<0");
        $lostQ->execute([$userId]);
        $lost = intval($lostQ->fetchColumn());
        $rows = $pdo->prepare("SELECT c.coins, c.reason, c.created_at, t.task_id
                               FROM coin_ledger c LEFT JOIN tasks t ON c.task_id=t.id
                               WHERE c.user_id=? ORDER BY c.created_at DESC LIMIT 100");
        $rows->execute([$userId]);
        echo json_encode([
            'success'      => true,
            'total_coins'  => $total,
            'total_earned' => $earned,
            'total_lost'   => abs($lost),
            'history'      => $rows->fetchAll(),
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- ADMIN: resolve a customer dispute (valid = deduct 50 coins from technician) ----
case 'resolve_dispute':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id = intval($body['task_id'] ?? $body['id'] ?? 0);
    $verdict = trim($body['verdict'] ?? ''); // 'valid' or 'invalid'
    if (!$id || !in_array($verdict,['valid','invalid'])) { echo json_encode(['error'=>'Need task_id and verdict (valid/invalid)']); break; }
    try {
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN dispute_status VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
        $tq=$pdo->prepare("SELECT id,assigned_to,dispute_status FROM tasks WHERE id=?"); $tq->execute([$id]); $t=$tq->fetch();
        if (!$t) { echo json_encode(['error'=>'Task not found']); break; }
        if ($verdict==='valid') {
            if ($t['assigned_to']) {
                award_coins($pdo, intval($t['assigned_to']), -50, 'Customer report confirmed valid by admin', $id, 'dispute50_'.$id, '😔 -50 coins — customer complaint', 'A customer report against your task was confirmed. Please ensure quality to avoid this.');
            }
            $pdo->prepare("UPDATE tasks SET dispute_status='confirmed' WHERE id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")
                ->execute([$id,$userId,'⚠️ Customer report confirmed valid — 50 coins deducted from technician.']);
        } else {
            $pdo->prepare("UPDATE tasks SET dispute_status='dismissed' WHERE id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")
                ->execute([$id,$userId,'Customer report reviewed and dismissed — no penalty.']);
        }
        echo json_encode(['success'=>true,'dispute_status'=>$verdict==='valid'?'confirmed':'dismissed']);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- ADMIN: list tasks with pending disputes ----
case 'get_disputes':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    try {
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN dispute_status VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
        $st=$pdo->prepare("SELECT t.id,t.task_id,t.customer_name,t.dispute_status,u.name AS tech_name,
                           (SELECT a.remark FROM task_activities a WHERE a.task_id=t.id AND a.activity_type='customer_dispute' ORDER BY a.created_at DESC LIMIT 1) AS dispute_remark
                           FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id
                           WHERE t.dispute_status='pending' ORDER BY t.updated_at DESC");
        $st->execute();
        echo json_encode(['success'=>true,'disputes'=>$st->fetchAll()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ---- ADMIN: verify (or reject) a non-cash payment screenshot ----
case 'verify_payment':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id = intval($body['task_id'] ?? $body['id'] ?? 0);
    $verdict = trim($body['verdict'] ?? 'verified'); // 'verified' or 'rejected'
    if (!$id) { echo json_encode(['error'=>'Missing task_id']); break; }
    try {
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN payment_verify_status VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
        $newStatus = $verdict==='rejected' ? 'rejected' : 'verified';
        $pdo->prepare("UPDATE tasks SET payment_verify_status=? WHERE id=?")->execute([$newStatus,$id]);
        $note = $newStatus==='verified'
            ? '✅ Payment screenshot verified by admin.'
            : '❌ Payment screenshot rejected by admin — please re-collect proof.';
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,$note]);
        echo json_encode(['success'=>true,'payment_verify_status'=>$newStatus]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'save_device_install':
    $tid = intval($body['task_id']??0);
    $idx = intval($body['device_index']??1);
    if (!$tid||!$idx) { echo json_encode(['error'=>'Missing params']); break; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_device_installs (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, device_index INT NOT NULL DEFAULT 1, vehicle_number VARCHAR(50), vehicle_type VARCHAR(50), gps_serial_no VARCHAR(100), name_on_server VARCHAR(200), server_name VARCHAR(50), rc_photo VARCHAR(200), selfie_photo VARCHAR(200), remarks TEXT, saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_device (task_id, device_index)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE task_device_installs ADD COLUMN server_id INT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE task_device_installs ADD COLUMN device_id VARCHAR(50) NULL"); } catch(Exception $e){}
    $pdo->prepare("INSERT INTO task_device_installs (task_id,device_index,vehicle_number,vehicle_type,gps_serial_no,name_on_server,server_name,server_id,device_id,remarks) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vehicle_number=VALUES(vehicle_number),vehicle_type=VALUES(vehicle_type),gps_serial_no=VALUES(gps_serial_no),name_on_server=VALUES(name_on_server),server_name=VALUES(server_name),server_id=VALUES(server_id),device_id=VALUES(device_id),remarks=VALUES(remarks),saved_at=NOW()")
        ->execute([$tid,$idx,trim($body['vehicle_number']??''),trim($body['vehicle_type']??''),trim($body['gps_serial_no']??''),trim($body['name_on_server']??''),trim($body['server_name']??''),(isset($body['server_id'])?intval($body['server_id']):null),trim($body['device_id']??''),trim($body['remarks']??'')]);
    if ($idx===1) {
        $pdo->prepare("UPDATE tasks SET gps_serial_no=?,name_on_server=?,server_name=? WHERE id=?")->execute([trim($body['gps_serial_no']??''),trim($body['name_on_server']??''),trim($body['server_name']??''),$tid]);
    }

    // ── Mark this device's assignment as INSTALLED (so it leaves "with technician" stock) ──
    try {
        $instImei = preg_replace('/\D/','',(string)($body['gps_serial_no']??''));
        if ($instImei !== '') {
            _devEnsureTables($pdo);
            $pdo->prepare("UPDATE device_assignments SET status='installed', assigned_at=assigned_at WHERE imei=? AND status='with_tech'")->execute([$instImei]);
        }
    } catch(Exception $e) {}

    // ── AUTO-CREATE BALANCE SHEET ENTRY ─────────────────────────────
    // Once ALL devices are installed → create BS entry if not already exists
    try {
        // Ensure bs_entry_id column exists
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id INT NULL"); } catch(Exception $e2){}
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e2){}

        // Fetch full task details
        $tr2 = $pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
        $tr2->execute([$tid]); $t2 = $tr2->fetch();

        if ($t2) {
            $totalQty = intval($t2['device_qty']??1);
            // Count devices actually installed so far
            $doneCount = $pdo->prepare("SELECT COUNT(*) FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL AND gps_serial_no != ''");
            $doneCount->execute([$tid]);
            $installedCount = intval($doneCount->fetchColumn());

            // SELF-HEAL: device_qty must never be LESS than the number of devices actually
            // installed. Older partial-finish logic could reduce device_qty below the real
            // installed count (e.g. task showed qty 4 while 5 devices were installed). Correct it.
            if ($installedCount > $totalQty) {
                try { $pdo->prepare("UPDATE tasks SET device_qty=? WHERE id=?")->execute([$installedCount, $tid]); } catch(Exception $e2){}
                $totalQty = $installedCount;
                $t2['device_qty'] = $installedCount;
            }

            // Free services (Troubleshoot / Demo / any zero-price task) must NOT appear in the
            // balance sheet — there is no money to track. Skip BS creation for them.
            $bsLead  = strtolower(trim((string)($t2['lead_type'] ?? '')));
            $bsPrice = floatval($t2['price_to_collect'] ?? 0);
            $bsIsFree = ($bsPrice <= 0) || in_array($bsLead, ['troubleshoot','demo']);
            if ($installedCount >= 1 && !$bsIsFree) {
                // Collect installed devices' serials/names for the entry
                $diRows = $pdo->prepare("SELECT gps_serial_no, name_on_server, server_name FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL AND gps_serial_no != '' ORDER BY device_index ASC");
                $diRows->execute([$tid]);
                $installs = $diRows->fetchAll();
                $allSerials = implode(', ', array_filter(array_column($installs, 'gps_serial_no')));
                $allNames   = implode(', ', array_filter(array_column($installs, 'name_on_server')));
                $serverName = $installs[0]['server_name'] ?? $t2['server_name'] ?? null;

                // PER-DEVICE PRICE: bill = unit_price × installed devices.
                // Use the unit_price captured at creation (stable even if qty changes). Fall back
                // to total ÷ qty only for older tasks that have no unit_price stored yet.
                $fullQty   = $totalQty > 0 ? $totalQty : 1;
                $fullTotal = floatval($t2['price_to_collect']??0);
                $storedUnit = isset($t2['unit_price']) ? floatval($t2['unit_price']) : 0;
                $unit2     = $storedUnit > 0 ? $storedUnit : ($fullQty > 0 ? $fullTotal / $fullQty : $fullTotal);
                $billQty   = $installedCount;                 // only what is installed
                $billTotal = round($unit2 * $billQty, 2);     // installed devices × per-device price
                $profile2  = $t2['profile']??'BGPT';

                // Payment received stays as recorded on the task; pending is only on installed amount
                $recv2 = floatval($t2['amount_collected']??0);
                if ($recv2 > $billTotal) $recv2 = $billTotal; // never show received above billed-so-far
                // CASH counts as received only after it is deposited & confirmed (cash_deposit_status='deposited').
                // While the cash is still with the technician, the balance sheet shows it as pending.
                if (strtolower((string)($t2['payment_mode']??''))==='cash' && ($t2['cash_deposit_status']??'') !== 'deposited') { $recv2 = 0; }
                $pend2 = max(0, $billTotal - $recv2);
                $pStatus = ($recv2 >= $billTotal && $billTotal > 0) ? 'paid' : ($recv2>0 ? 'partially_paid' : 'pending');

                $existingBsId = $t2['bs_entry_id'] ? intval($t2['bs_entry_id']) : 0;
                if ($existingBsId) {
                    // UPDATE the same task entry — club installs together, grow qty/amount
                    $pdo->prepare("UPDATE balance_sheet_entries SET
                        gps_serial_no=?, name_on_server=?, server_name=?,
                        qty=?, unit_price=?, total_price=?,
                        payment_received=?, pending_payment=?, payment_status=?,
                        updated_at=NOW()
                        WHERE id=?")
                        ->execute([
                            $allSerials ?: null, $allNames ?: null, $serverName,
                            $billQty, $unit2, $billTotal,
                            $recv2, $pend2, $pStatus,
                            $existingBsId
                        ]);
                } else {
                    // CREATE the task entry on first install
                    $bsType2 = bs_type_for_task($t2['device_details']??'');
                    $pdo->prepare("INSERT INTO balance_sheet_entries
                        (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,
                         name_on_server,server_name,device_model,qty,unit_price,gst,total_price,
                         payment_status,payment_received,pending_payment,payment_mode,
                         technician_name,location,remarks,created_by_code)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $bsType2,
                            $profile2, $t2['task_id'], $tid,
                            date('Y-m-d'),
                            $allSerials ?: null,
                            $t2['lead_type']??null,
                            $allNames ?: null,
                            $serverName,
                            $t2['device_details']??null,
                            $billQty, $unit2,
                            floatval($t2['gst_amount']??0), $billTotal,
                            $pStatus, $recv2, $pend2,
                            $t2['payment_mode']??null,
                            $t2['tech_name']??null, $t2['location']??null,
                            $t2['general_notes']??null,
                            $cu['name']??'system',
                        ]);
                    $newBsId = $pdo->lastInsertId();
                    if ($newBsId) {
                        $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$newBsId, $tid]);
                    }
                }
            }
        }
    } catch(Exception $bsEx) {
        // BS creation failure must NOT break the install save
        error_log('BS auto-create error: '.$bsEx->getMessage());
    }
    // ── END AUTO-CREATE BS ──────────────────────────────────────────

    echo json_encode(['success'=>true]);
    break;

// ============================================================
// BALANCE SHEET ENTRIES
// ============================================================

case 'get_balance_sheet':
    // Legacy — tasks based (kept for backward compat)
    $from=$_GET['from']??date('Y-m-01'); $to=$_GET['to']??date('Y-m-d');
    $s=$pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC"); $s->execute([$from,$to]);
    echo json_encode(['tasks'=>$s->fetchAll()]);
    break;

case 'bs_backfill_installs':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try {
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id INT NULL"); } catch(Exception $e2){}
        // Find tasks that have at least one installed device
        $rows = $pdo->query("SELECT DISTINCT t.id FROM tasks t
                             JOIN task_device_installs di ON di.task_id=t.id
                             WHERE di.gps_serial_no IS NOT NULL AND di.gps_serial_no != ''")->fetchAll(PDO::FETCH_COLUMN);
        $created = 0; $updated = 0;
        foreach ($rows as $tid) {
            $tr = $pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
            $tr->execute([$tid]); $t2 = $tr->fetch();
            if (!$t2) continue;
            $di = $pdo->prepare("SELECT gps_serial_no,name_on_server,server_name FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL AND gps_serial_no != '' ORDER BY device_index ASC");
            $di->execute([$tid]); $installs = $di->fetchAll();
            $installedCount = count($installs);
            if ($installedCount < 1) continue;
            $allSerials = implode(', ', array_filter(array_column($installs,'gps_serial_no')));
            $allNames   = implode(', ', array_filter(array_column($installs,'name_on_server')));
            $serverName = $installs[0]['server_name'] ?? $t2['server_name'] ?? null;
            $fullQty   = intval($t2['device_qty']??1); if($fullQty<1)$fullQty=1;
            $fullTotal = floatval($t2['price_to_collect']??0);
            $unit2     = $fullQty>0 ? $fullTotal/$fullQty : $fullTotal;
            $billQty   = $installedCount;
            $billTotal = round($unit2*$billQty, 2);
            $recv2     = floatval($t2['amount_collected']??0); if($recv2>$billTotal)$recv2=$billTotal;
            $pend2     = max(0, $billTotal-$recv2);
            $pStatus   = ($recv2>=$billTotal && $billTotal>0) ? 'paid' : ($recv2>0 ? 'partially_paid' : 'pending');
            $profile2  = !empty($t2['profile']) ? $t2['profile'] : 'BGPT';
            if (!empty($t2['bs_entry_id'])) {
                $pdo->prepare("UPDATE balance_sheet_entries SET gps_serial_no=?,name_on_server=?,server_name=?,qty=?,unit_price=?,total_price=?,payment_received=?,pending_payment=?,payment_status=?,updated_at=NOW() WHERE id=?")
                    ->execute([$allSerials?:null,$allNames?:null,$serverName,$billQty,$unit2,$billTotal,$recv2,$pend2,$pStatus,intval($t2['bs_entry_id'])]);
                $updated++;
            } else {
                $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code) VALUES (?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([bs_type_for_task($t2['device_details']??''),$profile2,$t2['task_id'],$tid,$allSerials?:null,$t2['lead_type']??null,$allNames?:null,$serverName,$t2['device_details']??null,$billQty,$unit2,floatval($t2['gst_amount']??0),$billTotal,$pStatus,$recv2,$pend2,$t2['payment_mode']??null,$t2['tech_name']??null,$t2['location']??null,$t2['general_notes']??null,$cu['name']??'system']);
                $bsId=$pdo->lastInsertId();
                if($bsId){ $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$tid]); }
                $created++;
            }
        }
        echo json_encode(['success'=>true,'created'=>$created,'updated'=>$updated,'scanned'=>count($rows)]);
    } catch(Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'bs_get_entries':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // Ensure table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE balance_sheet_entries ADD COLUMN technician_id INT DEFAULT NULL"); } catch(Exception $e) {}
    // Self-heal installs: this is HEAVY (loops all tasks with installed devices) and was
    // running on every load — for large profiles (BGPT) it slowed the request enough to blank
    // the page. Balance entries are already kept current by the update_task / approve_task hooks,
    // so only run this sync when explicitly requested (the Resync button sends resync=1).
    if (!empty($_GET['resync']) || !empty($body['resync'])) {
        try { _bsSyncInstalls($pdo, $cu['name']??'system'); } catch(Exception $e) {}
    }
    // Cleanup (also maintenance) — only on explicit resync, not every load.
    if (!empty($_GET['resync']) || !empty($body['resync'])) {
        try {
            $freeIds = $pdo->query("SELECT b.id FROM balance_sheet_entries b JOIN tasks t ON b.task_db_id=t.id WHERE COALESCE(t.price_to_collect,0) <= 0")->fetchAll(PDO::FETCH_COLUMN);
            if ($freeIds) {
                $in = implode(',', array_map('intval',$freeIds));
                $pdo->exec("DELETE FROM balance_sheet_entries WHERE id IN ($in)");
                $pdo->exec("UPDATE tasks SET bs_entry_id=NULL WHERE bs_entry_id IN ($in)");
            }
            // Also clear zero-total entries that have no task link
            $pdo->exec("DELETE FROM balance_sheet_entries WHERE COALESCE(total_price,0) <= 0 AND type='sales'");
        } catch(Exception $e) {}
    }
    $profile = $_GET['profile'] ?? 'BGPT';
    $where[] = "profile=?"; $params[] = $profile;
    if (!empty($_GET['type']))     { $where[]="type=?";          $params[]=$_GET['type']; }
    if (!empty($_GET['from']))     { $where[]="date>=?";         $params[]=$_GET['from']; }
    if (!empty($_GET['to']))       { $where[]="date<=?";         $params[]=$_GET['to']; }
    if (!empty($_GET['search']))   { $q='%'.$_GET['search'].'%'; $where[]="(task_id LIKE ? OR name_on_server LIKE ? OR gps_serial_no LIKE ? OR invoice_no LIKE ? OR technician_name LIKE ?)"; $params=array_merge($params,[$q,$q,$q,$q,$q]); }
    if (!empty($_GET['pending']))  { $where[]="pending_payment > 0"; }
    $sql = "SELECT * FROM balance_sheet_entries" . ($where?" WHERE ".implode(" AND ",$where):"") . " ORDER BY date DESC, created_at DESC LIMIT 1000";
    $s = $pdo->prepare($sql); $s->execute($params);
    $entries = $s->fetchAll();
    // Attach the task's payment screenshot (for sales/task entries) from task_documents.
    // License/renewal entries already carry their proof in payment_transaction_details.
    try {
        // Build the set of numeric task ids to look up. Prefer task_db_id; if missing,
        // resolve the string task_id (e.g. ID-2026-1462) to the numeric tasks.id.
        $taskDbIds = [];
        $needResolve = [];       // string task_id => entry indices
        foreach ($entries as $i => $e) {
            if (!empty($e['task_db_id'])) { $taskDbIds[(int)$e['task_db_id']] = true; }
            else if (!empty($e['task_id'])) { $needResolve[$e['task_id']][] = $i; }
        }
        // Resolve any string task_ids to numeric ids
        $resolvedByStr = [];
        if ($needResolve) {
            $strs = array_keys($needResolve);
            $ph2  = implode(',', array_fill(0, count($strs), '?'));
            try {
                $rq = $pdo->prepare("SELECT id, task_id FROM tasks WHERE task_id IN ($ph2)");
                $rq->execute($strs);
                foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                    $resolvedByStr[$tr['task_id']] = (int)$tr['id'];
                    $taskDbIds[(int)$tr['id']] = true;
                }
            } catch(Exception $e) {}
        }
        if ($taskDbIds) {
            $ids = array_keys($taskDbIds);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $ds = $pdo->prepare("SELECT task_id, doc_type, filename FROM task_documents
                                 WHERE task_id IN ($ph)
                                   AND ( doc_type IN ('payment_screenshot','cash_deposit_screenshot','cash_deposit')
                                         OR doc_type LIKE '%payment%'
                                         OR doc_type LIKE '%screenshot%'
                                         OR doc_type LIKE '%deposit%' )
                                 ORDER BY id ASC");
            $ds->execute($ids);
            $shotByTask = [];
            foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                // last one wins (most recent id) — payment_screenshot preferred over cash if both exist
                $tid = (int)$doc['task_id'];
                $path = 'uploads/task_'.$tid.'/'.$doc['filename'];
                if (!isset($shotByTask[$tid]) || $doc['doc_type']==='payment_screenshot') {
                    $shotByTask[$tid] = $path;
                }
            }
            foreach ($entries as $i => &$e) {
                $tid = (int)($e['task_db_id'] ?? 0);
                if (!$tid && !empty($e['task_id']) && isset($resolvedByStr[$e['task_id']])) {
                    $tid = $resolvedByStr[$e['task_id']];
                }
                if ($tid && isset($shotByTask[$tid])) { $e['payment_screenshot'] = $shotByTask[$tid]; }
            }
            unset($e);
        }
    } catch(Exception $e) { /* non-fatal: entries still returned without screenshot */ }
    // Stats
    $stats = [
        'total_sales'       => 0, 'total_license'     => 0,
        'payment_received'  => 0, 'pending_payment'   => 0,
        'devices_sold'      => 0, 'license_count'     => 0,
    ];
    foreach ($entries as $e) {
        if ($e['type']==='sales')   { $stats['total_sales']   += $e['total_price']; $stats['devices_sold']   += $e['qty']; }
        if ($e['type']==='license') { $stats['total_license'] += $e['total_price']; $stats['license_count'] += $e['qty']; }
        $stats['payment_received'] += $e['payment_received'];
        $stats['pending_payment']  += $e['pending_payment'];
    }
    echo json_encode(['entries'=>$entries,'stats'=>$stats,'count'=>count($entries)]);
    break;

case 'bs_add_entry':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $b = $body;
    $qty   = floatval($b['qty']??1);
    $unit  = floatval($b['unit_price']??0);
    $gst   = floatval($b['gst']??0);
    $total = floatval($b['total_price']??($unit*$qty+$gst));
    $recv  = floatval($b['payment_received']??0);
    $pend  = floatval($b['pending_payment']??($total-$recv));
    try { $pdo->exec("ALTER TABLE balance_sheet_entries ADD COLUMN technician_id INT DEFAULT NULL"); } catch(Exception $e){}
    $pdo->prepare("INSERT INTO balance_sheet_entries
        (type,profile,task_id,task_db_id,date,invoice_no,gps_serial_no,customer_type,
         name_on_server,server_name,device_model,service_type,license_plan,
         qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,
         payment_mode,payment_received_on,payment_transaction_details,
         pending_reason,discount_given,discount_reason,discount_incharge,payment_reminder_date,
         technician_name,technician_id,location,remarks,created_by_code)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $b['type']??'sales', $b['profile']??'BGPT',
            $b['task_id']??null, $b['task_db_id']??null,
            $b['date']??date('Y-m-d'), $b['invoice_no']??null,
            $b['gps_serial_no']??null, $b['customer_type']??null,
            $b['name_on_server']??null, $b['server_name']??null,
            $b['device_model']??null, $b['service_type']??null, $b['license_plan']??null,
            $qty, $unit, $gst, $total,
            $b['payment_status']??null, $recv, $pend,
            $b['payment_mode']??null,
            !empty($b['payment_received_on'])?$b['payment_received_on']:null,
            $b['payment_transaction_details']??null,
            $b['pending_reason']??null, floatval($b['discount_given']??0),
            $b['discount_reason']??null, $b['discount_incharge']??null,
            !empty($b['payment_reminder_date'])?$b['payment_reminder_date']:null,
            $b['technician_name']??null,
            (isset($b['technician_id']) && $b['technician_id']!=='')?intval($b['technician_id']):null,
            $b['location']??null,
            $b['remarks']??null, $cu['name'],
        ]);
    $newId = $pdo->lastInsertId();
    // Link back to task if task_db_id provided
    if (!empty($b['task_db_id'])) {
        $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$newId, $b['task_db_id']]);
    }
    echo json_encode(['success'=>true,'id'=>$newId]);
    break;

case 'bs_update_entry':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = intval($body['id']??0);
    if (!$id) { echo json_encode(['error'=>'Missing id']); break; }
    // Ensure technician_id column exists for linking manual entries to real technician accounts
    if (array_key_exists('technician_id',$body)) { try { $pdo->exec("ALTER TABLE balance_sheet_entries ADD COLUMN technician_id INT DEFAULT NULL"); } catch(Exception $e){} }
    $allowed = ['date','invoice_no','task_id','gps_serial_no','customer_type','name_on_server','server_name',
                'device_model','service_type','license_plan','qty','unit_price','gst','total_price',
                'payment_status','payment_received','pending_payment','payment_mode','payment_received_on',
                'payment_transaction_details','pending_reason','discount_given','discount_reason',
                'discount_incharge','payment_reminder_date','technician_name','technician_id','location','remarks','profile'];
    $sets=[]; $vals=[];
    foreach ($allowed as $f) {
        if (array_key_exists($f,$body)) { $sets[]="$f=?"; $vals[]=($body[$f]===''?null:$body[$f]); }
    }
    if ($sets) { $vals[]=$id; $pdo->prepare("UPDATE balance_sheet_entries SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    echo json_encode(['success'=>true]);
    break;

case 'bs_delete_entry':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id = intval($body['id']??0);
    $pdo->prepare("DELETE FROM balance_sheet_entries WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    break;

// ── EXPENSES (manual operating expenses: electricity, wifi, rent, etc.) ──────
case 'exp_get_entries':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureExpensesTable($pdo);
        $where=[]; $params=[];
        if (!empty($_GET['from'])) { $where[]="date >= ?"; $params[]=$_GET['from']; }
        if (!empty($_GET['to']))   { $where[]="date <= ?"; $params[]=$_GET['to']; }
        if (!empty($_GET['category'])) { $where[]="category = ?"; $params[]=$_GET['category']; }
        // Alias the shared-table columns to the field names the Expenses page expects.
        $sql = "SELECT id, date, category, amount, payment_mode,
                       description AS title, paid_to AS vendor, reference AS reference_no,
                       receipt_note AS notes, created_by AS created_by_code, created_at
                FROM expenses".($where?" WHERE ".implode(" AND ",$where):"")." ORDER BY date DESC, id DESC LIMIT 2000";
        $st = $pdo->prepare($sql); $st->execute($params);
        echo json_encode(['success'=>true,'entries'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── RECURRING MONTHLY BILLS ─────────────────────────────────────────
// ── RECURRING YEARLY BILLS ─────────────────────────────────────────
case 'exp_yearly_add':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureYearlyTables($pdo);
        $title=trim($body['title'] ?? ''); $amount=floatval($body['amount'] ?? 0);
        if ($title==='') { echo json_encode(['error'=>'Name required']); break; }
        if ($amount<=0)  { echo json_encode(['error'=>'Amount must be greater than 0']); break; }
        $pdo->prepare("INSERT INTO yearly_bills (title,category,amount,due_month,due_day,vendor,payment_mode,created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$title, trim($body['category'] ?? 'Other'), $amount,
                strval($body['due_month'] ?? '1'), strval($body['due_day'] ?? '1'),
                trim($body['vendor'] ?? '') ?: null, trim($body['payment_mode'] ?? '') ?: null, $cu['name'] ?? 'admin']);
        echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_yearly_list':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureYearlyTables($pdo);
        $yr = date('Y');
        $rows = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM yearly_bill_payments p WHERE p.bill_id=b.id AND p.yr='$yr') AS paid_this_year
                             FROM yearly_bills b WHERE b.active=1 ORDER BY CAST(b.due_month AS UNSIGNED) ASC, CAST(b.due_day AS UNSIGNED) ASC, b.title ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'items'=>$rows,'year'=>$yr]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_yearly_update':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureYearlyTables($pdo);
        $bid = intval($body['id'] ?? 0);
        if (!$bid) { echo json_encode(['error'=>'Missing id']); break; }
        $map = ['title'=>'title','category'=>'category','amount'=>'amount','due_month'=>'due_month','due_day'=>'due_day','vendor'=>'vendor','payment_mode'=>'payment_mode'];
        $sets=[]; $vals=[];
        foreach ($map as $in=>$col) { if (array_key_exists($in,$body)) { $sets[]="$col=?"; $vals[]=($body[$in]===''?null:$body[$in]); } }
        if ($sets) { $vals[]=$bid; $pdo->prepare("UPDATE yearly_bills SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_yearly_pay':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureYearlyTables($pdo); _ensureExpensesTable($pdo);
        $bid = intval($body['id'] ?? 0);
        if (!$bid) { echo json_encode(['error'=>'Missing id']); break; }
        $b = $pdo->prepare("SELECT * FROM yearly_bills WHERE id=?"); $b->execute([$bid]); $bill=$b->fetch(PDO::FETCH_ASSOC);
        if (!$bill) { echo json_encode(['error'=>'Bill not found']); break; }
        $yr = date('Y');
        $chk = $pdo->prepare("SELECT id FROM yearly_bill_payments WHERE bill_id=? AND yr=?"); $chk->execute([$bid,$yr]);
        if ($chk->fetch()) { echo json_encode(['error'=>'Already marked paid this year']); break; }
        $pdo->prepare("INSERT INTO expenses (company,date,category,description,amount,payment_mode,paid_to,receipt_note,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute(['BGPT', date('Y-m-d'), $bill['category'], $bill['title'], $bill['amount'],
                $bill['payment_mode'], $bill['vendor'], 'Yearly recurring bill', $cu['name'] ?? 'admin']);
        $expId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO yearly_bill_payments (bill_id,yr,expense_id) VALUES (?,?,?)")->execute([$bid,$yr,$expId]);
        echo json_encode(['success'=>true,'expense_id'=>$expId]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_yearly_delete':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can delete']); break; }
    try {
        _ensureYearlyTables($pdo);
        $bid = intval($body['id'] ?? 0);
        $pdo->prepare("UPDATE yearly_bills SET active=0 WHERE id=?")->execute([$bid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_monthly_add':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureMonthlyTables($pdo);
        $title=trim($body['title'] ?? ''); $amount=floatval($body['amount'] ?? 0);
        if ($title==='') { echo json_encode(['error'=>'Name required']); break; }
        if ($amount<=0)  { echo json_encode(['error'=>'Amount must be greater than 0']); break; }
        $pdo->prepare("INSERT INTO monthly_bills (title,category,amount,due_day,vendor,payment_mode,created_by)
            VALUES (?,?,?,?,?,?,?)")
            ->execute([$title, trim($body['category'] ?? 'Other'), $amount,
                strval($body['due_day'] ?? '1'), trim($body['vendor'] ?? '') ?: null,
                trim($body['payment_mode'] ?? '') ?: null, $cu['name'] ?? 'admin']);
        echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_monthly_update':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureMonthlyTables($pdo);
        $bid = intval($body['id'] ?? 0);
        if (!$bid) { echo json_encode(['error'=>'Missing id']); break; }
        $map = ['title'=>'title','category'=>'category','amount'=>'amount','due_day'=>'due_day','vendor'=>'vendor','payment_mode'=>'payment_mode'];
        $sets=[]; $vals=[];
        foreach ($map as $in=>$col) { if (array_key_exists($in,$body)) { $sets[]="$col=?"; $vals[]=($body[$in]===''?null:$body[$in]); } }
        if ($sets) { $vals[]=$bid; $pdo->prepare("UPDATE monthly_bills SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_monthly_list':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureMonthlyTables($pdo);
        $ym = date('Y-m');
        // active bills + whether paid this month
        $rows = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM monthly_bill_payments p WHERE p.bill_id=b.id AND p.ym='$ym') AS paid_this_month
                             FROM monthly_bills b WHERE b.active=1 ORDER BY CAST(b.due_day AS UNSIGNED) ASC, b.title ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'items'=>$rows,'month'=>$ym]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_monthly_pay':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureMonthlyTables($pdo); _ensureExpensesTable($pdo);
        $bid = intval($body['id'] ?? 0);
        if (!$bid) { echo json_encode(['error'=>'Missing id']); break; }
        $b = $pdo->prepare("SELECT * FROM monthly_bills WHERE id=?"); $b->execute([$bid]); $bill=$b->fetch(PDO::FETCH_ASSOC);
        if (!$bill) { echo json_encode(['error'=>'Bill not found']); break; }
        $ym = date('Y-m');
        // already paid this month?
        $chk = $pdo->prepare("SELECT id FROM monthly_bill_payments WHERE bill_id=? AND ym=?"); $chk->execute([$bid,$ym]);
        if ($chk->fetch()) { echo json_encode(['error'=>'Already marked paid this month']); break; }
        // 1) create the expense record
        $pdo->prepare("INSERT INTO expenses (company,date,category,description,amount,payment_mode,paid_to,receipt_note,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute(['BGPT', date('Y-m-d'), $bill['category'], $bill['title'], $bill['amount'],
                $bill['payment_mode'], $bill['vendor'], 'Monthly recurring bill', $cu['name'] ?? 'admin']);
        $expId = $pdo->lastInsertId();
        // 2) log the payment for this month so it disappears until next month
        $pdo->prepare("INSERT INTO monthly_bill_payments (bill_id,ym,expense_id) VALUES (?,?,?)")->execute([$bid,$ym,$expId]);
        echo json_encode(['success'=>true,'expense_id'=>$expId]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_monthly_delete':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can delete']); break; }
    try {
        _ensureMonthlyTables($pdo);
        $bid = intval($body['id'] ?? 0);
        // soft-remove so history/paid records stay intact
        $pdo->prepare("UPDATE monthly_bills SET active=0 WHERE id=?")->execute([$bid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_add_entry':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureExpensesTable($pdo);
        $title = trim($body['title'] ?? '');
        $amount = floatval($body['amount'] ?? 0);
        if ($title==='') { echo json_encode(['error'=>'Title required']); break; }
        if ($amount<=0)  { echo json_encode(['error'=>'Amount must be greater than 0']); break; }
        // Store into the shared expenses table columns (description/paid_to/reference/receipt_note).
        $pdo->prepare("INSERT INTO expenses (company,date,category,description,amount,payment_mode,paid_to,reference,receipt_note,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $body['company'] ?? 'BGPT',
                $body['date'] ?? date('Y-m-d'),
                trim($body['category'] ?? 'Other'),
                $title, $amount,
                trim($body['payment_mode'] ?? '') ?: null,
                trim($body['vendor'] ?? '') ?: null,
                trim($body['reference_no'] ?? '') ?: null,
                trim($body['notes'] ?? '') ?: null,
                $cu['name'] ?? 'admin',
            ]);
        echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_update_entry':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureExpensesTable($pdo);
        $id = intval($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'Missing id']); break; }
        // Map incoming page field names → shared-table column names.
        $map = ['date'=>'date','category'=>'category','title'=>'description','amount'=>'amount',
                'vendor'=>'paid_to','payment_mode'=>'payment_mode','reference_no'=>'reference','notes'=>'receipt_note'];
        $sets=[]; $vals=[];
        foreach ($map as $in=>$col) { if (array_key_exists($in,$body)) { $sets[]="$col=?"; $vals[]=($body[$in]===''?null:$body[$in]); } }
        if ($sets) { $vals[]=$id; $pdo->prepare("UPDATE expenses SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'exp_delete_entry':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can delete expenses']); break; }
    try {
        _ensureExpensesTable($pdo);
        $id = intval($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'bs_from_task':
    // Auto-create BS entry from a closed task
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // Ensure table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}
    // Ensure bs_entry_id column exists on tasks
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id INT NULL"); } catch(Exception $e) {}
    $taskDbId = intval($body['task_db_id']??0);
    if (!$taskDbId) { echo json_encode(['error'=>'Missing task_db_id']); break; }
    $tr=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
    $tr->execute([$taskDbId]); $t=$tr->fetch();
    if (!$t) { echo json_encode(['error'=>'Task not found']); break; }
    // Check if already has BS entry
    if ($t['bs_entry_id']) { echo json_encode(['success'=>true,'id'=>$t['bs_entry_id'],'existing'=>true]); break; }
    $qty   = floatval($t['device_qty']??1);
    $total = floatval($t['price_to_collect']??0);
    $unit  = $qty>0 ? $total/$qty : $total;
    $recv  = floatval($t['amount_collected']??0);
    $pend  = max(0,$total-$recv);
    $profile = $body['profile']??'BGPT';
    $pdo->prepare("INSERT INTO balance_sheet_entries
        (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,
         name_on_server,server_name,device_model,qty,unit_price,gst,total_price,
         payment_status,payment_received,pending_payment,payment_mode,
         payment_received_on,payment_transaction_details,
         pending_reason,discount_reason,discount_incharge,payment_reminder_date,
         technician_name,location,remarks,created_by_code)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            bs_type_for_task($t['device_details']??''),
            $profile, $t['task_id'], $taskDbId,
            $t['closed_at']?date('Y-m-d',strtotime($t['closed_at'])):date('Y-m-d'),
            $t['gps_serial_no']??null,
            $t['lead_type']??null,
            $t['name_on_server']??null, $t['server_name']??null,
            $t['device_details']??null, $qty, $unit,
            floatval($t['gst_amount']??0), $total,
            $t['payment_status']??'Pending', $recv, $pend,
            $t['payment_mode']??null,
            $t['payment_received_on']??null, $t['payment_transaction_details']??null,
            $t['pending_reason']??null, $t['discount_reason']??null,
            $t['discount_incharge']??null, $t['payment_reminder_date']??null,
            $t['tech_name']??null, $t['location']??null,
            $t['general_notes']??null, $cu['name'],
        ]);
    $newId = $pdo->lastInsertId();
    $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$newId,$taskDbId]);
    echo json_encode(['success'=>true,'id'=>$newId,'task_id'=>$t['task_id']]);
    break;

// ── BS RESYNC ALL — fix existing entries from task data ──────
// ── DIAGNOSE why a task is / isn't in the balance sheet ──
case 'bs_task_diagnose':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $q = trim($_GET['task'] ?? $body['task'] ?? '');
        if ($q==='') { echo json_encode(['error'=>'Pass ?task=<task number or id>']); break; }
        // Find by task_id string first, then by numeric id
        $tt=$pdo->prepare("SELECT * FROM tasks WHERE task_id=? OR id=? LIMIT 1");
        $tt->execute([$q, intval($q)]);
        $t=$tt->fetch(PDO::FETCH_ASSOC);
        if (!$t) { echo json_encode(['error'=>'Task not found for "'.$q.'"']); break; }
        $tid = intval($t['id']);
        // Installs with serials
        $di=$pdo->prepare("SELECT device_index,gps_serial_no,name_on_server,server_name FROM task_device_installs WHERE task_id=?");
        $di->execute([$tid]); $installs=$di->fetchAll(PDO::FETCH_ASSOC);
        $withSerial = array_filter($installs, function($x){ return !empty($x['gps_serial_no']); });
        // BS entry?
        $be=$pdo->prepare("SELECT id,total_price,payment_status,payment_received,pending_payment FROM balance_sheet_entries WHERE task_db_id=?");
        $be->execute([$tid]); $bs=$be->fetchAll(PDO::FETCH_ASSOC);
        $lead = strtolower(trim((string)($t['lead_type']??'')));
        $price= floatval($t['price_to_collect']??0);
        $isFree = ($price<=0) || in_array($lead,['troubleshoot','demo']);
        // Work out the verdict
        $reasons=[];
        if ($isFree) $reasons[]='Task is FREE/zero-value (price '.$price.', lead "'.$t['lead_type'].'") → excluded from balance sheet by design.';
        if (count($withSerial)<1) $reasons[]='No installed device with a GPS serial number recorded → balance sheet entry is only created once a device is installed with a serial.';
        if (!empty($bs)) $reasons[]='A balance-sheet entry DOES exist (id '.$bs[0]['id'].', total '.$bs[0]['total_price'].', status '.$bs[0]['payment_status'].') — it may be hidden by the current filter/date/profile on the page.';
        if (empty($reasons)) $reasons[]='Task qualifies but has no entry — click Resync from Tasks to create it.';
        echo json_encode(['success'=>true,'diagnosis'=>[
            'task_id'=>$t['task_id'],'db_id'=>$tid,'status'=>$t['task_status'],
            'lead_type'=>$t['lead_type'],'profile'=>$t['profile'],
            'price_to_collect'=>$price,'amount_collected'=>$t['amount_collected'],
            'payment_mode'=>$t['payment_mode'],'cash_deposit_status'=>$t['cash_deposit_status'],
            'device_qty'=>$t['device_qty'],'installs_total'=>count($installs),
            'installs_with_serial'=>count($withSerial),
            'is_free_excluded'=>$isFree,
            'bs_entry_exists'=>!empty($bs),
            'bs_entry'=>$bs[0]??null,
            'why'=>$reasons,
        ]]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'bs_resync_all':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    try {
        // FIRST: create any MISSING entries for tasks that have installed devices but no BS entry yet.
        $created = 0;
        try { $syncRes = _bsSyncInstalls($pdo, $cu['name'] ?? 'system'); $created = intval($syncRes['created'] ?? 0); } catch(Exception $e){ error_log('resync create: '.$e->getMessage()); }
        // THEN: refresh payment figures on all task-linked entries.
        $rows = $pdo->query("SELECT b.id, b.task_db_id, t.price_to_collect, t.amount_collected, t.payment_mode, t.task_status, t.device_details, t.cash_deposit_status
            FROM balance_sheet_entries b
            JOIN tasks t ON b.task_db_id = t.id
            WHERE b.task_db_id IS NOT NULL")->fetchAll();
        $count = 0;
        foreach ($rows as $r) {
            $total = floatval($r['price_to_collect']??0);
            // Cash counts as received only once deposited & confirmed; otherwise it is still pending.
            $isCash = strtolower((string)($r['payment_mode']??''))==='cash';
            $recv = floatval($r['amount_collected']??0);
            if ($isCash && ($r['cash_deposit_status']??'') !== 'deposited') { $recv = 0; }
            if ($recv > $total) $recv = $total;
            $pend  = max(0, $total - $recv);
            if ($total <= 0 || $recv <= 0)  $ps = 'pending';
            elseif ($recv >= $total - 15)   $ps = 'paid';
            else                             $ps = 'partially_paid';
            $bsType = bs_type_for_task($r['device_details']??'');
            $pdo->prepare("UPDATE balance_sheet_entries SET
                payment_received=?, pending_payment=?, payment_status=?,
                payment_mode=?, total_price=?, type=?, updated_at=NOW()
                WHERE id=?")
                ->execute([$recv, $pend, $ps, $r['payment_mode'], $total, $bsType, $r['id']]);
            $count++;
        }
        echo json_encode(['success'=>true, 'updated'=>$count, 'created'=>$created]);
    } catch(Exception $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    break;

// ── USER MANAGEMENT ──────────────────────────────────────────
case 'delete_user':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $uid = intval($body['id']??0);
    if(!$uid) { echo json_encode(['error'=>'Missing user id']); break; }
    // Safety: cannot delete yourself
    if($uid === $cu['id']) { echo json_encode(['error'=>'Cannot delete your own account']); break; }
    try {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){
        echo json_encode(['error'=>'Cannot delete user — they may have tasks assigned. Try disabling instead.']);
    }
    break;

case 'create_user':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $name  = trim($body['name']??'');
    $email = trim($body['email']??'');
    $pass  = $body['password']??'';
    $role  = $body['role']??'technician';
    $active= intval($body['is_active']??1);
    if(!$name||!$email||!$pass) { echo json_encode(['error'=>'Name, email and password required']); break; }
    if(strlen($pass)<6) { echo json_encode(['error'=>'Password must be at least 6 characters']); break; }
    $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $check->execute([$email]);
    if($check->fetch()) { echo json_encode(['error'=>'Email already exists']); break; }
    $pdo->prepare("INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,?,?)")
        ->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$active]);
    echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
    break;

case 'update_user':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $uid   = intval($body['id']??0);
    if(!$uid) { echo json_encode(['error'=>'Missing user id']); break; }
    $sets=[]; $vals=[];
    if(!empty($body['name']))      { $sets[]='name=?';      $vals[]=$body['name']; }
    if(!empty($body['email']))     { $sets[]='email=?';     $vals[]=$body['email']; }
    if(!empty($body['role']))      { $sets[]='role=?';      $vals[]=$body['role']; }
    if(isset($body['is_active']))  { $sets[]='is_active=?'; $vals[]=intval($body['is_active']); }
    if(!empty($body['password']))  { $sets[]='password=?';  $vals[]=password_hash($body['password'],PASSWORD_DEFAULT); }
    if(!$sets) { echo json_encode(['error'=>'Nothing to update']); break; }
    $vals[]=$uid;
    $pdo->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    echo json_encode(['success'=>true]);
    break;

// ============================================================
// BLACKLIST
// ============================================================
case 'check_blacklist':
    // Read-only lookup — safe to run without strict auth
    // Returns found:false silently if table doesn't exist yet
    $blPhone = trim($_GET['phone'] ?? '');
    $blEmail = trim($_GET['email'] ?? '');
    if(!$blPhone && !$blEmail){ echo json_encode(['found'=>false]); break; }
    try {
        $blWhere=[]; $blVals=[];
        if($blPhone){ $blWhere[]="phone=?"; $blVals[]=$blPhone; }
        if($blEmail){ $blWhere[]="email=?"; $blVals[]=$blEmail; }
        $blStmt=$pdo->prepare("SELECT * FROM blacklist_entries WHERE status='active' AND (".implode(' OR ',$blWhere).") ORDER BY added_at DESC LIMIT 1");
        $blStmt->execute($blVals);
        $blRow=$blStmt->fetch();
        echo json_encode($blRow ? ['found'=>true,'entry'=>$blRow] : ['found'=>false]);
    } catch(Exception $blE){
        echo json_encode(['found'=>false]);
    }
    break;

// ══════════ TECHNICIAN NOTES (warning / appreciation / issue / general) ══════════
case 'add_tech_note':
    // Admin only: post a note for a staff member (technician or any user).
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can add notes']); break; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tech_notes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, note_type VARCHAR(20) DEFAULT 'general', title VARCHAR(200), body TEXT, created_by INT NULL, created_by_name VARCHAR(190), acknowledged_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $uid  = intval($body['user_id'] ?? 0);
        $type = trim($body['note_type'] ?? 'general');
        $ttl  = trim($body['title'] ?? '');
        $bdy  = trim($body['body'] ?? '');
        if(!$uid || $bdy===''){ echo json_encode(['error'=>'Staff and note are required']); break; }
        if(!in_array($type,['warning','appreciation','issue','general'])) $type='general';
        $pdo->prepare("INSERT INTO tech_notes (user_id,note_type,title,body,created_by,created_by_name) VALUES (?,?,?,?,?,?)")
            ->execute([$uid,$type,$ttl,$bdy,$userId,($cu['name']??'Admin')]);
        // Optional push to the technician
        try {
            $titles = ['warning'=>'⚠️ Warning from admin','appreciation'=>'👏 Appreciation from admin','issue'=>'❗ Issue noted','general'=>'📝 Note from admin'];
            if(function_exists('fcm_send_to_user')){ fcm_send_to_user($pdo,$uid,$titles[$type]??'📝 New note', ($ttl!==''?$ttl:mb_substr($bdy,0,80)), ['type'=>'tech_note']); }
        } catch(Exception $e){}
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'list_tech_notes':
    // Admin: notes for a given user_id (?user_id=). Technician: their own notes.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tech_notes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, note_type VARCHAR(20) DEFAULT 'general', title VARCHAR(200), body TEXT, created_by INT NULL, created_by_name VARCHAR(190), acknowledged_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if($userRole==='admin'){
            $uid = intval($_GET['user_id'] ?? $body['user_id'] ?? 0);
            if(!$uid){ echo json_encode(['error'=>'user_id required']); break; }
        } else {
            $uid = intval($userId);   // technician sees only their own
        }
        $st = $pdo->prepare("SELECT * FROM tech_notes WHERE user_id=? ORDER BY created_at DESC");
        $st->execute([$uid]);
        echo json_encode(['success'=>true,'notes'=>$st->fetchAll()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'fcm_diagnostic':
    // Admin only: report FCM health so we know why pushes are/aren't arriving.
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try {
        $out = [];
        require_once __DIR__.'/fcm_send.php';
        // 1) Key file present & readable?
        $keyPath = function_exists('fcm_key_path') ? fcm_key_path() : (__DIR__.'/../fcm-key.json');
        $out['key_file_path'] = $keyPath;
        $out['key_file_exists'] = file_exists($keyPath);
        $out['key_file_readable'] = is_readable($keyPath);
        if($out['key_file_readable']){
            $k = json_decode(@file_get_contents($keyPath), true);
            $out['project_id'] = $k['project_id'] ?? null;
            $out['client_email'] = $k['client_email'] ?? null;
        }
        // 2) Function available?
        $out['fcm_function_exists'] = function_exists('fcm_send_to_user');
        // 3) Access token obtainable?
        if(function_exists('fcm_get_access_token')){
            $tok = fcm_get_access_token();
            $out['access_token_ok'] = !empty($tok);
        } else { $out['access_token_ok'] = 'no fcm_get_access_token()'; }
        // 4) How many users have a token?
        try { $out['users_total'] = intval($pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn()); } catch(Exception $e){ $out['users_total']='?'; }
        try { $out['users_with_token'] = intval($pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1 AND fcm_token IS NOT NULL AND fcm_token<>''")->fetchColumn()); } catch(Exception $e){ $out['users_with_token']='err: '.$e->getMessage(); }
        // 5) Does the CURRENT admin have a token, and can we send to self?
        try { $me=$pdo->prepare("SELECT fcm_token FROM users WHERE id=?"); $me->execute([$userId]); $mt=$me->fetchColumn(); $out['my_token_present']=!empty($mt); } catch(Exception $e){ $out['my_token_present']='err'; }
        if(!empty($mt) && function_exists('fcm_send_to_user')){
            $out['test_send_to_self'] = fcm_send_to_user($pdo,$userId,'🔔 Test','This is a test push from BharatGPS',['type'=>'test']) ? 'sent' : 'failed';
        } else { $out['test_send_to_self']='skipped (no token)'; }

        // 6) Check a SPECIFIC technician (pass ?tech_id= or body tech_id) and test-send to them.
        $diagTech = intval($_GET['tech_id'] ?? $body['tech_id'] ?? 0);
        if ($diagTech) {
            try {
                $tq = $pdo->prepare("SELECT id,name,is_active,fcm_token FROM users WHERE id=?");
                $tq->execute([$diagTech]); $tr = $tq->fetch(PDO::FETCH_ASSOC);
                if (!$tr) { $out['tech_check'] = ['error'=>'technician not found']; }
                else {
                    $hasToken = !empty($tr['fcm_token']);
                    $info = [
                        'name'         => $tr['name'],
                        'is_active'    => intval($tr['is_active']),
                        'token_present'=> $hasToken,
                        'token_tail'   => $hasToken ? ('…'.substr($tr['fcm_token'],-8)) : null,
                    ];
                    // Try sending regardless of is_active so we can see the real reason.
                    if ($hasToken && function_exists('fcm_send_to_token')) {
                        $sent = fcm_send_to_token($tr['fcm_token'], '🔔 Test to '.$tr['name'], 'If you see this, push works.', ['type'=>'test']);
                        $info['direct_send'] = $sent ? 'sent' : 'failed';
                        $info['fcm_response'] = $GLOBALS['_fcm_last_response'] ?? null;
                    } else {
                        $info['direct_send'] = 'skipped (no token)';
                    }
                    // Also test via the normal helper (which filters is_active=1) to reveal that filter.
                    if (function_exists('fcm_send_to_user')) {
                        $info['via_helper_is_active_filter'] = fcm_send_to_user($pdo,$diagTech,'🔔 Test (helper)','Helper path test.',['type'=>'test']) ? 'sent' : 'failed/blocked';
                    }
                    $out['tech_check'] = $info;
                }
            } catch(Exception $e){ $out['tech_check']=['error'=>$e->getMessage()]; }
        }
        echo json_encode(['success'=>true,'diag'=>$out]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'broadcast_push':
    // Admin only: send a one-off push alert to all active staff. Not stored — just a pop-up.
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can broadcast']); break; }
    try {
        $title = trim($body['title'] ?? '📢 Announcement');
        $msg   = trim($body['message'] ?? '');
        if($msg===''){ echo json_encode(['error'=>'Message is required']); break; }
        if($title==='') $title='📢 Announcement';
        // Target: all active users, or a role filter if provided
        $roleFilter = trim($body['role'] ?? '');
        if(in_array($roleFilter,['technician','assigner','admin'])){
            $q = $pdo->prepare("SELECT id FROM users WHERE is_active=1 AND role=?"); $q->execute([$roleFilter]);
        } else {
            $q = $pdo->query("SELECT id FROM users WHERE is_active=1");
        }
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        $sent=0; $failed=0;
        require_once __DIR__.'/fcm_send.php';
        if(function_exists('fcm_send_to_user')){
            foreach($ids as $uid){
                try { if(fcm_send_to_user($pdo, intval($uid), $title, $msg, ['type'=>'broadcast'])) $sent++; else $failed++; }
                catch(Exception $e){ $failed++; }
            }
        }
        echo json_encode(['success'=>true,'sent'=>$sent,'failed'=>$failed,'total'=>count($ids)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'delete_tech_note':
    // Admin only: delete a note. Removing it here removes it for the technician too (single source).
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can delete notes']); break; }
    try {
        $nid = intval($body['note_id'] ?? 0);
        if(!$nid){ echo json_encode(['error'=>'note_id required']); break; }
        $pdo->prepare("DELETE FROM tech_notes WHERE id=?")->execute([$nid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'ack_all_notes':
    // Technician: mark ALL their notes as read (called when they open the notes panel).
    try {
        $pdo->prepare("UPDATE tech_notes SET acknowledged_at=NOW() WHERE user_id=? AND acknowledged_at IS NULL")
            ->execute([intval($userId)]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'ack_tech_note':
    // Technician acknowledges (marks read) one of their own notes.
    try {
        $nid = intval($body['note_id'] ?? 0);
        if(!$nid){ echo json_encode(['error'=>'note_id required']); break; }
        $pdo->prepare("UPDATE tech_notes SET acknowledged_at=NOW() WHERE id=? AND user_id=? AND acknowledged_at IS NULL")
            ->execute([$nid, intval($userId)]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'my_notes_unread':
    // Technician: count of unacknowledged notes (for a badge).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tech_notes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, note_type VARCHAR(20) DEFAULT 'general', title VARCHAR(200), body TEXT, created_by INT NULL, created_by_name VARCHAR(190), acknowledged_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("SELECT COUNT(*) FROM tech_notes WHERE user_id=? AND acknowledged_at IS NULL");
        $st->execute([intval($userId)]);
        echo json_encode(['success'=>true,'unread'=>intval($st->fetchColumn())]);
    } catch(Exception $e){ echo json_encode(['success'=>true,'unread'=>0]); }
    break;

case 'get_blacklist':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // Create table if not exists (runs on first load of blacklist page)
    $pdo->exec("CREATE TABLE IF NOT EXISTS blacklist_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(200) NULL,
        phone VARCHAR(20) NULL,
        email VARCHAR(200) NULL,
        task_id VARCHAR(20) NULL,
        task_db_id INT NULL,
        reason TEXT NULL,
        added_by VARCHAR(100) NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        cleared_by VARCHAR(100) NULL,
        cleared_reason TEXT NULL,
        cleared_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $blSearch = trim($_GET['search'] ?? '');
    $blStatus = $_GET['status'] ?? 'active';
    $blSql = "SELECT * FROM blacklist_entries";
    $blW=[]; $blV=[];
    if($blStatus !== 'all'){ $blW[]="status=?"; $blV[]=$blStatus; }
    if($blSearch){ $blW[]="(phone LIKE ? OR email LIKE ? OR customer_name LIKE ?)"; $blV[]="%$blSearch%"; $blV[]="%$blSearch%"; $blV[]="%$blSearch%"; }
    if($blW) $blSql .= " WHERE ".implode(' AND ',$blW);
    $blSql .= " ORDER BY added_at DESC";
    $blS=$pdo->prepare($blSql); $blS->execute($blV);
    echo json_encode(['entries'=>$blS->fetchAll()]);
    break;

case 'add_blacklist':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS blacklist_entries (id INT AUTO_INCREMENT PRIMARY KEY, customer_name VARCHAR(200) NULL, phone VARCHAR(20) NULL, email VARCHAR(200) NULL, task_id VARCHAR(20) NULL, task_db_id INT NULL, reason TEXT NULL, added_by VARCHAR(100) NULL, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, status VARCHAR(20) DEFAULT 'active', cleared_by VARCHAR(100) NULL, cleared_reason TEXT NULL, cleared_at TIMESTAMP NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $blPhone = trim($body['phone'] ?? '');
    $blEmail = trim($body['email'] ?? '');
    if(!$blPhone && !$blEmail){ echo json_encode(['error'=>'Phone or email required']); break; }
    $pdo->prepare("INSERT INTO blacklist_entries (customer_name,phone,email,task_id,task_db_id,reason,added_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([
            $body['customer_name'] ?? null,
            $blPhone ?: null,
            $blEmail ?: null,
            $body['task_id'] ?? null,
            !empty($body['task_db_id']) ? intval($body['task_db_id']) : null,
            $body['reason'] ?? null,
            $cu['name'] ?? 'System',
        ]);
    echo json_encode(['success'=>true]);
    break;

case 'clear_blacklist':
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $blId = intval($body['id']??0);
    if(!$blId){ echo json_encode(['error'=>'Missing id']); break; }
    $pdo->prepare("UPDATE blacklist_entries SET status='cleared', cleared_by=?, cleared_reason=?, cleared_at=NOW() WHERE id=?")
        ->execute([$cu['name']??'Admin', $body['reason']??'Cleared by management', $blId]);
    echo json_encode(['success'=>true]);
    break;

// ============================================================
// SAVE JOB OUTCOME — Troubleshoot / Demo / Remove / V2V
// ============================================================
case 'save_job_outcome':
    if(!in_array($userRole,['admin','assigner','technician'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $jid   = intval($body['task_id']??0);
    $jtype = $body['job_type']??'';
    if(!$jid){ echo json_encode(['error'=>'Task ID required']); break; }

    // Build remark from submitted fields
    $parts = [];
    $fields = $body['fields'] ?? [];
    foreach($fields as $label => $val){
        if($val!=='' && $val!==null) $parts[] = "**{$label}:** {$val}";
    }
    $remark = ($body['summary']??'') . "
" . implode("
", $parts);

    // Log activity
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'tech')")
        ->execute([$jid, $userId, trim($remark)]);

    // Set task status based on job type
    $isDemo = ($jtype === 'demo');
    $statusUpdateOk = false;
    $statusErrMsg = '';
    try {
        if($isDemo){
            // Demo tasks go to 'Demo Done' — NOT closed, awaiting conversion or follow-up
            $newStatus = 'Demo Done';
            // Save demo fields to task for future reference
            $interest   = $fields['Interest Level'] ?? '';
            $followup   = $fields['Follow-up']      ?? '';
            $fuDate     = '';
            if(strpos($followup,'Yes') !== false){
                preg_match('/\d{4}-\d{2}-\d{2}/', $followup, $m);
                $fuDate = $m[0] ?? '';
            }
            $reportJson = json_encode($fields);
            $pdo->prepare("UPDATE tasks SET task_status='Demo Done', demo_interest=?, demo_followup_date=?, demo_report_json=?, updated_at=NOW() WHERE id=?")
                ->execute([$interest ?: null, $fuDate ?: null, $reportJson, $jid]);
            $statusUpdateOk = true;
            // Send customer thank-you email (failure here must not block success response)
            try {
                $taskRow = $pdo->prepare("SELECT t.*,u.name as tech_name,u.phone as tech_phone FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
                $taskRow->execute([$jid]); $tr = $taskRow->fetch();
                if($tr && $tr['email']){
                    require_once __DIR__.'/mailer.php';
                    sendDemoDoneCustomer($tr, $tr['tech_name']??'', $fields);
                }
            } catch(Exception $mailEx){ /* email failure should not block task status save */ }
        } else {
            $newStatus = ($body['close_task']??false) ? 'Awaiting Approval' : 'In Progress';
            $pdo->prepare("UPDATE tasks SET task_status=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStatus, $jid]);
            $statusUpdateOk = true;
        }

        // For removal — save serial number
        if(!empty($body['removed_serial'])){
            $pdo->prepare("UPDATE tasks SET gps_serial_no=?, updated_at=NOW() WHERE id=?")
                ->execute([$body['removed_serial'], $jid]);
        }
    } catch(Exception $statusEx){
        $statusErrMsg = $statusEx->getMessage();
    }

    if($statusUpdateOk){
        echo json_encode(['success'=>true, 'status'=>$newStatus]);
    } else {
        http_response_code(500);
        echo json_encode(['success'=>false, 'error'=>'Could not update task status: ' . $statusErrMsg]);
    }
    break;

// ============================================================
// FINANCE PORTAL ACTIONS
// ============================================================
case 'verify_pin':
    $pin=trim($body['pin']??'');
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS finance_settings(id INT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(50) UNIQUE,setting_value TEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    try{$pdo->exec("INSERT IGNORE INTO finance_settings(setting_key,setting_value)VALUES('finance_pin','9999')");}catch(Exception $e){}
    $s=$pdo->prepare("SELECT setting_value FROM finance_settings WHERE setting_key='finance_pin'");$s->execute();$stored=$s->fetchColumn()?:'9999';
    echo json_encode($pin===$stored?['success'=>true]:['success'=>false,'error'=>'Wrong PIN']);break;

case 'update_pin':
    $pin=trim($body['pin']??'');if(strlen($pin)<4){echo json_encode(['error'=>'Min 4 digits']);break;}
    $pdo->prepare("INSERT INTO finance_settings(setting_key,setting_value)VALUES('finance_pin',?)ON DUPLICATE KEY UPDATE setting_value=?")->execute([$pin,$pin]);
    echo json_encode(['success'=>true]);break;

case 'get_suppliers':
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS suppliers(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',name VARCHAR(150) NOT NULL,contact_person VARCHAR(100),phone VARCHAR(20),email VARCHAR(150),address TEXT,gst_no VARCHAR(20),device_types TEXT,notes TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    $s=$pdo->prepare("SELECT * FROM suppliers WHERE company=? AND is_active=1 ORDER BY name");$s->execute([$_GET['company']??'BGPT']);echo json_encode(['suppliers'=>$s->fetchAll()]);break;

case 'save_supplier':
    $id=intval($body['id']??0);$name=trim($body['name']??'');if(!$name){echo json_encode(['error'=>'Name required']);break;}
    if($id){$pdo->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,gst_no=?,device_types=?,notes=?,company=? WHERE id=?")->execute([trim($body['name']),trim($body['contact_person']??''),trim($body['phone']??''),trim($body['email']??''),trim($body['address']??''),trim($body['gst_no']??''),trim($body['device_types']??''),trim($body['notes']??''),$body['company']??'BGPT',$id]);}
    else{$pdo->prepare("INSERT INTO suppliers(company,name,contact_person,phone,email,address,gst_no,device_types,notes)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$body['company']??'BGPT',trim($body['name']),trim($body['contact_person']??''),trim($body['phone']??''),trim($body['email']??''),trim($body['address']??''),trim($body['gst_no']??''),trim($body['device_types']??''),trim($body['notes']??'')]);}
    echo json_encode(['success'=>true,'id'=>$id?:$pdo->lastInsertId()]);break;

case 'delete_supplier':
    $pdo->prepare("UPDATE suppliers SET is_active=0 WHERE id=?")->execute([intval($body['id']??0)]);echo json_encode(['success'=>true]);break;

case 'get_purchase_orders':
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',po_number VARCHAR(30) UNIQUE NOT NULL,supplier_id INT,order_date DATE,expected_date DATE NULL,status VARCHAR(30) DEFAULT 'Draft',total_amount DECIMAL(10,2) DEFAULT 0,paid_amount DECIMAL(10,2) DEFAULT 0,payment_mode VARCHAR(50),payment_ref VARCHAR(100),notes TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items(id INT AUTO_INCREMENT PRIMARY KEY,po_id INT,device_model VARCHAR(100),quantity INT DEFAULT 1,received_qty INT DEFAULT 0,unit_cost DECIMAL(10,2),total_cost DECIMAL(10,2),notes TEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    $co=$_GET['company']??'BGPT';$s=$pdo->prepare("SELECT p.*,s.name as supplier_name FROM purchase_orders p LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE p.company=? ORDER BY p.order_date DESC");$s->execute([$co]);$orders=$s->fetchAll();
    foreach($orders as &$o){$i=$pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id=?");$i->execute([$o['id']]);$o['items']=$i->fetchAll();}
    echo json_encode(['orders'=>$orders]);break;

case 'save_purchase_order':
    $id=intval($body['id']??0);$items=$body['items']??[];$total=array_sum(array_column($items,'total_cost'));$co=$body['company']??'BGPT';
    if($id){$pdo->prepare("UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_date=?,status=?,total_amount=?,paid_amount=?,payment_mode=?,payment_ref=?,notes=? WHERE id=?")->execute([intval($body['supplier_id']),$body['order_date'],$body['expected_date']?:null,$body['status']??'Draft',$total,floatval($body['paid_amount']??0),$body['payment_mode']??'',$body['payment_ref']??'',$body['notes']??'',$id]);$pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);}
    else{$yr=date('Y');$cnt=$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE YEAR(created_at)=$yr")->fetchColumn();$pn='PO-'.$yr.'-'.str_pad($cnt+1,4,'0',STR_PAD_LEFT);$pdo->prepare("INSERT INTO purchase_orders(company,po_number,supplier_id,order_date,expected_date,status,total_amount,paid_amount,payment_mode,payment_ref,notes,created_by)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$co,$pn,intval($body['supplier_id']),$body['order_date'],$body['expected_date']?:null,$body['status']??'Draft',$total,floatval($body['paid_amount']??0),$body['payment_mode']??'',$body['payment_ref']??'',$body['notes']??'',$cu['name']]);$id=$pdo->lastInsertId();}
    foreach($items as $item){$pdo->prepare("INSERT INTO purchase_order_items(po_id,device_model,quantity,received_qty,unit_cost,total_cost,notes)VALUES(?,?,?,?,?,?,?)")->execute([$id,$item['device_model'],intval($item['quantity']),intval($item['received_qty']??0),floatval($item['unit_cost']),floatval($item['total_cost']),$item['notes']??'']);}
    echo json_encode(['success'=>true,'id'=>$id]);break;

case 'delete_purchase_order':
    $id=intval($body['id']??0);$pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);$pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);echo json_encode(['success'=>true]);break;

case 'get_expenses':
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS expenses(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',date DATE,category VARCHAR(50),description TEXT,amount DECIMAL(10,2),payment_mode VARCHAR(50),paid_to VARCHAR(100),reference VARCHAR(100),receipt_note TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    $co=$_GET['company']??'BGPT';$w=['company=?'];$pa=[$co];
    if(!empty($_GET['from'])){$w[]='date>=?';$pa[]=$_GET['from'];}if(!empty($_GET['to'])){$w[]='date<=?';$pa[]=$_GET['to'];}if(!empty($_GET['category'])){$w[]='category=?';$pa[]=$_GET['category'];}
    $s=$pdo->prepare("SELECT * FROM expenses WHERE ".implode(' AND ',$w)." ORDER BY date DESC");$s->execute($pa);$rows=$s->fetchAll();
    echo json_encode(['expenses'=>$rows,'total'=>array_sum(array_column($rows,'amount'))]);break;

case 'save_expense':
    $id=intval($body['id']??0);
    if($id){$sets=[];$vals=[];foreach(['company','date','category','description','amount','payment_mode','paid_to','reference','receipt_note']as $f){if(isset($body[$f])){$sets[]="$f=?";$vals[]=$body[$f];}}$vals[]=$id;$pdo->prepare("UPDATE expenses SET ".implode(',',$sets)." WHERE id=?")->execute($vals);}
    else{$pdo->prepare("INSERT INTO expenses(company,date,category,description,amount,payment_mode,paid_to,reference,receipt_note,created_by)VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$body['company']??'BGPT',$body['date'],$body['category'],$body['description'],floatval($body['amount']),$body['payment_mode']??'',$body['paid_to']??'',$body['reference']??'',$body['receipt_note']??'',$cu['name']]);$id=$pdo->lastInsertId();}
    echo json_encode(['success'=>true,'id'=>$id]);break;

case 'delete_expense':
    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([intval($body['id']??0)]);echo json_encode(['success'=>true]);break;

case 'get_accounts_summary':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Only admin can view P&L']); break; }
    $co=$_GET['company']??'BGPT';$from=$_GET['from']??date('Y-01-01');$to=$_GET['to']??date('Y-m-d');
    // ── Income (from Balance Sheet) ──
    try{$q1=$pdo->prepare("SELECT COALESCE(SUM(total_price),0)ts,COALESCE(SUM(payment_received),0)tr,COALESCE(SUM(pending_payment),0)tp,COALESCE(SUM(CASE WHEN type='sales' THEN total_price ELSE 0 END),0)si,COALESCE(SUM(CASE WHEN type='license' THEN total_price ELSE 0 END),0)li,COALESCE(SUM(CASE WHEN type='sales' THEN qty ELSE 0 END),0)ds,COUNT(*)tc FROM balance_sheet_entries WHERE profile=? AND date BETWEEN ? AND ?");$q1->execute([$co,$from,$to]);$inc=$q1->fetch();}catch(Exception $e){$inc=['ts'=>0,'tr'=>0,'tp'=>0,'si'=>0,'li'=>0,'ds'=>0,'tc'=>0];}
    // ── Purchases (from Purchase Orders) — cash invested in stock this period ──
    $q2=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0)tp,COALESCE(SUM(paid_amount),0)pp,COUNT(*)pc FROM purchase_orders WHERE company=? AND order_date BETWEEN ? AND ? AND status!='Cancelled'");$q2->execute([$co,$from,$to]);$pur=$q2->fetch();
    // The Purchases page writes to the `purchases` table (not purchase_orders). Include it so the
    // real dealer purchases (qty, unit price, GST) are reflected in purchase totals and inventory.
    try {
        $q2b=$pdo->prepare("SELECT COALESCE(SUM(total_incl),0) tp, COUNT(*) pc, COALESCE(SUM(qty),0) units FROM purchases WHERE purchase_date BETWEEN ? AND ?");
        $q2b->execute([$from,$to]); $purB=$q2b->fetch(PDO::FETCH_ASSOC);
        if ($purB) {
            $pur['tp'] = floatval($pur['tp'] ?? 0) + floatval($purB['tp'] ?? 0);
            $pur['pp'] = floatval($pur['pp'] ?? 0) + floatval($purB['tp'] ?? 0); // dealer purchases are paid on entry
            $pur['pc'] = intval($pur['pc'] ?? 0) + intval($purB['pc'] ?? 0);
            $pur['units'] = intval($purB['units'] ?? 0);
        }
    } catch(Exception $e){}
    // ── Operating expenses (from Expenses page) ──
    try{$q3=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company=? AND date BETWEEN ? AND ?");$q3->execute([$co,$from,$to]);$tex=floatval($q3->fetchColumn());}catch(Exception $e){$tex=0;}
    try{$q4=$pdo->prepare("SELECT category,COALESCE(SUM(amount),0)ct FROM expenses WHERE company=? AND date BETWEEN ? AND ? GROUP BY category ORDER BY ct DESC");$q4->execute([$co,$from,$to]);$cats=$q4->fetchAll();}catch(Exception $e){$cats=[];}
    try{$q5=$pdo->prepare("SELECT DATE_FORMAT(date,'%Y-%m')month,COALESCE(SUM(total_price),0)income,COALESCE(SUM(payment_received),0)received FROM balance_sheet_entries WHERE profile=? AND date BETWEEN ? AND ? GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month");$q5->execute([$co,$from,$to]);$monthly=$q5->fetchAll();}catch(Exception $e){$monthly=[];}

    // ── COGS (Cost of Goods Sold) — professional method ──
    // Only the cost of devices ACTUALLY SOLD counts against profit. Unsold stock stays as inventory.
    // Cost per model comes from the Price List buying_price (source of truth); if a model has no
    // buying_price, we fall back to the average purchase-order unit_cost for that model.
    $cogs = 0.0; $cogsBreakdown = [];
    try {
        // Units sold per model from the balance sheet (sales-type entries only)
        $qs = $pdo->prepare("SELECT device_model, COALESCE(SUM(qty),0) sold FROM balance_sheet_entries
            WHERE profile=? AND type='sales' AND date BETWEEN ? AND ? AND device_model IS NOT NULL AND device_model<>''
            GROUP BY device_model");
        $qs->execute([$co,$from,$to]);
        $soldRows = $qs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($soldRows as $sr) {
            $model = $sr['device_model']; $sold = floatval($sr['sold']); if($sold<=0) continue;
            $cost = null;
            // 1) Price List buying_price
            try { $pc=$pdo->prepare("SELECT buying_price FROM price_list WHERE product_name=? AND buying_price>0 LIMIT 1"); $pc->execute([$model]); $bp=$pc->fetchColumn(); if($bp!==false && floatval($bp)>0) $cost=floatval($bp); } catch(Exception $e){}
            // 2) Fallback: weighted-average unit cost from the ACTUAL purchases table (Purchases page).
            //    purchases.item_id -> stock_items.name. Item names may be like "Engine Status GPS"
            //    while the sold model is "Engine Status", so match loosely both ways.
            if ($cost===null) {
                try {
                    $ap=$pdo->prepare("SELECT SUM(p.total_incl)/NULLIF(SUM(p.qty),0)
                                       FROM purchases p JOIN stock_items s ON p.item_id=s.id
                                       WHERE p.qty>0 AND (s.name=? OR s.name LIKE CONCAT(?, '%') OR ? LIKE CONCAT(s.name, '%'))");
                    $ap->execute([$model,$model,$model]);
                    $av=$ap->fetchColumn();
                    if($av!==false && floatval($av)>0) $cost=floatval($av);
                } catch(Exception $e){}
            }
            // 3) Fallback: legacy purchase-order items (older Purchase Orders feature)
            if ($cost===null) { try { $ac=$pdo->prepare("SELECT AVG(unit_cost) FROM purchase_order_items WHERE device_model=? AND unit_cost>0"); $ac->execute([$model]); $av2=$ac->fetchColumn(); if($av2!==false && floatval($av2)>0) $cost=floatval($av2); } catch(Exception $e){} }
            if ($cost===null) $cost = 0.0; // unknown cost — counts as 0 so profit isn't overstated as negative
            $lineCogs = $cost * $sold;
            $cogs += $lineCogs;
            $cogsBreakdown[] = ['model'=>$model,'units_sold'=>$sold,'unit_cost'=>$cost,'cost'=>$lineCogs];
        }
    } catch(Exception $e){}

    $revenue      = floatval($inc['ts']??0);
    $totalPurch   = floatval($pur['tp']??0);
    $grossProfit  = $revenue - $cogs;              // Revenue − COGS
    $netProfit    = $grossProfit - $tex;           // − Operating expenses
    // Inventory value on hand this period = what was purchased minus what was consumed (COGS).
    // (Simple period view; not a full perpetual inventory ledger.)
    $inventoryValue = max(0, $totalPurch - $cogs);

    echo json_encode([
        'period'   => ['from'=>$from,'to'=>$to],
        'income'   => [
            'total_sales'    => floatval($inc['ts']??0),
            'total_received' => floatval($inc['tr']??0),
            'total_pending'  => floatval($inc['tp']??0),
            'sales_income'   => floatval($inc['si']??0),
            'license_income' => floatval($inc['li']??0),
            'devices_sold'   => floatval($inc['ds']??0),
            'total_entries'  => intval($inc['tc']??0),
        ],
        'purchases'      => ['total_po'=>$totalPurch,'paid'=>floatval($pur['pp']??0),'po_count'=>intval($pur['pc']??0)],
        'cogs'           => $cogs,
        'cogs_breakdown' => $cogsBreakdown,
        'inventory_value'=> $inventoryValue,
        'expenses_by_category' => $cats,
        'total_expenses' => $tex,
        'revenue'        => $revenue,
        'gross_profit'   => $grossProfit,
        'net_profit'     => $netProfit,
        'monthly'        => $monthly,
    ]);
    break;


// ════════════════════════════════════════════════════
// INVOICING API — Items, Parties, Invoices, Settings
// ════════════════════════════════════════════════════

// ── INV ITEMS ────────────────────────────────────────
case 'inv_get_items':
    $stmt = $pdo->prepare("SELECT * FROM inv_items ORDER BY name ASC");
    $stmt->execute();
    echo json_encode(['success'=>true,'items'=>$stmt->fetchAll()]);
    break;

case 'inv_save_item':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id'] ?? ('ITEM-'.time().'-'.rand(100,999));
    $existing = $pdo->prepare("SELECT id FROM inv_items WHERE id=?"); $existing->execute([$id]);
    $data = [
        'id'=>$id, 'name'=>$body['name']??'', 'hsn'=>$body['hsn']??'',
        'code'=>$body['code']??'', 'unit'=>$body['unit']??'PCS',
        'category'=>$body['category']??'', 'description'=>$body['description']??'',
        'mrp'=>floatval($body['mrp']??0), 'sale_price'=>floatval($body['sale_price']??0),
        'purchase_price'=>floatval($body['purchase_price']??0), 'gst_rate'=>floatval($body['gst_rate']??18),
        'opening_stock'=>intval($body['opening_stock']??0), 'low_stock_alert'=>intval($body['low_stock_alert']??5),
        'location'=>$body['location']??'', 'is_service'=>intval($body['is_service']??0),
        'created_by'=>$userId
    ];
    if($existing->fetch()){
        $pdo->prepare("UPDATE inv_items SET name=?,hsn=?,code=?,unit=?,category=?,description=?,mrp=?,sale_price=?,purchase_price=?,gst_rate=?,opening_stock=?,low_stock_alert=?,location=?,is_service=? WHERE id=?")
            ->execute(array_values(array_slice(array_values($data),1,14))+[$id]);
    } else {
        $pdo->prepare("INSERT INTO inv_items (id,name,hsn,code,unit,category,description,mrp,sale_price,purchase_price,gst_rate,opening_stock,low_stock_alert,location,is_service,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$data['name'],$data['hsn'],$data['code'],$data['unit'],$data['category'],$data['description'],$data['mrp'],$data['sale_price'],$data['purchase_price'],$data['gst_rate'],$data['opening_stock'],$data['low_stock_alert'],$data['location'],$data['is_service'],$userId]);
    }
    echo json_encode(['success'=>true,'id'=>$id]);
    break;

case 'inv_delete_item':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id']??'';
    $pdo->prepare("DELETE FROM inv_items WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    break;

// ── INV PARTIES ──────────────────────────────────────
case 'inv_get_parties':
    $stmt = $pdo->prepare("SELECT * FROM inv_parties ORDER BY name ASC");
    $stmt->execute();
    echo json_encode(['success'=>true,'parties'=>$stmt->fetchAll()]);
    break;

case 'inv_save_party':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id'] ?? ('PARTY-'.time().'-'.rand(100,999));
    $existing = $pdo->prepare("SELECT id FROM inv_parties WHERE id=?"); $existing->execute([$id]);
    if($existing->fetch()){
        $pdo->prepare("UPDATE inv_parties SET name=?,phone=?,email=?,gstin=?,gst_type=?,billing_address=?,state=?,opening_balance=?,balance_type=? WHERE id=?")
            ->execute([$body['name']??'',$body['phone']??'',$body['email']??'',$body['gstin']??'',$body['gst_type']??'Unregistered/Consumer',$body['billing_address']??'',$body['state']??'',floatval($body['opening_balance']??0),$body['balance_type']??'receivable',$id]);
    } else {
        $pdo->prepare("INSERT INTO inv_parties (id,name,phone,email,gstin,gst_type,billing_address,state,opening_balance,balance_type,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$body['name']??'',$body['phone']??'',$body['email']??'',$body['gstin']??'',$body['gst_type']??'Unregistered/Consumer',$body['billing_address']??'',$body['state']??'',floatval($body['opening_balance']??0),$body['balance_type']??'receivable',$userId]);
    }
    echo json_encode(['success'=>true,'id'=>$id]);
    break;

case 'inv_delete_party':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $pdo->prepare("DELETE FROM inv_parties WHERE id=?")->execute([$body['id']??'']);
    echo json_encode(['success'=>true]);
    break;

// ── INV INVOICES ─────────────────────────────────────
case 'inv_get_invoices':
    // Ensure new columns exist (safe migration for existing installs)
    $migrations_inv = [
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS gstin VARCHAR(20) DEFAULT '' AFTER po_no",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS gst_type VARCHAR(50) DEFAULT 'Unregistered/Consumer' AFTER gstin",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS billing_address TEXT AFTER state",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS gst_split VARCHAR(20) DEFAULT 'GST' AFTER cash_sale",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS cgst DECIMAL(12,2) DEFAULT 0 AFTER gst_split",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS sgst DECIMAL(12,2) DEFAULT 0 AFTER cgst",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS igst DECIMAL(12,2) DEFAULT 0 AFTER sgst",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS task_id_ref INT DEFAULT NULL AFTER notes",
    ];
    foreach($migrations_inv as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }

    $type = $_GET['type'] ?? 'sale';
    $stmt = $pdo->prepare("SELECT * FROM inv_invoices WHERE inv_type=? AND status!='cancelled' ORDER BY date DESC, created_at DESC");
    $stmt->execute([$type]);
    $rows = $stmt->fetchAll();
    foreach($rows as &$r){ $r['items'] = json_decode($r['items_json']??'[]',true); }
    echo json_encode(['success'=>true,'invoices'=>$rows]);
    break;

case 'inv_save_invoice':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id'] ?? ('INV-'.time().'-'.rand(100,999));
    $existing = $pdo->prepare("SELECT id FROM inv_invoices WHERE id=?"); $existing->execute([$id]);
    $itemsJson = json_encode($body['items']??[]);

    // Safe helper
    $g = function($k,$d='') use($body){ return $body[$k]??$d; };

    $data = [
        'inv_no'          => $g('inv_no'),
        'inv_type'        => $g('inv_type','sale'),
        'date'            => $g('date',date('Y-m-d')),
        'due_date'        => $g('due_date')||null,
        'party_id'        => $g('party_id')||null,
        'customer'        => $g('customer'),
        'billing_name'    => $g('billing_name'),
        'po_no'           => $g('po_no'),
        'gstin'           => $g('gstin'),
        'gst_type'        => $g('gst_type','Unregistered/Consumer'),
        'state'           => $g('state'),
        'billing_address' => $g('billing_address'),
        'pay_mode'        => $g('pay_mode'),
        'cash_sale'       => intval($g('cash_sale',0)),
        'gst_split'       => $g('gst_split','GST'),
        'cgst'            => floatval($g('cgst',0)),
        'sgst'            => floatval($g('sgst',0)),
        'igst'            => floatval($g('igst',0)),
        'items_json'      => $itemsJson,
        'sub_total'       => floatval($g('sub_total',0)),
        'discount_total'  => floatval($g('discount_total',0)),
        'gst_total'       => floatval($g('gst_total',0)),
        'grand_total'     => floatval($g('grand_total',0)),
        'amount_received' => floatval($g('amount_received',0)),
        'terms'           => $g('terms'),
        'notes'           => $g('notes'),
        'task_id_ref'     => $g('task_id_ref')||null,
    ];

    if($existing->fetch()){
        $pdo->prepare("UPDATE inv_invoices SET inv_no=?,inv_type=?,date=?,due_date=?,party_id=?,customer=?,billing_name=?,po_no=?,gstin=?,gst_type=?,state=?,billing_address=?,pay_mode=?,cash_sale=?,gst_split=?,cgst=?,sgst=?,igst=?,items_json=?,sub_total=?,discount_total=?,gst_total=?,grand_total=?,amount_received=?,terms=?,notes=?,task_id_ref=? WHERE id=?")
            ->execute(array_merge(array_values($data),[$id]));
    } else {
        $cols = implode(',',array_keys($data));
        $placeholders = implode(',',array_fill(0,count($data),'?'));
        $pdo->prepare("INSERT INTO inv_invoices (id,$cols,created_by) VALUES (?,{$placeholders},?)")
            ->execute(array_merge([$id],array_values($data),[$userId]));
    }
    echo json_encode(['success'=>true,'id'=>$id]);
    break;

case 'inv_delete_invoice':
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    // Soft-delete only — never hard delete
    $pdo->prepare("UPDATE inv_invoices SET status='cancelled' WHERE id=?")->execute([$body['id']??'']);
    echo json_encode(['success'=>true]);
    break;

case 'inv_get_counter':
    $stmt = $pdo->prepare("SELECT setting_value FROM inv_settings WHERE setting_key='inv_counter'");
    $stmt->execute();
    $row = $stmt->fetch();
    echo json_encode(['success'=>true,'counter'=>intval($row['setting_value']??116)]);
    break;

case 'inv_increment_counter':
    $pdo->exec("INSERT INTO inv_settings (setting_key,setting_value) VALUES ('inv_counter','117') ON DUPLICATE KEY UPDATE setting_value=CAST(setting_value AS UNSIGNED)+1");
    $stmt = $pdo->prepare("SELECT setting_value FROM inv_settings WHERE setting_key='inv_counter'"); $stmt->execute();
    echo json_encode(['success'=>true,'counter'=>intval($stmt->fetch()['setting_value']??117)]);
    break;

case 'inv_save_setting':
    $pdo->prepare("INSERT INTO inv_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
        ->execute([$body['key']??'',$body['value']??'',$body['value']??'']);
    echo json_encode(['success'=>true]);
    break;

case 'inv_get_settings':
    $stmt=$pdo->query("SELECT setting_key,setting_value FROM inv_settings"); $rows=$stmt->fetchAll();
    $out=[];foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value'];
    echo json_encode(['success'=>true,'settings'=>$out]);
    break;


// ============================================================
// SEND CONSENT REQUEST — technician clicks Attend
// ============================================================
// CUSTOMER LOCATION + TRIP TRACKING
// ============================================================
case 'send_location_request':
    $id = intval($body['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    _ensureTripColumns($pdo);
    $ts = $pdo->prepare("SELECT t.*, u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
    $ts->execute([$id]); $tr = $ts->fetch();
    if(!$tr){ echo json_encode(['error'=>'Task not found']); break; }
    // Ensure a column to remember when the token was issued (for a real, truthful expiry)
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN loc_token_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
    // REUSE the existing token if it is still valid and the customer has NOT yet shared location.
    // This keeps every email/WhatsApp link for this task pointing at the SAME working link, so
    // re-sending (or the customer opening an earlier email) never shows "expired".
    $reuse = false;
    if(!empty($tr['loc_token']) && empty($tr['cust_loc_at'])){
        $issuedAt = !empty($tr['loc_token_at']) ? strtotime($tr['loc_token_at']) : 0;
        if($issuedAt && (time() - $issuedAt) < 24*3600){ // still fresh (< 24h)
            $locTok = $tr['loc_token'];
            $reuse = true;
        }
    }
    if(!$reuse){
        $locTok = bin2hex(random_bytes(20));
        // New token: reset the share state and stamp issue time. Do NOT wipe an existing shared location on mere resend.
        $pdo->prepare("UPDATE tasks SET loc_token=?, loc_token_at=NOW(), cust_loc_at=NULL, cust_loc_lat=NULL, cust_loc_lng=NULL WHERE id=?")->execute([$locTok, $id]);
    } else {
        // Reusing: just refresh the issue time so the link stays valid another window
        $pdo->prepare("UPDATE tasks SET loc_token_at=NOW() WHERE id=?")->execute([$id]);
    }
    // email (optional) + return link for WhatsApp
    $link = 'https://salmon-goldfish-110661.hostingersite.com/loc.php?t='.$locTok;
    $sent = false;
    try {
        require_once __DIR__.'/mailer.php';
        if(!empty($tr['email']) && function_exists('sendMail')){
            $b = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto">'
               . '<div style="background:#1f5fd6;color:#fff;padding:16px 20px;border-radius:10px 10px 0 0"><h2 style="margin:0;font-size:17px">📍 Share your location</h2></div>'
               . '<div style="background:#fff;border:1px solid #e5e9f0;border-top:none;padding:18px 20px;border-radius:0 0 10px 10px">'
               . '<p style="font-size:14px;color:#333">Dear '.htmlspecialchars($tr['customer_name']).',</p>'
               . '<p style="font-size:13.5px;color:#555;line-height:1.6">Our technician is on the way for your GPS service. Please tap below to share your exact location so they can reach you quickly.</p>'
               . '<p style="text-align:center;margin:18px 0"><a href="'.$link.'" style="background:#1f5fd6;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px">📍 Share My Location</a></p>'
               . '<p style="font-size:11px;color:#999">This link is unique to your service request. If you have trouble, please contact our technician.</p></div></div>';
            sendMail($tr['email'], $tr['customer_name'], 'BharatGPS — Please share your location', $b);
            $sent = true;
        }
    } catch(Exception $e){}
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
        ->execute([$id, $userId, "📍 Location request sent to customer".($sent?" via email":"")]);
    echo json_encode(['success'=>true, 'link'=>$link, 'email_sent'=>$sent, 'phone'=>$tr['contact_number']]);
    break;

case 'check_customer_location':
    $id = intval($body['id'] ?? $_GET['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    $cs = $pdo->prepare("SELECT cust_loc_lat, cust_loc_lng, cust_loc_at, trip_start_at, trip_reach_at, trip_km, trip_minutes FROM tasks WHERE id=?");
    $cs->execute([$id]); $c = $cs->fetch();
    echo json_encode([
        'has_location' => !empty($c['cust_loc_at']),
        'lat' => $c['cust_loc_lat'], 'lng' => $c['cust_loc_lng'],
        'started' => !empty($c['trip_start_at']),
        'reached' => !empty($c['trip_reach_at']),
        'trip_km' => $c['trip_km'], 'trip_minutes' => $c['trip_minutes'],
    ]);
    break;

case 'save_trip_start':
    $id = intval($body['id'] ?? 0);
    $la = floatval($body['lat'] ?? 0); $lo = floatval($body['lng'] ?? 0);
    if(!$id || !$la){ echo json_encode(['error'=>'Missing data']); break; }
    _ensureTripColumns($pdo);
    $pdo->prepare("UPDATE tasks SET trip_start_lat=?, trip_start_lng=?, trip_start_at=NOW() WHERE id=?")->execute([$la,$lo,$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
        ->execute([$id, $userId, "🧭 Technician started navigation to customer"]);
    echo json_encode(['success'=>true]);
    break;

case 'save_trip_reached':
    $id = intval($body['id'] ?? 0);
    $la = floatval($body['lat'] ?? 0); $lo = floatval($body['lng'] ?? 0);
    $skipped = !empty($body['skipped']);
    if(!$id){ echo json_encode(['error'=>'Missing data']); break; }
    if(!$skipped && !$la){ echo json_encode(['error'=>'Missing data']); break; }
    _ensureTripColumns($pdo);
    if($skipped && !$la){
        // Location skipped (customer would not share) — stamp reached with 0 distance so the flow advances and stays advanced on reload
        $pdo->prepare("UPDATE tasks SET trip_reach_at=NOW(), trip_km=0, trip_minutes=0 WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
            ->execute([$id, $userId, "⏭️ Location skipped — customer did not share location; directions taken over phone"]);
        echo json_encode(['success'=>true, 'skipped'=>true, 'trip_km'=>0, 'trip_minutes'=>0]);
        break;
    }
    $r = $pdo->prepare("SELECT trip_start_lat, trip_start_lng, trip_start_at FROM tasks WHERE id=?");
    $r->execute([$id]); $rr = $r->fetch();
    $km = 0; $mins = 0;
    if($rr && $rr['trip_start_lat']){
        $km = _haversineKm(floatval($rr['trip_start_lat']),floatval($rr['trip_start_lng']),$la,$lo);
        if($rr['trip_start_at']){ $mins = max(0, round((time() - strtotime($rr['trip_start_at']))/60)); }
    }
    $pdo->prepare("UPDATE tasks SET trip_reach_lat=?, trip_reach_lng=?, trip_reach_at=NOW(), trip_km=?, trip_minutes=? WHERE id=?")
        ->execute([$la,$lo,$km,$mins,$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
        ->execute([$id, $userId, "📍 Technician reached customer location · Trip: {$km} km, {$mins} min"]);
    echo json_encode(['success'=>true, 'trip_km'=>$km, 'trip_minutes'=>$mins]);
    break;

// ============================================================
// SKIP CONSENT — existing-customer jobs only (V2V / Troubleshoot)
// Technician takes responsibility; marks consent as given so flow proceeds
// ============================================================
case 'skip_consent':
    $id = intval($body['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    try {
        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_at DATETIME DEFAULT NULL")->execute(); } catch(Exception $e){}
        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_name VARCHAR(200) DEFAULT NULL")->execute(); } catch(Exception $e){}
        $tr = $pdo->prepare("SELECT t.*, u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
        $tr->execute([$id]); $trow = $tr->fetch();
        if(!$trow){ echo json_encode(['error'=>'Task not found']); break; }
        // Skip consent is allowed on any job when the customer genuinely can't complete it
        // (forgot login, no email access, etc.). The technician takes responsibility and it is
        // logged in the task activity for accountability.
        // Only the assigned technician (or admin/assigner) may skip
        if(intval($trow['assigned_to']) !== $userId && !in_array($userRole,['admin','assigner'])){
            echo json_encode(['error'=>'Not authorized']); break;
        }
        $techName = $trow['tech_name'] ?? 'Technician';
        $pdo->prepare("UPDATE tasks SET customer_consent_at=NOW(), customer_consent_name=?, consent_token='USED' WHERE id=?")
            ->execute(['Consent skipped by technician', $id]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
            ->execute([$id, $userId, "⏭️ Consent SKIPPED by {$techName} (existing customer) — technician takes responsibility for proceeding without customer confirmation."]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ============================================================
// SEND CONSENT REQUEST
// Generates consent_token, sends email to customer
// ============================================================
case 'send_consent':
    $id = intval($body['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }

    // Ensure columns exist
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN consent_token VARCHAR(64) DEFAULT NULL")->execute(); } catch(Exception $e){}
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_at DATETIME DEFAULT NULL")->execute(); } catch(Exception $e){}
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_name VARCHAR(200) DEFAULT NULL")->execute(); } catch(Exception $e){}
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_mobile VARCHAR(20) DEFAULT NULL")->execute(); } catch(Exception $e){}

    $taskStmt = $pdo->prepare("SELECT t.*, u.name as tech_name, u.email as tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
    $taskStmt->execute([$id]);
    $taskRow  = $taskStmt->fetch();
    if(!$taskRow){ echo json_encode(['error'=>'Task not found']); break; }

    // Generate fresh consent token
    $cToken = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE tasks SET consent_token=?, customer_consent_at=NULL WHERE id=?")->execute([$cToken, $id]);
    $taskRow['consent_token'] = $cToken;
    $consentLink = 'https://salmon-goldfish-110661.hostingersite.com/consent.php?token=' . urlencode($cToken);

    // Send email to customer
    $sent = false;
    try {
        require_once __DIR__.'/mailer.php';
        if(!empty($taskRow['email'])){
            $sent = sendConsentRequest($taskRow, $taskRow['tech_name'] ?? 'BharatGPS Team');
        }
    } catch(Exception $e){ error_log('Consent email: '.$e->getMessage()); }

    // Log in activity
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
        ->execute([$id, $userId, "📩 Consent request " . ($sent ? "emailed to customer" : (!empty($taskRow['email']) ? "could NOT be emailed (delivery failed)" : "sent (no email on file)"))]);

    echo json_encode(['success'=>true, 'email_sent'=>$sent, 'has_email'=>!empty($taskRow['email']), 'consent_link'=>$consentLink]);
    break;

// ============================================================
// CHECK CONSENT — poll to see if customer has confirmed
// ============================================================
case 'check_consent':
    $id = intval($body['id'] ?? $_GET['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    $cs = $pdo->prepare("SELECT customer_consent_at, customer_consent_name, customer_consent_mobile FROM tasks WHERE id=?");
    $cs->execute([$id]);
    $crow = $cs->fetch();
    if($crow && !empty($crow['customer_consent_at'])){
        echo json_encode([
            'consented'   => true,
            'consented_at'=> $crow['customer_consent_at'],
            'name'        => $crow['customer_consent_name'],
            'mobile'      => $crow['customer_consent_mobile'],
        ]);
    } else {
        // Check if consent was sent but not yet confirmed
        $ts = $pdo->prepare("SELECT consent_token FROM tasks WHERE id=?");
        $ts->execute([$id]);
        $trow = $ts->fetch();
        $sent = !empty($trow['consent_token']) && $trow['consent_token'] !== 'USED';
        echo json_encode(['consented'=>false, 'sent'=>$sent]);
    }
    break;

// ---- MARK TASK VIEWED (clears unseen badge) ----

// ---- ADMIN WIPE ----
case 'admin_wipe':
    if($userRole !== 'admin'){ echo json_encode(['error'=>'Admin only']); break; }
    if(($body['confirm']??'') !== 'DELETE'){ echo json_encode(['error'=>'Confirmation required']); break; }
    $type = $body['type'] ?? '';
    try {
        if($type === 'tasks'){
            // Wipe in order (FK constraints)
            $pdo->exec("DELETE FROM task_device_installs");
            $pdo->exec("DELETE FROM task_activities");
            try { $pdo->exec("DELETE FROM consent_logs"); } catch(Exception $e){}
            try { $pdo->exec("DELETE FROM balance_sheet_entries WHERE task_db_id IS NOT NULL"); } catch(Exception $e){}
            try { $pdo->exec("DELETE FROM blacklist_entries WHERE task_db_id IS NOT NULL"); } catch(Exception $e){}
            $pdo->exec("DELETE FROM tasks");
            // Reset task ID offset
            try { $pdo->exec("DELETE FROM app_settings WHERE key_name='task_id_offset'"); } catch(Exception $e){}
            echo json_encode(['success'=>true,'message'=>'All tasks, activities, device installs and linked BS entries deleted.']);
        } elseif($type === 'reset_ids'){
            // Set task counter to a specific start number
            $startNum = intval($body['start_num'] ?? 1);
            if($startNum < 1) $startNum = 1;
            // Create settings table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (key_name VARCHAR(100) PRIMARY KEY, key_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Count current tasks this year to calculate offset
            $year = date('Y');
            $curCount = intval($pdo->query("SELECT COUNT(*) FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetchColumn());
            // offset = startNum - 1 - curCount (so next task = curCount + offset + 1 = startNum)
            $offset = max(0, $startNum - 1 - $curCount);
            $pdo->prepare("INSERT INTO app_settings (key_name,key_value) VALUES ('task_id_offset',?) ON DUPLICATE KEY UPDATE key_value=VALUES(key_value)")->execute([$offset]);
            $nextId = 'ID-'.$year.'-'.str_pad($startNum, 4, '0', STR_PAD_LEFT);
            echo json_encode(['success'=>true,'message'=>"Task counter set. Next task will be $nextId.", 'next'=>$nextId]);
        } elseif($type === 'balance_sheet'){
            // Wipe ALL balance sheet entries
            try {
                $pdo->exec("DELETE FROM balance_sheet_entries");
                echo json_encode(['success'=>true,'message'=>'All balance sheet entries deleted.']);
            } catch(Exception $e){
                echo json_encode(['error'=>'Could not delete: '.$e->getMessage()]);
            }
        } else {
            echo json_encode(['error'=>'Unknown wipe type']);
        }
    } catch(Exception $e){
        echo json_encode(['error'=>'DB error: '.$e->getMessage()]);
    }
    break;

// ---- ADMIN DB STATS ----
case 'admin_db_stats':
    if($userRole !== 'admin'){ echo json_encode(['error'=>'Admin only']); break; }
    $stats = [
        'tasks'          => $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
        'activities'     => $pdo->query("SELECT COUNT(*) FROM task_activities")->fetchColumn(),
        'device_installs'=> $pdo->query("SELECT COUNT(*) FROM task_device_installs")->fetchColumn(),
        'consents'       => $pdo->query("SELECT COUNT(*) FROM tasks WHERE customer_consent_at IS NOT NULL")->fetchColumn(),
        'users'          => $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
    ];
    echo json_encode(['stats'=>$stats]);
    break;

case 'mark_viewed':
    $id = intval($body['id'] ?? $_GET['id'] ?? 0);
    if($id){
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN admin_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        $pdo->prepare("UPDATE tasks SET admin_viewed_at=NOW() WHERE id=?")->execute([$id]);
    }
    echo json_encode(['success'=>true]);
    break;


// ---- SIGNAL CHECK (real-time polling) ----
case 'get_signal':
    // Returns the timestamp of the most recently updated task
    // Client compares with its last known value to detect changes
    try {
        $sig = $pdo->query("SELECT MAX(updated_at) as sig FROM tasks")->fetchColumn();
        echo json_encode(['signal' => $sig ?: '0']);
    } catch(Exception $e){
        echo json_encode(['signal' => '0']);
    }
    break;

// ---- VERIFY TOKEN ----
case 'delete_task':
    if($userRole !== 'admin'){ echo json_encode(['error'=>'Admin only']); break; }
    $did = intval($body['id'] ?? 0);
    if(!$did){ echo json_encode(['error'=>'Invalid ID']); break; }
    try {
        $pdo->prepare("DELETE FROM task_device_installs WHERE task_id=?")->execute([$did]);
        $pdo->prepare("DELETE FROM task_activities WHERE task_id=?")->execute([$did]);
        $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$did]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){
        echo json_encode(['error'=>$e->getMessage()]);
    }
    break;

case 'verify_token':
    // Validate the auth token and return user info
    $tok = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if(!$tok){ echo json_encode(['valid'=>false,'error'=>'No token']); break; }
    $vs = $pdo->prepare("SELECT id,name,email,role,phone FROM users WHERE auth_token=? AND is_active=1");
    $vs->execute([$tok]);
    $vu = $vs->fetch();
    if($vu){
        $pdo->prepare("UPDATE users SET last_active=NOW() WHERE id=?")->execute([$vu['id']]);
        echo json_encode(['valid'=>true,'user'=>$vu]);
    } else {
        echo json_encode(['valid'=>false,'error'=>'Invalid token']);
    }
    break;

case 'logout':
    $tok = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    // Remove only this session; keep other PCs on the same account logged in.
    if($tok){
        try { $pdo->prepare("DELETE FROM auth_sessions WHERE token=?")->execute([$tok]); } catch(Exception $e){}
        $pdo->prepare("UPDATE users SET auth_token=NULL WHERE auth_token=?")->execute([$tok]);
    }
    echo json_encode(['success'=>true]);
    break;


// ── TECHNICIAN: Submit cash deposit (→ 'submitted', awaiting admin verification) ──
case 'confirm_cash_deposit':
    $id = intval($body['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    $task2 = $pdo->prepare("SELECT * FROM tasks WHERE id=?");
    $task2->execute([$id]); $td = $task2->fetch();
    if(!$td){ echo json_encode(['error'=>'Task not found']); break; }
    if(intval($td['assigned_to']) !== $userId && !in_array($userRole,['admin','assigner'])){
        echo json_encode(['error'=>'Not authorized']); break;
    }
    $depositMethod = trim($body['deposit_method'] ?? '');
    if(!$depositMethod){ echo json_encode(['error'=>'Deposit method required']); break; }
    // Optional payment screenshot (base64 data URL) — saved as a file, path stored on the task.
    $shotPath = null;
    try {
        $shot = $body['deposit_screenshot'] ?? '';
        if (is_string($shot) && strpos($shot,'base64,') !== false) {
            try { $pdo->exec("ALTER TABLE tasks ADD COLUMN cash_deposit_screenshot VARCHAR(255) NULL"); } catch(Exception $e){}
            $parts = explode('base64,', $shot, 2);
            $meta  = $parts[0]; $data = base64_decode($parts[1] ?? '', true);
            if ($data !== false && strlen($data) > 0) {
                $ext = 'jpg';
                if (stripos($meta,'image/png')!==false)  $ext='png';
                elseif (stripos($meta,'image/webp')!==false) $ext='webp';
                elseif (stripos($meta,'image/jpeg')!==false || stripos($meta,'image/jpg')!==false) $ext='jpg';
                $dir = __DIR__.'/../uploads/task_'.$id.'/'; if(!is_dir($dir)) @mkdir($dir,0755,true);
                $fn  = 'cash_deposit_'.time().'.'.$ext;
                if (@file_put_contents($dir.$fn, $data) !== false) {
                    $shotPath = 'uploads/task_'.$id.'/'.$fn;
                }
            }
        }
    } catch(Exception $e){ error_log('deposit screenshot save: '.$e->getMessage()); }
    // Compute how many devices remain not-installed (for office partial handling)
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN pending_devices INT DEFAULT 0"); } catch(Exception $e){}
    $instCnt = 0;
    try {
        $ic = $pdo->prepare("SELECT COUNT(*) FROM task_device_installs WHERE task_id=?");
        $ic->execute([$id]); $instCnt = intval($ic->fetchColumn());
    } catch(Exception $e){}
    $totQty = intval($td['device_qty'] ?? 1); if($totQty < 1) $totQty = 1;
    $pendDev = max(0, $totQty - $instCnt);
    $pdo->prepare("UPDATE tasks SET pending_devices=? WHERE id=?")->execute([$pendDev, $id]);
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN cash_deposit_screenshot VARCHAR(255) NULL"); } catch(Exception $e){}
    $pdo->prepare("UPDATE tasks SET
        cash_deposit_status='submitted',
        task_status='Awaiting Approval',
        cash_deposit_method=?,
        cash_handover_to=?,
        cash_deposit_date=?,
        cash_deposit_ref=?,
        cash_deposit_notes=?,
        cash_deposit_screenshot=COALESCE(?, cash_deposit_screenshot),
        cash_submitted_at=NOW()
        WHERE id=?")
        ->execute([
            $depositMethod,
            trim($body['handover_to'] ?? ''),
            trim($body['deposit_date'] ?? '') ?: null,
            trim($body['deposit_ref'] ?? ''),
            trim($body['remarks'] ?? ''),
            $shotPath,
            $id
        ]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
        ->execute([$id, $userId, "💰 Cash deposit submitted — Method: {$depositMethod}. Awaiting admin verification."]);

    // ── Feature #2: 50 coins if submitted (via cash flow) within 24h of creation ──
    try {
        if (!empty($td['created_at']) && $td['assigned_to']) {
            $hrs=(time()-strtotime($td['created_at']))/3600;
            if ($hrs <= 24) {
                award_task_reward($pdo, intval($td['assigned_to']), 50, 'On-time submission (within 24h)', $id, 'submit24_'.$id, '🎉 Congratulations! +50 coins', 'Task submitted within 24 hours. Great work — keep it up!');
            }
        }
    } catch(Exception $e) { error_log('coin submit24 cash error: '.$e->getMessage()); }

    echo json_encode(['success'=>true,'message'=>'Cash deposit submitted — admin will verify.']);
    break;

// ── OFFICE: Manage partially-complete tasks (keep pending / close / reopen) ──
case 'save_vehicle_status':
    $vid = intval($body['id'] ?? 0);
    if(!$vid){ echo json_encode(['error'=>'Task ID required']); break; }
    foreach(["veh_status_found TINYINT DEFAULT 0","veh_status_online VARCHAR(10) DEFAULT NULL","veh_status_last VARCHAR(60) DEFAULT NULL","veh_status_name VARCHAR(120) DEFAULT NULL","veh_status_server VARCHAR(60) DEFAULT NULL","veh_status_at DATETIME DEFAULT NULL"] as $c){
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN $c"); } catch(Exception $e){}
    }
    $found  = !empty($body['found']) ? 1 : 0;
    $online = trim($body['online'] ?? '');
    $last   = trim($body['last_time'] ?? '');
    $vname  = trim($body['device_name'] ?? '');
    $vsrv   = trim($body['server_name'] ?? '');
    $pdo->prepare("UPDATE tasks SET veh_status_found=?, veh_status_online=?, veh_status_last=?, veh_status_name=?, veh_status_server=?, veh_status_at=NOW() WHERE id=?")
        ->execute([$found, $online, $last, $vname, $vsrv, $vid]);
    echo json_encode(['success'=>true]);
    break;

case 'office_task_action':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $oid = intval($body['id'] ?? 0);
    $oact = trim($body['office_action'] ?? '');
    if(!$oid){ echo json_encode(['error'=>'Task ID required']); break; }
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN pending_devices INT DEFAULT 0"); } catch(Exception $e){}
    $ot = $pdo->prepare("SELECT * FROM tasks WHERE id=?"); $ot->execute([$oid]); $otask = $ot->fetch();
    if(!$otask){ echo json_encode(['error'=>'Task not found']); break; }
    $me = $currentUser['name'] ?? 'Office';
    if($oact === 'keep_pending'){
        $pdo->prepare("UPDATE tasks SET task_status='In Progress' WHERE id=?")->execute([$oid]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
            ->execute([$oid, $userId, "⏳ Kept PENDING by {$me} — remaining device(s) to be installed later."]);
        echo json_encode(['success'=>true,'message'=>'Task kept pending.']);
    } elseif($oact === 'close'){
        $pdo->prepare("UPDATE tasks SET task_status='Closed', closed_at=NOW() WHERE id=?")->execute([$oid]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
            ->execute([$oid, $userId, "🔒 Task CLOSED by {$me}".(intval($otask['pending_devices'])>0 ? " with ".intval($otask['pending_devices'])." device(s) pending (can be reopened)." : ".")]);
        echo json_encode(['success'=>true,'message'=>'Task closed.']);
    } elseif($oact === 'reopen'){
        // send back to the same technician for the remaining device(s)
        $pdo->prepare("UPDATE tasks SET task_status='In Progress', closed_at=NULL WHERE id=?")->execute([$oid]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
            ->execute([$oid, $userId, "🔓 Task REOPENED by {$me} for the remaining device(s) — back with the technician."]);
        echo json_encode(['success'=>true,'message'=>'Task reopened for remaining devices.']);
    } else {
        echo json_encode(['error'=>'Unknown action']);
    }
    break;

// ── Technician coin balances (all technicians) — for inventory cards ──
case 'tech_coins':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _ensureCoinLedger($pdo);
        // Accrue any owed penalties first so balances are current.
        try { sweep_unopened_penalties($pdo); } catch(Exception $e){}
        try {
            $techs = $pdo->query("SELECT id FROM users WHERE role='technician' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($techs as $tId) { try { apply_cash_penalty($pdo, intval($tId)); } catch(Exception $e){} }
        } catch(Exception $e){}
        $rows = $pdo->query("SELECT u.id, u.name, COALESCE(SUM(c.coins),0) AS coins
                    FROM users u LEFT JOIN coin_ledger c ON c.user_id=u.id
                    WHERE u.role='technician'
                    GROUP BY u.id, u.name ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'coins'=>$rows]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── Technician: my cash lock + coin status (for the red banner on app home) ──
case 'my_cash_lock_status':
    try {
        $me2 = $userId;
        // Accrue any owed penalty before reporting.
        try { apply_cash_penalty($pdo, $me2); } catch(Exception $e){}
        $days = cash_oldest_pending_days($pdo, $me2);
        // Pending amount (tasks + manual)
        $amt = 0.0;
        try { $r=$pdo->prepare("SELECT COALESCE(SUM(amount_collected),0) FROM tasks WHERE assigned_to=? AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending' AND COALESCE(amount_collected,0)>0"); $r->execute([$me2]); $amt+=floatval($r->fetchColumn()); } catch(Exception $e){}
        try { $r2=$pdo->prepare("SELECT COALESCE(SUM(pending_payment),0) FROM balance_sheet_entries WHERE technician_id=? AND COALESCE(pending_payment,0)>0 AND (task_db_id IS NULL OR task_db_id=0)"); $r2->execute([$me2]); $amt+=floatval($r2->fetchColumn()); } catch(Exception $e){}
        $locked = ($days > 4 && $amt > 0);
        echo json_encode([
            'success'=>true,
            'locked'=>$locked,
            'pending_days'=>$days,
            'pending_amount'=>$amt,
            'coins'=>coin_balance($pdo,$me2),
            'message'=>$locked ? ('🔒 Tasks locked — please deposit your pending cash. ₹'.number_format($amt).' pending for '.$days.' days. Deposit it and your tasks unlock automatically.'."\n".'🔒 మీ టాస్క్‌లు లాక్ అయ్యాయి — దయచేసి మీ దగ్గర ఉన్న క్యాష్ డిపాజిట్ చేయండి. ₹'.number_format($amt).', '.$days.' రోజులుగా పెండింగ్‌లో ఉంది. డిపాజిట్ చేయగానే మీ టాస్క్‌లు ఆటోమేటిక్‌గా అన్‌లాక్ అవుతాయి.') : ''
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: Cash pending-deposit summary (per technician + per task) ──
case 'cash_pending_summary':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $LOCK_DAYS = 4;
        try { $pdo->exec("ALTER TABLE balance_sheet_entries ADD COLUMN technician_id INT DEFAULT NULL"); } catch(Exception $e){}
        // Per-task pending cash (collected, cash mode, not yet deposited)
        $q = $pdo->query("SELECT t.id, t.task_id, t.customer_name, t.amount_collected, t.cash_pending_at,
                    u.id AS tech_id, u.name AS tech_name, u.phone AS tech_phone,
                    TIMESTAMPDIFF(HOUR, t.cash_pending_at, NOW()) AS age_hours
                 FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id
                 WHERE LOWER(t.payment_mode)='cash'
                   AND t.cash_deposit_status='pending'
                   AND COALESCE(t.amount_collected,0) > 0
                 ORDER BY t.cash_pending_at ASC");
        $tasks = $q->fetchAll(PDO::FETCH_ASSOC);
        $byTech = [];
        foreach ($tasks as &$row) {
            $ageH = intval($row['age_hours']??0);
            $row['age_days'] = intval(floor($ageH/24));
            $row['overdue']  = ($row['age_days'] >= $LOCK_DAYS);
            $row['source']   = 'task';
            $tid = intval($row['tech_id']);
            if (!isset($byTech[$tid])) $byTech[$tid] = ['tech_id'=>$tid,'tech_name'=>$row['tech_name'],'tech_phone'=>$row['tech_phone'],'total'=>0,'count'=>0,'oldest_days'=>0,'overdue'=>false];
            $byTech[$tid]['total'] += floatval($row['amount_collected']);
            $byTech[$tid]['count'] += 1;
            if ($row['age_days'] > $byTech[$tid]['oldest_days']) $byTech[$tid]['oldest_days'] = $row['age_days'];
            if ($row['overdue']) $byTech[$tid]['overdue'] = true;
        }
        unset($row);
        // Manual balance-sheet entries linked to a technician with a pending amount.
        // These are old manually-entered rows. We count ANY pending amount tied to a technician
        // (payment mode is often blank on manual rows), as long as it is not already a task entry.
        try {
            $mq = $pdo->query("SELECT b.id, b.task_id, b.name_on_server AS customer_name,
                        b.pending_payment, b.date, b.payment_mode,
                        u.id AS tech_id, u.name AS tech_name, u.phone AS tech_phone,
                        DATEDIFF(NOW(), b.date) AS age_days
                     FROM balance_sheet_entries b JOIN users u ON b.technician_id=u.id
                     WHERE COALESCE(b.pending_payment,0) > 0
                       AND (b.task_db_id IS NULL OR b.task_db_id=0)
                     ORDER BY b.date ASC");
            $ments = $mq->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ments as $m) {
                $ad = intval($m['age_days']??0);
                $overdue = ($ad >= $LOCK_DAYS);
                $tid = intval($m['tech_id']);
                $tasks[] = ['id'=>$m['id'],'task_id'=>$m['task_id'],'customer_name'=>$m['customer_name'],
                    'amount_collected'=>$m['pending_payment'],'cash_pending_at'=>$m['date'],
                    'tech_id'=>$tid,'tech_name'=>$m['tech_name'],'tech_phone'=>$m['tech_phone'],
                    'age_days'=>$ad,'overdue'=>$overdue,'source'=>'manual'];
                if (!isset($byTech[$tid])) $byTech[$tid] = ['tech_id'=>$tid,'tech_name'=>$m['tech_name'],'tech_phone'=>$m['tech_phone'],'total'=>0,'count'=>0,'oldest_days'=>0,'overdue'=>false];
                $byTech[$tid]['total'] += floatval($m['pending_payment']);
                $byTech[$tid]['count'] += 1;
                if ($ad > $byTech[$tid]['oldest_days']) $byTech[$tid]['oldest_days'] = $ad;
                if ($overdue) $byTech[$tid]['overdue'] = true;
            }
        } catch(Exception $e){}
        // Apply any owed penalty windows and attach each technician's current coin balance.
        $techList = array_values($byTech);
        foreach ($techList as &$tt) {
            try { apply_cash_penalty($pdo, intval($tt['tech_id'])); } catch(Exception $e){}
            $tt['coins'] = coin_balance($pdo, intval($tt['tech_id']));
        }
        unset($tt);
        echo json_encode(['success'=>true,'lock_days'=>$LOCK_DAYS,'tasks'=>$tasks,'by_technician'=>$techList]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: Test the reminder (push + WhatsApp) on any user, WITHOUT touching tasks/balance sheet ──
case 'test_reminder':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $tid = intval($body['tech_id'] ?? 0);
        $tname = trim($body['tech_name'] ?? '');
        if(!$tid && $tname){ $uq=$pdo->prepare("SELECT id FROM users WHERE name=? LIMIT 1"); $uq->execute([$tname]); $tid=intval($uq->fetchColumn()); }
        if(!$tid){ echo json_encode(['error'=>'Provide tech_id or tech_name']); break; }
        $u=$pdo->prepare("SELECT name,phone,fcm_token FROM users WHERE id=?"); $u->execute([$tid]); $usr=$u->fetch(PDO::FETCH_ASSOC);
        if(!$usr){ echo json_encode(['error'=>'User not found']); break; }
        $title='🔔 Test reminder — BharatGPS';
        $msg='This is a TEST cash-deposit reminder from the office. If you received this, notifications are working. No action needed.';
        $pushSent=false; $hasToken=!empty($usr['fcm_token']);
        if(function_exists('fcm_send_to_user')){ try{ $pushSent = (bool) fcm_send_to_user($pdo,$tid,$title,$msg,['type'=>'test']); }catch(Exception $e){} }
        $ph=preg_replace('/\D/','',(string)($usr['phone']??'')); if(strlen($ph)===10)$ph='91'.$ph;
        $wa = $ph ? ('https://wa.me/'.$ph.'?text='.rawurlencode($msg)) : '';
        echo json_encode([
            'success'=>true,
            'user'=>$usr['name'],
            'push_sent'=>$pushSent,
            'has_fcm_token'=>$hasToken,
            'has_phone'=>($ph!==''),
            'whatsapp'=>$wa,
            'note'=> ($hasToken ? 'Push attempted.' : 'No app token saved for this user — they must log into the technician app on their phone for push to work. WhatsApp still works.')
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: Remind technician(s) to deposit pending cash (push + WhatsApp link) ──
case 'remind_cash_deposit':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $taskDbId = intval($body['task_id'] ?? 0);   // remind for one task
        $techId   = intval($body['tech_id'] ?? 0);   // OR remind a technician for all their pending cash
        $sent = []; $waLinks = [];
        if ($taskDbId) {
            $r = $pdo->prepare("SELECT t.id,t.task_id,t.amount_collected,t.cash_pending_at,u.id tech_id,u.name tech_name,u.phone tech_phone
                    FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
            $r->execute([$taskDbId]); $row=$r->fetch(PDO::FETCH_ASSOC);
            if(!$row){ echo json_encode(['error'=>'Task not found']); break; }
            $amt=floatval($row['amount_collected']); $days=0;
            try { if($row['cash_pending_at']) $days=(int)floor((time()-strtotime($row['cash_pending_at']))/86400); } catch(Exception $e){}
            $title='💰 Deposit pending cash';
            $msg='Please deposit ₹'.number_format($amt).' cash you collected for task '.$row['task_id'].' ('.$days.' day'.($days==1?'':'s').' pending).';
            if(function_exists('fcm_send_to_user') && $row['tech_id']){ try{ fcm_send_to_user($pdo,intval($row['tech_id']),$title,$msg,['type'=>'cash_deposit','task_id'=>$row['task_id']]); $sent[]='push'; }catch(Exception $e){} }
            $ph=preg_replace('/\D/','',(string)($row['tech_phone']??'')); if(strlen($ph)===10)$ph='91'.$ph;
            if($ph) $waLinks[]=['tech'=>$row['tech_name'],'url'=>'https://wa.me/'.$ph.'?text='.rawurlencode($msg)];
            try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN cash_reminder_at DATETIME NULL"); } catch(Exception $e){}
            try { $pdo->prepare("UPDATE tasks SET cash_reminder_at=NOW() WHERE id=?")->execute([$taskDbId]); } catch(Exception $e){}
        } elseif ($techId) {
            $r = $pdo->prepare("SELECT COALESCE(SUM(amount_collected),0) amt, COUNT(*) c, MIN(cash_pending_at) oldest
                    FROM tasks WHERE assigned_to=? AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending' AND COALESCE(amount_collected,0)>0");
            $r->execute([$techId]); $agg=$r->fetch(PDO::FETCH_ASSOC);
            $amt=floatval($agg['amt']); $cnt=intval($agg['c']); $oldest=$agg['oldest'];
            // Add manual balance-sheet pending cash for this technician
            try {
                $rm = $pdo->prepare("SELECT COALESCE(SUM(pending_payment),0) amt, COUNT(*) c, MIN(date) oldest
                        FROM balance_sheet_entries WHERE technician_id=?
                          AND COALESCE(pending_payment,0)>0 AND (task_db_id IS NULL OR task_db_id=0)");
                $rm->execute([$techId]); $aggM=$rm->fetch(PDO::FETCH_ASSOC);
                $amt += floatval($aggM['amt']); $cnt += intval($aggM['c']);
                if (!empty($aggM['oldest']) && (empty($oldest) || $aggM['oldest']<$oldest)) $oldest=$aggM['oldest'];
            } catch(Exception $e){}
            $u = $pdo->prepare("SELECT name,phone FROM users WHERE id=?"); $u->execute([$techId]); $usr=$u->fetch(PDO::FETCH_ASSOC);
            $days=0;
            try { if($oldest) $days=(int)floor((time()-strtotime($oldest))/86400); } catch(Exception $e){}
            if($cnt<1){ echo json_encode(['success'=>true,'message'=>'No pending cash for this technician.']); break; }
            $title='💰 Deposit pending cash';
            $msg='Please deposit ₹'.number_format($amt).' cash pending across '.$cnt.' task(s), oldest '.$days.' day'.($days==1?'':'s').'. Deposit today to avoid your tasks being locked.';
            if(function_exists('fcm_send_to_user')){ try{ fcm_send_to_user($pdo,$techId,$title,$msg,['type'=>'cash_deposit']); $sent[]='push'; }catch(Exception $e){} }
            $ph=preg_replace('/\D/','',(string)($usr['phone']??'')); if(strlen($ph)===10)$ph='91'.$ph;
            if($ph) $waLinks[]=['tech'=>$usr['name'],'url'=>'https://wa.me/'.$ph.'?text='.rawurlencode($msg)];
            try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN cash_reminder_at DATETIME NULL"); } catch(Exception $e){}
            try { $pdo->prepare("UPDATE tasks SET cash_reminder_at=NOW() WHERE assigned_to=? AND LOWER(payment_mode)='cash' AND cash_deposit_status='pending'")->execute([$techId]); } catch(Exception $e){}
        } else { echo json_encode(['error'=>'Provide task_id or tech_id']); break; }
        echo json_encode(['success'=>true,'push_sent'=>in_array('push',$sent),'whatsapp'=>$waLinks]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── ADMIN: Verify cash deposit (approve → 'deposited' / reject → back to 'pending') ──
case 'verify_cash_deposit':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id2    = intval($body['id'] ?? 0);
    $vact   = trim($body['action'] ?? 'approve');
    if(!$id2){ echo json_encode(['error'=>'Task ID required']); break; }
    if($vact === 'approve'){
        $pdo->prepare("UPDATE tasks SET cash_deposit_status='deposited' WHERE id=?")
            ->execute([$id2]);
        // Mark the task's balance-sheet entry as payment received
        try {
            $trow = $pdo->prepare("SELECT bs_entry_id, amount_collected, price_to_collect FROM tasks WHERE id=?");
            $trow->execute([$id2]); $tr = $trow->fetch();
            if($tr && !empty($tr['bs_entry_id'])){
                $recv = floatval($tr['amount_collected'] ?? 0);
                // fetch the billed total on the entry so pending is computed against installed amount
                $bse = $pdo->prepare("SELECT total_price FROM balance_sheet_entries WHERE id=?");
                $bse->execute([intval($tr['bs_entry_id'])]); $bsrow = $bse->fetch();
                $billed = $bsrow ? floatval($bsrow['total_price']) : $recv;
                if($recv > $billed) $recv = $billed;
                $pend = max(0, $billed - $recv);
                $status = ($recv >= $billed && $billed > 0) ? 'paid' : ($recv>0 ? 'partially_paid' : 'pending');
                $pdo->prepare("UPDATE balance_sheet_entries SET payment_received=?, pending_payment=?, payment_status=?, payment_received_on=CURDATE(), updated_at=NOW() WHERE id=?")
                    ->execute([$recv, $pend, $status, intval($tr['bs_entry_id'])]);
            }
        } catch(Exception $e) {}
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
            ->execute([$id2, $userId, "✅ Cash deposit verified and confirmed by {$currentUser['name']}."]);
        echo json_encode(['success'=>true,'message'=>'Cash deposit verified.']);
    } else {
        // Rejecting the deposit must also UNLOCK the task for the technician. The task was moved to
        // 'Awaiting Approval' when submitted; if we only reset cash_deposit_status the technician stays
        // stuck on the locked "Awaiting approval" screen and can never re-deposit or correct the amount.
        $pdo->prepare("UPDATE tasks SET cash_deposit_status='pending', task_status='In Progress' WHERE id=?")
            ->execute([$id2]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
            ->execute([$id2, $userId, "❌ Cash deposit rejected by {$currentUser['name']} — sent back to technician to correct and resubmit."]);
        echo json_encode(['success'=>true,'message'=>'Deposit rejected — sent back to technician.']);
    }
    break;

// ── CONVERT DEMO TO INSTALLATION (IN-PLACE — same Task ID) ────────────────
case 'convert_demo_to_installation':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id3 = intval($body['id']??0);
    if(!$id3){ echo json_encode(['error'=>'Task ID required']); break; }
    $src = $pdo->prepare("SELECT * FROM tasks WHERE id=?");
    $src->execute([$id3]); $srcTask = $src->fetch();
    if(!$srcTask){ echo json_encode(['error'=>'Task not found']); break; }
    if($srcTask['task_status'] !== 'Demo Done'){
        echo json_encode(['error'=>'Task is not in Demo Done status — cannot convert']); break;
    }

    $newJobType = trim($body['job_type'] ?? 'Basic/Normal');
    $newQty     = max(1, intval($body['device_qty'] ?? 1));
    $newPrice   = floatval($body['price'] ?? 0);
    $newPayMode = $body['payment_mode'] ?? 'Cash';

    try {
        $pdo->beginTransaction();

        // Update the SAME task in place: demo -> installation job
        $pdo->prepare("UPDATE tasks SET
                task_status = 'Task Pending',
                lead_type   = 'Existing Customer Lead',
                device_details = ?,
                device_qty = ?,
                price_to_collect = ?,
                payment_mode = ?,
                amount_collected = 0,
                payment_status = 'Pending',
                demo_converted_at = NOW(),
                consent_token = NULL,
                customer_consent_at = NULL,
                customer_consent_name = NULL,
                customer_consent_mobile = NULL,
                updated_at = NOW()
            WHERE id=?")
            ->execute([$newJobType, $newQty, $newPrice, $newPayMode, $id3]);

        // Clear any leftover device-install rows from a prior cycle on this task (safety — normally none for a pure demo)
        $pdo->prepare("DELETE FROM task_device_installs WHERE task_id=?")->execute([$id3]);

        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")
            ->execute([$id3, $userId, "✅ Demo converted to installation by {$currentUser['name']}. Job: {$newJobType} x{$newQty}, Price: ₹{$newPrice}. Same task ID continues — consent will be re-sent to customer."]);

        $pdo->commit();
    } catch(Exception $convEx){
        $pdo->rollBack();
        echo json_encode(['error'=>'Conversion failed: '.$convEx->getMessage()]);
        break;
    }

    // Re-send consent on the SAME task so customer confirms the installation visit
    $consentSent = false;
    try {
        $cToken = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE tasks SET consent_token=? WHERE id=?")->execute([$cToken, $id3]);
        $taskStmt2 = $pdo->prepare("SELECT t.*, u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
        $taskStmt2->execute([$id3]);
        $taskRow2 = $taskStmt2->fetch();
        if($taskRow2 && !empty($taskRow2['email'])){
            require_once __DIR__.'/mailer.php';
            sendConsentRequest($taskRow2, $taskRow2['tech_name'] ?? 'BharatGPS Team');
            $consentSent = true;
        }
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
            ->execute([$id3, $userId, "📩 Consent request sent to customer for installation visit" . ($consentSent ? " via email" : " (no email on file)")]);
    } catch(Exception $mailEx){ /* consent email failure must not fail the conversion */ }

    echo json_encode(['success'=>true, 'task_id'=>$srcTask['task_id'], 'consent_sent'=>$consentSent, 'message'=>'Task '.$srcTask['task_id'].' converted to installation. Consent request sent to customer.']);
    break;

// ── MARK DEMO AS LOST ─────────────────────────────────────────────────────
case 'mark_demo_lost':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id4 = intval($body['id']??0);
    $reason = trim($body['reason']??'Not interested');
    if(!$id4){ echo json_encode(['error'=>'Task ID required']); break; }
    $pdo->prepare("UPDATE tasks SET task_status='Cancelled', closed_at=NOW() WHERE id=?")
        ->execute([$id4]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")
        ->execute([$id4, $userId, "❌ Demo marked as lost — Reason: {$reason}"]);
    echo json_encode(['success'=>true,'message'=>'Demo task marked as lost.']);
    break;


// ── PRICE LIST API (admin only) ──────────────────────────────────────

case 'pl_seed_services':
    // Seed the service job types into the Price List (Service category), BGT (no GST) + SBGT (18% GST).
    // Prices are starting defaults — office edits them in the Price List afterwards.
    if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS price_list (id INT AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(200) NOT NULL, category VARCHAR(100) NOT NULL DEFAULT 'GPS Device', server_name VARCHAR(100) DEFAULT NULL, description TEXT DEFAULT NULL, buying_price DECIMAL(10,2) NOT NULL DEFAULT 0, price_excl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18, price_incl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, has_stock TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // name, base(excl) default — corrected real prices
        $svcs = [
            ['Troubleshoot/Offline',       500],
            ['Vehicle to Vehicle Change',  500],
            ['GPS Remove',                 300],
            ['Demonstration',                0],
        ];
        // For each service create a BGT (0% GST) and an SBGT (18% GST) row.
        $chk = $pdo->prepare("SELECT COUNT(*) FROM price_list WHERE product_name=?");
        $ins = $pdo->prepare("INSERT INTO price_list (product_name,category,server_name,description,buying_price,price_excl_gst,gst_percent,price_incl_gst,has_stock,is_active,created_by,sort_order) VALUES (?,?,?,?,0,?,?,?,0,1,?,?)");
        $added = 0; $so = 20;
        foreach($svcs as $s){
            foreach([['(BGT)',0],['(SBGT)',18]] as $variant){
                $nm = $s[0].' '.$variant[0];
                $chk->execute([$nm]);
                if(intval($chk->fetchColumn())>0) continue;
                $excl = $s[1]; $gst = $variant[1];
                $incl = round($excl * (1 + $gst/100), 2);
                $desc = 'Service — '.($gst>0?'with 18% GST (SBGT)':'no GST (BGT)');
                $ins->execute([$nm,'Service','BharatGPS Server',$desc,$excl,$gst,$incl,($cu['name']??'System'),$so++]);
                $added++;
            }
        }
        echo json_encode(['success'=>true,'added'=>$added,'note'=>$added?('Added '.$added.' service plan(s). Edit prices in the Price List.'):'All service plans already exist.']);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'pl_seed_renewals':
    // Add the renewal plans in BGT (non-GST) and SBGT (18% GST) profiles.
    // Real prices from the app. Skips any that already exist by name.
    if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS price_list (id INT AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(200) NOT NULL, category VARCHAR(100) NOT NULL DEFAULT 'GPS Device', server_name VARCHAR(100) DEFAULT NULL, description TEXT DEFAULT NULL, buying_price DECIMAL(10,2) NOT NULL DEFAULT 0, price_excl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18, price_incl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, has_stock TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // name, months, base(excl), gst%  — real prices from BharatGPS app (BGT flat, SBGT +18% GST)
        $plans = [
            ['GPS Renewal 3 Months (BGT)',  3,   350, 0],
            ['GPS Renewal 6 Months (BGT)',  6,   700, 0],
            ['GPS Renewal 1 Year (BGT)',    12,  1200, 0],
            ['GPS Renewal 2 Years (BGT)',   24,  2400, 0],
            ['GPS Renewal 4 Years (BGT)',   48,  4800, 0],
            ['GPS Renewal 3 Months (SBGT)', 3,   350, 18],
            ['GPS Renewal 6 Months (SBGT)', 6,   700, 18],
            ['GPS Renewal 1 Year (SBGT)',   12,  1200, 18],
            ['GPS Renewal 2 Years (SBGT)',  24,  2400, 18],
            ['GPS Renewal 4 Years (SBGT)',  48,  4800, 18],
        ];
        $chk = $pdo->prepare("SELECT COUNT(*) FROM price_list WHERE product_name=?");
        $ins = $pdo->prepare("INSERT INTO price_list (product_name,category,server_name,description,buying_price,price_excl_gst,gst_percent,price_incl_gst,has_stock,is_active,created_by,sort_order) VALUES (?,?,?,?,0,?,?,?,0,1,?,?)");
        $added = 0; $so = 50;
        foreach($plans as $p){
            $chk->execute([$p[0]]);
            if(intval($chk->fetchColumn())>0) continue;
            $excl = $p[2]; $gst = $p[3];
            $incl = round($excl * (1 + $gst/100), 2);
            $prof = strpos($p[0],'SBGT')!==false ? 'SBGT (with GST)' : 'BGT (non-GST)';
            $desc = $p[1].'-month GPS subscription renewal — '.$prof;
            $ins->execute([$p[0],'Renewal','BharatGPS Server',$desc,$excl,$gst,$incl,($cu['name']??'System'),$so++]);
            $added++;
        }
        echo json_encode(['success'=>true,'added'=>$added,'note'=>$added?'Added '.$added.' renewal plan(s). Edit prices in the Price List page.':'All renewal plans already exist.']);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'pl_get':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS price_list (id INT AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(200) NOT NULL, category VARCHAR(100) NOT NULL DEFAULT 'GPS Device', server_name VARCHAR(100) DEFAULT NULL, description TEXT DEFAULT NULL, buying_price DECIMAL(10,2) NOT NULL DEFAULT 0, price_excl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18, price_incl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, has_stock TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE price_list ADD COLUMN IF NOT EXISTS buying_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER description"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE price_list ADD COLUMN IF NOT EXISTS handover_price DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch(Exception $e){}
        // Seed defaults if empty
        $cnt = $pdo->query("SELECT COUNT(*) FROM price_list")->fetchColumn();
        if($cnt == 0){
            $defs = [
                ['Basic/Normal GPS',     'GPS Tracker',  'BharatGPS Server',  'Standard GPS tracker installation',              3000, 18],
                ['Engine Status GPS',    'GPS Tracker',  'BharatGPS Server',  'GPS with engine on/off monitoring',              3500, 18],
                ['Engine Cut GPS',       'GPS Tracker',  'BharatGPS Server',  'GPS with remote engine cut/restore relay',       4500, 18],
                ['Micro GPS',            'GPS Tracker',  'BharatGPS Server',  'Compact micro GPS tracker',                      3200, 18],
                ['Magnet GPS',           'GPS Tracker',  'BharatGPS Server',  'Magnetic portable GPS tracker (no wiring)',      3000, 18],
                ['MIC/SOS GPS',          'GPS Tracker',  'BharatGPS Server',  'GPS with microphone and SOS alert button',       4000, 18],
                ['VLTD',                 'VLTD',        'BharatGPS Server',  'Vehicle Location Tracking Device — AIS 140',    8000, 18],
                ['OBD GPS',              'GPS Tracker',  'BharatGPS Server',  'OBD port plug-in GPS tracker',                  2500, 18],
                ['Annual Renewal',       'Renewal',     'BharatGPS Server',  'Annual subscription renewal per vehicle',        1200, 18],
                ['VLTD Annual Renewal',  'Renewal',     'BharatGPS Server',  'AIS 140 VLTD annual subscription renewal',      2000, 18],
                ['SIM Card',             'Accessory',   '',                  'IoT SIM card for GPS tracker',                    300, 18],
                ['Troubleshoot Visit',   'Service',     '',                  'Technician visit for troubleshoot/repair',        500, 18],
            ];
            $ins = $pdo->prepare("INSERT INTO price_list (product_name,category,server_name,description,buying_price,price_excl_gst,gst_percent,price_incl_gst,has_stock,created_by,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            foreach($defs as $k=>$d){
                $excl = $d[4]; $gst = $d[5];
                $incl = round($excl * (1 + $gst/100), 2);
                $hasStk = ($d[1]==='GPS Tracker'||$d[1]==='VLTD'||$d[1]==='Accessory'||$d[1]==='Wire / Cable'||$d[1]==='SIM Card') ? 1 : 0;
                $ins->execute([$d[0],$d[1],$d[2],$d[3],0,$excl,$gst,$incl,$hasStk,'System',$k]);
            }
        }
        $rows = $pdo->query("SELECT * FROM price_list ORDER BY sort_order, category, product_name")->fetchAll();
        echo json_encode(['items'=>$rows]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage(),'items'=>[]]); }
    break;

case 'pl_save':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS price_list (id INT AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(200) NOT NULL, category VARCHAR(100) NOT NULL DEFAULT 'GPS Device', server_name VARCHAR(100) DEFAULT NULL, description TEXT DEFAULT NULL, buying_price DECIMAL(10,2) NOT NULL DEFAULT 0, price_excl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18, price_incl_gst DECIMAL(10,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, has_stock TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE price_list ADD COLUMN IF NOT EXISTS buying_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER description"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE price_list ADD COLUMN IF NOT EXISTS handover_price DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch(Exception $e){}
    $id   = intval($body['id']??0);
    $name = trim($body['product_name']??'');
    $cat  = trim($body['category']??'GPS Tracker');
    // Normalize legacy category name
    if($cat === 'GPS Device') $cat = 'GPS Tracker';
    if(!$name){ echo json_encode(['error'=>'Product name required']); break; }
    $srv   = trim($body['server_name']??'');
    $desc  = trim($body['description']??'');
    $buying = floatval($body['buying_price']??0);
    $handover = floatval($body['handover_price']??0);
    $excl  = floatval($body['price_excl_gst']??0);
    $gst   = floatval($body['gst_percent']??18);
    $incl  = round($excl * (1 + $gst/100), 2);
    $sort     = intval($body['sort_order']??0);
    $active   = intval($body['is_active']??1);
    $hasStock = intval($body['has_stock']??0);
    try {
        if($id){
            $pdo->prepare("UPDATE price_list SET product_name=?,category=?,server_name=?,description=?,buying_price=?,handover_price=?,price_excl_gst=?,gst_percent=?,price_incl_gst=?,sort_order=?,is_active=?,has_stock=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$name,$cat,$srv,$desc,$buying,$handover,$excl,$gst,$incl,$sort,$active,$hasStock,$id]);
            echo json_encode(['success'=>true,'id'=>$id,'price_incl_gst'=>$incl]);
        } else {
            $pdo->prepare("INSERT INTO price_list (product_name,category,server_name,description,buying_price,handover_price,price_excl_gst,gst_percent,price_incl_gst,sort_order,is_active,has_stock,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$cat,$srv,$desc,$buying,$handover,$excl,$gst,$incl,$sort,$active,$hasStock,$cu['name']]);
            echo json_encode(['success'=>true,'id'=>intval($pdo->lastInsertId()),'price_incl_gst'=>$incl]);
        }
        // ── Auto-sync to stock_items based on category ──────────────
        $productCategories = ['GPS Tracker','VLTD','Accessory','Wire / Cable','SIM Card'];
        $savedId = $id ?: intval($pdo->lastInsertId());
        $savedName = $name;
        if(in_array($cat, $productCategories)){
            // Product/Accessory → upsert into stock_items
            $exist = $pdo->prepare("SELECT id FROM stock_items WHERE name=? LIMIT 1");
            $exist->execute([$savedName]);
            $existRow = $exist->fetch();
            if($existRow){
                $pdo->prepare("UPDATE stock_items SET name=?,category=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$savedName,$cat,$existRow['id']]);
            } else {
                $pdo->prepare("INSERT INTO stock_items (name,category,unit,min_stock,created_by) VALUES (?,?,?,5,?)")
                    ->execute([$savedName,$cat,'Pcs',$cu['name']]);
            }
        } else {
            // Service/Renewal → remove from stock_items if exists (only if no movements)
            $hasMovements = $pdo->prepare("SELECT COUNT(*) FROM stock_movements m JOIN stock_items s ON m.item_id=s.id WHERE s.name=?");
            $hasMovements->execute([$savedName]);
            if($hasMovements->fetchColumn() == 0){
                $pdo->prepare("DELETE FROM stock_items WHERE name=? AND (SELECT COUNT(*) FROM purchases WHERE item_id=stock_items.id)=0")
                    ->execute([$savedName]);
            }
        }
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'pl_delete':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    $id = intval($body['id']??0);
    if(!$id){ echo json_encode(['error'=>'Invalid ID']); break; }
    try {
        // Get the item name before deleting so we can sync to stock_items
        $plRow = $pdo->prepare("SELECT product_name, category FROM price_list WHERE id=?");
        $plRow->execute([$id]); $plItem = $plRow->fetch();
        if(!$plItem){ echo json_encode(['error'=>'Item not found']); break; }

        // Delete from price_list
        $pdo->prepare("DELETE FROM price_list WHERE id=?")->execute([$id]);

        // Also delete from stock_items if no stock movements exist for it
        $stockRow = $pdo->prepare("SELECT id FROM stock_items WHERE name=? LIMIT 1");
        $stockRow->execute([$plItem['product_name']]); $si = $stockRow->fetch();
        if($si){
            $hasMov = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE item_id=?");
            $hasMov->execute([$si['id']]);
            $hasPur = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE item_id=?");
            $hasPur->execute([$si['id']]);
            if($hasMov->fetchColumn() == 0 && $hasPur->fetchColumn() == 0){
                $pdo->prepare("DELETE FROM stock_items WHERE id=?")->execute([$si['id']]);
                echo json_encode(['success'=>true, 'stock_removed'=>true]);
            } else {
                // Has history — keep in stock_items but note it
                echo json_encode(['success'=>true, 'stock_removed'=>false, 'note'=>'Removed from price list. Kept in inventory as it has stock movement or purchase history.']);
            }
        } else {
            echo json_encode(['success'=>true, 'stock_removed'=>false]);
        }
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'pl_get_public':
    // For other roles to READ prices (assigner/technician can view but not edit)
    try {
        try { $pdo->exec("ALTER TABLE price_list ADD COLUMN IF NOT EXISTS handover_price DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch(Exception $e){}
        $rows = $pdo->query("SELECT id,product_name,category,server_name,price_excl_gst,gst_percent,price_incl_gst,has_stock,buying_price,handover_price FROM price_list WHERE is_active=1 ORDER BY sort_order,category,product_name")->fetchAll();
        echo json_encode(['items'=>$rows]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage(),'items'=>[]]); }
    break;

case 'stock_reset_movements':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try {
        $deleted = $pdo->exec("DELETE FROM stock_movements");
        $pdo->prepare("INSERT INTO stock_movements (item_id, move_type, qty, ref_note, move_date, done_by) SELECT id, 'adjustment', 0, 'Reset by admin', CURDATE(), ? FROM stock_items WHERE 1=0")->execute([$cu['name']]);
        echo json_encode(['success'=>true, 'deleted'=>$deleted]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── PURCHASES API (admin only) ───────────────────────────────────────

case 'pur_get':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (id INT AUTO_INCREMENT PRIMARY KEY, purchase_date DATE NOT NULL, dealer_name VARCHAR(150) NOT NULL, dealer_contact VARCHAR(50) DEFAULT NULL, item_id INT NOT NULL, qty INT NOT NULL DEFAULT 1, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0, gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18, unit_price_incl DECIMAL(10,2) NOT NULL DEFAULT 0, total_excl DECIMAL(10,2) NOT NULL DEFAULT 0, total_incl DECIMAL(10,2) NOT NULL DEFAULT 0, invoice_no VARCHAR(100) DEFAULT NULL, notes TEXT DEFAULT NULL, stock_added TINYINT(1) NOT NULL DEFAULT 0, created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $from = $_GET['from'] ?? '';
        $to   = $_GET['to']   ?? '';
        $w=[]; $p=[];
        if($from){ $w[]="p.purchase_date>=?"; $p[]=$from; }
        if($to)  { $w[]="p.purchase_date<=?"; $p[]=$to; }
        $where = $w ? 'WHERE '.implode(' AND ',$w) : '';
        $stmt = $pdo->prepare("SELECT p.*, s.name as item_name, s.unit FROM purchases p LEFT JOIN stock_items s ON p.item_id=s.id $where ORDER BY p.purchase_date DESC, p.created_at DESC");
        $stmt->execute($p);
        $rows = $stmt->fetchAll();
        // Summary stats
        $total_spent = array_sum(array_column($rows,'total_incl'));
        $total_units = array_sum(array_column($rows,'qty'));
        echo json_encode(['purchases'=>$rows,'total_spent'=>$total_spent,'total_units'=>$total_units]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage(),'purchases'=>[]]); }
    break;

case 'pur_save':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (id INT AUTO_INCREMENT PRIMARY KEY, purchase_date DATE NOT NULL, dealer_name VARCHAR(150) NOT NULL, dealer_contact VARCHAR(50) DEFAULT NULL, item_id INT NOT NULL, qty INT NOT NULL DEFAULT 1, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0, gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18, unit_price_incl DECIMAL(10,2) NOT NULL DEFAULT 0, total_excl DECIMAL(10,2) NOT NULL DEFAULT 0, total_incl DECIMAL(10,2) NOT NULL DEFAULT 0, invoice_no VARCHAR(100) DEFAULT NULL, notes TEXT DEFAULT NULL, stock_added TINYINT(1) NOT NULL DEFAULT 0, created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
    $dealer     = trim($body['dealer_name']??'');
    $plItemId   = intval($body['item_id']??0);  // price_list.id
    $qty        = intval($body['qty']??0);
    $date       = trim($body['purchase_date']??date('Y-m-d'));
    if(!$dealer||!$plItemId||$qty<1){ echo json_encode(['error'=>'Dealer, item and qty required']); break; }
    // Find matching stock_items entry by price_list product_name
    $plRow = $pdo->prepare("SELECT product_name FROM price_list WHERE id=?"); $plRow->execute([$plItemId]); $plR = $plRow->fetch();
    if(!$plR){ echo json_encode(['error'=>'Price list item not found']); break; }
    $stockRow = $pdo->prepare("SELECT id FROM stock_items WHERE name=? LIMIT 1"); $stockRow->execute([$plR['product_name']]); $sr = $stockRow->fetch();
    if(!$sr){
        // Auto-create stock_items entry if not exists
        $pdo->prepare("INSERT INTO stock_items (name,category,unit,min_stock,created_by) SELECT product_name,category,'Pcs',5,? FROM price_list WHERE id=?")->execute([$cu['name'],$plItemId]);
        $itemId = intval($pdo->lastInsertId());
    } else {
        $itemId = intval($sr['id']);
    }
    $contact = trim($body['dealer_contact']??'');
    $uprice  = floatval($body['unit_price']??0);
    $gst     = floatval($body['gst_percent']??18);
    $uincl   = round($uprice*(1+$gst/100),2);
    $texcl   = round($uprice*$qty,2);
    $tincl   = round($uincl*$qty,2);
    $inv     = trim($body['invoice_no']??'');
    $notes   = trim($body['notes']??'');
    try {
        $pdo->prepare("INSERT INTO purchases (purchase_date,dealer_name,dealer_contact,item_id,qty,unit_price,gst_percent,unit_price_incl,total_excl,total_incl,invoice_no,notes,stock_added,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)")
            ->execute([$date,$dealer,$contact,$itemId,$qty,$uprice,$gst,$uincl,$texcl,$tincl,$inv?:null,$notes?:null,$cu['name']]);
        $purId = intval($pdo->lastInsertId());
        // Stock NOT added yet — added only when marked as received
        echo json_encode(['success'=>true,'id'=>$purId]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'pur_mark_received':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id          = intval($body['id']??0);
    $recDate     = trim($body['received_date']??date('Y-m-d'));
    $recQty      = intval($body['received_qty']??0);
    $imeis       = $body['imeis'] ?? [];
    if(!$id||$recQty<1){ echo json_encode(['error'=>'ID and received qty required']); break; }
    if(!is_array($imeis) || count($imeis) < 1){ echo json_encode(['error'=>'IMEI list is required']); break; }
    // normalize + dedupe IMEIs (digits only)
    $normImeis = [];
    foreach($imeis as $raw){ $x = preg_replace('/\D/','',(string)$raw); if($x!==''){ $normImeis[$x]=1; } }
    $normImeis = array_keys($normImeis);
    if(count($normImeis) !== $recQty){
        echo json_encode(['error'=>'IMEI count ('.count($normImeis).') must match received qty ('.$recQty.')']); break;
    }
    try {
        $pur = $pdo->prepare("SELECT * FROM purchases WHERE id=?"); $pur->execute([$id]); $p = $pur->fetch();
        if(!$p){ echo json_encode(['error'=>'Purchase not found']); break; }
        if($p['stock_added']){ echo json_encode(['error'=>'Stock already added for this purchase']); break; }
        // Save received IMEIs (master list), tagged with purchase + item
        _devEnsureTables($pdo);
        // add columns to received_devices if missing (purchase link)
        try { $pdo->exec("ALTER TABLE received_devices ADD COLUMN purchase_id INT DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE received_devices ADD COLUMN item_id INT DEFAULT NULL"); } catch(Exception $e){}
        $insImei = $pdo->prepare("INSERT INTO received_devices (imei,purchase_id,item_id,note) VALUES (?,?,?,?)
                                  ON DUPLICATE KEY UPDATE purchase_id=VALUES(purchase_id),item_id=VALUES(item_id)");
        $noteTxt = 'PO#'.$id.' '.$p['dealer_name'];
        foreach($normImeis as $im){ $insImei->execute([$im,$id,$p['item_id'],substr($noteTxt,0,190)]); }
        // Add stock movement
        $ref = 'Purchase received: '.$p['dealer_name'].($p['invoice_no']?' INV#'.$p['invoice_no']:'');
        $pdo->prepare("INSERT INTO stock_movements (item_id,move_type,qty,ref_note,move_date,done_by) VALUES (?,?,?,?,?,?)")
            ->execute([$p['item_id'],'in',$recQty,$ref,$recDate,$cu['name']]);
        // Mark purchase as received
        $pdo->prepare("UPDATE purchases SET stock_added=1, received_date=?, received_qty=?, received_by=? WHERE id=?")
            ->execute([$recDate,$recQty,$cu['name'],$id]);
        echo json_encode(['success'=>true,'imeis_saved'=>count($normImeis)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'pur_delete':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    $id = intval($body['id']??0);
    try {
        $pur = $pdo->prepare("SELECT * FROM purchases WHERE id=?"); $pur->execute([$id]); $p = $pur->fetch();
        if(!$p){ echo json_encode(['error'=>'Not found']); break; }
        // Only remove stock movement if stock was already added (received)
        if($p['stock_added']){
            $pdo->prepare("DELETE FROM stock_movements WHERE item_id=? AND move_type='in' AND qty=? AND move_date=? LIMIT 1")
                ->execute([$p['item_id'],$p['received_qty']??$p['qty'],$p['received_date']??$p['purchase_date']]);
        }
        $pdo->prepare("DELETE FROM purchases WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── SET OPENING BALANCE ──────────────────────────────────────────────
case 'stock_set_opening':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $itemId  = intval($body['item_id']??0);
    $opening = intval($body['opening_bal']??0);
    if(!$itemId){ echo json_encode(['error'=>'Item ID required']); break; }
    try {
        $pdo->prepare("UPDATE stock_items SET opening_bal=? WHERE id=?")->execute([$opening, $itemId]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ── GPS STOCK INVENTORY API (stock_ prefix, no collision with inv_ invoicing) ──

// Any logged-in user can get item list (for task dropdown, etc.)
case 'stock_items_list':
    // Now reads from price_list (master) — returns all active items
    try {
        $rows = $pdo->query("SELECT id, product_name as name, category, server_name as model, 'Pcs' as unit, has_stock, price_excl_gst, price_incl_gst FROM price_list WHERE is_active=1 ORDER BY sort_order, category, product_name")->fetchAll();
        echo json_encode(['items'=>$rows]);
    } catch(Exception $e){ echo json_encode(['items'=>[]]); }
    break;

case 'stock_get':
    if(!in_array($userRole,['admin','assigner','technician'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM stock_items")->fetchColumn();
        if($cnt == 0){
            $def = [
                ['GPS Tracker — Basic',      'GPS Tracker', 'TK303 / Standard', 'Pcs',  5],
                ['GPS Tracker — VLTD',       'GPS Tracker', 'AIS 140 VLTD',     'Pcs',  3],
                ['GPS Tracker — Engine Cut', 'GPS Tracker', 'With Relay',        'Pcs',  3],
                ['GPS Tracker — OBD',        'GPS Tracker', 'OBD Plug-in',       'Pcs',  2],
                ['Main Wire (4-Pin)',         'Wire / Cable','4-Pin Connector',   'Pcs', 10],
                ['Relay Wire',               'Wire / Cable','Engine Cut Relay',  'Pcs',  5],
                ['SIM Card (Data)',          'SIM Card',    'IoT SIM',           'Pcs', 10],
                ['Mounting Tape',            'Accessory',   '3M Double-sided',   'Roll', 5],
            ];
            $ins = $pdo->prepare("INSERT INTO stock_items (name,category,model,unit,min_stock,created_by) VALUES (?,?,?,?,?,?)");
            foreach($def as $d) $ins->execute([$d[0],$d[1],$d[2],$d[3],$d[4],'System']);
        }
        $rows = $pdo->query("SELECT i.*,
            COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=i.id AND move_type='in'),0) as total_in,
            COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=i.id AND move_type='out'),0) as total_out,
            COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=i.id AND move_type='return'),0) as total_return,
            COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=i.id AND move_type='adjustment'),0) as total_adj
            FROM stock_items i ORDER BY i.category, i.name")->fetchAll();
        foreach($rows as &$r){
            $r['office_stock'] = max(0, intval($r['opening_bal']) + intval($r['total_in']) - intval($r['total_out']) + intval($r['total_return']) + intval($r['total_adj']));
            $r['with_techs']   = max(0, intval($r['total_out']) - intval($r['total_return']));
            $r['closing_bal']  = $r['office_stock'];
        } unset($r);
        $tm = $pdo->query("SELECT tech_name,item_id,move_type,SUM(qty) as qty FROM stock_movements WHERE tech_name IS NOT NULL GROUP BY tech_name,item_id,move_type")->fetchAll();
        $ts = [];
        foreach($tm as $row){
            $n=$row['tech_name']; $iid=$row['item_id'];
            if(!isset($ts[$n])) $ts[$n]=[];
            if(!isset($ts[$n][$iid])) $ts[$n][$iid]=0;
            if($row['move_type']==='out')    $ts[$n][$iid]+=intval($row['qty']);
            if($row['move_type']==='return') $ts[$n][$iid]-=intval($row['qty']);
        }
        // ── Fold ASSIGNED SERVER DEVICES into tech stock (device_assignments) ──
        // Map each device's model code → an inventory item, add to that tech's held count.
        try {
            _devEnsureTables($pdo);
            // model code → item keyword groups
            $modelGroups = [
                'engine status' => ['ev02','g-17','g17','g19','gt06','gt-17','bt-50','c-32','x-3','x-03','x-1','m-01'],
                'vltd'          => ['vltd'],
                'magnet'        => ['mt'],
                'micro'         => ['micro'],
                'mic'           => ['mic','sos'],
            ];
            // resolve each keyword group to an item id
            $itemIdFor = [];
            $findItem2 = function($kw) use ($pdo){
                $s = $pdo->prepare("SELECT id FROM stock_items WHERE LOWER(CONCAT(name,' ',COALESCE(model,''))) LIKE ? ORDER BY id LIMIT 1");
                $s->execute(['%'.strtolower($kw).'%']); $v=$s->fetchColumn(); return $v?intval($v):0;
            };
            $itemIdFor['engine status'] = $findItem2('engine status') ?: $findItem2('basic');
            $itemIdFor['vltd']          = $findItem2('vltd');
            $itemIdFor['magnet']        = $findItem2('magnet');
            $itemIdFor['micro']         = $findItem2('micro');
            $itemIdFor['mic']           = $findItem2('mic') ?: $findItem2('sos');

            $asg = $pdo->query("SELECT technician,model FROM device_assignments WHERE status='with_tech'")->fetchAll(PDO::FETCH_ASSOC);
            foreach($asg as $a){
                $tname = $a['technician']; if($tname==='') continue;
                $mdl = strtolower(trim($a['model'] ?? ''));
                // find which group this model belongs to
                $grp = 'engine status'; // default GPS
                foreach($modelGroups as $g => $codes){
                    foreach($codes as $code){ if($mdl !== '' && strpos($mdl, $code) !== false){ $grp = $g; break 2; } }
                }
                $iid = $itemIdFor[$grp] ?? 0;
                if(!$iid) continue;
                if(!isset($ts[$tname])) $ts[$tname]=[];
                if(!isset($ts[$tname][$iid])) $ts[$tname][$iid]=0;
                $ts[$tname][$iid] += 1;
            }
        } catch(Exception $e){}
        // Technicians may only see their own held stock
        if($userRole === 'technician'){
            $myName = $cu['name'] ?? '';
            $ts = isset($ts[$myName]) ? [$myName => $ts[$myName]] : [];
        }
        // Also key tech stock by user id (robust against name variance).
        // Build name -> id lookup from users, then remap.
        $tsById = [];
        try {
            $urows = $pdo->query("SELECT id,name FROM users")->fetchAll();
            $nameToId = [];
            foreach($urows as $u){ $nameToId[trim(strtolower($u['name']))] = intval($u['id']); }
            foreach($ts as $nm => $itemsMap){
                $key = trim(strtolower($nm));
                if(isset($nameToId[$key])){ $tsById[$nameToId[$key]] = $itemsMap; }
            }
        } catch(Exception $e){}
        // ── BUNDLES (BOM): a sellable line = multiple physical components ──
        // e.g. Engine Cut GPS = 1x Engine Status GPS + 1x Relay
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_bundles (id INT AUTO_INCREMENT PRIMARY KEY, match_keyword VARCHAR(100) NOT NULL, label VARCHAR(150) NOT NULL, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_bundle_items (id INT AUTO_INCREMENT PRIMARY KEY, bundle_id INT NOT NULL, component_item_id INT NOT NULL, qty INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Seed Engine Cut bundle once, if no bundles exist yet and the two components can be found
        $bcnt = $pdo->query("SELECT COUNT(*) FROM stock_bundles")->fetchColumn();
        if($bcnt == 0){
            $findItem = function($kw) use ($pdo){
                $s = $pdo->prepare("SELECT id FROM stock_items WHERE LOWER(CONCAT(name,' ',COALESCE(model,''))) LIKE ? ORDER BY id LIMIT 1");
                $s->execute(['%'.strtolower($kw).'%']); $v = $s->fetchColumn(); return $v ? intval($v) : 0;
            };
            $gpsId   = $findItem('engine status'); if(!$gpsId) $gpsId = $findItem('basic');
            $relayId = $findItem('relay');
            if($gpsId && $relayId){
                $pdo->prepare("INSERT INTO stock_bundles (match_keyword,label) VALUES ('engine cut','Engine Cut GPS')")->execute();
                $bid = intval($pdo->lastInsertId());
                $pdo->prepare("INSERT INTO stock_bundle_items (bundle_id,component_item_id,qty) VALUES (?,?,1)")->execute([$bid,$gpsId]);
                $pdo->prepare("INSERT INTO stock_bundle_items (bundle_id,component_item_id,qty) VALUES (?,?,1)")->execute([$bid,$relayId]);
            }
        }
        // Build bundles payload: [{match_keyword,label,components:[{item_id,qty}]}]
        $bundles = [];
        $brows = $pdo->query("SELECT * FROM stock_bundles WHERE is_active=1")->fetchAll();
        foreach($brows as $b){
            $ci = $pdo->prepare("SELECT component_item_id as item_id, qty FROM stock_bundle_items WHERE bundle_id=?");
            $ci->execute([$b['id']]);
            $comps = $ci->fetchAll();
            foreach($comps as &$c){ $c['item_id']=intval($c['item_id']); $c['qty']=intval($c['qty']); } unset($c);
            $bundles[] = ['match_keyword'=>$b['match_keyword'],'label'=>$b['label'],'components'=>$comps];
        }
        echo json_encode(['items'=>$rows,'tech_stock'=>$ts,'tech_stock_by_id'=>$tsById,'bundles'=>$bundles]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage(),'items'=>[],'tech_stock'=>[]]); }
    break;

case 'stock_get_movements':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        $w=[]; $p=[];
        if(!empty($_GET['item_id'])){ $w[]="m.item_id=?";   $p[]=intval($_GET['item_id']); }
        if(!empty($_GET['type'])   ){ $w[]="m.move_type=?"; $p[]=$_GET['type']; }
        if(!empty($_GET['from'])   ){ $w[]="m.move_date>=?";$p[]=$_GET['from']; }
        if(!empty($_GET['to'])     ){ $w[]="m.move_date<=?";$p[]=$_GET['to']; }
        $where = $w ? 'WHERE '.implode(' AND ',$w) : '';
        $s = $pdo->prepare("SELECT m.*,i.name as item_name,i.category,i.unit FROM stock_movements m LEFT JOIN stock_items i ON m.item_id=i.id $where ORDER BY m.created_at DESC LIMIT 500");
        $s->execute($p);
        echo json_encode(['movements'=>$s->fetchAll()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage(),'movements'=>[]]); }
    break;

case 'stock_save_item':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $iid=intval($body['id']??0); $name=trim($body['name']??''); $cat=trim($body['category']??'');
    if(!$name||!$cat){ echo json_encode(['error'=>'Name and category required']); break; }
    $model=trim($body['model']??''); $unit=trim($body['unit']??'Pcs');
    $opening=intval($body['opening_bal']??0); $minStk=intval($body['min_stock']??5); $notes=trim($body['notes']??'');
    try {
        if($iid){
            $pdo->prepare("UPDATE stock_items SET name=?,category=?,model=?,unit=?,opening_bal=?,min_stock=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$name,$cat,$model,$unit,$opening,$minStk,$notes,$iid]);
            echo json_encode(['success'=>true,'id'=>$iid]);
        } else {
            $pdo->prepare("INSERT INTO stock_items (name,category,model,unit,opening_bal,min_stock,notes,created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$name,$cat,$model,$unit,$opening,$minStk,$notes,$cu['name']]);
            echo json_encode(['success'=>true,'id'=>intval($pdo->lastInsertId())]);
        }
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'stock_delete_item':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $iid=intval($body['id']??0);
    try {
        $cnt=$pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE item_id=?"); $cnt->execute([$iid]);
        if($cnt->fetchColumn()>0){ echo json_encode(['error'=>'Cannot delete — item has movement history']); break; }
        $pdo->prepare("DELETE FROM stock_items WHERE id=?")->execute([$iid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'stock_save_movement':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $itemId=intval($body['item_id']??0); $type=trim($body['type']??'');
    $qty=intval($body['qty']??0); $tech=trim($body['tech_name']??'');
    $ref=trim($body['ref_note']??''); $date=trim($body['move_date']??date('Y-m-d'));
    if(!$itemId||!$type||$qty<1){ echo json_encode(['error'=>'Item, type and qty required']); break; }
    if(!in_array($type,['in','out','return','adjustment'])){ echo json_encode(['error'=>'Invalid type']); break; }
    if(($type==='out'||$type==='return')&&!$tech){ echo json_encode(['error'=>'Technician required']); break; }
    try {
        if($type==='out'){
            $r=$pdo->prepare("SELECT opening_bal,
                COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=? AND move_type='in'),0) ti,
                COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=? AND move_type='out'),0) to2,
                COALESCE((SELECT SUM(qty) FROM stock_movements WHERE item_id=? AND move_type='return'),0) tr
                FROM stock_items WHERE id=?");
            $r->execute([$itemId,$itemId,$itemId,$itemId]); $rr=$r->fetch();
            $avail=max(0,intval($rr['opening_bal'])+intval($rr['ti'])-intval($rr['to2'])+intval($rr['tr']));
            if($qty>$avail){ echo json_encode(['error'=>'Only '.$avail.' in office stock']); break; }
        }
        $pdo->prepare("INSERT INTO stock_movements (item_id,move_type,qty,tech_name,ref_note,move_date,done_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$itemId,$type,$qty,$tech?:null,$ref?:null,$date,$cu['name']]);
        echo json_encode(['success'=>true,'id'=>intval($pdo->lastInsertId())]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ══════════════════════════════════════════════════════════════════════
// ── DEVICE SYNC (server pull + received IMEI match) ───────────────────
// server_devices  = latest pull from GPS servers (office / with-tech only)
// received_devices = master list of IMEIs physically received (Excel upload)
// ══════════════════════════════════════════════════════════════════════

case 'dev_save_pull':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // body.devices = [{imei,device_name,status,technician,server,device_id}]
    try {
        _devEnsureTables($pdo);
        $devices = $body['devices'] ?? [];
        if(!is_array($devices)){ echo json_encode(['error'=>'devices must be array']); break; }
        // Replace whole snapshot: clear then insert current pull
        $pdo->exec("DELETE FROM server_devices");
        $ins = $pdo->prepare("INSERT INTO server_devices (imei,device_name,status,technician,server,device_id) VALUES (?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE device_name=VALUES(device_name),status=VALUES(status),technician=VALUES(technician),server=VALUES(server),device_id=VALUES(device_id),synced_at=NOW()");
        $n = 0;
        foreach($devices as $d){
            $imei = _devNorm($d['imei'] ?? '');
            if($imei === '') continue;
            $ins->execute([
                $imei,
                substr($d['device_name'] ?? '', 0, 190),
                substr($d['status'] ?? '', 0, 20),
                substr($d['technician'] ?? '', 0, 120),
                substr($d['server'] ?? '', 0, 60),
                substr((string)($d['device_id'] ?? ''), 0, 40)
            ]);
            $n++;
        }
        echo json_encode(['success'=>true,'saved'=>$n]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_upload_received':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // body.imeis = ["imei1","imei2",...]  (append to master, dedupe)
    try {
        _devEnsureTables($pdo);
        $imeis = $body['imeis'] ?? [];
        if(!is_array($imeis)){ echo json_encode(['error'=>'imeis must be array']); break; }
        $ins = $pdo->prepare("INSERT IGNORE INTO received_devices (imei) VALUES (?)");
        $added = 0; $skipped = 0;
        foreach($imeis as $raw){
            $imei = _devNorm($raw);
            if($imei === ''){ $skipped++; continue; }
            $ins->execute([$imei]);
            if($ins->rowCount() > 0) $added++; else $skipped++;
        }
        $total = $pdo->query("SELECT COUNT(*) FROM received_devices")->fetchColumn();
        echo json_encode(['success'=>true,'added'=>$added,'skipped'=>$skipped,'total_received'=>intval($total)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_match_report':
    if(!in_array($userRole,['admin','assigner','technician'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _devEnsureTables($pdo);
        $received = $pdo->query("SELECT imei FROM received_devices")->fetchAll(PDO::FETCH_COLUMN);
        $server   = $pdo->query("SELECT imei,device_name,status,technician,server FROM server_devices")->fetchAll(PDO::FETCH_ASSOC);
        $serverByImei = [];
        foreach($server as $s){ $serverByImei[$s['imei']] = $s; }
        $matched = []; $missing = [];
        foreach($received as $imei){
            if(isset($serverByImei[$imei])) $matched[] = $serverByImei[$imei];
            else $missing[] = $imei;   // received but NOT on server = not yet uploaded
        }
        // On server but NOT in received list (data-entry / wrong upload)
        $receivedSet = array_flip($received);
        $extra = [];
        foreach($server as $s){ if(!isset($receivedSet[$s['imei']])) $extra[] = $s; }
        // Counts by status among server devices
        $office = 0; $withTech = 0; $byTech = [];
        foreach($server as $s){
            if($s['status']==='office') $office++;
            elseif($s['status']==='tech'){ $withTech++; $t=$s['technician']?:'(unknown)'; $byTech[$t]=($byTech[$t]??0)+1; }
        }
        echo json_encode([
            'success'=>true,
            'received_total'=>count($received),
            'server_total'=>count($server),
            'matched'=>count($matched),
            'missing_count'=>count($missing),
            'missing_imeis'=>$missing,
            'extra_count'=>count($extra),
            'extra'=>$extra,
            'office_stock'=>$office,
            'with_tech'=>$withTech,
            'by_tech'=>$byTech
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_received_delete':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    // TEMPORARY. Passcode required. which='all' clears all, or imeis=[...] to delete specific.
    if(($body['passcode'] ?? '') !== '532842'){ echo json_encode(['error'=>'Wrong passcode']); break; }
    try {
        _devEnsureTables($pdo);
        $imeis = $body['imeis'] ?? [];
        if(($body['which'] ?? '') === 'all'){
            $pdo->exec("DELETE FROM received_devices");
            echo json_encode(['success'=>true,'cleared'=>'all']);
        } else if(is_array($imeis) && count($imeis)){
            $del=$pdo->prepare("DELETE FROM received_devices WHERE imei=?");
            $n=0; foreach($imeis as $raw){ $im=preg_replace('/\D/','',(string)$raw); if($im===''){continue;} $del->execute([$im]); $n+=$del->rowCount(); }
            echo json_encode(['success'=>true,'removed'=>$n]);
        } else { echo json_encode(['error'=>'Provide which=all or imeis[]']); }
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_received_list':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _devEnsureTables($pdo);
        try { $pdo->exec("ALTER TABLE received_devices ADD COLUMN purchase_id INT DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE received_devices ADD COLUMN item_id INT DEFAULT NULL"); } catch(Exception $e){}
        $recv = $pdo->query("SELECT r.imei, r.received_at, r.note, r.purchase_id FROM received_devices r ORDER BY r.received_at DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        // status maps
        $onServer = []; foreach($pdo->query("SELECT imei FROM server_devices")->fetchAll(PDO::FETCH_COLUMN) as $i){ $onServer[$i]=1; }
        $assigned = []; foreach($pdo->query("SELECT imei,technician FROM device_assignments WHERE status='with_tech'")->fetchAll(PDO::FETCH_ASSOC) as $a){ $assigned[$a['imei']]=$a['technician']; }
        foreach($recv as &$r){
            if(isset($assigned[$r['imei']])){ $r['status']='assigned'; $r['technician']=$assigned[$r['imei']]; }
            elseif(isset($onServer[$r['imei']])){ $r['status']='on_server'; }
            else { $r['status']='not_uploaded'; }
        } unset($r);
        echo json_encode(['success'=>true,'received'=>$recv,'total'=>count($recv)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_get':
    if(!in_array($userRole,['admin','assigner','technician'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _devEnsureTables($pdo);
        $server = $pdo->query("SELECT imei,device_name,status,technician,server,device_id FROM server_devices ORDER BY status,technician")->fetchAll(PDO::FETCH_ASSOC);
        $recv = intval($pdo->query("SELECT COUNT(*) FROM received_devices")->fetchColumn());
        echo json_encode(['success'=>true,'server_devices'=>$server,'received_total'=>$recv]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_delete_all':
    if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
    // TEMPORARY test button. Requires passcode. Wipes both device tables.
    if(($body['passcode'] ?? '') !== '532842'){ echo json_encode(['error'=>'Wrong passcode']); break; }
    try {
        _devEnsureTables($pdo);
        $which = $body['which'] ?? 'all';
        if($which === 'server' || $which === 'all') $pdo->exec("DELETE FROM server_devices");
        if($which === 'received' || $which === 'all') $pdo->exec("DELETE FROM received_devices");
        if($which === 'assignments' || $which === 'all') $pdo->exec("DELETE FROM device_assignments");
        echo json_encode(['success'=>true,'cleared'=>$which]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_assign':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // body.devices = [{imei,device_name,server}], body.technician, body.technician_id
    try {
        _devEnsureTables($pdo);
        $devices = $body['devices'] ?? [];
        $tech    = trim($body['technician'] ?? '');
        $techId  = isset($body['technician_id']) ? intval($body['technician_id']) : null;
        if(!is_array($devices) || !count($devices)){ echo json_encode(['error'=>'No devices provided']); break; }
        if($tech === ''){ echo json_encode(['error'=>'Technician required']); break; }
        // Resolve technician_id and canonical name from users table (so tech panel can find it).
        // If techId given, use that user's name. Else match by name (case-insensitive).
        if($techId){
            $u = $pdo->prepare("SELECT id,name FROM users WHERE id=?"); $u->execute([$techId]); $urow=$u->fetch();
            if($urow){ $tech = $urow['name']; }
        } else {
            $u = $pdo->prepare("SELECT id,name FROM users WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1"); $u->execute([$tech]); $urow=$u->fetch();
            if($urow){ $techId = intval($urow['id']); $tech = $urow['name']; }
        }
        $ins = $pdo->prepare("INSERT INTO device_assignments (imei,device_name,model,server,technician,technician_id,status,assigned_by)
                              VALUES (?,?,?,?,?,?, 'with_tech', ?)
                              ON DUPLICATE KEY UPDATE device_name=VALUES(device_name),model=VALUES(model),server=VALUES(server),technician=VALUES(technician),technician_id=VALUES(technician_id),status='with_tech',assigned_by=VALUES(assigned_by),assigned_at=NOW()");
        $n = 0;
        foreach($devices as $d){
            $imei = _devNorm($d['imei'] ?? '');
            if($imei === '') continue;
            $ins->execute([
                $imei,
                substr($d['device_name'] ?? '', 0, 190),
                substr($d['model'] ?? '', 0, 60),
                substr($d['server'] ?? '', 0, 60),
                substr($tech, 0, 120),
                $techId,
                substr($cu['name'] ?? '', 0, 120)
            ]);
            $n++;
        }
        echo json_encode(['success'=>true,'assigned'=>$n,'technician'=>$tech]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_assignments_get':
    if(!in_array($userRole,['admin','assigner','technician'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _devEnsureTables($pdo);
        $tech = trim($params['technician'] ?? ($body['technician'] ?? ''));
        // Technicians only ever see their own — match by id OR name
        if($userRole === 'technician'){
            $s = $pdo->prepare("SELECT imei,device_name,model,server,technician,technician_id,status,assigned_at FROM device_assignments WHERE status='with_tech' AND (technician_id=? OR LOWER(TRIM(technician))=LOWER(TRIM(?))) ORDER BY device_name");
            $s->execute([$userId, ($cu['name'] ?? '')]);
        } else if($tech !== ''){
            $s = $pdo->prepare("SELECT imei,device_name,model,server,technician,technician_id,status,assigned_at FROM device_assignments WHERE status='with_tech' AND LOWER(TRIM(technician))=LOWER(TRIM(?)) ORDER BY device_name");
            $s->execute([$tech]);
        } else {
            $s = $pdo->query("SELECT imei,device_name,model,server,technician,technician_id,status,assigned_at FROM device_assignments WHERE status='with_tech' ORDER BY technician,device_name");
        }
        echo json_encode(['success'=>true,'assignments'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_assigned_imeis':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _devEnsureTables($pdo);
        $rows = $pdo->query("SELECT imei,technician FROM device_assignments WHERE status='with_tech'")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach($rows as $r){ $map[$r['imei']] = $r['technician']; }
        echo json_encode(['success'=>true,'assigned'=>$map]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'dev_unassign':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    try {
        _devEnsureTables($pdo);
        $imeis = $body['imeis'] ?? [];
        if(!is_array($imeis) || !count($imeis)){ echo json_encode(['error'=>'No imeis']); break; }
        $del = $pdo->prepare("DELETE FROM device_assignments WHERE imei=?");
        $n=0; foreach($imeis as $raw){ $imei=_devNorm($raw); if($imei===''){continue;} $del->execute([$imei]); $n+=$del->rowCount(); }
        echo json_encode(['success'=>true,'removed'=>$n]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ═══════════════════════════════════════════════════════════════════
// RE-ADDING — technician requests device re-add; admin/assigner approves
// (approval pushes device to GPS server; done client-side via gps_proxy add_device)
// ═══════════════════════════════════════════════════════════════════
case 'readding_submit':
    try {
        _readdingEnsureTable($pdo);
        $name  = trim($body['name'] ?? '');
        $plate = trim($body['plate_number'] ?? '');
        $vin   = trim($body['vin'] ?? '');
        $reg   = trim($body['registration_number'] ?? '');
        $owner = trim($body['object_owner'] ?? '');
        $imei  = preg_replace('/\D/','', $body['imei'] ?? '');
        $server_id = intval($body['server_id'] ?? 0);
        if(!$name || !$plate || !$vin || !$reg || !$owner || !$imei || !$server_id){
            echo json_encode(['error'=>'All fields are required']); break;
        }
        $ref = 'RA'.date('YmdHis').rand(10,99);
        $pdo->prepare("INSERT INTO readding_requests (ref,status,name,imei,vin,plate_number,registration_number,object_owner,server_id,requested_by,requested_by_name,requested_role)
            VALUES (?,'pending',?,?,?,?,?,?,?,?,?,?)")
            ->execute([$ref,$name,$imei,$vin,$plate,$reg,$owner,$server_id,$userId,$cu['name']??'',$userRole]);
        echo json_encode(['success'=>true,'ref'=>$ref,'id'=>$pdo->lastInsertId()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'readding_list':
    try {
        _readdingEnsureTable($pdo);
        $status = trim($body['status'] ?? $_GET['status'] ?? '');
        $params = [];
        $sql = "SELECT * FROM readding_requests";
        $where = [];
        // Technicians see only their own requests; admin/assigner see all
        if($userRole === 'technician'){ $where[]="requested_by=?"; $params[]=$userId; }
        if($status!==''){ $where[]="status=?"; $params[]=$status; }
        if($where) $sql .= " WHERE ".implode(' AND ',$where);
        $sql .= " ORDER BY created_at DESC LIMIT 300";
        $st = $pdo->prepare($sql); $st->execute($params);
        echo json_encode(['success'=>true,'requests'=>$st->fetchAll()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'readding_count':
    try {
        _readdingEnsureTable($pdo);
        $n = intval($pdo->query("SELECT COUNT(*) FROM readding_requests WHERE status='pending'")->fetchColumn());
        echo json_encode(['success'=>true,'count'=>$n]);
    } catch(Exception $e){ echo json_encode(['success'=>true,'count'=>0]); }
    break;

case 'readding_get':
    // fetch one request (used by approval UI to get device fields to push)
    try {
        _readdingEnsureTable($pdo);
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        $id = intval($body['id'] ?? $_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM readding_requests WHERE id=?"); $st->execute([$id]);
        $r = $st->fetch();
        if(!$r){ echo json_encode(['error'=>'Not found']); break; }
        echo json_encode(['success'=>true,'request'=>$r]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'readding_approve':
    // Admin/assigner marks approved AFTER the device was pushed client-side via gps_proxy.
    // Client sends {id, device_id}. We record it.
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _readdingEnsureTable($pdo);
        $id = intval($body['id'] ?? 0);
        $device_id = trim($body['device_id'] ?? '');
        $st = $pdo->prepare("SELECT * FROM readding_requests WHERE id=?"); $st->execute([$id]);
        $r = $st->fetch();
        if(!$r){ echo json_encode(['error'=>'Request not found']); break; }
        if($r['status']!=='pending'){ echo json_encode(['error'=>'Request already '.$r['status']]); break; }
        $pdo->prepare("UPDATE readding_requests SET status='approved', device_id=?, approved_by=?, approved_at=NOW() WHERE id=?")
            ->execute([$device_id ?: null, $cu['name']??'Admin', $id]);

        // Also drop the device into the requesting technician's stock so it appears in their
        // device list and they can use it for the adding/installation process.
        try {
            _devEnsureTables($pdo);
            $imei = _devNorm($r['imei'] ?? '');
            $techId = intval($r['requested_by'] ?? 0);
            if($imei !== '' && $techId){
                // Resolve canonical technician name from users
                $techName = trim($r['requested_by_name'] ?? '');
                $u = $pdo->prepare("SELECT name FROM users WHERE id=?"); $u->execute([$techId]); $urow=$u->fetch();
                if($urow){ $techName = $urow['name']; }
                $srvName = ['1'=>'bharatgps.com','2'=>'bharatgps.in','3'=>'bharatgps.school','4'=>'bharatgps.org'][strval($r['server_id'])] ?? ('Server '.$r['server_id']);
                $pdo->prepare("INSERT INTO device_assignments (imei,device_name,model,server,technician,technician_id,status,assigned_by)
                    VALUES (?,?,?,?,?,?, 'with_tech', ?)
                    ON DUPLICATE KEY UPDATE device_name=VALUES(device_name),server=VALUES(server),technician=VALUES(technician),technician_id=VALUES(technician_id),status='with_tech',assigned_by=VALUES(assigned_by),assigned_at=NOW()")
                    ->execute([$imei, substr($r['name']??'',0,190), '', substr($srvName,0,60), substr($techName,0,120), $techId, substr(($cu['name']??'Admin').' (re-adding)',0,120)]);
            }
        } catch(Exception $e){ /* assignment is best-effort; approval already recorded */ }

        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'readding_cancel':
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _readdingEnsureTable($pdo);
        $id = intval($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM readding_requests WHERE id=?"); $st->execute([$id]);
        $r = $st->fetch();
        if(!$r){ echo json_encode(['error'=>'Not found']); break; }
        if($r['status']!=='pending'){ echo json_encode(['error'=>'Already '.$r['status']]); break; }
        $pdo->prepare("UPDATE readding_requests SET status='cancelled', cancelled_by=?, cancelled_at=NOW() WHERE id=?")
            ->execute([$cu['name']??'Admin', $id]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// ═══════════════════════════════════════════════════════════════════
// LICENSE / RENEWAL — manager/assigner raise renewal request; admin approves
// (approve extends expiry on GPS server via gps_proxy, creates license balance
//  sheet entry from Price List amount, and emails the customer)
// ═══════════════════════════════════════════════════════════════════
case 'renewal_upload_screenshot':
    // Multipart upload of the payment screenshot BEFORE submitting a renewal request.
    // Returns a stored path that the client passes into renewal_request.
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        if(!isset($_FILES['file'])){ echo json_encode(['error'=>'No file']); break; }
        $dir = __DIR__.'/../uploads/renewals/'; if(!is_dir($dir)) mkdir($dir,0755,true);
        $fn = time().'_'.rand(100,999).'_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$_FILES['file']['name']);
        if(move_uploaded_file($_FILES['file']['tmp_name'], $dir.$fn)){
            echo json_encode(['success'=>true,'path'=>'uploads/renewals/'.$fn]);
        } else {
            echo json_encode(['error'=>'Upload failed']);
        }
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_request':
    // Manager or assigner (not technician) raises a renewal request
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _renewalEnsureTable($pdo);
        $server_id = intval($body['server_id'] ?? 0);
        $device_id = trim($body['device_id'] ?? '');
        $months    = intval($body['months'] ?? 12);
        $curExpiry = trim($body['current_expiry'] ?? '');
        if(!$server_id || !$device_id){ echo json_encode(['error'=>'Missing device info']); break; }
        if(!in_array($months,[3,6,12,24,48])){ echo json_encode(['error'=>'Invalid renewal period']); break; }
        $screenshot = trim($body['payment_screenshot'] ?? '');
        if($screenshot===''){ echo json_encode(['error'=>'Payment screenshot is required before sending for approval']); break; }
        // Compute new expiry from current expiry (or today if missing)
        $base = ($curExpiry && $curExpiry!=='0000-00-00') ? $curExpiry : date('Y-m-d');
        $newExpiry = date('Y-m-d', strtotime('+'.$months.' months', strtotime($base)));
        $label = ($months>=12 && $months%12===0) ? (($months/12).' Year'.(($months/12)>1?'s':'')) : ($months.' Months');
        // Amount from Price List item (client sends the chosen price_item id/name + amount)
        $amount = floatval($body['amount'] ?? 0);
        $gst    = floatval($body['gst'] ?? 0);
        $ref = 'RNW'.date('YmdHis').rand(10,99);
        $pdo->prepare("INSERT INTO renewal_requests
            (ref,status,server_id,server_name,device_id,device_name,imei,plate,owner,current_expiry,months,label,new_expiry,price_item,amount,gst,requested_by,requested_by_name,requested_role,payment_screenshot)
            VALUES (?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$ref,$server_id,trim($body['server_name']??''),$device_id,trim($body['device_name']??''),
                       trim($body['imei']??''),trim($body['plate']??''),trim($body['owner']??''),
                       ($curExpiry && $curExpiry!=='0000-00-00')?$curExpiry:null,$months,$label,$newExpiry,
                       trim($body['price_item']??''),$amount,$gst,$userId,$cu['name']??'',$userRole,$screenshot]);
        echo json_encode(['success'=>true,'ref'=>$ref,'new_expiry'=>$newExpiry,'id'=>$pdo->lastInsertId()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_list':
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _renewalEnsureTable($pdo);
        $status = trim($body['status'] ?? $_GET['status'] ?? '');
        $sql = "SELECT * FROM renewal_requests"; $params=[];
        if($status!==''){ $sql .= " WHERE status=?"; $params[]=$status; }
        $sql .= " ORDER BY requested_at DESC LIMIT 500";
        $st = $pdo->prepare($sql); $st->execute($params);
        echo json_encode(['success'=>true,'requests'=>$st->fetchAll()]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_count':
    try {
        _renewalEnsureTable($pdo);
        $n = intval($pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status='pending'")->fetchColumn());
        echo json_encode(['success'=>true,'count'=>$n]);
    } catch(Exception $e){ echo json_encode(['success'=>true,'count'=>0]); }
    break;

case 'renewal_approve':
    // Admin ONLY. Client has already extended expiry on the server via gps_proxy renew_device
    // and passes back {id, customer_email}. We record approval, create the balance sheet entry,
    // and email the customer.
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can approve renewals']); break; }
        _renewalEnsureTable($pdo);
        $id = intval($body['id'] ?? 0);
        $customerEmail = trim($body['customer_email'] ?? '');
        $st = $pdo->prepare("SELECT * FROM renewal_requests WHERE id=?"); $st->execute([$id]);
        $r = $st->fetch();
        if(!$r){ echo json_encode(['error'=>'Request not found']); break; }
        if($r['status']!=='pending'){ echo json_encode(['error'=>'Request already '.$r['status']]); break; }

        $pdo->prepare("UPDATE renewal_requests SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
            ->execute([$cu['name']??'Admin', $id]);

        // Create a LICENSE-type balance sheet entry
        $bsId = null;
        $amount = floatval($r['amount'] ?? 0);
        $gstAmt = floatval($r['gst'] ?? 0);
        // Safety net 1: recover price from Price List by exact plan name.
        if($amount <= 0 && !empty($r['price_item'])){
            try {
                $pl = $pdo->prepare("SELECT price_incl_gst, price_excl_gst FROM price_list WHERE product_name=? LIMIT 1");
                $pl->execute([$r['price_item']]); $plr = $pl->fetch();
                if($plr){
                    $amount = floatval($plr['price_incl_gst']);
                    $gstAmt = max(0, floatval($plr['price_incl_gst']) - floatval($plr['price_excl_gst']));
                }
            } catch(Exception $e){}
        }
        // Safety net 2: fuzzy match by period + profile keyword if exact name failed.
        if($amount <= 0){
            try {
                $mo = intval($r['months']);
                $periodKey = ($mo>=12 && $mo%12===0) ? (($mo/12).' Year') : ($mo.' Month');
                $profKey = (strpos(strtoupper($r['price_item']??''),'SBGT')!==false) ? 'SBGT' : 'BGT';
                $pl2 = $pdo->prepare("SELECT price_incl_gst, price_excl_gst FROM price_list WHERE category='Renewal' AND product_name LIKE ? AND product_name LIKE ? LIMIT 1");
                $pl2->execute(['%'.$periodKey.'%','%'.$profKey.'%']);
                $plr2 = $pl2->fetch();
                if($plr2){
                    $amount = floatval($plr2['price_incl_gst']);
                    $gstAmt = max(0, floatval($plr2['price_incl_gst']) - floatval($plr2['price_excl_gst']));
                }
            } catch(Exception $e){}
        }
        // Determine profile from the plan (SBGT -> SBGT company, BGT -> BGPT).
        $planName = strtoupper($r['price_item'] ?? '');
        if(strpos($planName,'SBGT') !== false)      $rnwProfile = 'SBGT';
        elseif(strpos($planName,'BGT') !== false)    $rnwProfile = 'BGPT';
        else                                         $rnwProfile = ($gstAmt > 0) ? 'SBGT' : 'BGPT';
        // ALWAYS create the entry (even if amount is still 0) so it never silently disappears.
        // If price is missing, flag it in the remark so it can be corrected in the balance sheet.
        {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch(Exception $e){}
            $gst = $gstAmt;
            $noteExtra = ($amount<=0) ? ' [PRICE MISSING — set the renewal plan price in Price List, then edit this entry]' : '';
            // Clean server label: "Server 1/2/3/4" instead of the raw URL/name.
            $srvNum = intval($r['server_id']);
            $srvLabel = $srvNum ? ('Server '.$srvNum) : ($r['server_name'] ?: '');
            // If a payment screenshot was attached, the payment was made by UPI.
            $payMode = !empty($r['payment_screenshot']) ? 'UPI' : null;
            // Name on server = the EXACT device (object) name from the server. Not owner, not plate.
            $rnwNameOnServer = trim($r['device_name'] ?? '');
            if($rnwNameOnServer === '') $rnwNameOnServer = trim($r['plate'] ?? '') ?: 'Renewal';
            $pdo->prepare("INSERT INTO balance_sheet_entries
                (type,profile,date,gps_serial_no,name_on_server,server_name,device_model,service_type,license_plan,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,payment_transaction_details,payment_received_on,remarks,created_by_code)
                VALUES ('license',?,CURDATE(),?,?,?,?,?,?,1,?,?,?,'paid',?,0,?,?,CURDATE(),?,?)")
                ->execute([
                    $rnwProfile,
                    $r['imei'] ?: null, $rnwNameOnServer, $srvLabel,
                    'Renewal', 'Renewal', $r['label'],
                    $amount - $gst, $gst, $amount, $amount,
                    $payMode,
                    $r['payment_screenshot'] ?: null,
                    'Renewal '.$r['label'].' — '.$r['device_name'].' ('.$r['plate'].') new expiry '.$r['new_expiry'].$noteExtra,
                    $cu['name'] ?? 'admin'
                ]);
            $bsId = $pdo->lastInsertId();
            if($bsId){ $pdo->prepare("UPDATE renewal_requests SET bs_entry_id=? WHERE id=?")->execute([$bsId,$id]); }
        }

        // Approval is fully recorded. Return immediately — NO email here (it is sent by a separate
        // background call from the browser after this responds, so SMTP can never block approval).
        echo json_encode(['success'=>true,'bs_entry'=>$bsId?true:false,'customer_email'=>$customerEmail?:null,
                          'device_name'=>$r['device_name'],'new_expiry'=>$r['new_expiry'],'plate'=>$r['plate'],'owner'=>$r['owner']]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

// Send the renewal confirmation email (called separately by the browser, so SMTP never blocks approval)
case 'renewal_send_email':
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
        $to = trim($body['customer_email'] ?? '');
        if(!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)){ echo json_encode(['success'=>false,'error'=>'no email']); break; }
        require_once __DIR__.'/mailer.php';
        $subject = 'GPS Tracker Renewed Successfully — BharatGPS';
        $html = '<p>Hi,</p><p>Greetings from BharatGPS Tracker. Thank you for your payment — we truly appreciate your trust.</p>'
              . '<p>We are happy to inform you that your GPS tracker subscription has been <b>successfully renewed</b>.</p>'
              . '<table cellpadding="6" style="border-collapse:collapse">'
              . '<tr><td><b>Next Expiry Date</b></td><td>'.htmlspecialchars($body['new_expiry'] ?? '').'</td></tr>'
              . '<tr><td><b>Device</b></td><td>'.htmlspecialchars($body['device_name'] ?? '').'</td></tr>'
              . ((!empty($body['plate']) && $body['plate']!=='—')?('<tr><td><b>Vehicle</b></td><td>'.htmlspecialchars($body['plate']).'</td></tr>'):'')
              . '</table>'
              . '<p>Your device is now active and will continue tracking without interruption.</p>'
              . '<p>For any assistance: support@bharatgps.com · +91 93818 74178</p>'
              . '<p>Warm regards,<br>Team BharatGPS</p>';
        $ok = @sendMail($to, $body['owner'] ?? 'Customer', $subject, $html);
        echo json_encode(['success'=>$ok?true:false]);
    } catch(Throwable $e){ echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    break;

case 'daily_report_data':
    // Admin/manager: structured data for the end-of-day WhatsApp report.
    // Installations & services CLOSED today, grouped by technician and job type, plus today's re-addings.
    try {
        if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        $date = $_GET['date'] ?? date('Y-m-d');
        // ── Installs DONE today (by install date saved_at), not by close date. This matches what the
        //    manager actually reports — devices physically installed today, regardless of admin close time.
        $rows = $pdo->prepare("SELECT DISTINCT t.id, t.task_id, t.device_details, t.customer_name, t.vehicle_number,
                    u.name AS tech_name
                FROM task_device_installs di
                JOIN tasks t ON di.task_id=t.id
                LEFT JOIN users u ON t.assigned_to=u.id
                WHERE DATE(di.saved_at)=? AND di.gps_serial_no IS NOT NULL AND di.gps_serial_no<>''
                ORDER BY u.name, t.id");
        $rows->execute([$date]);
        $tasks = $rows->fetchAll(PDO::FETCH_ASSOC);

        // For each task, pull its installed device name(s) + server (only today's installs)
        $out = [];
        foreach($tasks as $t){
            $names = []; $servers = [];
            try {
                $di = $pdo->prepare("SELECT name_on_server, server_name FROM task_device_installs WHERE task_id=? AND DATE(saved_at)=? AND gps_serial_no IS NOT NULL AND gps_serial_no<>'' ORDER BY device_index ASC");
                $di->execute([$t['id'], $date]);
                foreach($di->fetchAll(PDO::FETCH_ASSOC) as $d){
                    if(trim($d['name_on_server']??'')!=='') $names[] = trim($d['name_on_server']);
                    if(trim($d['server_name']??'')!=='') $servers[] = trim($d['server_name']);
                }
            } catch(Exception $e){}
            $nameOnServer = $names ? implode(', ', $names) : ($t['vehicle_number'] ?: $t['customer_name'] ?: '—');
            $server = $servers ? $servers[0] : '';
            // Classify job type from device_details
            $jd = strtolower(trim($t['device_details'] ?? ''));
            if(strpos($jd,'self')!==false)                                             $cat='Self Installation';
            elseif(strpos($jd,'troubleshoot')!==false || strpos($jd,'offline')!==false) $cat='Troubleshoot';
            elseif(strpos($jd,'vehicle change')!==false || strpos($jd,'v2v')!==false || strpos($jd,'vehicle to vehicle')!==false) $cat='Vehicle Change';
            elseif(strpos($jd,'re-add')!==false || strpos($jd,'readd')!==false || strpos($jd,'re add')!==false) $cat='Re-adding';
            elseif(strpos($jd,'remove')!==false)   $cat='Removal';
            elseif(strpos($jd,'demo')!==false)     $cat='Demo';
            else                                    $cat='Sales';
            $service = trim($t['device_details'] ?? '') ?: 'Installation';
            $out[] = [
                'tech'    => $t['tech_name'] ?: 'Unassigned',
                'category'=> $cat,
                'name'    => $nameOnServer,
                'server'  => $server,
                'service' => $service,
            ];
        }

        // Today's approved re-addings (separate list)
        $readds = [];
        try {
            _readdingEnsureTable($pdo);
            $rr = $pdo->prepare("SELECT r.name, u.name AS tech_name FROM readding_requests r LEFT JOIN users u ON r.requested_by=u.id WHERE r.status='approved' AND DATE(r.approved_at)=? ORDER BY u.name");
            $rr->execute([$date]);
            $readds = $rr->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e){}

        // Licenses done today — ONLY actual renewals (service_type='Renewal'), not re-adding/other jobs
        $licenses = [];
        try {
            $lc = $pdo->prepare("SELECT name_on_server, server_name, license_plan FROM balance_sheet_entries WHERE type='license' AND service_type='Renewal' AND date=? ORDER BY id");
            $lc->execute([$date]);
            foreach($lc->fetchAll(PDO::FETCH_ASSOC) as $l){
                $licenses[] = [
                    'name'   => trim($l['name_on_server'] ?? '') ?: '—',
                    'server' => trim($l['server_name'] ?? ''),
                    'plan'   => trim($l['license_plan'] ?? ''),
                ];
            }
        } catch(Exception $e){}

        // Tasks created today (for the Assignment report) — count + list of names/IDs
        $createdList = []; $createdCount = 0;
        try {
            $ct = $pdo->prepare("SELECT task_id, customer_name, device_details FROM tasks WHERE DATE(created_at)=? ORDER BY id");
            $ct->execute([$date]);
            $cr = $ct->fetchAll(PDO::FETCH_ASSOC);
            $createdCount = count($cr);
            foreach($cr as $c){
                $createdList[] = [
                    'task_id' => $c['task_id'] ?? '',
                    'name'    => trim($c['customer_name'] ?? '') ?: '—',
                    'service' => trim($c['device_details'] ?? ''),
                ];
            }
        } catch(Exception $e){}

        // Attendance: technicians who did NO completed work today (no installs) → Half Day.
        // "Work" = an install saved today (installation, troubleshoot, v2v, removal all create install/activity).
        $halfDay = [];
        try {
            $techsAll = $pdo->query("SELECT id,name FROM users WHERE role='technician' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            // Set of technician names who have at least one install saved today
            $workedIds = [];
            $ws = $pdo->prepare("SELECT DISTINCT t.assigned_to FROM task_device_installs di JOIN tasks t ON di.task_id=t.id WHERE DATE(di.saved_at)=? AND di.gps_serial_no IS NOT NULL AND di.gps_serial_no<>''");
            $ws->execute([$date]);
            foreach($ws->fetchAll(PDO::FETCH_COLUMN) as $wid){ $workedIds[intval($wid)] = true; }
            // Also count a troubleshoot/removal report activity today as "work"
            $as = $pdo->prepare("SELECT DISTINCT t.assigned_to FROM task_activities a JOIN tasks t ON a.task_id=t.id WHERE DATE(a.created_at)=? AND (a.remark LIKE '%Troubleshoot Report%' OR a.remark LIKE '%Removal Report%' OR a.remark LIKE '%Transfer%')");
            $as->execute([$date]);
            foreach($as->fetchAll(PDO::FETCH_COLUMN) as $wid){ $workedIds[intval($wid)] = true; }
            foreach($techsAll as $tc){
                if(empty($workedIds[intval($tc['id'])])){ $halfDay[] = $tc['name']; }
            }
        } catch(Exception $e){}

        echo json_encode(['success'=>true,'date'=>$date,'items'=>$out,'readds'=>$readds,'licenses'=>$licenses,'created_count'=>$createdCount,'created_list'=>$createdList,'half_day'=>$halfDay]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_backfill_names':
    // Admin: rewrite name_on_server of already-approved renewal balance-sheet entries to
    // "Device name - Vehicle number" (server identification) instead of the customer name.
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin']); break; }
        _renewalEnsureTable($pdo);
        $rows = $pdo->query("SELECT id, device_name, plate, owner, bs_entry_id FROM renewal_requests WHERE status='approved' AND bs_entry_id IS NOT NULL AND bs_entry_id>0")->fetchAll();
        $updated = 0; $skipped = 0; $sample = [];
        foreach($rows as $rr){
            $dev = trim($rr['device_name'] ?? '');
            $plate = trim($rr['plate'] ?? '');
            // Name on server = EXACT device (object) name from the server. Fallback to plate only if empty.
            if($dev !== ''){ $newName = $dev; }
            elseif($plate !== '' && $plate !== '—'){ $newName = $plate; }
            else { $skipped++; continue; }
            // Read the current bs name for the diagnostic sample
            $curName = '';
            try { $cs=$pdo->prepare("SELECT name_on_server FROM balance_sheet_entries WHERE id=?"); $cs->execute([intval($rr['bs_entry_id'])]); $curName=(string)$cs->fetchColumn(); } catch(Exception $e){}
            $up = $pdo->prepare("UPDATE balance_sheet_entries SET name_on_server=?, updated_at=NOW() WHERE id=? AND type='license'");
            $up->execute([$newName, intval($rr['bs_entry_id'])]);
            if($up->rowCount() > 0) $updated++;
            if(count($sample) < 8){ $sample[] = ['bs_id'=>intval($rr['bs_entry_id']),'was'=>$curName,'now'=>$newName,'owner'=>trim($rr['owner']??'')]; }
        }
        echo json_encode(['success'=>true,'updated'=>$updated,'skipped'=>$skipped,'checked'=>count($rows),'sample'=>$sample]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_bs_check':
    // Admin: report each approved renewal and whether its balance-sheet entry exists + where.
    try {
        if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _renewalEnsureTable($pdo);
        $rows = $pdo->query("SELECT id,ref,device_name,plate,price_item,amount,gst,status,bs_entry_id,approved_at FROM renewal_requests WHERE status='approved' ORDER BY id DESC LIMIT 50")->fetchAll();
        $out = [];
        foreach($rows as $r){
            $bs = null;
            if(!empty($r['bs_entry_id'])){
                $b = $pdo->prepare("SELECT id,type,profile,total_price,date,service_type,payment_transaction_details FROM balance_sheet_entries WHERE id=?");
                $b->execute([$r['bs_entry_id']]); $bs = $b->fetch();
            }
            // Also try to find any license entry that matches this renewal by remark, in case bs_entry_id wasn't linked
            $orphan = null;
            if(!$bs){
                $o = $pdo->prepare("SELECT id,type,profile,total_price,date FROM balance_sheet_entries WHERE type='license' AND remarks LIKE ? ORDER BY id DESC LIMIT 1");
                $o->execute(['%'.$r['device_name'].'%'.$r['plate'].'%']);
                $orphan = $o->fetch();
            }
            $out[] = [
                'renewal_id'=>$r['id'], 'ref'=>$r['ref'],
                'device'=>$r['device_name'].' ('.$r['plate'].')',
                'plan'=>$r['price_item'], 'amount'=>$r['amount'],
                'bs_entry_id'=>$r['bs_entry_id'],
                'bs_entry_found'=>$bs?true:false,
                'bs_profile'=>$bs?$bs['profile']:null,
                'bs_type'=>$bs?$bs['type']:null,
                'bs_total'=>$bs?$bs['total_price']:null,
                'bs_date'=>$bs?$bs['date']:null,
                'orphan_match'=>$orphan?('id '.$orphan['id'].' profile '.$orphan['profile']):null,
            ];
        }
        echo json_encode(['success'=>true,'approved_renewals'=>count($rows),'details'=>$out]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_bs_repair':
    // Admin: fix balance-sheet entries for already-approved renewals — correct the profile
    // (SBGT plan -> SBGT company) and create any missing entries.
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
        _renewalEnsureTable($pdo);
        $rows = $pdo->query("SELECT * FROM renewal_requests WHERE status='approved'")->fetchAll();
        $fixed = 0; $created = 0;
        foreach($rows as $r){
            $amount = floatval($r['amount'] ?? 0);
            $gstAmt = floatval($r['gst'] ?? 0);
            if($amount <= 0 && !empty($r['price_item'])){
                $pl = $pdo->prepare("SELECT price_incl_gst, price_excl_gst FROM price_list WHERE product_name=? LIMIT 1");
                $pl->execute([$r['price_item']]); $plr = $pl->fetch();
                if($plr){ $amount = floatval($plr['price_incl_gst']); $gstAmt = max(0, floatval($plr['price_incl_gst'])-floatval($plr['price_excl_gst'])); }
            }
            if($amount <= 0) continue;
            $planName = strtoupper($r['price_item'] ?? '');
            if(strpos($planName,'SBGT')!==false)   $prof='SBGT';
            elseif(strpos($planName,'BGT')!==false) $prof='BGPT';
            else                                    $prof=($gstAmt>0)?'SBGT':'BGPT';
            // Does the linked balance-sheet row actually exist? (bs_entry_id can be stale/deleted)
            $entryExists = false;
            if(!empty($r['bs_entry_id'])){
                $chk = $pdo->prepare("SELECT id FROM balance_sheet_entries WHERE id=?");
                $chk->execute([intval($r['bs_entry_id'])]);
                $entryExists = (bool)$chk->fetchColumn();
            }
            if($entryExists){
                // Correct the profile / amount / screenshot of the existing entry
                $pdo->prepare("UPDATE balance_sheet_entries SET profile=?, type='license', service_type='Renewal', license_plan=?, total_price=?, unit_price=?, gst=?, payment_status='paid', payment_received=?, pending_payment=0, payment_transaction_details=COALESCE(payment_transaction_details,?) WHERE id=?")
                    ->execute([$prof, $r['label'], $amount, $amount-$gstAmt, $gstAmt, $amount, $r['payment_screenshot']?:null, intval($r['bs_entry_id'])]);
                $fixed++;
            } else {
                // No real entry (missing or stale link) → create it
                $pdo->prepare("INSERT INTO balance_sheet_entries
                    (type,profile,date,gps_serial_no,name_on_server,server_name,device_model,service_type,license_plan,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_transaction_details,remarks,created_by_code)
                    VALUES ('license',?,COALESCE(?,CURDATE()),?,?,?,?,?,?,1,?,?,?,'paid',?,0,?,?,?)")
                    ->execute([
                        $prof, ($r['approved_at']?substr($r['approved_at'],0,10):null),
                        $r['imei']?:null, $r['owner']?:$r['device_name'], $r['server_name'],
                        'Renewal','Renewal',$r['label'],
                        $amount-$gstAmt, $gstAmt, $amount, $amount,
                        $r['payment_screenshot']?:null,
                        'Renewal '.$r['label'].' — '.$r['device_name'].' ('.$r['plate'].') new expiry '.$r['new_expiry'],
                        $cu['name']??'admin'
                    ]);
                $newId=$pdo->lastInsertId();
                if($newId){ $pdo->prepare("UPDATE renewal_requests SET bs_entry_id=? WHERE id=?")->execute([$newId,$r['id']]); }
                $created++;
            }
        }
        echo json_encode(['success'=>true,'fixed'=>$fixed,'created'=>$created]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_delete':
    // Admin: delete a renewal request AND its linked balance-sheet entry (for test/cleanup).
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can delete renewals']); break; }
        _renewalEnsureTable($pdo);
        $id = intval($body['id'] ?? 0);
        if(!$id){ echo json_encode(['error'=>'Renewal id required']); break; }
        $st = $pdo->prepare("SELECT bs_entry_id FROM renewal_requests WHERE id=?"); $st->execute([$id]);
        $r = $st->fetch();
        if(!$r){ echo json_encode(['error'=>'Renewal not found']); break; }
        $bsDeleted = false;
        if(!empty($r['bs_entry_id'])){
            try { $pdo->prepare("DELETE FROM balance_sheet_entries WHERE id=?")->execute([intval($r['bs_entry_id'])]); $bsDeleted = true; } catch(Exception $e){}
        }
        $pdo->prepare("DELETE FROM renewal_requests WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true,'bs_deleted'=>$bsDeleted]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_reject':
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can reject renewals']); break; }
        _renewalEnsureTable($pdo);
        $id = intval($body['id'] ?? 0);
        $st = $pdo->prepare("SELECT status FROM renewal_requests WHERE id=?"); $st->execute([$id]);
        $r = $st->fetch();
        if(!$r){ echo json_encode(['error'=>'Not found']); break; }
        if($r['status']!=='pending'){ echo json_encode(['error'=>'Already '.$r['status']]); break; }
        $pdo->prepare("UPDATE renewal_requests SET status='rejected', approved_by=?, approved_at=NOW(), notes=? WHERE id=?")
            ->execute([$cu['name']??'Admin', trim($body['notes']??''), $id]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'renewal_fix_missing_bs':
    // Repair: approved renewals with no balance sheet entry (amount was 0 at request time) —
    // recover price from Price List and create the license entry now.
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
        _renewalEnsureTable($pdo);
        $rows = $pdo->query("SELECT * FROM renewal_requests WHERE status='approved' AND (bs_entry_id IS NULL OR bs_entry_id=0)")->fetchAll();
        $fixed = 0;
        foreach($rows as $r){
            $amount = floatval($r['amount'] ?? 0);
            $gstAmt = floatval($r['gst'] ?? 0);
            if($amount <= 0 && !empty($r['price_item'])){
                $pl = $pdo->prepare("SELECT price_incl_gst, price_excl_gst FROM price_list WHERE product_name=? AND is_active=1 LIMIT 1");
                $pl->execute([$r['price_item']]); $plr = $pl->fetch();
                if($plr){ $amount = floatval($plr['price_incl_gst']); $gstAmt = max(0, floatval($plr['price_incl_gst']) - floatval($plr['price_excl_gst'])); }
            }
            if($amount <= 0) continue;
            $pdo->prepare("INSERT INTO balance_sheet_entries
                (type,profile,date,gps_serial_no,name_on_server,server_name,device_model,service_type,license_plan,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_transaction_details,remarks,created_by_code)
                VALUES ('license','BGPT',CURDATE(),?,?,?,?,?,?,1,?,?,?,'paid',?,0,?,?,?)")
                ->execute([
                    $r['imei'] ?: null, $r['owner'] ?: $r['device_name'], $r['server_name'],
                    'Renewal', 'Renewal', $r['label'],
                    $amount - $gstAmt, $gstAmt, $amount, $amount,
                    $r['payment_screenshot'] ?: null,
                    'Renewal '.$r['label'].' — '.$r['device_name'].' ('.$r['plate'].') new expiry '.$r['new_expiry'].' [recovered]',
                    $cu['name'] ?? 'admin'
                ]);
            $bsId = $pdo->lastInsertId();
            if($bsId){ $pdo->prepare("UPDATE renewal_requests SET bs_entry_id=?, amount=?, gst=? WHERE id=?")->execute([$bsId,$amount,$gstAmt,$r['id']]); $fixed++; }
        }
        echo json_encode(['success'=>true,'fixed'=>$fixed]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'coin_diagnose':
    // Admin: check why a task did/didn't award the 24h submission coins.
    try {
        if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _ensureCoinLedger($pdo);
        $tid = intval($body['id'] ?? $_GET['id'] ?? 0);
        if(!$tid){ echo json_encode(['error'=>'Task id required']); break; }
        $t = $pdo->prepare("SELECT id,task_id,assigned_to,created_at,task_status FROM tasks WHERE id=?");
        $t->execute([$tid]); $tr=$t->fetch();
        if(!$tr){ echo json_encode(['error'=>'Task not found']); break; }
        // Was it ever submitted? find first time it hit Awaiting Approval (activity) or use updated
        $sub = $pdo->prepare("SELECT MIN(created_at) FROM task_activities WHERE task_id=? AND (remark LIKE '%Awaiting Approval%' OR activity_type='status_change')");
        $sub->execute([$tid]); $subAt = $sub->fetchColumn();
        $ledger = $pdo->prepare("SELECT coins,reason,created_at FROM coin_ledger WHERE task_id=? AND event_key=?");
        $ledger->execute([$tid,'submit24_'.$tid]); $coinRow=$ledger->fetch();
        $hrs = (!empty($tr['created_at']) && $subAt) ? round((strtotime($subAt)-strtotime($tr['created_at']))/3600,1) : null;
        echo json_encode([
            'success'=>true,
            'task'=>$tr['task_id'],
            'assigned_to'=>$tr['assigned_to'],
            'created_at'=>$tr['created_at'],
            'submitted_at'=>$subAt,
            'hours_to_submit'=>$hrs,
            'within_24h'=>($hrs!==null && $hrs<=24),
            'coins_awarded'=>$coinRow?intval($coinRow['coins']):0,
            'has_coin_entry'=>$coinRow?true:false,
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'coin_backfill_24h':
    // Admin: award the 50 within-24h coins for any past task that qualified but never got them.
    // "Submitted/closed within 24h of creation." Uses the best available timestamp:
    // activity-log submit time -> closed_at -> cash_submitted_at -> updated_at. Idempotent.
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Admin only']); break; }
        _ensureCoinLedger($pdo);
        // Make sure timestamp columns exist (older DBs)
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS closed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        $tasks = $pdo->query("SELECT id,task_id,assigned_to,created_at,closed_at,cash_submitted_at,updated_at,task_status
                              FROM tasks
                              WHERE assigned_to IS NOT NULL
                                AND task_status IN ('Awaiting Approval','Completed','Closed')")->fetchAll();
        $awarded=0; $skipped=0; $noTime=0; $tooLate=0; $already=0;
        foreach($tasks as $t){
            if(empty($t['created_at']) || !$t['assigned_to']){ $skipped++; continue; }
            // Already credited?
            $before = $pdo->prepare("SELECT COUNT(*) FROM coin_ledger WHERE event_key=?");
            $before->execute(['submit24_'.$t['id']]);
            if(intval($before->fetchColumn())>0){ $already++; $skipped++; continue; }
            // Determine the completion/submission time — first match wins
            $doneAt = null;
            $sub = $pdo->prepare("SELECT MIN(created_at) FROM task_activities WHERE task_id=? AND (remark LIKE '%Awaiting Approval%' OR remark LIKE '%submitted for approval%' OR remark LIKE '%payment complete%' OR remark LIKE '%payment collected%')");
            $sub->execute([$t['id']]); $subAt=$sub->fetchColumn();
            if($subAt) $doneAt = $subAt;
            elseif(!empty($t['closed_at']))         $doneAt = $t['closed_at'];
            elseif(!empty($t['cash_submitted_at'])) $doneAt = $t['cash_submitted_at'];
            elseif(!empty($t['updated_at']))        $doneAt = $t['updated_at'];
            if(!$doneAt){ $noTime++; $skipped++; continue; }
            $hrs=(strtotime($doneAt)-strtotime($t['created_at']))/3600;
            if($hrs < 0 || $hrs > 24){ $tooLate++; $skipped++; continue; }
            award_task_reward($pdo, intval($t['assigned_to']), 50, 'On-time submission (within 24h)', $t['id'], 'submit24_'.$t['id'], '🎉 +50 coins', 'On-time submission bonus credited.');
            $awarded++;
        }
        echo json_encode([
            'success'=>true,
            'awarded'=>$awarded,
            'skipped'=>$skipped,
            'checked'=>count($tasks),
            'breakdown'=>['already_had_coins'=>$already,'no_timestamp'=>$noTime,'over_24h'=>$tooLate],
        ]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'discount_budget_list':
    // List discount approvers with monthly limit + used (last 7 days) + weekly remaining.
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        _discountBudgetEnsureTable($pdo);
        // The fixed approver list (matches the task form dropdown). Include any extra names already in the table.
        $names = ['Raghavendra Manoj','Gummidi Lohita','Tanuja','Somesh','Sri Mam'];
        try { foreach($pdo->query("SELECT approver_name FROM discount_budgets")->fetchAll(PDO::FETCH_COLUMN) as $n){ if(!in_array($n,$names)) $names[]=$n; } } catch(Exception $e){}
        $out = [];
        foreach($names as $nm){
            $monthly = _discountMonthlyLimit($pdo, $nm);
            $used = _discountUsed7d($pdo, $nm);
            $weekly = ($monthly!==null && $monthly>0) ? round($monthly/4,2) : null;
            $out[] = [
                'approver_name'=>$nm,
                'monthly_limit'=>$monthly,           // null => no limit (unlimited)
                'weekly_cap'=>$weekly,
                'used_7d'=>round($used,2),
                'remaining'=>($weekly!==null)?round($weekly-$used,2):null,
                'limited'=>($monthly!==null && $monthly>0)
            ];
        }
        echo json_encode(['success'=>true,'budgets'=>$out]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'discount_budget_set':
    // Admin sets/updates an approver's MONTHLY discount limit (0 or empty => remove limit / unlimited).
    try {
        if($userRole !== 'admin'){ http_response_code(403); echo json_encode(['error'=>'Only admin can set discount budgets']); break; }
        _discountBudgetEnsureTable($pdo);
        $nm = trim($body['approver_name'] ?? '');
        if($nm===''){ echo json_encode(['error'=>'Approver name required']); break; }
        $lim = floatval($body['monthly_limit'] ?? 0);
        if($lim <= 0){
            $pdo->prepare("DELETE FROM discount_budgets WHERE approver_name=?")->execute([$nm]);
            echo json_encode(['success'=>true,'removed'=>true]);
        } else {
            $pdo->prepare("INSERT INTO discount_budgets (approver_name,monthly_limit,updated_by) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE monthly_limit=VALUES(monthly_limit), updated_by=VALUES(updated_by)")
                ->execute([$nm,$lim,$cu['name']??'admin']);
            echo json_encode(['success'=>true,'monthly_limit'=>$lim,'weekly_cap'=>round($lim/4,2)]);
        }
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'discount_check':
    // Live check used by the task form: can this approver give this amount now?
    try {
        $nm  = trim($body['approver_name'] ?? $_GET['approver_name'] ?? '');
        $amt = floatval($body['amount'] ?? $_GET['amount'] ?? 0);
        list($ok, $reason, $info) = _discountCheck($pdo, $nm, $amt);
        echo json_encode(['success'=>true,'allowed'=>$ok,'reason'=>$reason,'info'=>$info]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'quotation_create':
    // Admin/Manager/Assigner: create a follow-up task AND email the PDF quotation to the customer.
    // The PDF is generated in the browser and sent here as base64 (pdf_base64).
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized to send quotations']); break; }
        $custName = trim($body['customer_name'] ?? '');
        $custEmail= trim($body['email'] ?? '');
        $custPhone= trim($body['contact_number'] ?? '');
        if($custName===''){ echo json_encode(['error'=>'Customer name required']); break; }
        if(!$custEmail || !filter_var($custEmail, FILTER_VALIDATE_EMAIL)){ echo json_encode(['error'=>'Valid customer email required to send the quotation']); break; }
        $items    = $body['items'] ?? [];            // [{name,qty,unit_price,gst_percent,line_total}]
        $grand    = floatval($body['grand_total'] ?? 0);
        $notes    = trim($body['notes'] ?? '');
        $followDate = trim($body['follow_up_date'] ?? '');
        $quoteNo  = trim($body['quote_no'] ?? ('QT-'.date('Ymd-His')));
        $pdfB64   = $body['pdf_base64'] ?? '';

        // Ensure quotations table
        $pdo->exec("CREATE TABLE IF NOT EXISTS quotations (
            id INT AUTO_INCREMENT PRIMARY KEY, quote_no VARCHAR(50), task_db_id INT NULL, task_id VARCHAR(20) NULL,
            customer_name VARCHAR(200), email VARCHAR(200), contact_number VARCHAR(30),
            items_json TEXT, grand_total DECIMAL(10,2) DEFAULT 0, notes TEXT,
            emailed TINYINT(1) DEFAULT 0, created_by VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 1) Create the follow-up task (lead_type = Quotation) so it can never be skipped.
        $year = date('Y');
        $maxRow = $pdo->query("SELECT MAX(CAST(SUBSTRING(task_id, 9) AS UNSIGNED)) AS maxnum FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetch();
        $nextNum = intval($maxRow['maxnum'] ?? 0) + 1;
        $taskId = "ID-$year-".str_pad($nextNum,4,'0',STR_PAD_LEFT);
        for($i=0;$i<20;$i++){ $ex=$pdo->prepare("SELECT 1 FROM tasks WHERE task_id=? LIMIT 1"); $ex->execute([$taskId]); if(!$ex->fetch()) break; $nextNum++; $taskId="ID-$year-".str_pad($nextNum,4,'0',STR_PAD_LEFT); }
        $itemSummary = '';
        foreach((array)$items as $it){ $itemSummary .= ($itemSummary?', ':'').trim($it['name']??'').' x'.intval($it['qty']??1); }
        $reminder = $followDate ?: date('Y-m-d', strtotime('+2 days'));
        $notesFull = 'QUOTATION '.$quoteNo.' sent'."\n".'Items: '.$itemSummary."\n".'Total: Rs.'.number_format($grand,2).($notes?("\nNote: ".$notes):'');
        $pdo->prepare("INSERT INTO tasks (task_id,customer_name,contact_number,email,lead_type,task_status,general_notes,reminder_date,created_by,device_details)
                       VALUES (?,?,?,?,?, 'Open', ?, ?, ?, ?)")
            ->execute([$taskId,$custName,$custPhone,$custEmail,'Quotation',$notesFull,$reminder,($cu['name']??'staff'),$itemSummary]);
        $taskDbId = $pdo->lastInsertId();

        // 2) Store the quotation record
        $pdo->prepare("INSERT INTO quotations (quote_no,task_db_id,task_id,customer_name,email,contact_number,items_json,grand_total,notes,created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$quoteNo,$taskDbId,$taskId,$custName,$custEmail,$custPhone,json_encode($items),$grand,$notes,($cu['name']??'staff')]);
        $quoteId = $pdo->lastInsertId();

        // 3) Email the PDF quotation to the customer
        $emailed = false;
        if($pdfB64){
            $pdfRaw = base64_decode(preg_replace('#^data:application/pdf;base64,#','',$pdfB64), true);
            if($pdfRaw !== false){
                require_once __DIR__.'/mailer.php';
                $subject = 'Quotation '.$quoteNo.' — BharatGPS Tracker';
                $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6">'
                      . '<p>Dear '.htmlspecialchars($custName).',</p>'
                      . '<p>Thank you for your interest in BharatGPS Tracker. Please find your quotation <b>'.htmlspecialchars($quoteNo).'</b> attached as a PDF.</p>'
                      . '<p><b>Total: Rs. '.number_format($grand,2).'</b></p>'
                      . ($notes?('<p>'.nl2br(htmlspecialchars($notes)).'</p>'):'')
                      . '<p>For any questions, reply to this email or call 9849849824.</p>'
                      . '<p>Warm regards,<br>BharatGPS Tracker</p></div>';
                $emailed = sendMailWithAttachment($custEmail, $custName, $subject, $html, $pdfRaw, $quoteNo.'.pdf', 'application/pdf');
                if($emailed){ $pdo->prepare("UPDATE quotations SET emailed=1 WHERE id=?")->execute([$quoteId]); }
            }
        }
        echo json_encode(['success'=>true,'task_id'=>$taskId,'task_db_id'=>$taskDbId,'quote_no'=>$quoteNo,'emailed'=>$emailed]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

case 'quotation_list':
    try {
        if(!in_array($userRole,['admin','assigner','manager'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
        $pdo->exec("CREATE TABLE IF NOT EXISTS quotations (id INT AUTO_INCREMENT PRIMARY KEY, quote_no VARCHAR(50), task_db_id INT NULL, task_id VARCHAR(20) NULL, customer_name VARCHAR(200), email VARCHAR(200), contact_number VARCHAR(30), items_json TEXT, grand_total DECIMAL(10,2) DEFAULT 0, notes TEXT, emailed TINYINT(1) DEFAULT 0, created_by VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT * FROM quotations ORDER BY created_at DESC LIMIT 300")->fetchAll();
        echo json_encode(['success'=>true,'quotations'=>$rows]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    break;

default:
    http_response_code(404);
    echo json_encode(['error'=>'Unknown action: '.$action]);
    break;
}
