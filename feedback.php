<?php
// BharatGPS — Customer Feedback (Dispute / Reschedule)
// Standalone — no require of mailer.php
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=UTF-8');

$token = trim($_GET['token'] ?? '');
$mode  = trim($_GET['mode']  ?? 'dispute');

if(!$token) die('<p style="font-family:sans-serif;padding:40px;color:red">Invalid link.</p>');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=u943205660_bharatgps;charset=utf8mb4',
        'u943205660_bharatgps','kTrV>Le6+',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch(Exception $e){
    die('<p style="font-family:sans-serif;padding:40px;color:red">Database error. Call 9849849824.</p>');
}

$s = $pdo->prepare("SELECT t.*, u.name AS tech_name, u.email AS tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.feedback_token=?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task) die('<p style="font-family:sans-serif;padding:40px;color:red">Link not found or expired. Call 9849849824.</p>');

if($task['feedback_token']==='USED'){
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Already Submitted</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:14px;padding:32px;max-width:400px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:48px;margin-bottom:12px">✅</div>
<h2 style="color:#0E5C5C;margin-bottom:8px">Already Submitted</h2>
<p style="color:#4a5568;font-size:14px;line-height:1.7">Your message has been received and is being reviewed by our team.<br><br>For urgent matters call <strong>9849849824</strong>.</p>
</div></body></html>');
}

$submitted = false;
$error     = '';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $message = trim($_POST['message'] ?? '');
    if(!$message){ $error = 'Please write your message before submitting.'; }
    else {
        $isRes   = ($mode==='reschedule');
        $prefix  = $isRes ? '🔄 CUSTOMER RESCHEDULE REQUEST' : '🚨 CUSTOMER DISPUTE';
        $remark  = $prefix . ': ' . $message;

        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,0,?,'customer_dispute')")
            ->execute([$task['id'],$remark]);
        $pdo->prepare("UPDATE tasks SET feedback_token='USED' WHERE id=?")
            ->execute([$task['id']]);

        // Send email via direct SMTP
        $subject = $isRes ? '🔄 Reschedule Request — '.$task['task_id'] : '🚨 Customer Dispute — '.$task['task_id'];
        $accent  = $isRes ? '#0E5C5C' : '#c0392b';
        $bg      = $isRes ? '#e0eeee' : '#fdecea';
        $icon    = $isRes ? '🔄' : '🚨';
        $title   = $isRes ? 'Customer Wants to Reschedule' : 'Customer Raised a Dispute';

        $html = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f0f2f5;padding:20px">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)">
<div style="background:linear-gradient(135deg,#0E5C5C,#137272);padding:18px 24px;text-align:center">
<img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" style="height:48px;width:auto;background:#fff;padding:6px 12px;border-radius:8px" alt="BharatGPS">
</div>
<div style="padding:24px">
<div style="background:'.$bg.';border:2px solid '.$accent.';border-radius:8px;padding:16px;margin-bottom:16px">
<div style="font-size:15px;font-weight:800;color:'.$accent.';margin-bottom:8px">'.$icon.' '.$title.'</div>
<div style="font-size:13px;color:#1a1f2e;line-height:1.6">'.nl2br(htmlspecialchars($message)).'</div>
</div>
<table style="width:100%;font-size:13px;border-collapse:collapse">
<tr><td style="padding:5px 0;color:#4a5568;width:120px">Task</td><td style="font-weight:700">'.htmlspecialchars($task['task_id']).'</td></tr>
<tr><td style="padding:5px 0;color:#4a5568">Customer</td><td style="font-weight:700">'.htmlspecialchars($task['customer_name']).'</td></tr>
<tr><td style="padding:5px 0;color:#4a5568">Contact</td><td style="font-weight:700">'.$task['contact_number'].'</td></tr>
<tr><td style="padding:5px 0;color:#4a5568">Technician</td><td style="font-weight:700">'.htmlspecialchars($task['tech_name']??'–').'</td></tr>
<tr><td style="padding:5px 0;color:#4a5568">Status</td><td style="font-weight:700">'.$task['task_status'].'</td></tr>
</table>
<p style="font-size:13px;color:'.$accent.';font-weight:700;margin-top:14px">⚡ Please review and respond to the customer promptly.</p>
</div>
<div style="background:#f7f8fa;padding:12px 24px;text-align:center;font-size:11px;color:#8a9ab0">
BharatGPS Tracker · 9849849824 · sales@bharatgps.com
</div></div></body></html>';

        function fbSendMail($to,$toName,$subject,$html){
            $socket = @fsockopen('smtp.gmail.com',587,$errno,$errstr,15);
            if(!$socket) return;
            stream_set_timeout($socket,15);
            $c=function($s,$cmd){fwrite($s,$cmd."\r\n");$b='';while(!feof($s)){$l=fgets($s,512);$b.=$l;if(substr($l,3,1)==' ')break;}return $b;};
            $c($socket,''); fread($socket,512);
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
            $msg = "From: BharatGPS Task Manager <info@bharatgps.com>\r\nTo: $toName <$to>\r\nReply-To: sales@bharatgps.com\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n".$html."\r\n.";
            $c($socket,$msg);
            $c($socket,"QUIT");
            fclose($socket);
        }

        $custEmail = strtolower(trim($task['email']??''));
        $admins = $pdo->query("SELECT name,email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email!='' AND is_active=1")->fetchAll();
        foreach($admins as $a){
            if(strtolower($a['email'])!==$custEmail) @fbSendMail($a['email'],$a['name'],$subject,$html);
        }
        if($task['tech_email'] && strtolower($task['tech_email'])!==$custEmail){
            @fbSendMail($task['tech_email'],$task['tech_name']??'Tech',$subject,$html);
        }

        $submitted = true;
    }
}

