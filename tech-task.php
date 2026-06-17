<?php
ini_set('display_errors',0);
require_once 'api/db.php';
$pdo = getDB();

$token = $_GET['token'] ?? '';
$tid   = intval($_GET['id'] ?? 0);
if (!$token || !$tid) { header('Location: index.html'); exit; }

// Auth
$us = $pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");
$us->execute([$token]); $me = $us->fetch();
if (!$me) { header('Location: index.html'); exit; }

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
$di = $pdo->prepare("SELECT * FROM task_device_installs WHERE task_id=? ORDER BY device_index ASC");
try { $di->execute([$tid]); $installs = $di->fetchAll(); } catch(Exception $e) { $installs = []; }

// Create device installs table if missing
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

$qty = max(1, intval($task['device_qty']));
$pricePerDevice = floatval($task['price_to_collect']) / $qty;
$totalPrice = floatval($task['price_to_collect']);
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
  <div class="step" id="step-updates" onclick="goStep('updates')"><span class="step-num">💬</span>Updates</div>
  <div class="step <?= $installDone?'done':'' ?>" id="step-install" onclick="goStep('install')"><span class="step-num">🔧</span>Install</div>
  <div class="step <?= $paymentDone?'done':'' ?>" id="step-payment" onclick="goStep('payment')"><span class="step-num">💳</span>Payment</div>
  <div class="step <?= $isLocked?'done':'' ?>" id="step-submit" onclick="goStep('submit')"><span class="step-num">🚀</span>Submit</div>
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
    <div class="card-head"><div style="font-size:20px">✍️</div><h3>Add Update</h3></div>
    <div class="card-body">
      <?php if($st==='Open'): ?>
      <div style="background:var(--accl);border-radius:var(--rs);padding:12px;margin-bottom:12px;font-size:13px;color:var(--acc2)">
        📍 <strong>You are at the customer location?</strong> Click Attend to start the installation process.
      </div>
      <button class="btn btn-grn" onclick="clickAttend()">🔧 Attend — Start Installation</button>
      <?php else: ?>
      <div class="f"><label>Remark / Update</label><textarea id="upd-rem" rows="3" placeholder="What did you do? What was the status?"></textarea></div>
      <div class="f"><label>Next Step</label>
        <select id="upd-next">
          <option value="">Not decided</option>
          <option>Will visit today</option>
          <option>Will visit tomorrow</option>
          <option>Waiting for customer</option>
          <option>Ready for installation</option>
        </select>
      </div>
      <button class="btn" onclick="submitUpdate()">💬 Save Update</button>
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
    <div style="font-size:13px;color:var(--tx3);margin-bottom:18px">Go to Updates tab and click Attend when you are at the customer location.</div>
    <button class="btn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('updates')">← Go to Updates</button>
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
  <?php if(!$installDone && $st!=='Task Pending'): ?>
  <div style="text-align:center;padding:40px 16px">
    <div style="font-size:48px;margin-bottom:12px">🔒</div>
    <div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Complete Installation First</div>
    <div style="font-size:13px;color:var(--tx3);margin-bottom:18px">Save all device information before proceeding to payment.</div>
    <button class="btn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('install')">← Go to Installation</button>
  </div>
  <?php else: ?>
  <!-- Price Summary -->
  <div class="price-summary">
    <div class="price-row"><span><?= htmlspecialchars($task['device_details']??'Device') ?></span><span>₹<?= number_format($pricePerDevice) ?></span></div>
    <div class="price-row"><span>Quantity</span><span>× <?= $qty ?></span></div>
    <?php if(floatval($task['gst_amount']??0)>0): ?>
    <div class="price-row"><span>GST (18%)</span><span>₹<?= number_format($task['gst_amount']) ?></span></div>
    <?php endif; ?>
    <div class="price-total"><span>Total</span><span>₹<?= number_format($totalPrice) ?></span></div>
  </div>

  <!-- Existing payments -->
  <?php if(!empty($payments)): ?>
  <div class="card">
    <div class="card-head"><div style="font-size:20px">💰</div><h3>Payments Received</h3></div>
    <div class="card-body">
      <?php
      $totalCollected = 0;
      foreach($payments as $p):
        $totalCollected += floatval($p['amount']);
      ?>
      <div class="pay-item">
        <span><?= date('d M', strtotime($p['created_at'])) ?> · <?= htmlspecialchars($p['payment_mode']??'Cash') ?></span>
        <strong>₹<?= number_format($p['amount']) ?></strong>
      </div>
      <?php endforeach; ?>
      <div class="ir" style="margin-top:8px">
        <span class="il">Total Collected</span>
        <span class="iv" style="color:var(--grn);font-size:16px">₹<?= number_format($totalCollected) ?></span>
      </div>
      <div class="ir">
        <span class="il">Balance Due</span>
        <span class="iv" style="color:<?= ($totalPrice-$totalCollected)>0?'var(--red)':'var(--grn)' ?>;font-size:16px">₹<?= number_format(max(0,$totalPrice-$totalCollected)) ?></span>
      </div>
    </div>
  </div>
  <?php else: $totalCollected=0; ?>
  <?php endif; ?>

  <?php if(!$isLocked): ?>
  <!-- Add payment -->
  <div class="card">
    <div class="card-head"><div style="font-size:20px">➕</div><h3>Add Payment</h3></div>
    <div class="card-body">
      <div class="f"><label>Amount Received (₹) <span class="req">*</span></label><input type="number" id="pay-amt" placeholder="Enter amount received"></div>
      <div class="g2">
        <div class="f"><label>Payment Mode <span class="req">*</span></label>
          <select id="pay-mode">
            <option value="">Select</option>
            <option>Cash</option><option>UPI</option><option>Bank Transfer</option>
          </select>
        </div>
        <div class="f"><label>Transaction Ref</label><input type="text" id="pay-ref" placeholder="UPI ID / Ref no."></div>
      </div>
      <div class="f" id="pending-reason-field" style="display:none">
        <label>Reason for Pending Balance <span class="req">*</span></label>
        <select id="pending-reason">
          <option value="">Select reason</option>
          <option value="customer_will_pay_later">Customer will pay later</option>
          <option value="discount_given">Discount given</option>
          <option value="partial_payment">Partial payment agreed</option>
        </select>
      </div>
      <div class="f" id="pay-remind-field" style="display:none">
        <label>Payment Reminder Date</label>
        <input type="date" id="pay-remind-date">
      </div>
      <button class="btn" onclick="addPayment()">💾 Save Payment</button>
      <button class="btn btn-org" style="margin-top:8px" onclick="markPending()" id="mark-pending-btn">⏳ Mark as Pending (Collect Later)</button>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- PANEL 5: SUBMIT -->
