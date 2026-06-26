<?php
// BharatGPS — Customer Consent Page
// Standalone — no require of mailer.php to avoid memory issues
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Content-Type: text/html; charset=UTF-8');

$token = trim($_GET['token'] ?? '');
if(!$token) die('<p style="font-family:sans-serif;padding:40px;color:red">Invalid link.</p>');

// Direct DB — no migrations overhead
try {
    $pdo = new PDO('mysql:host=localhost;dbname=u943205660_bharatgps;charset=utf8mb4',
        'u943205660_bharatgps','kTrV>Le6+',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch(Exception $e){
    die('<p style="font-family:sans-serif;padding:40px;color:red">Database error. Call 9849849824.</p>');
}

$s = $pdo->prepare("SELECT t.*, u.name AS tech_name, u.email AS tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.consent_token=?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task){ die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BharatGPS</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:12px;padding:32px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:40px;margin-bottom:12px">❌</div>
<h2 style="color:#c0392b;margin-bottom:8px">Link Expired or Invalid</h2>
<p style="color:#4a5568;font-size:14px">This consent link is no longer valid.<br>Please contact your technician or call <strong>9849849824</strong>.</p>
</div></body></html>'); }

// Already consented
if(!empty($task['customer_consent_at'])){
    $dt    = date('d M Y, h:i A', strtotime($task['customer_consent_at']));
    $cName = htmlspecialchars($task['customer_consent_name'] ?? $task['customer_name'] ?? 'You');
    $price = number_format(floatval($task['price_to_collect']??0),0);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Already Confirmed</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Segoe UI",sans-serif;background:#f0f2f5;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px}.card{background:#fff;border-radius:14px;padding:32px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}.detail-box{background:#e8f5ec;border:1.5px solid #1a7a3a;border-radius:8px;padding:14px 16px;text-align:left;margin:16px 0;font-size:13px;line-height:2}.detail-box strong{color:#1a7a3a}</style>
</head><body><div class="card">
<div style="font-size:56px;margin-bottom:16px">✅</div>
<div style="font-size:20px;font-weight:800;color:#1a7a3a;margin-bottom:8px">Already Confirmed</div>
<div style="font-size:14px;color:#4a5568;line-height:1.7;margin-bottom:16px">'.$cName.', you have already confirmed your agreement.<br>Our technician is proceeding with your installation.</div>
<div class="detail-box">
<div><strong>Task ID:</strong> '.htmlspecialchars($task['task_id']??'').'</div>
<div><strong>Service:</strong> '.htmlspecialchars($task['device_details']??'GPS Installation').'</div>
<div><strong>Amount:</strong> ₹'.$price.'</div>
<div><strong>Confirmed at:</strong> '.$dt.'</div>
</div>
<div style="font-size:12px;color:#8a9ab0;line-height:1.6">This link is now inactive.<br>For help call <strong>9849849824</strong></div>
</div></body></html>'); }

$error = '';
$submitted = false;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $cName   = trim($_POST['c_name']   ?? '');
    $cMobile = trim($_POST['c_mobile'] ?? '');
    $chkT    = isset($_POST['chk_terms']);
    $chkP    = isset($_POST['chk_pay']);
    if(!$cName||!$cMobile){ $error='Please confirm your name and mobile number.'; }
    elseif(!$chkT){ $error='Please read and accept the Terms & Conditions.'; }
    elseif(!$chkP){ $error='Please confirm your payment commitment.'; }
    else {
        $now = date('Y-m-d H:i:s');
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_name VARCHAR(200) DEFAULT NULL"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS customer_consent_mobile VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
        $pdo->prepare("UPDATE tasks SET customer_consent_at=?,customer_consent_name=?,customer_consent_mobile=? WHERE id=?")
            ->execute([$now,$cName,$cMobile,$task['id']]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,0,?,'system')")
            ->execute([$task['id'],"✅ Customer consent received — {$cName} ({$cMobile}) agreed to T&C and payment of ₹".number_format(floatval($task['price_to_collect']??0),0)." at {$now}"]);
        // Notify via simple SMTP (same credentials as mailer.php)
        try {
            $admins = $pdo->query("SELECT name,email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email!='' AND is_active=1")->fetchAll();
            $tech   = $pdo->prepare("SELECT name,email FROM users WHERE id=?");
            $tech->execute([$task['assigned_to']??0]);
            $tc = $tech->fetch();
            $subj = "=?UTF-8?B?".base64_encode("✅ Consent Received — ".$task['task_id']." | ".$cName)."?=";
            $html = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f0f2f5;padding:20px">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)">
<div style="background:linear-gradient(135deg,#0E5C5C,#137272);padding:18px 24px;text-align:center">
<img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" style="height:50px;width:auto" alt="BharatGPS">
</div>
<div style="padding:24px">
<div style="background:#e8f5ec;border:2px solid #1a7a3a;border-radius:8px;padding:16px;margin-bottom:16px">
<div style="font-size:15px;font-weight:800;color:#1a7a3a;margin-bottom:6px">✅ Customer Consent Received</div>
<div style="font-size:13px;color:#1a1f2e">Customer has agreed to Terms & Conditions and payment commitment. Technician can proceed.</div>
</div>
<table style="width:100%;font-size:13px;border-collapse:collapse">
<tr><td style="padding:6px 0;color:#4a5568;width:140px">Task</td><td style="font-weight:700">'.htmlspecialchars($task['task_id']).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Customer</td><td style="font-weight:700">'.htmlspecialchars($cName).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Mobile</td><td style="font-weight:700">'.htmlspecialchars($cMobile).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Service</td><td style="font-weight:700">'.htmlspecialchars($task['device_details']??'GPS').'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Amount</td><td style="font-weight:800;color:#1a7a3a;font-size:15px">₹'.number_format(floatval($task['price_to_collect']??0),0).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Time</td><td style="font-weight:700">'.date('d M Y, h:i A',strtotime($now)).'</td></tr>
</table>
<p style="font-size:13px;color:#1a7a3a;font-weight:700;margin-top:16px">✅ Technician can now proceed with installation.</p>
</div>
<div style="background:#f7f8fa;padding:14px 24px;text-align:center;font-size:11px;color:#8a9ab0">
BharatGPS Tracker · 9849849824 · sales@bharatgps.com
</div></div></body></html>';
            // Use raw socket SMTP (same as mailer.php sendMail)
            function consentSMTP($to,$toName,$subject,$html){
                $socket = @fsockopen('smtp.gmail.com',587,$errno,$errstr,15);
                if(!$socket) return;
                stream_set_timeout($socket,15);
                $r=function($s){$b='';while(!feof($s)){$l=fgets($s,512);$b.=$l;if(substr($l,3,1)==' ')break;}return $b;};
                $c=function($s,$cmd){fwrite($s,$cmd."\r\n");$b='';while(!feof($s)){$l=fgets($s,512);$b.=$l;if(substr($l,3,1)==' ')break;}return $b;};
                $r($socket);
                $c($socket,"EHLO bharatgps.com");
                $c($socket,"STARTTLS");
                stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $c($socket,"EHLO bharatgps.com");
                $c($socket,"AUTH LOGIN");
                $c($socket,base64_encode('info@bharatgps.com'));
                $c($socket,base64_encode('rxeumqjrhyrzeeye'));
                $c($socket,"MAIL FROM: <info@bharatgps.com>");
                $c($socket,"RCPT TO: <$to>");
                $c($socket,"DATA");
                $msg="From: BharatGPS Task Manager <info@bharatgps.com>\r\nTo: $toName <$to>\r\nReply-To: sales@bharatgps.com\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n".$html."\r\n.";
                $c($socket,$msg);
                $c($socket,"QUIT");
                fclose($socket);
            }
            foreach($admins as $a){ if($a['email']) @consentSMTP($a['email'],$a['name'],$subj,$html); }
            if($tc && !empty($tc['email'])) @consentSMTP($tc['email'],$tc['name'],$subj,$html);
        } catch(Exception $e){}
        $submitted = true;
    }
}

$price   = number_format(floatval($task['price_to_collect']??0),0);
$service = htmlspecialchars($task['device_details']??'GPS Installation');
$cust    = htmlspecialchars($task['customer_name']??'');
$taskId  = htmlspecialchars($task['task_id']??'');
$tech    = htmlspecialchars($task['tech_name']??'BharatGPS Technician');
$payMode = htmlspecialchars($task['payment_mode']??'');
$mobile  = htmlspecialchars($task['contact_number']??'');
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
.header img{height:38px;width:auto}
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
.btn-submit{width:100%;padding:15px;background:linear-gradient(135deg,#0E5C5C,#137272);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:800;cursor:pointer;letter-spacing:.3px;box-shadow:0 4px 12px rgba(14,92,92,.3);transition:opacity .2s}
.btn-submit:active{opacity:.88}
.btn-submit:disabled{background:#718096;cursor:not-allowed;box-shadow:none}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{display:inline-block;width:16px;height:16px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px}
.error-box{background:#fdecea;border:1.5px solid #c0392b;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#c0392b;font-weight:600}
.success-wrap{text-align:center;padding:32px 16px}
.footer{text-align:center;padding:20px;font-size:11px;color:#8a9ab0;line-height:1.8}
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
<div class="card">
  <div class="success-wrap">
    <div style="font-size:56px;margin-bottom:14px">✅</div>
    <div style="font-size:22px;font-weight:800;color:#1a7a3a;margin-bottom:8px">Thank You, <?=$cust?>!</div>
    <div style="font-size:14px;color:#4a5568;line-height:1.7">
      Your consent has been confirmed and our technician has been notified.<br><br>
      <strong>Installation will now proceed.</strong><br><br>
      Please ensure your vehicle is available and accessible.<br><br>
      <strong>Task ID: <?=$taskId?></strong><br>
      For any help call <strong>9849849824</strong>
    </div>
  </div>
</div>

<?php else: ?>

<div class="card">
  <div class="card-hd">📋 Service Request — <?=$taskId?></div>
  <div class="card-body">
    <div class="info-row"><span class="info-lbl">Customer</span><span class="info-val"><?=$cust?></span></div>
    <div class="info-row"><span class="info-lbl">Service</span><span class="info-val"><?=$service?></span></div>
    <div class="info-row"><span class="info-lbl">Technician</span><span class="info-val"><?=$tech?></span></div>
    <div class="info-row"><span class="info-lbl">Location</span><span class="info-val"><?=htmlspecialchars($task['location']??'–')?></span></div>
  </div>
</div>

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
      <strong>Payment Terms &amp; Recovery Rights</strong>, failure to pay after
      installation gives BharatGPS the right to recover the GPS device.
    </p>
  </div>
</div>

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
        <li>Technicians will perform installation with adequate safety measures.</li>
      </ul>
      <h4>Refund Policy</h4>
      <ul>
        <li>Device is non-refundable once installed.</li>
        <li>Full support provided if device malfunctions.</li>
        <li>Service revocation may be requested within one month from installation date (device must be in working condition). Refund processed within 2 working days from revoke approval.</li>
      </ul>
      <h4>Payment Terms & Recovery Rights</h4>
      <p>In the event a customer fails to make payment after installation or as per agreed payment terms, <strong>BharatGPS reserves the right to recover the GPS device</strong> to ensure compliance with the financial agreement.</p>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-hd">✍️ Your Confirmation</div>
  <div class="card-body">
    <?php if($error): ?>
    <div class="error-box">⚠️ <?=htmlspecialchars($error)?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Your Full Name *</label>
        <input type="text" name="c_name" value="<?=$cust?>" placeholder="Full name" required>
      </div>
      <div class="form-group">
        <label>Your Mobile Number *</label>
        <input type="tel" name="c_mobile" value="<?=$mobile?>" placeholder="Mobile number" required>
      </div>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_terms" id="chk_terms" <?=isset($_POST['chk_terms'])?'checked':''?>>
        <label for="chk_terms">I have <strong>read and understood</strong> all the Terms &amp; Conditions above, including the Warranty Policy, Installation terms, Refund Policy, and Payment Recovery Rights of BharatGPS.</label>
      </div>
      <div class="chk-item" onclick="this.querySelector('input').click()">
        <input type="checkbox" name="chk_pay" id="chk_pay" <?=isset($_POST['chk_pay'])?'checked':''?>>
        <label for="chk_pay">I confirm that I will pay <strong>₹<?=$price?></strong><?php if($payMode): ?> via <strong><?=$payMode?></strong><?php endif; ?> <strong>immediately after installation</strong>.</label>
      </div>
      <button type="submit" class="btn-submit" id="consent-submit-btn" onclick="return handleSubmit(this)">
        ✅ I Agree — Confirm & Proceed for Installation
      </button>
      <p style="font-size:11px;color:#8a9ab0;text-align:center;margin-top:12px;line-height:1.6">
        By submitting this form, you provide your digital consent to the above terms.<br>
        This is legally binding. Timestamp and details will be recorded.
      </p>
    </form>
  </div>
</div>

<?php endif; ?>

<div class="footer">
  BharatGPS Tracker · 9849849824 · sales@bharatgps.com<br>
  <a href="https://bharatgpstracker.com" style="color:#0E5C5C">bharatgpstracker.com</a><br>
  Task <?=$taskId?> · <?=date('d M Y')?>
</div>
</div>

<script>
function handleSubmit(btn){
  var terms  = document.getElementById('chk_terms');
  var pay    = document.getElementById('chk_pay');
  var name   = document.querySelector('input[name="c_name"]');
  var mobile = document.querySelector('input[name="c_mobile"]');
  if(!name.value.trim()||!mobile.value.trim()||!terms.checked||!pay.checked){ return true; }
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Submitting — Please wait…';
  return true;
}
if(window.performance && window.performance.navigation && window.performance.navigation.type===2){
  window.location.reload();
}
</script>
</body>
</html>
