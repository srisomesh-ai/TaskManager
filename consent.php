<?php
// BharatGPS — Customer Consent Page (standalone)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = trim($_GET['token'] ?? '');
if(!$token) die('<p style="font-family:sans-serif;padding:40px;color:red">Invalid link.</p>');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=u943205660_bharatgps;charset=utf8mb4',
        'u943205660_bharatgps','kTrV>Le6+',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch(Exception $e){
    die('<p style="font-family:sans-serif;padding:40px;color:red">Database error. Call 9849849824.</p>');
}

$s = $pdo->prepare("SELECT t.*, u.name AS tech_name, u.email AS tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.consent_token=?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task){
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BharatGPS</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:12px;padding:32px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:40px;margin-bottom:12px">❌</div>
<h2 style="color:#c0392b;margin-bottom:8px">Link Expired or Invalid</h2>
<p style="color:#4a5568;font-size:14px">This consent link is no longer valid.<br>Please contact your technician or call <strong>9849849824</strong>.</p>
</div></body></html>');
}

// ── Already consented — show confirmed page ───────────────────────────────
if(!empty($task['customer_consent_at'])){
    $dt    = date('d M Y, h:i A', strtotime($task['customer_consent_at']));
    $cName = htmlspecialchars($task['customer_consent_name'] ?? $task['customer_name'] ?? 'You');
    $price = number_format(floatval($task['price_to_collect']??0),0);
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Already Confirmed</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px}.card{background:#fff;border-radius:14px;padding:32px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}.detail-box{background:#e8f5ec;border:1.5px solid #1a7a3a;border-radius:8px;padding:14px 16px;text-align:left;margin:16px 0;font-size:13px;line-height:2}.detail-box strong{color:#1a7a3a}</style>
</head><body><div class="card">
<div style="font-size:56px;margin-bottom:16px">✅</div>
<div style="font-size:20px;font-weight:800;color:#1a7a3a;margin-bottom:8px">Consent Already Confirmed</div>
<div style="font-size:14px;color:#4a5568;line-height:1.7;margin-bottom:16px"><?=$cName?>, you have already confirmed your agreement.<br>Our technician is proceeding with your installation.</div>
<div class="detail-box">
<div><strong>Task ID:</strong> <?=htmlspecialchars($task['task_id']??'')?></div>
<div><strong>Service:</strong> <?=htmlspecialchars($task['device_details']??'GPS Installation')?></div>
<div><strong>Amount:</strong> ₹<?=$price?></div>
<div><strong>Confirmed at:</strong> <?=$dt?></div>
</div>
<div style="font-size:12px;color:#8a9ab0;line-height:1.6">This link is now inactive.<br>For help call <strong>9849849824</strong></div>
</div></body></html><?php
    exit;
}

$error = '';

