<?php
// BharatGPS — Customer Consent Page (standalone, no heavy dependencies)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=UTF-8');

$token = trim($_GET['token'] ?? '');
if(!$token) die('<p style="font-family:sans-serif;padding:40px;color:red">Invalid link.</p>');

// Direct DB connection — no migrations, no overhead
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=u943205660_bharatgps;charset=utf8mb4',
        'u943205660_bharatgps', 'kTrV>Le6+',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
} catch(Exception $e){
    die('<p style="font-family:sans-serif;padding:40px;color:red">Database error. Please call 9849849824.</p>');
}

// Fetch task
$s = $pdo->prepare("SELECT t.*, u.name AS tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.consent_token=?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task){
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BharatGPS</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:12px;padding:32px;max-width:400px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:40px;margin-bottom:12px">❌</div>
<h2 style="color:#c0392b;margin-bottom:8px">Link Expired</h2>
<p style="color:#4a5568;font-size:14px">This link is no longer valid.<br>Please contact your technician or call <strong>9849849824</strong>.</p>
</div></body></html>');
}

// Already consented
if(!empty($task['customer_consent_at'])){
    $dt    = date('d M Y, h:i A', strtotime($task['customer_consent_at']));
    $price = number_format(floatval($task['price_to_collect']??0),0);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Already Confirmed</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:14px;padding:32px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)">
<div style="font-size:56px;margin-bottom:16px">✅</div>
<div style="font-size:20px;font-weight:800;color:#1a7a3a;margin-bottom:8px">Already Confirmed</div>
<div style="font-size:14px;color:#4a5568;line-height:1.7;margin-bottom:16px">You have already confirmed your agreement.<br>Our technician is proceeding with your installation.</div>
<div style="background:#f0f2f5;border-radius:8px;padding:14px;text-align:left;font-size:13px;line-height:2">
<div><strong>Task:</strong> '.htmlspecialchars($task['task_id']??'').'</div>
<div><strong>Service:</strong> '.htmlspecialchars($task['device_details']??'GPS').'</div>
<div><strong>Amount:</strong> ₹'.$price.'</div>
<div><strong>Confirmed:</strong> '.$dt.'</div>
</div>
<div style="margin-top:14px;font-size:12px;color:#8a9ab0">This link is now inactive.<br>For help call <strong>9849849824</strong></div>
</div></body></html>');
}

$error     = '';
$submitted = false;

// Handle form POST
if($_SERVER['REQUEST_METHOD']==='POST'){
    $cName   = trim($_POST['c_name']   ?? '');
    $cMobile = trim($_POST['c_mobile'] ?? '');
    $chkT    = isset($_POST['chk_terms']);
    $chkP    = isset($_POST['chk_pay']);

    if(!$cName || !$cMobile){ $error = 'Please enter your name and mobile number.'; }
    elseif(!$chkT){ $error = 'Please accept the Terms & Conditions.'; }
    elseif(!$chkP){ $error = 'Please confirm your payment commitment.'; }
    else {
        $now = date('Y-m-d H:i:s');
        try {
            $pdo->prepare("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_at DATETIME DEFAULT NULL")->execute();
            $pdo->prepare("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_name VARCHAR(200) DEFAULT NULL")->execute();
            $pdo->prepare("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_mobile VARCHAR(20) DEFAULT NULL")->execute();
        } catch(Exception $e){}

        $pdo->prepare("UPDATE tasks SET customer_consent_at=?, customer_consent_name=?, customer_consent_mobile=? WHERE id=?")
            ->execute([$now, $cName, $cMobile, $task['id']]);

        $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 0, ?, 'system')")
            ->execute([$task['id'], "✅ Customer consent received — {$cName} ({$cMobile}) agreed to T&C and payment of ₹" . number_format(floatval($task['price_to_collect']??0),0)]);

        // Notify admin + tech by email (simple direct mail)
        try {
            $admins = $pdo->query("SELECT name,email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email!='' AND is_active=1")->fetchAll();
            $techR  = $pdo->prepare("SELECT name,email FROM users WHERE id=?");
            $techR->execute([$task['assigned_to']??0]);
            $tech = $techR->fetch();

            $subject = "✅ Consent Received — " . $task['task_id'] . " | " . $task['customer_name'];
            $body    = "Customer " . $task['customer_name'] . " has confirmed T&C and payment of Rs." .
                       number_format(floatval($task['price_to_collect']??0),0) .
                       " for task " . $task['task_id'] . ". Name: $cName, Mobile: $cMobile. Time: $now";
            $headers = "From: BharatGPS Task Manager <info@bharatgps.com>\r\nContent-Type: text/plain; charset=UTF-8";

            foreach($admins as $a){
                @mail($a['email'], $subject, $body, $headers);
            }
            if($tech && !empty($tech['email'])){
                @mail($tech['email'], $subject, $body, $headers);
            }
        } catch(Exception $e){}

        $submitted = true;
    }
}

