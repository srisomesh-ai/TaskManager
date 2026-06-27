<?php
// Background consent notification — called fire-and-forget by consent.php
// Sends email to admin + tech after consent is saved
if(($_GET['secret']??'') !== 'bgps_notify_2024') exit;

$taskId = intval($_GET['task_id'] ?? 0);
$cName  = trim($_GET['name']    ?? '');
$mobile = trim($_GET['mobile']  ?? '');
$now    = trim($_GET['time']    ?? date('Y-m-d H:i:s'));

if(!$taskId) exit;

try {
    $pdo = new PDO('mysql:host=localhost;dbname=u943205660_bharatgps;charset=utf8mb4',
        'u943205660_bharatgps','kTrV>Le6+',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch(Exception $e){ exit; }

$s = $pdo->prepare("SELECT t.*, u.name AS tech_name, u.email AS tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
$s->execute([$taskId]);
$task = $s->fetch();
if(!$task) exit;

$subject = '✅ Consent Received — '.$task['task_id'].' | '.$cName;
$html = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f0f2f5;padding:20px">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)">
<div style="background:linear-gradient(135deg,#0E5C5C,#137272);padding:18px 24px;text-align:center">
<div style="background:#fff;border-radius:10px;padding:8px 18px;display:inline-block;margin-bottom:8px">
<img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" style="height:42px;width:auto;display:block" alt="BharatGPS">
</div>
<div style="color:rgba(255,255,255,.7);font-size:11px;letter-spacing:1px;font-weight:600">TASK MANAGER NOTIFICATION</div>
</div>
<div style="padding:24px">
<div style="background:#e8f5ec;border:2px solid #1a7a3a;border-radius:8px;padding:16px;margin-bottom:16px">
<div style="font-size:15px;font-weight:800;color:#1a7a3a;margin-bottom:6px">✅ Customer Consent Received</div>
<div style="font-size:13px;color:#1a1f2e">Customer has agreed to T&amp;C and payment. Technician can proceed with installation.</div>
</div>
<table style="width:100%;font-size:13px;border-collapse:collapse">
<tr><td style="padding:6px 0;color:#4a5568;width:140px">Task</td><td style="font-weight:700">'.htmlspecialchars($task['task_id']).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Customer</td><td style="font-weight:700">'.htmlspecialchars($cName).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Mobile</td><td style="font-weight:700">'.htmlspecialchars($mobile).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Service</td><td style="font-weight:700">'.htmlspecialchars($task['device_details']??'GPS').'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Amount</td><td style="font-weight:800;color:#1a7a3a;font-size:15px">₹'.number_format(floatval($task['price_to_collect']??0),0).'</td></tr>
<tr><td style="padding:6px 0;color:#4a5568">Time</td><td style="font-weight:700">'.date('d M Y, h:i A', strtotime($now)).'</td></tr>
</table>
<p style="font-size:13px;color:#1a7a3a;font-weight:700;margin-top:16px">✅ Technician can now proceed with installation.</p>
</div>
<div style="background:#f7f8fa;padding:12px 24px;text-align:center;font-size:11px;color:#8a9ab0">
BharatGPS Tracker · 9849849824 · sales@bharatgps.com
</div></div></body></html>';

function notifyMail($to,$toName,$subject,$html){
    $sock = @fsockopen('smtp.gmail.com',587,$e1,$e2,15);
    if(!$sock) return;
    stream_set_timeout($sock,15);
    $c=function($s,$cmd){fwrite($s,$cmd."\r\n");$b='';while(!feof($s)){$l=fgets($s,512);$b.=$l;if(substr($l,3,1)==' ')break;}return $b;};
    fread($sock,512);
    $c($sock,"EHLO bharatgps.com");
    $c($sock,"STARTTLS");
    stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $c($sock,"EHLO bharatgps.com");
    $c($sock,"AUTH LOGIN");
    $c($sock,base64_encode('info@bharatgps.com'));
    $c($sock,base64_encode('rxeumqjrhyrzeeye'));
    $c($sock,"MAIL FROM: <info@bharatgps.com>");
    $c($sock,"RCPT TO: <$to>");
    $c($sock,"DATA");
    $msg="From: BharatGPS Task Manager <info@bharatgps.com>\r\nTo: $toName <$to>\r\nReply-To: sales@bharatgps.com\r\nSubject: ".mb_encode_mimeheader($subject,'UTF-8')."\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n".$html."\r\n.";
    $c($sock,$msg);
    $c($sock,"QUIT");
    fclose($sock);
}

$admins = $pdo->query("SELECT name,email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email!='' AND is_active=1")->fetchAll();
foreach($admins as $a) { if($a['email']) @notifyMail($a['email'],$a['name'],$subject,$html); }
if($task['tech_email']) @notifyMail($task['tech_email'],$task['tech_name']??'Tech',$subject,$html);