// ── Handle POST — save immediately, redirect, email later ─────────────────
if($_SERVER['REQUEST_METHOD']==='POST'){
    $cName   = trim($_POST['c_name']   ?? '');
    $cMobile = trim($_POST['c_mobile'] ?? '');
    $chkT    = isset($_POST['chk_terms']);
    $chkP    = isset($_POST['chk_pay']);

    if(!$cName||!$cMobile)   { $error = 'Please confirm your name and mobile number.'; }
    elseif(!$chkT)            { $error = 'Please read and accept the Terms & Conditions.'; }
    elseif(!$chkP)            { $error = 'Please confirm your payment commitment.'; }
    else {
        $now = date('Y-m-d H:i:s');

        // ── Step 1: Save to DB ───────────────────────────────────────────
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_name VARCHAR(200) DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_mobile VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}

        $pdo->prepare("UPDATE tasks SET customer_consent_at=?,customer_consent_name=?,customer_consent_mobile=? WHERE id=?")
            ->execute([$now,$cName,$cMobile,$task['id']]);
        // Build activity remark based on consent type
        $actMsgs = [
            'troubleshoot' => "✅ Free service consent — {$cName} ({$cMobile}) confirmed vehicle availability. ₹300 if unavailable.",
            'v2v'          => "✅ V2V consent — {$cName} ({$cMobile}) confirmed both vehicles available. Pay ₹".number_format(floatval($task['price_to_collect']??0),0),
            'readding'     => "✅ Re-adding consent — {$cName} ({$cMobile}) confirmed vehicle available. Pay ₹".number_format(floatval($task['price_to_collect']??0),0),
            'remove'       => "✅ GPS removal consent — {$cName} ({$cMobile}) confirmed permanent removal.",
            'demo'         => "✅ Demo consent — {$cName} ({$cMobile}) confirmed availability.",
        ];
        $actRemark = $actMsgs[$consentType] ?? "✅ Customer consent received — {$cName} ({$cMobile}) agreed to T&C and payment of ₹".number_format(floatval($task['price_to_collect']??0),0);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,0,?,'system')")
            ->execute([$task['id'], $actRemark]);

        // ── Step 2: Trigger background email via non-blocking HTTP ───────
        // We call a separate endpoint that handles SMTP — fire and forget
        // This means the customer sees success instantly without waiting for SMTP
        $bgUrl  = 'https://salmon-goldfish-110661.hostingersite.com/api/consent_notify.php';
        $bgData = http_build_query([
            'task_id' => $task['id'],
            'name'    => $cName,
            'mobile'  => $cMobile,
            'time'    => $now,
            'secret'  => 'bgps_notify_2024'
        ]);
        @file_get_contents($bgUrl.'?'.$bgData, false, stream_context_create([
            'http'=>['timeout'=>1, 'ignore_errors'=>true]
        ]));

        // ── Step 3: Redirect to success page ────────────────────────────
        header('Location: ?token='.urlencode($token).'&done=1');
        exit;
    }
}

// ── Show success page if redirected here after save ───────────────────────
if(isset($_GET['done']) && !empty($task['customer_consent_at'])){
    $cName = htmlspecialchars($task['customer_consent_name'] ?? $task['customer_name'] ?? '');
    $taskId = htmlspecialchars($task['task_id']??'');
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Confirmed — BharatGPS</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}.card{background:#fff;border-radius:14px;padding:32px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}</style>
</head><body><div class="card">
<div style="font-size:60px;margin-bottom:16px">✅</div>
<div style="font-size:22px;font-weight:800;color:#1a7a3a;margin-bottom:10px">Thank You, <?=$cName?>!</div>
<div style="font-size:14px;color:#4a5568;line-height:1.8">
  Your consent has been confirmed successfully.<br><br>
  <strong>Our technician will now proceed with the installation.</strong><br><br>
  Please ensure your vehicle is available and accessible.<br><br>
  <strong>Task ID: <?=$taskId?></strong><br><br>
  For any help call <strong>9849849824</strong>
</div>
</div></body></html><?php
    exit;
}

$price   = number_format(floatval($task['price_to_collect']??0),0);
$service = htmlspecialchars($task['device_details']??'GPS Installation');
$cust    = htmlspecialchars($task['customer_name']??'');
$taskId  = htmlspecialchars($task['task_id']??'');
$tech    = htmlspecialchars($task['tech_name']??'BharatGPS Technician');
$payMode = htmlspecialchars($task['payment_mode']??'');
$mobile  = htmlspecialchars($task['contact_number']??'');