<div class="panel" id="panel-submit">
  <?php if($isLocked): ?>
  <div class="lockbox">
    <div style="font-size:40px;margin-bottom:10px"><?= $st==='Closed'?'🎉':'🚀' ?></div>
    <h3><?= $st==='Closed'?'Task Completed!':'Submitted for Approval' ?></h3>
    <p><?= $st==='Closed'?'This task has been approved and closed by the manager.':'Your work has been submitted. The manager will review and close it.' ?></p>
  </div>
  <?php else: ?>
    <?php if(!$installDone): ?>
    <div style="text-align:center;padding:30px 14px">
      <div style="font-size:40px;margin-bottom:10px">⚠️</div>
      <div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Installation Not Complete</div>
      <div style="font-size:13px;color:var(--tx3);margin-bottom:16px">Save all device information before submitting.</div>
      <button class="btn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('install')">← Go to Installation</button>
    </div>
    <?php elseif(empty($payments) && floatval($task['amount_collected']??0)<=0): ?>
    <div style="text-align:center;padding:30px 14px">
      <div style="font-size:40px;margin-bottom:10px">💳</div>
      <div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Payment Status Required</div>
      <div style="font-size:13px;color:var(--tx3);margin-bottom:16px">Enter payment received or mark as pending before submitting.</div>
      <button class="btn btn-sm" style="width:auto;margin:0 auto" onclick="goStep('payment')">← Go to Payment</button>
    </div>
    <?php else: ?>
    <!-- Summary before submit -->
    <div class="card">
      <div class="card-head"><div style="font-size:20px">📋</div><h3>Summary</h3></div>
      <div class="card-body">
        <div class="ir"><span class="il">Task</span><span class="iv"><?= htmlspecialchars($task['task_id']) ?></span></div>
        <div class="ir"><span class="il">Customer</span><span class="iv"><?= htmlspecialchars($task['customer_name']) ?></span></div>
        <div class="ir"><span class="il">Job</span><span class="iv"><?= htmlspecialchars($task['device_details']??'–') ?> × <?= $qty ?></span></div>
        <div class="ir"><span class="il">Total</span><span class="iv" style="color:var(--grn)">₹<?= number_format($totalPrice) ?></span></div>
        <div class="ir"><span class="il">Collected</span><span class="iv" style="color:var(--grn)">₹<?= number_format(floatval($task['amount_collected']??0)) ?></span></div>
        <div class="ir"><span class="il">Balance</span><span class="iv" style="color:<?= ($totalPrice-floatval($task['amount_collected']??0))>0?'var(--red)':'var(--grn)' ?>">₹<?= number_format(max(0,$totalPrice-floatval($task['amount_collected']??0))) ?></span></div>
        <div class="ir"><span class="il">Devices Saved</span><span class="iv" id="submit-devices-count">–</span></div>
      </div>
    </div>
    <button class="btn btn-grn" onclick="submitForApproval()" id="submit-btn" style="padding:16px;font-size:16px">🚀 Submit for Manager Approval</button>
    <div style="font-size:12px;color:var(--tx3);text-align:center;margin-top:8px">Once submitted, you cannot make any changes.</div>
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
  if(name==='submit') updateSubmitCount();
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

