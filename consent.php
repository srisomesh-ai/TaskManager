<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// No caching — always serve fresh (prevents back button showing old form)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// ============================================================
// BHARATGPS — Customer Consent & Payment Commitment Page
// Public URL — no login required
// Sent to customer before technician begins installation
// ============================================================
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/mailer.php';

$pdo   = getDB();
$token = trim($_GET['token'] ?? '');
$error = '';
$task  = null;

if(!$token){
    die('<p style="font-family:sans-serif;padding:40px;color:#c0392b">Invalid link.</p>');
}

// Fetch task by consent_token
$s = $pdo->prepare("SELECT t.*, u.name AS tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.consent_token=?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task){
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BharatGPS</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:12px;padding:32px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:40px;margin-bottom:12px">❌</div>
<h2 style="color:#c0392b;margin-bottom:8px">Link Expired or Invalid</h2>
<p style="color:#4a5568;font-size:14px">This consent link is no longer valid. Please contact your technician or call <strong>9849849824</strong>.</p>
</div></body></html>');
}

// Already consented — show confirmation page and stop (handles back button + repeat link clicks)
if(!empty($task['customer_consent_at'])){
    $consentTime = date('d M Y, h:i A', strtotime($task['customer_consent_at']));
    $consentName = htmlspecialchars($task['customer_consent_name'] ?? $task['customer_name'] ?? 'You');
    $consentPrice = number_format(floatval($task['price_to_collect']??0),0);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    die('<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<title>Already Confirmed — BharatGPS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",sans-serif;background:#f0f2f5;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px}
.card{background:#fff;border-radius:14px;padding:32px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}
.icon{font-size:56px;margin-bottom:16px}
.title{font-size:20px;font-weight:800;color:#1a7a3a;margin-bottom:8px}
.sub{font-size:14px;color:#4a5568;line-height:1.7;margin-bottom:20px}
.detail-box{background:#e8f5ec;border:1.5px solid #1a7a3a;border-radius:8px;padding:14px 16px;text-align:left;margin-bottom:16px;font-size:13px;line-height:2}
.detail-box strong{color:#1a7a3a}
.note{font-size:12px;color:#8a9ab0;line-height:1.6}
/* Loading state */
.btn-submit:disabled{background:linear-gradient(135deg,#4a5568,#718096);cursor:not-allowed;box-shadow:none}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-spinner{display:inline-block;width:16px;height:16px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px}
</style>
<script>
function handleConsentSubmit(btn){
  // Validate checkboxes before showing loading
  const terms = document.getElementById('chk_terms');
  const pay   = document.getElementById('chk_pay');
  const name  = document.querySelector('input[name="c_name"]');
  const mobile= document.querySelector('input[name="c_mobile"]');
  if(!name.value.trim()||!mobile.value.trim()||!terms.checked||!pay.checked){
    return true; // Let form validation handle it
  }
  // Show loading state
  btn.disabled = true;
  btn.innerHTML = '<span class="btn-spinner"></span>Submitting — Please wait…';
  // Prevent double submit on back button
  window.history.replaceState(null,'','?token=<?= urlencode($token) ?>&submitted=1');
  return true;
}
// If user navigated back to a submitted page, reload to get server check
if(window.performance && window.performance.navigation.type === 2){
  window.location.reload();
}
</script>
</head>
<body>
<div class="card">
  <div class="icon">✅</div>
  <div class="title">Consent Already Submitted</div>
  <div class="sub">
    ' . $consentName . ', you have already confirmed your agreement.<br>
    Our technician is proceeding with your installation.
  </div>
  <div class="detail-box">
    <div><strong>Task ID:</strong> ' . htmlspecialchars($task['task_id']??'') . '</div>
    <div><strong>Service:</strong> ' . htmlspecialchars($task['device_details']??'GPS Installation') . '</div>
    <div><strong>Amount:</strong> ₹' . $consentPrice . '</div>
    <div><strong>Confirmed at:</strong> ' . $consentTime . '</div>
  </div>
  <div class="note">
    This link is now inactive. For help call <strong>9849849824</strong> or email <a href='mailto:sales@bharatgps.com'>sales@bharatgps.com</a>
  </div>
</div>
</body>
</html>');
}

$submitted = false;

// Handle form POST
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $cName    = trim($_POST['c_name']    ?? '');
    $cMobile  = trim($_POST['c_mobile']  ?? '');
    $chkTerms = isset($_POST['chk_terms']);
    $chkPay   = isset($_POST['chk_pay']);

    if(!$cName || !$cMobile){
        $error = 'Please confirm your name and mobile number.';
    } elseif(!$chkTerms){
        $error = 'Please read and accept the Terms & Conditions.';
    } elseif(!$chkPay){
        $error = 'Please confirm your payment commitment.';
    } else {
        $now = date('Y-m-d H:i:s');

        // Save consent to DB
        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN consent_token VARCHAR(64) DEFAULT NULL")->execute(); } catch(Exception $e){}
        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_at DATETIME DEFAULT NULL")->execute(); } catch(Exception $e){}
        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_name VARCHAR(200) DEFAULT NULL")->execute(); } catch(Exception $e){}
        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_mobile VARCHAR(20) DEFAULT NULL")->execute(); } catch(Exception $e){}

        $pdo->prepare("UPDATE tasks SET customer_consent_at=?, customer_consent_name=?, customer_consent_mobile=? WHERE id=?")
            ->execute([$now, $cName, $cMobile, $task['id']]);

        // Log in activity
        $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 0, ?, 'system')")
            ->execute([$task['id'], "✅ Customer consent received — {$cName} ({$cMobile}) agreed to T&C and payment of ₹" . number_format(floatval($task['price_to_collect']),0) . " at {$now}"]);

        // Notify admins & tech that consent received
        try {
            $admins = $pdo->query("SELECT name, email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email != ''")->fetchAll();
            $techStmt = $pdo->prepare("SELECT name, email FROM users WHERE id=?");
            $techStmt->execute([$task['assigned_to']]);
            $tech = $techStmt->fetch();

            $notifyContent = '
            <div style="background:#e8f5ec;border:2px solid #1a7a3a;border-radius:8px;padding:16px;margin-bottom:16px">
                <div style="font-size:15px;font-weight:800;color:#1a7a3a;margin-bottom:6px">✅ Customer Consent Received</div>
                <div style="font-size:13px;color:#1a1f2e">Customer has agreed to Terms &amp; Conditions and payment commitment.</div>
            </div>
            <div style="background:#f7f8fa;border-radius:8px;padding:14px;font-size:13px;line-height:2">
                <div><strong>Task:</strong> ' . $task['task_id'] . '</div>
                <div><strong>Customer:</strong> ' . htmlspecialchars($cName) . '</div>
                <div><strong>Mobile:</strong> ' . htmlspecialchars($cMobile) . '</div>
                <div><strong>Service:</strong> ' . htmlspecialchars($task['device_details'] ?? 'GPS Service') . '</div>
                <div><strong>Amount Committed:</strong> ₹' . number_format(floatval($task['price_to_collect']),0) . '</div>
                <div><strong>Time:</strong> ' . date('d M Y, h:i A', strtotime($now)) . '</div>
            </div>
            <p style="font-size:13px;color:#1a7a3a;font-weight:700;margin-top:14px">✅ Technician can now proceed with installation.</p>';

            foreach($admins as $admin){
                sendMail($admin['email'], $admin['name'],
                    '✅ Consent Received — ' . $task['task_id'] . ' | ' . $cName,
                    emailTemplate($notifyContent));
            }
            if($tech && $tech['email']){
                sendMail($tech['email'], $tech['name'],
                    '✅ Customer Agreed — Proceed with Installation — ' . $task['task_id'],
                    emailTemplate($notifyContent));
            }
        } catch(Exception $e){ error_log('Consent notify: '.$e->getMessage()); }

        $submitted = true;
    }
}

$price    = number_format(floatval($task['price_to_collect'] ?? 0), 0);
$service  = htmlspecialchars($task['device_details'] ?? 'GPS Installation');
$customer = htmlspecialchars($task['customer_name'] ?? '');
$taskId   = htmlspecialchars($task['task_id'] ?? '');
$techName = htmlspecialchars($task['tech_name'] ?? 'BharatGPS Technician');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>BharatGPS — Service Consent</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#1a1f2e;min-height:100vh}
.header{background:linear-gradient(135deg,#0E5C5C,#137272);padding:12px 16px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:10}
.header-flag{display:flex;flex-direction:column;gap:2px}
.header-flag span{display:block;width:14px;height:3px;border-radius:2px}
.header-flag span:nth-child(1){background:#ff9933}
.header-flag span:nth-child(2){background:#fff}
.header-flag span:nth-child(3){background:#138808}
.header-title{color:#fff;font-size:15px;font-weight:800}
.header-sub{color:rgba(255,255,255,.55);font-size:11px}
.container{max-width:520px;margin:0 auto;padding:16px}
.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden}
.card-hd{padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px}
.card-hd-icon{font-size:18px}
.card-hd-title{font-size:13px;font-weight:800;color:#1a1f2e}
.card-body{padding:14px 16px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f0f2f5;font-size:13px}
.info-row:last-child{border-bottom:none}
.info-lbl{color:#4a5568;font-weight:500}
.info-val{font-weight:700;color:#1a1f2e;text-align:right}
.price-box{background:#e8f5ec;border:2px solid #1a7a3a;border-radius:8px;padding:14px 16px;text-align:center;margin:12px 0}
.price-label{font-size:11px;font-weight:700;color:#1a7a3a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.price-amount{font-size:28px;font-weight:900;color:#1a7a3a}
.price-mode{font-size:12px;color:#2d6a4f;margin-top:3px}
/* T&C */
.tnc-box{background:#f7f8fa;border:1px solid #e2e8f0;border-radius:8px;padding:14px;max-height:260px;overflow-y:auto;font-size:12px;line-height:1.8;color:#4a5568}
.tnc-box h4{font-size:12px;font-weight:800;color:#1a3a6b;margin:10px 0 4px;text-transform:uppercase;letter-spacing:.4px}
.tnc-box h4:first-child{margin-top:0}
.tnc-box ul{padding-left:16px;margin:4px 0}
.tnc-box ul li{margin-bottom:3px}
.tnc-box p{margin-bottom:6px}
/* Form */
.form-group{margin-bottom:12px}
.form-group label{display:block;font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.form-group input[type=text],
.form-group input[type=tel]{width:100%;padding:10px 12px;border:1.5px solid #d0d5dd;border-radius:7px;font-size:14px;outline:none;transition:border .2s}
.form-group input:focus{border-color:#1a3a6b}
/* Checkboxes */
.chk-item{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:8px;margin-bottom:10px;cursor:pointer;transition:all .15s}
.chk-item:hover{border-color:#1a3a6b;background:#f0f4ff}
.chk-item input[type=checkbox]{width:20px;height:20px;flex-shrink:0;margin-top:1px;accent-color:#1a3a6b;cursor:pointer}
.chk-item label{font-size:13px;color:#1a1f2e;line-height:1.5;cursor:pointer;font-weight:500}
.chk-item label strong{color:#1a3a6b}
/* Submit */
.btn-submit{width:100%;padding:15px;background:linear-gradient(135deg,#1a7a3a,#22c55e);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:800;cursor:pointer;letter-spacing:.3px;box-shadow:0 4px 12px rgba(26,122,58,.3)}
.btn-submit:active{opacity:.88}
.error-box{background:#fdecea;border:1.5px solid #c0392b;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#c0392b;font-weight:600}
/* Success */
.success-wrap{text-align:center;padding:32px 16px}
.success-icon{font-size:56px;margin-bottom:14px}
.success-title{font-size:22px;font-weight:800;color:#1a7a3a;margin-bottom:8px}
.success-sub{font-size:14px;color:#4a5568;line-height:1.7}
.footer{text-align:center;padding:20px;font-size:11px;color:#8a9ab0}
</style>
</head>
<body>

<div class="header">
  <img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" alt="BharatGPS" style="height:36px;width:auto;flex-shrink:0">
  <div>
    <div class="header-title">🛰 BharatGPS</div>
    <div class="header-sub">Service Consent & Payment Confirmation</div>
  </div>
</div>

<div class="container">

<?php if($submitted): ?>

  <div class="success-wrap">
    <div class="success-icon">✅</div>
    <div class="success-title">Thank You, <?= $customer ?>!</div>
    <div class="success-sub">
      Your consent has been confirmed and our technician has been notified.<br><br>
      <strong>Installation will now proceed.</strong><br><br>
      Please ensure your vehicle is available and accessible.<br><br>
      <strong>Task ID: <?= $taskId ?></strong><br>
      For any help call <strong>9849849824</strong>
    </div>
  </div>

<?php else: ?>

  <!-- SERVICE SUMMARY -->
  <div class="card">
    <div class="card-hd">
      <span class="card-hd-icon">📋</span>
      <div class="card-hd-title">Service Request — <?= $taskId ?></div>
    </div>
    <div class="card-body">
      <div class="info-row"><span class="info-lbl">Customer</span><span class="info-val"><?= $customer ?></span></div>
      <div class="info-row"><span class="info-lbl">Service</span><span class="info-val"><?= $service ?></span></div>
      <div class="info-row"><span class="info-lbl">Technician</span><span class="info-val"><?= $techName ?></span></div>
      <div class="info-row"><span class="info-lbl">Location</span><span class="info-val"><?= htmlspecialchars($task['location'] ?? '–') ?></span></div>
    </div>
  </div>

  <!-- PAYMENT COMMITMENT -->
  <div class="card">
    <div class="card-hd">
      <span class="card-hd-icon">💰</span>
      <div class="card-hd-title">Payment Confirmation</div>
    </div>
    <div class="card-body">
      <div class="price-box">
        <div class="price-label">Amount to be Paid</div>
        <div class="price-amount">₹<?= $price ?></div>
        <?php if(!empty($task['payment_mode'])): ?>
        <div class="price-mode">Payment Mode: <?= htmlspecialchars($task['payment_mode']) ?></div>
        <?php endif; ?>
      </div>
      <p style="font-size:12px;color:#4a5568;line-height:1.7;margin-top:8px">
        This amount has been agreed upon before the installation. As per our 
        <strong>Payment Terms & Recovery Rights</strong>, failure to pay after 
        installation gives BharatGPS the right to recover the GPS device.
      </p>
    </div>
  </div>

  <!-- TERMS & CONDITIONS -->
  <div class="card">
    <div class="card-hd">
      <span class="card-hd-icon">📄</span>
      <div class="card-hd-title">Terms & Conditions — Please Read</div>
    </div>
    <div class="card-body">
      <div class="tnc-box">
        <h4>Installation and Use</h4>
        <ul>
          <li>The Bharat GPS Tracker device must be installed by an authorized Bharat GPS Tracker technician.</li>
          <li>The GPS tracker must not be moved, relocated, or disconnected without prior notification to Bharat GPS Customer Support.</li>
          <li>The device is designed exclusively for tracking vehicles/objects to safeguard against theft.</li>
        </ul>

        <h4>Warranty Voidance</h4>
        <ul>
          <li>Warranty will be void if the GPS tracker has been relocated, existing wires modified, or any unauthorized changes made by the owner.</li>
          <li>If the GPS device malfunctions after troubleshooting, replacement will take 3–4 working days.</li>
        </ul>

        <h4>Engine Cut GPS Installation</h4>
        <p>During Engine Cut GPS installation, the IGNITION wire will be cut and connected to the GPS Relay. Bharat GPS or its technicians will <strong>not be responsible</strong> for any issues arising from revocation of installation after wire cutting.</p>

        <h4>Warranty Exclusions</h4>
        <ul>
          <li>External damage to the GPS device</li>
          <li>Unauthorized modification or relocation</li>
          <li>Burn or damage to existing power cables/wires</li>
          <li>Damage due to liquid/moisture penetration</li>
          <li>Loss or theft of the GPS device</li>
        </ul>

        <h4>Maintenance & Troubleshooting</h4>
        <ul>
          <li>Services performed only at business locations and Authorized Service Centers.</li>
          <li>Owner must ensure vehicle is available before technician arrives.</li>
          <li>If technician arrives and vehicle is unavailable, owner is responsible for applicable service charge.</li>
        </ul>

        <h4>Limitation of Liability</h4>
        <ul>
          <li>Bharat GPS is not responsible for vehicle loss post-installation.</li>
          <li>The device is not waterproof — owner responsible for moisture protection.</li>
          <li>Technicians will perform installation with adequate safety measures.</li>
        </ul>

        <h4>Refund Policy</h4>
        <ul>
          <li>Device is non-refundable once installed.</li>
          <li>Full support provided if device malfunctions.</li>
          <li>Service revocation may be requested within one month from installation date (device must be in working condition). Refund processed within 2 working days from revoke approval.</li>
        </ul>

        <h4>Payment Terms & Recovery Rights</h4>
        <p>In the event a customer fails to make payment after installation or as per agreed payment terms, <strong>Bharat GPS reserves the right to recover the GPS device</strong> to ensure compliance with the financial agreement.</p>
      </div>
    </div>
  </div>

  <!-- CONSENT FORM -->
  <div class="card">
    <div class="card-hd">
      <span class="card-hd-icon">✍️</span>
      <div class="card-hd-title">Your Confirmation</div>
    </div>
    <div class="card-body">
      <?php if($error): ?>
      <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Your Full Name *</label>
          <input type="text" name="c_name" value="<?= $customer ?>" placeholder="Full name" required>
        </div>
        <div class="form-group">
          <label>Your Mobile Number *</label>
          <input type="tel" name="c_mobile" value="<?= htmlspecialchars($task['contact_number'] ?? '') ?>" placeholder="Mobile number" required>
        </div>

        <div class="chk-item" onclick="this.querySelector('input').click()">
          <input type="checkbox" name="chk_terms" id="chk_terms" <?= isset($_POST['chk_terms'])?'checked':'' ?>>
          <label for="chk_terms">I have <strong>read and understood</strong> all the Terms &amp; Conditions above, including the Warranty Policy, Installation terms, Refund Policy, and Payment Recovery Rights of BharatGPS.</label>
        </div>

        <div class="chk-item" onclick="this.querySelector('input').click()">
          <input type="checkbox" name="chk_pay" id="chk_pay" <?= isset($_POST['chk_pay'])?'checked':'' ?>>
          <label for="chk_pay">I confirm that I will pay <strong>₹<?= $price ?></strong><?php if(!empty($task['payment_mode'])): ?> via <strong><?= htmlspecialchars($task['payment_mode']) ?></strong><?php endif; ?> <strong>immediately after installation</strong>.</label>
        </div>

        <button type="submit" class="btn-submit" id="consent-submit-btn" onclick="handleConsentSubmit(this)">
          ✅ I Agree — Proceed with Installation
        </button>
      </form>

      <p style="font-size:11px;color:#8a9ab0;text-align:center;margin-top:12px;line-height:1.6">
        By submitting this form, you provide your digital consent to the above terms.<br>
        This is legally binding. Timestamp and details will be recorded.
      </p>
    </div>
  </div>

<?php endif; ?>

  <div class="footer">
    BharatGPS · 9849849824 · sales@bharatgps.com<br>
    Task <?= $taskId ?> · <?= date('d M Y') ?>
  </div>
</div>
</body>
</html>