// ── Detect consent type from job ────────────────────────────────────────
$jobRaw = strtolower($task['device_details']??'');
if(strpos($jobRaw,'troubleshoot')!==false || strpos($jobRaw,'offline')!==false){
    $consentType = 'troubleshoot';
} elseif(strpos($jobRaw,'vehicle to vehicle')!==false || strpos($jobRaw,'v2v')!==false){
    $consentType = 'v2v';
} elseif(strpos($jobRaw,'re-adding')!==false || strpos($jobRaw,'re adding')!==false || strpos($jobRaw,'readd')!==false){
    $consentType = 'readding';
} elseif(strpos($jobRaw,'only remove')!==false || strpos($jobRaw,'remove only')!==false){
    $consentType = 'remove';
} elseif(strpos($jobRaw,'demonstration')!==false || strpos($jobRaw,'demo')!==false){
    $consentType = 'demo';
} else {
    $consentType = 'installation'; // default GPS installation
}
$isFreeService = in_array($consentType, ['troubleshoot','remove','demo']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta http-equiv="Cache-Control" content="no-store">
<title>BharatGPS — Service Consent</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#1a1f2e;min-height:100vh}
.header{background:linear-gradient(135deg,#0E5C5C,#137272);padding:14px 16px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:10}
.header img{height:38px;width:auto;background:#fff;padding:5px 10px;border-radius:7px}
.header-text{color:#fff}
.header-title{font-size:15px;font-weight:800}
.header-sub{font-size:11px;opacity:.65;margin-top:2px}
.container{max-width:520px;margin:0 auto;padding:14px}
.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden}
.card-hd{padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;color:#1a1f2e}
.card-body{padding:14px 16px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f0f2f5;font-size:13px}
.info-row:last-child{border-bottom:none}
.info-lbl{color:#4a5568;font-weight:500}
.info-val{font-weight:700;color:#1a1f2e;text-align:right}
.price-box{background:#e8f5ec;border:2px solid #1a7a3a;border-radius:8px;padding:14px 16px;text-align:center;margin:10px 0}
.price-label{font-size:11px;font-weight:700;color:#1a7a3a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.price-amount{font-size:28px;font-weight:900;color:#1a7a3a}
.price-mode{font-size:12px;color:#2d6a4f;margin-top:3px}
.tnc-box{background:#f7f8fa;border:1px solid #e2e8f0;border-radius:8px;padding:14px;max-height:260px;overflow-y:auto;font-size:12px;line-height:1.8;color:#4a5568}
.tnc-box h4{font-size:12px;font-weight:800;color:#0E5C5C;margin:10px 0 4px;text-transform:uppercase;letter-spacing:.4px}
.tnc-box h4:first-child{margin-top:0}
.tnc-box ul{padding-left:16px;margin:4px 0}
.tnc-box ul li{margin-bottom:3px}
.tnc-box p{margin-bottom:6px}
.form-group{margin-bottom:12px}
.form-group label{display:block;font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.form-group input{width:100%;padding:11px 13px;border:1.5px solid #d0d5dd;border-radius:8px;font-size:14px;outline:none;transition:border .2s;font-family:inherit}
.form-group input:focus{border-color:#0E5C5C}
.chk-item{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:8px;margin-bottom:10px;cursor:pointer;transition:all .15s}
.chk-item:hover{border-color:#0E5C5C;background:#f0f7f7}
.chk-item input[type=checkbox]{width:20px;height:20px;flex-shrink:0;margin-top:1px;accent-color:#0E5C5C;cursor:pointer}
.chk-item label{font-size:13px;color:#1a1f2e;line-height:1.5;cursor:pointer;font-weight:500}
.chk-item label strong{color:#0E5C5C}
.btn-submit{width:100%;padding:15px;background:linear-gradient(135deg,#0E5C5C,#137272);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:800;cursor:pointer;letter-spacing:.3px;box-shadow:0 4px 12px rgba(14,92,92,.3)}
.btn-submit:disabled{background:#718096;cursor:not-allowed;box-shadow:none}
.error-box{background:#fdecea;border:1.5px solid #c0392b;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#c0392b;font-weight:600}
.footer{text-align:center;padding:20px;font-size:11px;color:#8a9ab0;line-height:1.8}
</style>
</head>
<body>

<div class="header">
  <img src="https://salmon-goldfish-110661.hostingersite.com/logo.png"
       onerror="this.style.display='none'" alt="BharatGPS">
  <div class="header-text">
    <div class="header-title">BharatGPS Tracker</div>
    <div class="header-sub"><?php
$subtitles = [
  'troubleshoot' => 'Free Service Visit — Vehicle Availability Confirmation',
  'v2v'          => 'Vehicle to Vehicle Change — Consent & Payment',
  'readding'     => 'Re-Adding Service — Consent & Payment',
  'remove'       => 'GPS Removal — Confirmation Required',
  'demo'         => 'Demonstration Visit — Availability Confirmation',
  'installation' => 'Service Consent & Payment Confirmation',
];
echo $subtitles[$consentType] ?? 'Service Consent & Payment Confirmation';
?></div>
  </div>
</div>

<div class="container">

<?php if($error): ?>
<div class="error-box">⚠️ <?=htmlspecialchars($error)?></div>
<?php endif; ?>

<div class="card">
  <div class="card-hd">📋 Service Request — <?=$taskId?></div>
  <div class="card-body">
    <div class="info-row"><span class="info-lbl">Customer</span><span class="info-val"><?=$cust?></span></div>
    <div class="info-row"><span class="info-lbl">Service</span><span class="info-val"><?=$service?></span></div>
    <div class="info-row"><span class="info-lbl">Technician</span><span class="info-val"><?=$tech?></span></div>
    <div class="info-row"><span class="info-lbl">Location</span><span class="info-val"><?=htmlspecialchars($task['location']??'–')?></span></div>
  </div>
</div>

<?php if($consentType==='troubleshoot'): ?>
<div class="card">
  <div class="card-hd" style="background:#e8f5ec;color:#1a7a3a">🔧 Troubleshoot / Offline Fix</div>
  <div class="card-body">
    <div class="price-box" style="background:#e8f5ec;border-color:#1a7a3a">
      <div class="price-label" style="color:#1a7a3a">Service Charge</div>
      <div class="price-amount" style="color:#1a7a3a">FREE</div>
      <div class="price-mode" style="color:#2d6a4f">Technician will bring your GPS back online</div>
    </div>
    <div style="background:#fff3e0;border:1.5px solid #e07b00;border-radius:8px;padding:12px 14px;margin-top:10px;font-size:12px;color:#8a5a00;line-height:1.8">
      ⚠️ <strong>Important:</strong> This is a free service visit. If our technician arrives and your <strong>vehicle is not available</strong>, a <strong>₹300 visit charge</strong> will be applicable.
    </div>
  </div>
</div>
<?php elseif($consentType==='v2v'): ?>
<div class="card">
  <div class="card-hd">🚗➡️🚗 Vehicle to Vehicle Change</div>
  <div class="card-body">
    <div style="background:#e8f0fb;border:1.5px solid #1a56a0;border-radius:8px;padding:12px 14px;margin-bottom:10px;font-size:12px;color:#1a3a6b;line-height:1.8">
      📌 Technician will <strong>remove GPS from old vehicle</strong> and <strong>reinstall on new vehicle</strong>. Please ensure <strong>both vehicles are available</strong>.
    </div>
    <div class="price-box">
      <div class="price-label">V2V Change Charge</div>
      <div class="price-amount">₹<?=$price?></div>
      <?php if($payMode): ?><div class="price-mode">Payment Mode: <?=$payMode?></div><?php endif; ?>
    </div>
  </div>
</div>
<?php elseif($consentType==='readding'): ?>
<div class="card">
  <div class="card-hd">🔄 Re-Adding Service</div>
  <div class="card-body">
    <div style="background:#e8f0fb;border:1.5px solid #1a56a0;border-radius:8px;padding:12px 14px;margin-bottom:10px;font-size:12px;color:#1a3a6b;line-height:1.8">
      📌 Technician will <strong>re-add your vehicle to the GPS server</strong>. Please ensure your vehicle is <strong>available, running and accessible</strong>.
    </div>
    <div class="price-box">
      <div class="price-label">Re-Adding Service Charge</div>
      <div class="price-amount">₹<?=$price?></div>
      <?php if($payMode): ?><div class="price-mode">Payment Mode: <?=$payMode?></div><?php endif; ?>
    </div>
  </div>
</div>
<?php elseif($consentType==='remove'): ?>
<div class="card">
  <div class="card-hd" style="background:#fdecea;color:#c0392b">🔴 GPS Device Removal</div>
  <div class="card-body">
    <div class="price-box" style="background:#e8f5ec;border-color:#1a7a3a">
      <div class="price-label" style="color:#1a7a3a">Removal Charge</div>
      <div class="price-amount" style="color:#1a7a3a">FREE</div>
    </div>
    <div style="background:#fdecea;border:1.5px solid #c0392b;border-radius:8px;padding:12px 14px;margin-top:10px;font-size:12px;color:#c0392b;line-height:1.8">
      ⚠️ <strong>Warning:</strong> Once removed, the GPS device <strong>cannot be reinstalled</strong> without a new order. The device will be taken back. This action is <strong>permanent</strong>.
    </div>
  </div>
</div>
<?php elseif($consentType==='demo'): ?>
<div class="card">
  <div class="card-hd" style="background:#f0eaf8;color:#5b2d8e">📱 Demonstration Visit</div>
  <div class="card-body">
    <div class="price-box" style="background:#e8f5ec;border-color:#1a7a3a">
      <div class="price-label" style="color:#1a7a3a">Demonstration Charge</div>
      <div class="price-amount" style="color:#1a7a3a">FREE</div>
      <div class="price-mode" style="color:#2d6a4f">No installation — demonstration only</div>
    </div>
    <div style="background:#f0eaf8;border:1.5px solid #5b2d8e;border-radius:8px;padding:12px 14px;margin-top:10px;font-size:12px;color:#5b2d8e;line-height:1.8">
      📌 This is a <strong>demonstration only</strong>. No GPS will be installed. There is <strong>no obligation to purchase</strong>.
    </div>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-hd">💰 Payment Confirmation</div>
  <div class="card-body">
    <div class="price-box">
      <div class="price-label">Amount to be Paid</div>
      <div class="price-amount">₹<?=$price?></div>
      <?php if($payMode): ?><div class="price-mode">Payment Mode: <?=$payMode?></div><?php endif; ?>
    </div>
    <p style="font-size:12px;color:#4a5568;line-height:1.7;margin-top:8px">
      This amount has been agreed upon before the installation. As per our
      <strong>Payment Terms &amp; Recovery Rights</strong>, failure to pay after installation gives BharatGPS the right to recover the GPS device.
    </p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-hd">📄 Terms & Conditions — Please Read</div>
  <div class="card-body">
    <div class="tnc-box">
      <h4>Installation and Use</h4>
      <ul>
        <li>The BharatGPS device must be installed by an authorized BharatGPS technician.</li>
        <li>The GPS tracker must not be moved, relocated, or disconnected without prior notification to BharatGPS Customer Support.</li>
        <li>The device is designed exclusively for tracking vehicles/objects to safeguard against theft.</li>
      </ul>
      <h4>Warranty Voidance</h4>
      <ul>
        <li>Warranty will be void if the GPS tracker has been relocated, existing wires modified, or any unauthorized changes made.</li>
        <li>If the GPS device malfunctions after troubleshooting, replacement will take 3–4 working days.</li>
      </ul>
      <h4>Engine Cut GPS Installation</h4>
      <p>During Engine Cut GPS installation, the IGNITION wire will be cut and connected to the GPS Relay. BharatGPS or its technicians will <strong>not be responsible</strong> for any issues arising from revocation of installation after wire cutting.</p>
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
        <li>BharatGPS is not responsible for vehicle loss post-installation.</li>
        <li>The device is not waterproof — owner responsible for moisture protection.</li>
      </ul>
      <h4>Refund Policy</h4>
      <ul>
        <li>Device is non-refundable once installed.</li>
        <li>Service revocation may be requested within one month from installation date. Refund processed within 2 working days from revoke approval.</li>
      </ul>
      <h4>Payment Terms & Recovery Rights</h4>
      <p>In the event a customer fails to make payment, <strong>BharatGPS reserves the right to recover the GPS device</strong> to ensure compliance with the financial agreement.</p>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-hd">✍️ Your Confirmation</div>
  <div class="card-body">
    <form method="POST" id="consent-form">
      <div class="form-group">
        <label>Your Full Name *</label>
        <input type="text" name="c_name" id="c_name" value="<?=$cust?>" placeholder="Full name" required>
      </div>
      <div class="form-group">
        <label>Your Mobile Number *</label>
        <input type="tel" name="c_mobile" id="c_mobile" value="<?=$mobile?>" placeholder="Mobile number" required>
      </div>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_terms" id="chk_terms">
        <label for="chk_terms">I have <strong>read and understood</strong> all the Terms &amp; Conditions above, including Warranty Policy, Installation terms, Refund Policy, and Payment Recovery Rights.</label>
      </div>
      <?php if($consentType==='troubleshoot'): ?>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay">
        <label for="chk_pay">I confirm my <strong>vehicle will be available and accessible</strong> when the technician arrives. I understand that if the vehicle is unavailable, a <strong>₹300 visit charge</strong> will apply.</label>
      </div>
      <?php elseif($consentType==='v2v'): ?>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay">
        <label for="chk_pay">I confirm <strong>both vehicles will be available</strong> and I will pay <strong>₹<?=$price?></strong><?php if($payMode): ?> via <strong><?=$payMode?></strong><?php endif; ?> to the technician.</label>
      </div>
      <?php elseif($consentType==='readding'): ?>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay">
        <label for="chk_pay">I confirm my <strong>vehicle will be available and running</strong> and I will pay <strong>₹<?=$price?></strong><?php if($payMode): ?> via <strong><?=$payMode?></strong><?php endif; ?> to the technician.</label>
      </div>
      <?php elseif($consentType==='remove'): ?>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay">
        <label for="chk_pay">I confirm my <strong>vehicle will be available</strong> for GPS removal and I understand this is <strong>permanent</strong> — the device will be taken back.</label>
      </div>
      <?php elseif($consentType==='demo'): ?>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay">
        <label for="chk_pay">I confirm I will be <strong>available at the location</strong> for the demonstration. I understand <strong>no GPS will be installed</strong> during this visit.</label>
      </div>
      <?php else: ?>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay">
        <label for="chk_pay">I confirm that I will pay <strong>₹<?=$price?></strong><?php if($payMode): ?> via <strong><?=$payMode?></strong><?php endif; ?> <strong>immediately after installation</strong>.</label>
      </div>
      <?php endif; ?>
      <button type="button" id="consent-btn" class="btn-submit" onclick="submitConsent()">
        <?php
        $btnLabels = [
          'troubleshoot' => '✅ I Agree — Vehicle Will Be Available',
          'v2v'          => '✅ I Agree — Both Vehicles Will Be Available',
          'readding'     => '✅ I Agree — Confirm Re-Adding Service',
          'remove'       => '✅ I Agree — Confirm GPS Removal',
          'demo'         => '✅ I Agree — I Will Be Available for Demo',
        ];
        echo $btnLabels[$consentType] ?? '✅ I Agree — Confirm &amp; Proceed for Installation';
        ?>
      </button>
      <p style="font-size:11px;color:#8a9ab0;text-align:center;margin-top:12px;line-height:1.6">
        By submitting this form, you provide your digital consent.<br>
        Timestamp and details will be recorded.
      </p>
    </form>
  </div>
</div>

<div class="footer">
  BharatGPS Tracker · 9849849824 · sales@bharatgps.com<br>
  <a href="https://bharatgpstracker.com" style="color:#0E5C5C">bharatgpstracker.com</a><br>
  Task <?=$taskId?> · <?=date('d M Y')?>
</div>
</div>

<script>
function submitConsent(){
  var name   = document.getElementById('c_name').value.trim();
  var mobile = document.getElementById('c_mobile').value.trim();
  var terms  = document.getElementById('chk_terms').checked;
  var pay    = document.getElementById('chk_pay').checked;

  if(!name || !mobile){
    alert('Please enter your name and mobile number.');
    return;
  }
  if(!terms){
    alert('Please read and accept the Terms & Conditions.');
    return;
  }
  if(!pay){
    alert('Please confirm your payment commitment.');
    return;
  }

  // Show loading immediately
  var btn = document.getElementById('consent-btn');
  btn.disabled = true;
  btn.textContent = '⏳ Submitting — Please wait…';

  // Submit the form
  document.getElementById('consent-form').submit();
}
</script>
</body>
</html>
