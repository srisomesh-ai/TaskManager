<?php
ini_set('display_errors',0);
error_reporting(0);
require_once 'api/db.php';
$pdo = getDB();

$token = $_GET['token'] ?? '';
$tid   = intval($_GET['id'] ?? 0);
if (!$token || !$tid) { header('Location: index.html'); exit; }

// Auth
$us = $pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");
$us->execute([$token]); $me = $us->fetch();
if (!$me) { header('Location: index.html'); exit; }

// Create device installs table FIRST before any SELECT
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_device_installs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      task_id INT NOT NULL,
      device_index INT NOT NULL DEFAULT 1,
      vehicle_number VARCHAR(50),
      vehicle_type VARCHAR(50),
      gps_serial_no VARCHAR(100),
      name_on_server VARCHAR(200),
      server_name VARCHAR(50),
      rc_photo VARCHAR(200),
      selfie_photo VARCHAR(200),
      remarks TEXT,
      saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY unique_device (task_id, device_index)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// Add outstation columns to tasks if missing
$outstationCols = [
    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_location VARCHAR(200) NULL",
    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_travel_paid_by VARCHAR(20) NULL",
    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_customer_travel_amount DECIMAL(10,2) NULL",
    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS outstation_claim_cap DECIMAL(10,2) NULL",
    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS profile VARCHAR(10) DEFAULT 'BGPT'",
    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS gst_amount DECIMAL(10,2) DEFAULT 0",
];
foreach ($outstationCols as $sql) {
    try { $pdo->exec($sql); } catch(Exception $e) {}
}

// Task
$ts = $pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
$ts->execute([$tid]); $task = $ts->fetch();
if (!$task || ($me['role']==='technician' && $task['assigned_to']!=$me['id'])) { header('Location: index.html'); exit; }

// Activities
$as = $pdo->prepare("SELECT a.*,u.name as uname FROM task_activities a LEFT JOIN users u ON a.user_id=u.id WHERE a.task_id=? ORDER BY a.created_at ASC");
$as->execute([$tid]); $acts = $as->fetchAll();

// Payments
$ps = $pdo->prepare("SELECT * FROM payments WHERE task_id=? ORDER BY created_at ASC");
$ps->execute([$tid]); $payments = $ps->fetchAll();

// Device installations saved
$installs = [];
try {
    $di = $pdo->prepare("SELECT * FROM task_device_installs WHERE task_id=? ORDER BY device_index ASC");
    $di->execute([$tid]); $installs = $di->fetchAll();
} catch(Exception $e) { $installs = []; }