// PAYMENT
function addPayment(){
  var amt = parseFloat(document.getElementById('pay-amt').value||0);
  var mode = document.getElementById('pay-mode').value;
  var ref = (document.getElementById('pay-ref')||{}).value||'';
  if(!amt||amt<=0){ toast('❌ Enter amount'); return; }
  if(!mode){ toast('❌ Select payment mode'); return; }

  var balance = TOTAL_PRICE - parseFloat(T.amount_collected||0) - amt;
  var pendReason = '';
  if(balance > 0){
    pendReason = (document.getElementById('pending-reason')||{}).value||'';
    if(!pendReason){ document.getElementById('pending-reason-field').style.display='block'; toast('❌ Select reason for pending balance'); return; }
  }

  api('add_payment',{},'POST',{task_id:TID,amount:amt,payment_mode:mode,transaction_ref:ref}).then(function(r){
    if(r.success){
      T.amount_collected = (parseFloat(T.amount_collected||0)+amt);
      if(pendReason) api('update_task',{},'POST',{id:TID,pending_reason:pendReason,payment_reminder_date:(document.getElementById('pay-remind-date')||{}).value||null}).catch(function(){});
      toast('✅ Payment saved!');
      setTimeout(function(){ window.location.reload(); },800);
    } else toast('❌ '+(r.error||'Error'));
  });
}

function markPending(){
  var reason = (document.getElementById('pending-reason')||{}).value||'';
  if(!reason){ document.getElementById('pending-reason-field').style.display='block'; toast('❌ Select a reason'); return; }
  api('update_task',{},'POST',{id:TID,payment_status:'Pending',pending_reason:reason,remark:'Payment marked as pending: '+reason}).then(function(r){
    if(r.success){ toast('✅ Marked as pending'); setTimeout(function(){ window.location.reload(); },800); }
    else toast('❌ '+(r.error||'Error'));
  });
}

// Show pending fields when needed
document.addEventListener('change',function(e){
  if(e.target.id==='pay-mode'){
    var bal = TOTAL_PRICE - parseFloat(T.amount_collected||0);
    if(bal>0) document.getElementById('pending-reason-field').style.display='block';
  }
  if(e.target.id==='pending-reason'){
    var rf = document.getElementById('pay-remind-field');
    if(rf) rf.style.display = e.target.value ? 'block':'none';
  }
});

// SUBMIT FOR APPROVAL
function submitForApproval(){
  // Check all devices saved
  for(var i=1;i<=QTY;i++){
    if(!getSavedDevice(i)){ toast('❌ Device '+i+' not saved yet'); goStep('install'); return; }
  }
  // Check payment
  var collected = parseFloat(T.amount_collected||0);
  if(collected<=0 && !T.payment_status){
    toast('❌ Enter payment or mark as pending first'); goStep('payment'); return;
  }
  if(!confirm('Submit this task for manager approval? You cannot make changes after this.')) return;
  var btn = document.getElementById('submit-btn');
  if(btn){ btn.textContent='Submitting...'; btn.disabled=true; }
  api('update_task',{},'POST',{id:TID,task_status:'Awaiting Approval',remark:'Task submitted for manager approval. All '+QTY+' device(s) installed and documented.'}).then(function(r){
    if(r.success){
      IS_LOCKED=true; T.task_status='Awaiting Approval';
      toast('🚀 Submitted! Awaiting approval.');
      setTimeout(function(){ window.location.href='index.html'; },2000);
    } else { toast('❌ '+(r.error||'Error')); if(btn){btn.disabled=false;btn.textContent='🚀 Submit for Manager Approval';} }
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