$isRes     = ($mode==='reschedule');
$pageTitle = $isRes ? '🔄 Request Reschedule' : '⚠️ Raise a Dispute';
$pageDesc  = $isRes
    ? 'Tell us your preferred date and time. Our team will call you to confirm.'
    : 'If something is wrong or was done without your knowledge, tell us. Our management will review and take action.';
$btnLabel  = $isRes ? '📅 Send Reschedule Request' : '🚨 Submit Dispute';
$btnColor  = $isRes ? '#0E5C5C' : '#c0392b';
$ph        = $isRes
    ? 'e.g. Please come Monday 30th June after 10am. My vehicle will be ready...'
    : 'e.g. I did not cancel / I did not ask for postponement / The technician did not visit...';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title><?=htmlspecialchars($pageTitle)?> — BharatGPS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#16201F;min-height:100vh}
.header{background:linear-gradient(135deg,#0E5C5C,#137272);padding:14px 20px;display:flex;align-items:center;gap:12px}
.header img{height:38px;width:auto;background:#fff;padding:5px 10px;border-radius:7px}
.header-text{color:#fff}
.header-title{font-size:14px;font-weight:800}
.header-sub{font-size:11px;opacity:.65;margin-top:2px}
.container{max-width:560px;margin:20px auto;padding:0 16px 32px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden}
.card-hd{padding:13px 18px;border-bottom:1px solid #E9EFEE;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:13px;font-weight:800}
.card-body{padding:16px 18px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.info-item{background:#F3F6F5;border-radius:7px;padding:10px 12px}
.info-lbl{font-size:10px;font-weight:700;color:#8A9A98;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.info-val{font-size:13px;font-weight:700}
.banner{border-radius:10px;padding:16px 18px;margin-bottom:14px}
.form-label{display:block;font-size:11px;font-weight:700;color:#55676A;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
textarea{width:100%;padding:12px 14px;border:1.5px solid #DDE5E4;border-radius:9px;font-size:14px;font-family:inherit;outline:none;resize:vertical;min-height:100px;line-height:1.6;transition:border .2s}
textarea:focus{border-color:#0E5C5C}
.btn{padding:14px 20px;border-radius:9px;border:none;font-size:14px;font-weight:800;cursor:pointer;width:100%;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);margin-top:12px}
.btn:disabled{opacity:.6;cursor:not-allowed}
.error-box{background:#FCE9E7;border:1.5px solid #E74C3C;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#E74C3C;font-weight:700}
.success-box{background:#E7F7EC;border:2px solid #27AE60;border-radius:12px;padding:28px 20px;text-align:center}
.footer{text-align:center;padding:20px 0;font-size:12px;color:#8A9A98;line-height:1.8}
.task-ref{background:#E0EEEE;color:#0E5C5C;font-size:11px;font-weight:800;padding:2px 8px;border-radius:5px;margin-left:6px}
</style>
</head>
<body>

<div class="header">
  <img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" alt="BharatGPS"
       onerror="this.style.display='none'">
  <div class="header-text">
    <div class="header-title">BharatGPS Tracker</div>
    <div class="header-sub"><?=htmlspecialchars($task['task_id'])?> · <?=htmlspecialchars($task['customer_name'])?></div>
  </div>
</div>

<div class="container">

<?php if($submitted): ?>
<div class="success-box">
  <div style="font-size:52px;margin-bottom:12px"><?=$isRes?'📅':'✅'?></div>
  <div style="font-size:19px;font-weight:800;color:#27AE60;margin-bottom:8px"><?=$isRes?'Reschedule Request Sent!':'Dispute Submitted!'?></div>
  <div style="font-size:13px;color:#1a6b3a;line-height:1.7">
    <?php if($isRes): ?>
      Your request has been sent to our team.<br>We will call you shortly to confirm the new date.<br><br>
    <?php else: ?>
      Your dispute has been recorded and sent to our management team.<br>We will review and take action within <strong>24 hours</strong>.<br><br>
    <?php endif; ?>
    For help call <strong>9849849824</strong>
  </div>
</div>
<?php else: ?>

<div class="card">
  <div class="card-hd">
    <div class="card-title">📋 Your Service Request</div>
    <span class="task-ref"><?=htmlspecialchars($task['task_id'])?></span>
  </div>
  <div class="card-body">
    <div class="info-grid">
      <div class="info-item"><div class="info-lbl">Service</div><div class="info-val"><?=htmlspecialchars($task['device_details']??'GPS')?></div></div>
      <div class="info-item"><div class="info-lbl">Status</div><div class="info-val"><?=htmlspecialchars($task['task_status'])?></div></div>
      <div class="info-item"><div class="info-lbl">Technician</div><div class="info-val"><?=htmlspecialchars($task['tech_name']??'–')?></div></div>
      <div class="info-item"><div class="info-lbl">Location</div><div class="info-val"><?=htmlspecialchars($task['location']??'–')?></div></div>
    </div>
  </div>
</div>

<div class="banner" style="background:<?=$isRes?'#E0EEEE':'#FCE9E7'?>;border:1.5px solid <?=$isRes?'#0E5C5C':'#E74C3C'?>">
  <div style="font-size:14px;font-weight:800;color:<?=$isRes?'#0E5C5C':'#E74C3C'?>;margin-bottom:6px"><?=$pageTitle?></div>
  <div style="font-size:13px;color:<?=$isRes?'#137272':'#7a1e14'?>;line-height:1.6"><?=$pageDesc?></div>
</div>

<?php if($error): ?>
<div class="error-box">⚠️ <?=htmlspecialchars($error)?></div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="POST" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Submitting…';return true;">
      <label class="form-label"><?=$isRes?'Your Message / Preferred Date':'What happened?'?> *</label>
      <textarea name="message" rows="5" placeholder="<?=htmlspecialchars($ph)?>" required><?=htmlspecialchars($_POST['message']??'')?></textarea>
      <button type="submit" class="btn" style="background:<?=$btnColor?>"><?=$btnLabel?></button>
    </form>
  </div>
</div>

<?php endif; ?>

<div class="footer">
  BharatGPS Tracker · 9849849824 · sales@bharatgps.com<br>
  <a href="https://bharatgpstracker.com" style="color:#0E5C5C">bharatgpstracker.com</a>
</div>
</div>
</body>
</html>