$qty = max(1, intval($task['device_qty']));
$totalPrice = floatval($task['price_to_collect']);
$pricePerDevice = $qty > 0 ? $totalPrice / $qty : $totalPrice;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title><?= htmlspecialchars($task['task_id']) ?> — Bharat GPS</title>
<style>
:root{
  --acc:#1a3a6b;--acc2:#2451a3;--accl:#e8eef8;
  --grn:#1a7a3a;--grnb:#e8f5ec;--red:#c0392b;--redb:#fdecea;
  --org:#e07b00;--orgb:#fff3e0;
  --sur:#fff;--sur2:#f7f8fa;--sur3:#eef0f4;
  --bdr:#d0d5dd;--tx:#1a1f2e;--tx2:#4a5568;--tx3:#8a9ab0;
  --r:10px;--rs:7px;--sh:0 2px 8px rgba(0,0,0,.08);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Calibri','Segoe UI',sans-serif;background:var(--sur2);color:var(--tx);min-height:100vh;padding-bottom:80px}

/* TOPBAR */
.topbar{background:linear-gradient(135deg,var(--acc),var(--acc2));padding:10px 14px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.back-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:6px 12px;border-radius:var(--rs);font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
.topbar-info{flex:1}
.topbar-tid{color:#fff;font-size:15px;font-weight:800}
.topbar-name{color:rgba(255,255,255,.8);font-size:12px}
.status-badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:800;background:rgba(255,255,255,.2);color:#fff}

/* OUTSTATION BANNER */
.os-banner{background:linear-gradient(135deg,#c17f00,var(--org));color:#fff;padding:10px 14px;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800}

/* PROGRESS STEPS */
.steps{display:flex;background:var(--sur);border-bottom:2px solid var(--bdr);overflow-x:auto}
.step{flex:1;min-width:60px;padding:10px 6px;text-align:center;font-size:10px;font-weight:800;color:var(--tx3);border-bottom:3px solid transparent;cursor:pointer;white-space:nowrap;text-transform:uppercase;letter-spacing:.3px;transition:all .2s}
.step.active{color:var(--acc);border-bottom-color:var(--acc)}
.step.done{color:var(--grn)}
.step.locked{color:var(--tx3);cursor:not-allowed}
.step-num{display:block;font-size:16px;margin-bottom:2px}

/* PANELS */
.panel{display:none;padding:14px}
.panel.active{display:block}

/* CARDS */
.card{background:var(--sur);border:1.5px solid var(--bdr);border-radius:var(--r);margin-bottom:12px;overflow:hidden;box-shadow:var(--sh)}
.card-head{background:var(--sur2);padding:12px 14px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:10px}
.card-head h3{font-size:14px;font-weight:800;color:var(--acc);flex:1}
.card-body{padding:14px}

/* DEVICE CARD */
.dev-card{background:var(--sur);border:2px solid var(--bdr);border-radius:var(--r);margin-bottom:14px;overflow:hidden}
.dev-card.saved{border-color:var(--grn)}
.dev-card-head{background:var(--accl);padding:10px 14px;display:flex;align-items:center;justify-content:space-between}
.dev-card-head h4{font-size:13px;font-weight:800;color:var(--acc)}
.saved-badge{background:var(--grn);color:#fff;font-size:11px;font-weight:800;padding:3px 10px;border-radius:20px}
.dev-card-body{padding:14px}

/* FORM */
.f{margin-bottom:12px}
.f label{display:block;font-size:11px;font-weight:800;color:var(--tx2);text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px}
.f input,.f select,.f textarea{width:100%;padding:10px 12px;background:var(--sur2);border:1.5px solid var(--bdr);border-radius:var(--rs);color:var(--tx);font-family:inherit;font-size:14px;outline:none}
.f input:focus,.f select:focus,.f textarea:focus{border-color:var(--acc2);box-shadow:0 0 0 3px rgba(36,81,163,.1)}
.f input:disabled,.f select:disabled,.f textarea:disabled{background:var(--sur3);color:var(--tx3);cursor:not-allowed}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.req{color:var(--red)}

/* UPLOAD */
.upload-box{border:2px dashed var(--bdr);border-radius:var(--rs);padding:14px;text-align:center;cursor:pointer;background:var(--sur2)}
.upload-box.done{border-color:var(--grn);background:var(--grnb)}
.upload-box .icon{font-size:24px;margin-bottom:4px}
.upload-box .lbl{font-size:11px;color:var(--tx3);font-weight:600}
.upload-box.done .lbl{color:var(--grn)}

/* BUTTONS */
.btn{width:100%;padding:13px;background:var(--acc);color:#fff;border:none;border-radius:var(--r);font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn:disabled{background:#aaa;cursor:not-allowed}
.btn-grn{background:var(--grn)}
.btn-org{background:var(--org)}
.btn-sec{background:var(--sur2);color:var(--tx);border:1.5px solid var(--bdr)}
.btn-sm{padding:8px 14px;font-size:13px;width:auto}
.btn-row{display:flex;gap:8px}

/* INFO ROWS */
.ir{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--sur3);font-size:13px}
.ir:last-child{border:none}
.il{color:var(--tx3);font-weight:600}
.iv{color:var(--tx);font-weight:700;text-align:right;max-width:60%}

/* ACTIVITY */
.act{padding:10px 0;border-bottom:1px solid var(--sur3);font-size:13px}
.act:last-child{border:none}
.act-time{font-size:10px;color:var(--tx3);margin-top:2px}
.act-r{color:var(--tx)}
.act-s{color:var(--acc);font-weight:700}
.act-p{color:var(--grn);font-weight:700}

/* LOCK BOX */
.lockbox{background:var(--grnb);border:2px solid var(--grn);border-radius:var(--r);padding:16px;text-align:center;margin-bottom:12px}
.lockbox h3{color:var(--grn);font-size:15px;margin-bottom:4px}
.lockbox p{font-size:12px;color:var(--tx3)}

/* PRICE SUMMARY */
.price-summary{background:linear-gradient(135deg,var(--acc),var(--acc2));border-radius:var(--r);padding:14px;color:#fff;margin-bottom:14px}
.price-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;opacity:.85}
.price-total{display:flex;justify-content:space-between;font-size:18px;font-weight:800;margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.3)}

/* PAYMENT */
.pay-item{background:var(--grnb);border:1px solid rgba(26,122,58,.3);border-radius:var(--rs);padding:10px 12px;margin-bottom:6px;font-size:13px;display:flex;justify-content:space-between}
.pay-item strong{color:var(--grn)}

/* STEP NAV */
.step-nav{position:fixed;bottom:0;left:0;right:0;background:var(--sur);border-top:2px solid var(--bdr);padding:10px 14px;display:flex;gap:8px;z-index:100;box-shadow:0 -2px 12px rgba(0,0,0,.08)}
.step-nav .btn{margin:0;flex:1}

/* TOAST */
.toast{position:fixed;top:70px;left:50%;transform:translateX(-50%);background:var(--acc);color:#fff;padding:10px 20px;border-radius:20px;font-size:13px;font-weight:700;z-index:9999;white-space:nowrap}
</style>
</head>
<body>

<?php
$st = $task['task_status'];
$isLocked = in_array($st, ['Awaiting Approval','Closed','Cancelled']);
$installDone = in_array($st, ['Task Pending','Awaiting Approval','Closed']);
$paymentDone = in_array($st, ['Awaiting Approval','Closed']);
$isOutstation = $task['is_outstation'];
?>

<!-- TOPBAR -->
<div class="topbar">
  <a href="index.html" class="back-btn">← Back</a>
  <div class="topbar-info">
    <div class="topbar-tid"><?= htmlspecialchars($task['task_id']) ?> — <?= htmlspecialchars($task['customer_name']) ?></div>
    <div class="topbar-name"><?= htmlspecialchars($task['contact_number']) ?> · <?= htmlspecialchars($task['location']??'') ?></div>
  </div>
  <span class="status-badge" id="status-badge"><?= htmlspecialchars($st) ?></span>
</div>

<?php if($isOutstation): ?>
<div class="os-banner">
  <span style="font-size:20px">📍</span>
  <div>
    <div>OUTSTATION TASK</div>
    <?php if($task['outstation_location']): ?>
    <div style="font-size:11px;font-weight:600;opacity:.9"><?= htmlspecialchars($task['outstation_location']) ?> · <?= $task['outstation_travel_paid_by']==='CUSTOMER'?'Customer paying travel':'Company paying travel' ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- PROGRESS STEPS -->
<div class="steps">
  <div class="step active done" id="step-info" onclick="goStep('info')"><span class="step-num">📋</span>Info</div>
  <div class="step" id="step-updates" onclick="goStep('updates')"><span class="step-num">💬</span><span style="font-size:9px">Updates<br>(Optional)</span></div>
  <div class="step <?= $installDone?'done':'' ?>" id="step-install" onclick="goStep('install')"><span class="step-num">🔧</span>Install</div>
  <div class="step <?= $paymentDone?'done':'' ?>" id="step-payment" onclick="goStep('payment')"><span class="step-num">💳</span>Payment</div>
  <div class="step <?= $isLocked?'done':'' ?>" id="step-submit" onclick="goStep('submit')"><span class="step-num">✅</span>Close</div>
</div>

<!-- PANEL 1: INFO -->
<div class="panel active" id="panel-info">
  <?php if($isOutstation && $task['outstation_travel_paid_by']==='CUSTOMER' && $task['outstation_customer_travel_amount']): ?>
  <div class="card" style="border-color:var(--org)">
    <div class="card-body" style="background:var(--orgb)">
      <div style="font-size:13px;font-weight:800;color:var(--org);margin-bottom:4px">💰 Travel Allowance — Customer Paying</div>
      <div style="font-size:13px;color:var(--tx2)">Customer will pay <strong>₹<?= number_format($task['outstation_customer_travel_amount']) ?></strong> travel allowance directly to you. You <strong>cannot</strong> claim this from the company.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head"><div style="font-size:20px">👤</div><h3>Customer Details</h3></div>
    <div class="card-body">
      <div class="ir"><span class="il">Customer</span><span class="iv"><?= htmlspecialchars($task['customer_name']) ?></span></div>
      <div class="ir"><span class="il">Phone</span><span class="iv"><a href="tel:<?= $task['contact_number'] ?>" style="color:var(--acc2)"><?= $task['contact_number'] ?></a></span></div>
      <div class="ir"><span class="il">Location</span><span class="iv"><?= htmlspecialchars($task['location']??'–') ?></span></div>
      <?php if($task['email']): ?><div class="ir"><span class="il">Email</span><span class="iv"><?= htmlspecialchars($task['email']) ?></span></div><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div style="font-size:20px">🔧</div><h3>Job Details</h3></div>
    <div class="card-body">
      <div class="ir"><span class="il">Job Type</span><span class="iv"><?= htmlspecialchars($task['device_details']??'–') ?></span></div>
      <div class="ir"><span class="il">Devices</span><span class="iv" style="color:var(--acc2);font-size:16px"><?= $qty ?> device<?= $qty>1?'s':'' ?></span></div>
      <div class="ir"><span class="il">Price / Device</span><span class="iv">₹<?= number_format($pricePerDevice) ?></span></div>
      <div class="ir"><span class="il">Total Price</span><span class="iv" style="color:var(--grn);font-size:16px">₹<?= number_format($totalPrice) ?></span></div>
      <div class="ir"><span class="il">Payment Mode</span><span class="iv"><?= htmlspecialchars($task['payment_mode']??'–') ?></span></div>
      <div class="ir"><span class="il">Lead Type</span><span class="iv"><?= htmlspecialchars($task['lead_type']??'–') ?></span></div>
      <div class="ir"><span class="il">Profile</span><span class="iv"><?= htmlspecialchars($task['profile']??'BGPT') ?></span></div>
    </div>
  </div>

  <?php if($task['general_notes']): ?>
  <div class="card" style="border-color:var(--acc)">
    <div class="card-head" style="background:var(--accl)"><div style="font-size:20px">📌</div><h3>Manager Instructions</h3></div>
    <div class="card-body" style="font-size:14px;color:var(--tx);line-height:1.6"><?= nl2br(htmlspecialchars($task['general_notes'])) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- PANEL 2: UPDATES -->
<div class="panel" id="panel-updates">
  <div class="card">
    <div class="card-head"><div style="font-size:20px">📜</div><h3>Activity Log</h3></div>
    <div class="card-body" id="act-log">
      <?php if(empty($acts)): ?>
      <div style="text-align:center;padding:20px;color:var(--tx3)">No activities yet</div>
      <?php else: ?>
        <?php foreach($acts as $a): ?>
        <div class="act">
          <div class="<?= $a['activity_type']==='payment_update'?'act-p':($a['activity_type']==='remark'?'act-r':'act-s') ?>"><?= htmlspecialchars($a['remark']) ?></div>
          <div class="act-time"><?= $a['uname'] ?> · <?= date('d M, h:i A', strtotime($a['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if(!$isLocked): ?>
  <div class="card">
    <div class="card-head"><div style="font-size:20px">✍️</div><h3>Add Update <span style="font-size:11px;color:var(--tx3);font-weight:400">(Optional)</span></h3></div>
    <div class="card-body">
      <?php if($st==='Open'): ?>
      <div style="background:var(--accl);border-radius:var(--rs);padding:12px;margin-bottom:12px;font-size:13px;color:var(--acc2)">
        📍 <strong>You are at the customer location?</strong> Click Attend to start the installation process.
      </div>
      <button class="btn btn-grn" onclick="clickAttend()">🔧 Attend — Start Installation</button>
      <?php else: ?>
      <div class="f"><label>Remark / Update</label><textarea id="upd-rem" rows="3" placeholder="Optional — log a call, note, or status update..."></textarea></div>
      <div class="f"><label>Next Step</label>
        <select id="upd-next">
          <option value="">Not decided</option>
          <option>Will visit today</option>
          <option>Will visit tomorrow</option>
          <option>Waiting for customer</option>
          <option>Ready for installation</option>
        </select>
      </div>
      <button class="btn btn-sec" onclick="submitUpdate()">💬 Save Update</button>
      <button class="btn btn-grn" style="margin-top:8px" onclick="goStep('install')">🔧 Go to Installation →</button>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- PANEL 3: INSTALLATION -->
<div class="panel" id="panel-install">
  <?php if($st === 'Open'): ?>
  <div style="text-align:center;padding:40px 16px">
    <div style="font-size:48px;margin-bottom:12px">🔒</div>
    <div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Not Started Yet</div>
    <div style="font-size:13px;color:var(--tx3);margin-bottom:18px">Click Attend in the Updates tab when you are at the customer location.</div>
    <button class="btn btn-grn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('updates')">← Attend First</button>
  </div>
  <?php else: ?>
    <?php if($installDone): ?>
    <div class="lockbox">
      <div style="font-size:32px;margin-bottom:8px">✅</div>
      <h3>Installation Submitted</h3>
      <p>All device data has been saved. Proceed to Payment.</p>
    </div>
    <?php endif; ?>
    <!-- Device cards -->
    <div id="device-cards"></div>
    <?php if(!$installDone): ?>
    <div id="install-next-btn" style="display:none">
      <button class="btn btn-grn" onclick="goStep('payment')">💳 All Devices Saved — Go to Payment →</button>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- PANEL 4: PAYMENT -->
<div class="panel" id="panel-payment">
  <?php if(!$installDone): ?>
  <div style="text-align:center;padding:40px 16px">
    <div style="font-size:48px;margin-bottom:12px">🔒</div>
    <div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Complete Installation First</div>
    <div style="font-size:13px;color:var(--tx3);margin-bottom:18px">Save all device information before proceeding to payment.</div>
    <button class="btn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('install')">← Go to Installation</button>
  </div>
  <?php else: ?>

  <!-- Price Banner -->
  <div class="price-summary">
    <div class="price-row"><span><?= htmlspecialchars($task['device_details']??'Device') ?></span><span>₹<?= number_format($pricePerDevice) ?></span></div>
    <div class="price-row"><span>Quantity</span><span>× <?= $qty ?></span></div>
    <?php if(floatval($task['gst_amount']??0)>0): ?>
    <div class="price-row"><span>GST (18%)</span><span>₹<?= number_format($task['gst_amount']) ?></span></div>
    <?php endif; ?>
    <div class="price-total"><span>💰 Amount to Collect</span><span id="total-display">₹<?= number_format($totalPrice) ?></span></div>
  </div>

  <?php if($isLocked): ?>
  <!-- Locked view -->
  <?php
  $tc = array_sum(array_column($payments,'amount'));
  $bal = max(0,$totalPrice-$tc);
  ?>
  <div class="card">
    <div class="card-head"><div style="font-size:20px">💳</div><h3>Payment Summary</h3></div>
    <div class="card-body">
      <?php foreach($payments as $p): ?>
      <div class="pay-item">
        <span><?= date('d M',strtotime($p['created_at'])) ?> · <?= htmlspecialchars($p['payment_mode']??'Cash') ?></span>
        <strong>₹<?= number_format($p['amount']) ?></strong>
      </div>
      <?php endforeach; ?>
      <div class="ir"><span class="il">Total Collected</span><span class="iv" style="color:var(--grn)">₹<?= number_format($tc) ?></span></div>
      <div class="ir"><span class="il">Balance</span><span class="iv" style="color:<?= $bal>0?'var(--red)':'var(--grn)' ?>">₹<?= number_format($bal) ?></span></div>
      <?php if($task['pending_reason']): ?>
      <div class="ir"><span class="il">Pending Reason</span><span class="iv"><?= htmlspecialchars($task['pending_reason']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- Active payment form -->
  <div class="card">
    <div class="card-head"><div style="font-size:20px">💳</div><h3>Enter Payment Received</h3></div>
    <div class="card-body">

      <!-- Large amount input -->
      <div style="margin-bottom:16px">
        <label style="font-size:11px;font-weight:800;color:var(--tx2);text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:6px">Amount Received (₹) *</label>
        <input type="number" id="pay-amt" placeholder="0"
          style="width:100%;padding:14px;font-size:24px;font-weight:800;color:var(--acc);border:2px solid var(--bdr);border-radius:var(--r);background:var(--sur2);outline:none;text-align:center"
          oninput="onPayAmtChange(this.value)">
        <!-- Live feedback -->
        <div id="pay-feedback" style="margin-top:8px;padding:10px 14px;border-radius:var(--rs);font-size:13px;font-weight:700;display:none"></div>
      </div>

      <div class="g2">
        <div class="f"><label>Payment Mode *</label>
          <select id="pay-mode">
            <option value="">Select</option>
            <option>Cash</option>
            <option>UPI</option>
            <option>Bank Transfer</option>
          </select>
        </div>
        <div class="f"><label>Transaction Ref / UPI ID</label>
          <input type="text" id="pay-ref" placeholder="Optional">
        </div>
      </div>

      <!-- Pending reason — only shows if amount less than total -->
      <div id="pending-section" style="display:none;margin-top:4px">
        <div style="background:var(--redb);border:1.5px solid var(--red);border-radius:var(--rs);padding:12px;margin-bottom:12px">
          <div style="font-size:13px;font-weight:800;color:var(--red);margin-bottom:4px">⚠️ Balance Pending — Reason Required</div>
          <div style="font-size:12px;color:var(--tx2)">Pending amount: <strong id="pending-amt-display" style="color:var(--red)">₹0</strong></div>
        </div>
        <div class="f"><label>Reason for Pending Balance *</label>
          <select id="pending-reason" onchange="onPendingReasonChange(this.value)">
            <option value="">Select reason</option>
            <option value="customer_will_pay_later">Customer will pay later</option>
            <option value="discount_given">Discount given — I will collect full amount</option>
          </select>
        </div>
        <!-- Customer will pay later warning -->
        <div id="cwpl-warning" style="display:none;background:#fff3e0;border:1.5px solid var(--org);border-radius:var(--rs);padding:14px;margin-top:8px">
          <div style="font-size:13px;font-weight:800;color:var(--org);margin-bottom:8px">⏰ 48-Hour Collection Responsibility</div>
          <div style="font-size:12px;color:var(--tx2);line-height:1.6;margin-bottom:12px">
            By selecting this option you accept full responsibility to collect the pending payment within <strong>48 hours</strong>.
            If payment is not collected within 48 hours, the pending amount will be <strong>deducted from your salary</strong>.
          </div>
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;font-weight:700;color:var(--tx);text-transform:none;letter-spacing:0">
            <input type="checkbox" id="cwpl-accept" style="width:18px;height:18px;margin-top:1px;flex-shrink:0;accent-color:var(--org)">
            <span>I accept responsibility to collect ₹<span id="cwpl-amount">0</span> within 48 hours. I understand non-collection will affect my salary.</span>
          </label>
        </div>
        <!-- Discount blocked -->
        <div id="discount-blocked" style="display:none;background:var(--redb);border:1.5px solid var(--red);border-radius:var(--rs);padding:14px;margin-top:8px">
          <div style="font-size:13px;font-weight:800;color:var(--red);margin-bottom:6px">❌ No Discount Applicable</div>
          <div style="font-size:12px;color:var(--tx2);line-height:1.6">
            The price was confirmed by the manager when this task was created. <strong>No discount can be given.</strong>
            You must collect the full amount of ₹<?= number_format($totalPrice) ?>.
            If the customer has an issue, contact your manager before proceeding.
          </div>
          <div style="margin-top:10px;font-size:12px;font-weight:800;color:var(--red)">👆 Select "Customer will pay later" or collect the full amount.</div>
        </div>
      </div>

      <button class="btn btn-grn" id="pay-next-btn" onclick="processPayment()" style="margin-top:16px;padding:14px;font-size:15px" disabled>
        Next — Review & Close Task →
      </button>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- PANEL 5: CLOSE TASK -->
<div class="panel" id="panel-submit">
  <?php if($isLocked): ?>
  <div class="lockbox">
    <div style="font-size:40px;margin-bottom:10px"><?= $st==='Closed'?'🎉':'✅' ?></div>
    <h3><?= $st==='Closed'?'Task Completed & Closed!':'Submitted — Awaiting Manager Approval' ?></h3>
    <p><?= $st==='Closed'?'This task has been approved and closed by the manager.':'Your work is submitted. Manager will review and close the task.' ?></p>
    <button class="btn btn-sec btn-sm" style="margin-top:14px;width:auto" onclick="window.location.href='index.html'">← Back to My Tasks</button>
  </div>
  <?php else: ?>
    <?php if(!$installDone): ?>
    <div style="text-align:center;padding:30px 14px">
      <div style="font-size:40px;margin-bottom:10px">⚠️</div>
      <div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Installation Not Complete</div>
      <div style="font-size:13px;color:var(--tx3);margin-bottom:16px">Save all device information before closing the task.</div>
      <button class="btn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('install')">← Go to Installation</button>
    </div>
    <?php else: ?>
    <!-- Final summary -->
    <div class="card">
      <div class="card-head" style="background:var(--grnb)"><div style="font-size:20px">📋</div><h3 style="color:var(--grn)">Final Review Before Closing</h3></div>
      <div class="card-body">
        <div class="ir"><span class="il">Task ID</span><span class="iv"><?= htmlspecialchars($task['task_id']) ?></span></div>
        <div class="ir"><span class="il">Customer</span><span class="iv"><?= htmlspecialchars($task['customer_name']) ?></span></div>
        <div class="ir"><span class="il">Job</span><span class="iv"><?= htmlspecialchars($task['device_details']??'–') ?> × <?= $qty ?></span></div>
        <div class="ir"><span class="il">Total Amount</span><span class="iv" style="color:var(--acc);font-weight:800;font-size:16px">₹<?= number_format($totalPrice) ?></span></div>
        <div class="ir"><span class="il">Amount Collected</span><span class="iv" style="color:var(--grn);font-size:16px" id="final-collected">₹<?= number_format(floatval($task['amount_collected']??0)) ?></span></div>
        <div class="ir"><span class="il">Balance</span><span class="iv" style="font-size:16px" id="final-balance">₹<?= number_format(max(0,$totalPrice-floatval($task['amount_collected']??0))) ?></span></div>
        <div class="ir"><span class="il">Devices Saved</span><span class="iv" id="submit-devices-count">–</span></div>
      </div>
    </div>
    <div id="submit-payment-warning" style="display:none;background:var(--redb);border:1.5px solid var(--red);border-radius:var(--rs);padding:12px;margin-bottom:12px;font-size:13px;font-weight:700;color:var(--red)">
      ❌ Payment not entered. Go to Payment tab first.
    </div>
    <button class="btn btn-grn" onclick="closeTask()" id="submit-btn" style="padding:16px;font-size:16px">
      ✅ Close Task — Submit to Manager
    </button>
    <div style="font-size:12px;color:var(--tx3);text-align:center;margin-top:8px;line-height:1.5">
      Once submitted, no further changes can be made.<br>Manager will review and approve.
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
var TID = <?= $tid ?>;
var TOK = <?= json_encode($token) ?>;
var ME  = <?= json_encode(['id'=>$me['id'],'name'=>$me['name'],'role'=>$me['role']]) ?>;
var T   = <?= json_encode($task) ?>;
var QTY = <?= $qty ?>;
var API = 'api/index.php';
var TOTAL_PRICE = <?= $totalPrice ?>;
var PRICE_PER_DEVICE = <?= $pricePerDevice ?>;
var INSTALL_DONE = <?= $installDone ? 'true' : 'false' ?>;
var IS_LOCKED = <?= $isLocked ? 'true' : 'false' ?>;

// Saved device data from DB
var savedDevices = <?= json_encode($installs) ?>;

var VTYPES = ['Car','Truck','Bus','Auto','Bike','JCB','Tractor','Van','Mini Truck','Other'];

function api(action, params, method, body){
  var url = API+'?action='+action;
  if(params && method!=='POST') Object.keys(params).forEach(function(k){ url+='&'+k+'='+encodeURIComponent(params[k]); });
  var opts = {method:method||'GET', headers:{'X-Auth-Token':TOK}};
  if(body){ opts.headers['Content-Type']='application/json'; opts.body=JSON.stringify(body); }
  return fetch(url,opts).then(function(r){ return r.json(); });
}

function apiUpload(taskId, docType, file, deviceIdx){
  var fd = new FormData();
  fd.append('file', file);
  fd.append('task_id', taskId);
  fd.append('doc_type', docType+'_device_'+deviceIdx);
  return fetch(API+'?action=upload_document', {method:'POST', headers:{'X-Auth-Token':TOK}, body:fd}).then(function(r){ return r.json(); });
}

function toast(m, dur){
  var t=document.createElement('div'); t.className='toast'; t.textContent=m;
  document.body.appendChild(t); setTimeout(function(){ t.remove(); }, dur||2500);
}

// STEP NAVIGATION
var currentStep = 'info';
function goStep(name){
  document.querySelectorAll('.panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.step').forEach(function(s){ s.classList.remove('active'); });
  document.getElementById('panel-'+name).classList.add('active');
  document.getElementById('step-'+name).classList.add('active');
  currentStep = name;
  if(name==='install') renderDeviceCards();
  if(name==='submit'){ updateSubmitCount(); updateSubmitInfo(); }
  window.scrollTo(0,0);
}

// ATTEND
function clickAttend(){
  if(!confirm('Are you at the customer location? This will start the installation process.')) return;
  api('update_task',{},'POST',{id:TID,task_status:'In Progress',remark:'Technician arrived at customer location. Starting installation.'}).then(function(r){
    if(r.success){ T.task_status='In Progress'; toast('✅ Started!'); setTimeout(function(){ window.location.reload(); },800); }
    else toast('❌ '+(r.error||'Error'));
  });
}

// UPDATE
function submitUpdate(){
  var rem = (document.getElementById('upd-rem')||{}).value||'';
  var next = (document.getElementById('upd-next')||{}).value||'';
  if(!rem.trim()){ toast('❌ Enter a remark'); return; }
  var full = rem + (next?' Next: '+next:'');
  api('update_task',{},'POST',{id:TID,remark:full}).then(function(r){
    if(r.success){ toast('✅ Saved!'); setTimeout(function(){ window.location.reload(); },800); }
    else toast('❌ '+(r.error||'Error'));
  });
}

// DEVICE CARDS
var uploads = {}; // {rc-1: File, selfie-1: File, ...}
var deviceSaved = {}; // which device indices are saved

function getSavedDevice(idx){
  for(var i=0;i<savedDevices.length;i++){
    if(parseInt(savedDevices[i].device_index)===idx) return savedDevices[i];
  }
  return null;
}

function renderDeviceCards(){
  var container = document.getElementById('device-cards');
  if(!container) return;
  var html = '';
  for(var i=1; i<=QTY; i++){
    var saved = getSavedDevice(i);
    var isSaved = !!saved && saved.gps_serial_no && saved.name_on_server && saved.server_name;
    if(isSaved) deviceSaved[i] = true;
    html += renderOneDeviceCard(i, saved, isSaved);
  }
  container.innerHTML = html;
  checkAllDevicesSaved();
}

function renderOneDeviceCard(idx, saved, isSaved){
  var dis = (IS_LOCKED || INSTALL_DONE) ? ' disabled' : '';
  var savedClass = isSaved ? ' saved' : '';
  var savedBadge = isSaved ? '<span class="saved-badge">✅ Saved</span>' : '<span style="font-size:11px;color:var(--tx3)">Not saved yet</span>';

  var vn = saved ? (saved.vehicle_number||'') : '';
  var vt = saved ? (saved.vehicle_type||'') : '';
  var imei = saved ? (saved.gps_serial_no||'') : '';
  var nameServer = saved ? (saved.name_on_server||'') : '';
  var server = saved ? (saved.server_name||'') : '';
  var rem = saved ? (saved.remarks||'') : '';
  var rcDone = saved && saved.rc_photo;
  var selfieDone = saved && saved.selfie_photo;

  var vtOpts = VTYPES.map(function(v){ return '<option'+(v===vt?' selected':'')+'>'+v+'</option>'; }).join('');
  var serverOpts = ['','Server 1','Server 2','Server 3','Server 4'].map(function(s){
    return '<option value="'+s+'"'+(s===server?' selected':'')+'>'+( s||'Select Server')+'</option>';
  }).join('');

  var h = '<div class="dev-card'+savedClass+'" id="dev-card-'+idx+'">';
  h += '<div class="dev-card-head"><h4>🚗 Device '+idx+' of '+QTY+'</h4>'+savedBadge+'</div>';
  h += '<div class="dev-card-body">';

  h += '<div class="g2">';
  h += '<div class="f"><label>Vehicle Number <span class="req">*</span></label><input type="text" id="vn-'+idx+'" value="'+escAttr(vn)+'" placeholder="AP31AB1234"'+dis+' oninput="this.value=this.value.toUpperCase()"></div>';
  h += '<div class="f"><label>Vehicle Type <span class="req">*</span></label><select id="vt-'+idx+'"'+dis+'><option value="">Select</option>'+vtOpts+'</select></div>';
  h += '</div>';

  h += '<div class="f"><label>GPS Serial / IMEI <span class="req">*</span></label><input type="text" id="imei-'+idx+'" value="'+escAttr(imei)+'" placeholder="15-digit IMEI"'+dis+'></div>';
  h += '<div class="f"><label>Name on Server <span class="req">*</span></label><input type="text" id="nos-'+idx+'" value="'+escAttr(nameServer)+'" placeholder="e.g. Ravi Kumar - AP31AB1234"'+dis+'></div>';
  h += '<div class="f"><label>GPS Server <span class="req">*</span></label><select id="srv-'+idx+'"'+dis+'>'+serverOpts+'</select></div>';

  h += '<div class="g2">';
  h += '<div><label style="font-size:11px;font-weight:800;color:var(--tx2);text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:6px">RC Copy <span class="req">*</span></label>';
  h += '<div class="upload-box'+(rcDone?' done':'')+'" id="rc-box-'+idx+'" onclick="'+(dis?'':'pickFile(\'rc\','+idx+')')+'">';
  h += '<div class="icon">'+(rcDone?'✅':'📄')+'</div>';
  h += '<div class="lbl" id="rc-lbl-'+idx+'">'+(rcDone?'RC Uploaded':'Upload RC / Photo')+'</div>';
  h += '</div><input type="file" id="rc-file-'+idx+'" accept="image/*,.pdf" style="display:none" onchange="filePicked(\'rc\','+idx+',this)"></div>';

  h += '<div><label style="font-size:11px;font-weight:800;color:var(--tx2);text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:6px">Selfie <span class="req">*</span></label>';
  h += '<div class="upload-box'+(selfieDone?' done':'')+'" id="sl-box-'+idx+'" onclick="'+(dis?'':'pickFile(\'selfie\','+idx+')')+'">';
  h += '<div class="icon">'+(selfieDone?'✅':'🤳')+'</div>';
  h += '<div class="lbl" id="sl-lbl-'+idx+'">'+(selfieDone?'Selfie Uploaded':'Take Selfie')+'</div>';
  h += '</div><input type="file" id="sl-file-'+idx+'" accept="image/*" style="display:none" onchange="filePicked(\'selfie\','+idx+',this)"></div>';
  h += '</div>';

  h += '<div class="f"><label>Work Remarks <span class="req">*</span></label><textarea id="rem-'+idx+'" rows="2" placeholder="Describe installation details..."'+dis+'>'+escAttr(rem)+'</textarea></div>';

  if(!INSTALL_DONE && !IS_LOCKED){
    h += '<button class="btn'+(isSaved?' btn-sec':'')+'" onclick="saveDevice('+idx+')" id="save-btn-'+idx+'">';
    h += isSaved ? '✏️ Update Device '+idx : '💾 Save Device '+idx;
    h += '</button>';
  }
  h += '</div></div>';
  return h;
}

function escAttr(s){ return (s||'').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function pickFile(type, idx){
  var input = document.getElementById((type==='rc'?'rc':'sl')+'-file-'+idx);
  if(input) input.click();
}

function filePicked(type, idx, inp){
  if(!inp.files[0]) return;
  var file = inp.files[0];
  if(file.size > 1024*1024){ toast('❌ File too large — max 1MB'); inp.value=''; return; }
  uploads[type+'-'+idx] = file;
  var box = document.getElementById((type==='rc'?'rc':'sl')+'-box-'+idx);
  var lbl = document.getElementById((type==='rc'?'rc':'sl')+'-lbl-'+idx);
  if(box){ box.className='upload-box done'; box.querySelector('.icon').textContent='✅'; }
  if(lbl) lbl.textContent = file.name.slice(0,20);
  toast('📎 '+file.name.slice(0,20));
}

function saveDevice(idx){
  var vn   = (document.getElementById('vn-'+idx)||{}).value||'';
  var vt   = (document.getElementById('vt-'+idx)||{}).value||'';
  var imei = (document.getElementById('imei-'+idx)||{}).value||'';
  var nos  = (document.getElementById('nos-'+idx)||{}).value||'';
  var srv  = (document.getElementById('srv-'+idx)||{}).value||'';
  var rem  = (document.getElementById('rem-'+idx)||{}).value||'';

  if(!vn.trim()){ toast('❌ Vehicle number required'); return; }
  if(!vt){ toast('❌ Vehicle type required'); return; }
  if(!imei.trim()){ toast('❌ GPS Serial / IMEI required'); return; }
  if(!nos.trim()){ toast('❌ Name on Server required'); return; }
  if(!srv){ toast('❌ GPS Server required'); return; }
  if(!rem.trim()){ toast('❌ Work remarks required'); return; }

  var saved = getSavedDevice(idx);
  var rcDone = (saved && saved.rc_photo) || uploads['rc-'+idx];
  var slDone = (saved && saved.selfie_photo) || uploads['selfie-'+idx];
  if(!rcDone){ toast('❌ RC Copy required'); return; }
  if(!slDone){ toast('❌ Selfie required'); return; }

  var btn = document.getElementById('save-btn-'+idx);
  if(btn){ btn.textContent='Saving...'; btn.disabled=true; }

  // Save to device_installs table via API
  api('save_device_install',{},'POST',{
    task_id:TID, device_index:idx,
    vehicle_number:vn, vehicle_type:vt,
    gps_serial_no:imei, name_on_server:nos,
    server_name:srv, remarks:rem
  }).then(function(r){
    if(!r.success){ toast('❌ '+(r.error||'Save failed')); if(btn){btn.disabled=false;btn.textContent='💾 Save Device '+idx;} return; }
    // Upload files
    var chain = Promise.resolve();
    ['rc','selfie'].forEach(function(type){
      var file = uploads[type+'-'+idx];
      if(file){ chain = chain.then(function(){ return apiUpload(TID, type, file, idx); }); }
    });
    return chain;
  }).then(function(){
    deviceSaved[idx] = true;
    // Update local savedDevices
    var existing = false;
    for(var i=0;i<savedDevices.length;i++){
      if(parseInt(savedDevices[i].device_index)===idx){
        savedDevices[i] = {device_index:idx,vehicle_number:vn,vehicle_type:vt,gps_serial_no:imei,name_on_server:nos,server_name:srv,remarks:rem,rc_photo:1,selfie_photo:1};
        existing=true; break;
      }
    }
    if(!existing) savedDevices.push({device_index:idx,vehicle_number:vn,vehicle_type:vt,gps_serial_no:imei,name_on_server:nos,server_name:srv,remarks:rem,rc_photo:1,selfie_photo:1});
    toast('✅ Device '+idx+' saved!');
    // Re-render this card
    var card = document.getElementById('dev-card-'+idx);
    if(card) card.outerHTML = renderOneDeviceCard(idx, savedDevices[savedDevices.length-1], true);
    checkAllDevicesSaved();
  }).catch(function(e){ toast('❌ '+e.message); if(btn){btn.disabled=false;btn.textContent='💾 Save Device '+idx;} });
}

function checkAllDevicesSaved(){
  var allSaved = true;
  for(var i=1;i<=QTY;i++){
    if(!deviceSaved[i]) { allSaved=false; break; }
  }
  var nextBtn = document.getElementById('install-next-btn');
  if(nextBtn) nextBtn.style.display = allSaved ? 'block' : 'none';

  if(allSaved && !INSTALL_DONE){
    // Mark task as Task Pending
    api('update_task',{},'POST',{id:TID,task_status:'Task Pending',remark:'All '+QTY+' device(s) installation data saved.'}).then(function(r){
      if(r.success){ INSTALL_DONE=true; T.task_status='Task Pending'; }
    });
  }
}

function updateSubmitCount(){
  var el = document.getElementById('submit-devices-count');
  if(!el) return;
  var count = 0;
  for(var i=1;i<=QTY;i++){ if(getSavedDevice(i)) count++; }
  el.textContent = count+' / '+QTY+' saved';
  el.style.color = count===QTY ? 'var(--grn)' : 'var(--red)';
}

// ============================================================
// PAYMENT LOGIC — STRICT
// ============================================================
var ALREADY_COLLECTED = <?= array_sum(array_column($payments,'amount')) ?>;

function onPayAmtChange(val){
  var amt = parseFloat(val||0);
  var total = TOTAL_PRICE;
  var pending = total - amt;
  var feedback = document.getElementById('pay-feedback');
  var pendSection = document.getElementById('pending-section');
  var nextBtn = document.getElementById('pay-next-btn');

  // Reset
  document.getElementById('cwpl-warning').style.display='none';
  document.getElementById('discount-blocked').style.display='none';
  var pr = document.getElementById('pending-reason');
  if(pr) pr.value='';
  var cb = document.getElementById('cwpl-accept');
  if(cb) cb.checked=false;

  if(!amt || amt <= 0){
    if(feedback){ feedback.style.display='none'; }
    if(pendSection) pendSection.style.display='none';
    nextBtn.disabled=true;
    return;
  }

  feedback.style.display='block';

  if(amt >= total){
    // Full payment — green
    feedback.style.background='var(--grnb)';
    feedback.style.color='var(--grn)';
    feedback.style.border='1.5px solid var(--grn)';
    feedback.textContent='✅ Full payment — Amount matches exactly ₹'+total.toLocaleString('en-IN');
    if(pendSection) pendSection.style.display='none';
    nextBtn.disabled=false;
  } else {
    // Partial — red, show pending section
    var pendAmt = total - amt;
    feedback.style.background='var(--redb)';
    feedback.style.color='var(--red)';
    feedback.style.border='1.5px solid var(--red)';
    feedback.textContent='⚠️ Pending balance: ₹'+pendAmt.toLocaleString('en-IN')+' — select reason below';
    document.getElementById('pending-amt-display').textContent='₹'+pendAmt.toLocaleString('en-IN');
    document.getElementById('cwpl-amount').textContent=pendAmt.toLocaleString('en-IN');
    if(pendSection) pendSection.style.display='block';
    nextBtn.disabled=true; // disabled until reason + acceptance
  }
}

function onPendingReasonChange(reason){
  var cwpl = document.getElementById('cwpl-warning');
  var disc = document.getElementById('discount-blocked');
  var nextBtn = document.getElementById('pay-next-btn');
  var cb = document.getElementById('cwpl-accept');
  if(cb) cb.checked=false;

  cwpl.style.display = reason==='customer_will_pay_later' ? 'block' : 'none';
  disc.style.display = reason==='discount_given' ? 'block' : 'none';

  if(reason==='discount_given'){
    nextBtn.disabled=true; // permanently blocked for this choice
  } else if(reason==='customer_will_pay_later'){
    nextBtn.disabled=true; // enabled only after checkbox
  } else {
    nextBtn.disabled=true;
  }
}

// Checkbox accept enables Next button
document.addEventListener('change', function(e){
  if(e.target.id==='cwpl-accept'){
    document.getElementById('pay-next-btn').disabled = !e.target.checked;
  }
  if(e.target.id==='pending-reason'){
    onPendingReasonChange(e.target.value);
  }
});

function processPayment(){
  var amt = parseFloat((document.getElementById('pay-amt')||{}).value||0);
  var mode = (document.getElementById('pay-mode')||{}).value||'';
  var ref  = (document.getElementById('pay-ref')||{}).value||'';

  if(!amt||amt<=0){ toast('❌ Enter payment amount'); return; }
  if(!mode){ toast('❌ Select payment mode'); return; }

  var pending = TOTAL_PRICE - amt;
  var reason  = '';
  var cwplNote = '';

  if(pending > 0){
    reason = (document.getElementById('pending-reason')||{}).value||'';
    if(!reason){ toast('❌ Select reason for pending balance'); return; }
    if(reason==='discount_given'){ toast('❌ No discount applicable — collect full amount'); return; }
    if(reason==='customer_will_pay_later'){
      var accepted = document.getElementById('cwpl-accept').checked;
      if(!accepted){ toast('❌ Accept responsibility first'); return; }
      cwplNote = 'Technician '+ME.name+' accepted 48-hour collection responsibility for pending ₹'+pending.toLocaleString('en-IN')+'. Non-collection will affect salary.';
    }
  }

  var btn = document.getElementById('pay-next-btn');
  btn.textContent='Saving...'; btn.disabled=true;

  // Save payment to DB
  api('add_payment',{},'POST',{task_id:TID,amount:amt,payment_mode:mode,transaction_ref:ref})
  .then(function(r){
    if(!r.success){ toast('❌ '+(r.error||'Error saving')); btn.disabled=false; btn.textContent='Next — Review & Close Task →'; return Promise.reject('err'); }
    // Update task with collected amount and pending reason
    var upd = {id:TID, amount_collected:amt, payment_status: pending<=0 ? 'Collected' : 'Pending'};
    if(reason) upd.pending_reason = reason;
    if(cwplNote) upd.remark = cwplNote;
    if(pending>0 && reason==='customer_will_pay_later'){
      var d = new Date(); d.setHours(d.getHours()+48);
      upd.payment_reminder_date = d.toISOString().split('T')[0];
    }
    return api('update_task',{},'POST',upd);
  })
  .then(function(r){
    if(!r) return;
    T.amount_collected = amt;
    T.payment_status = pending<=0 ? 'Collected' : 'Pending';
    toast('✅ Payment saved!');
    setTimeout(function(){ goStep('submit'); updateSubmitInfo(); }, 600);
  })
  .catch(function(e){ if(e!=='err') toast('❌ '+(e.message||'Error')); btn.disabled=false; btn.textContent='Next — Review & Close Task →'; });
}

function updateSubmitInfo(){
  var collected = parseFloat(T.amount_collected||0);
  var balance = Math.max(0, TOTAL_PRICE - collected);
  var fc = document.getElementById('final-collected');
  var fb = document.getElementById('final-balance');
  var pw = document.getElementById('submit-payment-warning');
  if(fc) fc.textContent = '₹'+collected.toLocaleString('en-IN');
  if(fb){
    fb.textContent = '₹'+balance.toLocaleString('en-IN');
    fb.style.color = balance>0 ? 'var(--red)' : 'var(--grn)';
  }
  if(pw) pw.style.display = (collected<=0 && !T.payment_status) ? 'block' : 'none';
}

// CLOSE TASK
function closeTask(){
  // Check all devices saved
  for(var i=1;i<=QTY;i++){
    if(!getSavedDevice(i)){ toast('❌ Device '+i+' not saved'); goStep('install'); return; }
  }
  // Check payment entered
  var collected = parseFloat(T.amount_collected||0);
  if(collected<=0 && !T.payment_status && !T.pending_reason){
    toast('❌ Enter payment first'); goStep('payment'); return;
  }
  if(!confirm('Close this task and submit to manager? No changes after this.')){ return; }
  var btn = document.getElementById('submit-btn');
  if(btn){ btn.textContent='Submitting...'; btn.disabled=true; }
  api('update_task',{},'POST',{
    id:TID,
    task_status:'Awaiting Approval',
    remark:'Task closed by '+ME.name+'. '+QTY+' device(s) installed. Payment: ₹'+parseFloat(T.amount_collected||0).toLocaleString('en-IN')+' collected.'+(T.pending_reason?' Pending reason: '+T.pending_reason:'')
  }).then(function(r){
    if(r.success){
      IS_LOCKED=true; T.task_status='Awaiting Approval';
      toast('✅ Task closed! Awaiting manager approval.');
      setTimeout(function(){ window.location.href='index.html'; }, 2000);
    } else {
      toast('❌ '+(r.error||'Error'));
      if(btn){ btn.disabled=false; btn.textContent='✅ Close Task — Submit to Manager'; }
    }
  });
}

// Init saved devices
(function(){
  for(var i=0;i<savedDevices.length;i++){
    var d = savedDevices[i];
    if(d.gps_serial_no && d.name_on_server && d.server_name) deviceSaved[parseInt(d.device_index)]=true;
  }
  checkAllDevicesSaved();
})();
</script>
</body>
</html>