$price    = number_format(floatval($task['price_to_collect']??0),0);
$payMode  = $task['payment_mode'] ?? 'UPI';
$service  = htmlspecialchars($task['device_details'] ?? 'GPS Installation');
$customer = htmlspecialchars($task['customer_name'] ?? '');
$tech     = htmlspecialchars($task['tech_name'] ?? 'BharatGPS Team');
$taskId   = htmlspecialchars($task['task_id'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta http-equiv="Cache-Control" content="no-store">
<title>Confirm Installation — BharatGPS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#1a1f2e;min-height:100vh}
.header{background:linear-gradient(135deg,#0E5C5C,#137272);padding:14px 20px;display:flex;align-items:center;gap:12px}
.header img{height:40px;width:auto}
.header-text{color:#fff}
.header-title{font-size:15px;font-weight:800}
.header-sub{font-size:11px;opacity:.7;margin-top:2px}
.container{max-width:560px;margin:20px auto;padding:0 14px 40px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden}
.card-hd{padding:13px 16px;border-bottom:1px solid #e9efee;font-size:13px;font-weight:800}
.card-body{padding:14px 16px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.info-item{background:#f3f6f5;border-radius:7px;padding:10px 12px}
.info-lbl{font-size:10px;font-weight:700;color:#8a9a98;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.info-val{font-size:13px;font-weight:700;color:#16201f}
.price-box{background:#e7f7ec;border:2px solid #27ae60;border-radius:10px;padding:16px;text-align:center;margin-bottom:14px}
.price-label{font-size:12px;color:#27ae60;font-weight:700;margin-bottom:4px}
.price-val{font-size:28px;font-weight:900;color:#27ae60}
.price-mode{font-size:12px;color:#55676a;margin-top:4px}
.terms-box{background:#f7f8fa;border-radius:8px;padding:12px 14px;font-size:12px;color:#55676a;line-height:1.7;max-height:160px;overflow-y:auto;margin-bottom:12px;border:1px solid #dde5e4}
.check-row{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px}
.check-row input[type=checkbox]{width:18px;height:18px;margin-top:2px;flex-shrink:0;accent-color:#0E5C5C}
.check-row label{font-size:13px;color:#1a1f2e;font-weight:600;line-height:1.5}
.form-group{margin-bottom:12px}
.form-group label{display:block;font-size:11px;font-weight:700;color:#55676a;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.form-group input{width:100%;padding:12px 14px;border:1.5px solid #dde5e4;border-radius:8px;font-size:15px;outline:none;transition:border .2s;font-family:inherit}
.form-group input:focus{border-color:#0E5C5C}
.error-box{background:#fce9e7;border:1.5px solid #e74c3c;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:#e74c3c;font-weight:700}
.btn{width:100%;padding:15px;border:none;border-radius:10px;font-size:16px;font-weight:800;cursor:pointer;background:#0E5C5C;color:#fff;box-shadow:0 4px 12px rgba(14,92,92,.3)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.success-box{background:#e7f7ec;border:2px solid #27ae60;border-radius:12px;padding:28px 20px;text-align:center}
.footer{text-align:center;font-size:11px;color:#8a9a98;padding:16px;line-height:1.8}
</style>
</head>
<body>

<div class="header">
  <img src="https://salmon-goldfish-110661.hostingersite.com/logo.png"
       onerror="this.style.display='none'" alt="BharatGPS">
  <div class="header-text">
    <div class="header-title">BharatGPS Tracker</div>
    <div class="header-sub">Service Consent & Payment Confirmation</div>
  </div>
</div>

<div class="container">

<?php if($submitted): ?>
<div class="success-box">
  <div style="font-size:52px;margin-bottom:12px">✅</div>
  <div style="font-size:20px;font-weight:800;color:#27ae60;margin-bottom:8px">Thank You, <?=$customer?>!</div>
  <div style="font-size:14px;color:#2d6a4f;line-height:1.7">
    Your confirmation has been received.<br>
    Our technician will begin the installation shortly.<br><br>
    For help call <strong>9849849824</strong>
  </div>
</div>

<?php else: ?>

<!-- Task Summary -->
<div class="card">
  <div class="card-hd">📋 Your Service Request — <?=$taskId?></div>
  <div class="card-body">
    <div class="info-grid">
      <div class="info-item"><div class="info-lbl">Customer</div><div class="info-val"><?=$customer?></div></div>
      <div class="info-item"><div class="info-lbl">Service</div><div class="info-val"><?=$service?></div></div>
      <div class="info-item"><div class="info-lbl">Technician</div><div class="info-val"><?=$tech?></div></div>
      <div class="info-item"><div class="info-lbl">Location</div><div class="info-val"><?=htmlspecialchars($task['location']??'–')?></div></div>
    </div>
  </div>
</div>

<!-- Payment -->
<div class="price-box">
  <div class="price-label">💰 Amount to Pay</div>
  <div class="price-val">₹<?=$price?></div>
  <div class="price-mode">via <?=$payMode?></div>
</div>

<!-- Form -->
<div class="card">
  <div class="card-hd">✍️ Your Confirmation</div>
  <div class="card-body">

    <?php if($error): ?>
    <div class="error-box">⚠️ <?=htmlspecialchars($error)?></div>
    <?php endif; ?>

    <form method="POST" onsubmit="return handleSubmit(this)">
      <div class="form-group">
        <label>Your Full Name *</label>
        <input type="text" name="c_name" value="<?=htmlspecialchars($customer)?>" required>
      </div>
      <div class="form-group">
        <label>Your Mobile Number *</label>
        <input type="tel" name="c_mobile" placeholder="10-digit mobile" required>
      </div>

      <div style="font-size:11px;font-weight:800;color:#55676a;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Terms & Conditions</div>
      <div class="terms-box">
        <strong>BharatGPS Installation Terms</strong><br><br>
        1. The GPS device will be installed by our certified technician.<br>
        2. Installation may take 30–90 minutes depending on vehicle type.<br>
        3. Payment of ₹<?=$price?> is due upon installation completion.<br>
        4. The device remains the property of BharatGPS until full payment is received.<br>
        5. Warranty covers manufacturing defects for 12 months.<br>
        6. Tampering or removal of the device voids warranty.<br>
        7. Monthly/Annual subscription may apply for tracking services.<br>
        8. BharatGPS is not liable for vehicle damage unrelated to installation.<br>
        9. By confirming you agree to our full terms at bharatgpstracker.com
      </div>

      <div class="check-row">
        <input type="checkbox" name="chk_terms" id="chk_terms" required>
        <label for="chk_terms">I have read and agree to the Terms & Conditions above</label>
      </div>
      <div class="check-row" style="margin-bottom:16px">
        <input type="checkbox" name="chk_pay" id="chk_pay" required>
        <label for="chk_pay">I confirm I will pay <strong>₹<?=$price?></strong> via <strong><?=$payMode?></strong> upon installation</label>
      </div>

      <button type="submit" class="btn" id="submit-btn">✅ I Agree — Proceed with Installation</button>
    </form>
  </div>
</div>

<?php endif; ?>

<div class="footer">
  BharatGPS Tracker · 9849849824 · sales@bharatgps.com<br>
  <a href="https://bharatgpstracker.com" style="color:#0E5C5C">bharatgpstracker.com</a>
</div>
</div>

<script>
function handleSubmit(form){
  var btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.textContent = 'Submitting… Please wait';
  return true;
}
</script>
</body>
</html>
