<?php
// ============================================================
// BHARATGPS — Customer Feedback Page
// Handles: dispute reports AND reschedule requests
// Public URL — no login required
// ============================================================
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/mailer.php';

$pdo       = getDB();
$token     = trim($_GET['token'] ?? '');
$mode      = trim($_GET['mode'] ?? 'dispute'); // 'dispute' or 'reschedule'
$submitted = false;
$error     = '';
$task      = null;

if(!$token){
    die('<h2 style="font-family:sans-serif;color:#c0392b;padding:40px">Invalid link. Please check your email.</h2>');
}

// Fetch task by feedback token
$s = $pdo->prepare("SELECT t.*, u.name AS tech_name, u.email AS tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.feedback_token = ?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task){
    die('<h2 style="font-family:sans-serif;color:#c0392b;padding:40px">Link not found or expired. Call 09963222009.</h2>');
}

// Already used check
if($task['feedback_token'] === 'USED'){
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Already Submitted</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:14px;padding:32px;max-width:400px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:48px;margin-bottom:12px">✅</div>
<h2 style="color:#0E5C5C;margin-bottom:8px">Already Submitted</h2>
<p style="color:#4a5568;font-size:14px;line-height:1.7">Your message has been received and is being reviewed by our team.<br><br>For urgent matters call <strong>09963222009</strong>.</p>
</div></body></html>');
}

// Handle form submission
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $message = trim($_POST['message'] ?? '');
    if(!$message){ $error = 'Please write your message before submitting.'; }
    else {
        $isReschedule = ($mode === 'reschedule');
        $prefix       = $isReschedule ? '🔄 CUSTOMER RESCHEDULE REQUEST' : '🚨 CUSTOMER DISPUTE';
        $remark       = $prefix . ': ' . $message;

        // Log to activity
        $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 0, ?, 'customer_dispute')")
            ->execute([$task['id'], $remark]);

        // Expire token
        $pdo->prepare("UPDATE tasks SET feedback_token='USED' WHERE id=?")
            ->execute([$task['id']]);

        // Email content
        $colorAccent = $isReschedule ? '#0E5C5C' : '#c0392b';
        $icon        = $isReschedule ? '🔄' : '🚨';
        $subject     = $isReschedule
            ? '🔄 Customer Reschedule Request — ' . $task['task_id']
            : '🚨 Customer Dispute — ' . $task['task_id'];

        $emailBody = '
        <div style="background:'.($isReschedule?'#e0eeee':'#fdecea').';border:2px solid '.$colorAccent.';border-radius:8px;padding:16px;margin-bottom:16px">
            <div style="font-size:15px;font-weight:800;color:'.$colorAccent.';margin-bottom:8px">'.$icon.' '.($isReschedule?'Customer Wants to Reschedule':'Customer Raised a Dispute').'</div>
            <div style="font-size:13px;color:#1a1f2e;line-height:1.6">'.htmlspecialchars($message).'</div>
        </div>
        <div style="background:#f7f8fa;border-radius:8px;padding:14px;font-size:13px;line-height:2">
            <div><strong>Task:</strong> '.$task['task_id'].'</div>
            <div><strong>Customer:</strong> '.htmlspecialchars($task['customer_name']).'</div>
            <div><strong>Contact:</strong> '.$task['contact_number'].'</div>
            <div><strong>Technician:</strong> '.htmlspecialchars($task['tech_name']??'–').'</div>
            <div><strong>Status:</strong> '.$task['task_status'].'</div>
        </div>
        <p style="font-size:13px;color:'.$colorAccent.';font-weight:700;margin-top:14px">⚡ Please review and respond to the customer promptly.</p>';

        $customerEmail = strtolower(trim($task['email'] ?? ''));

        // Email all admins
        $admins = $pdo->prepare("SELECT name, email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email != '' AND LOWER(email) != ?");
        $admins->execute([$customerEmail]);
        foreach($admins->fetchAll() as $admin){
            sendMail($admin['email'], $admin['name'], $subject, emailTemplate($emailBody));
        }

        // Email technician
        if($task['tech_email'] && strtolower($task['tech_email']) !== $customerEmail){
            sendMail($task['tech_email'], $task['tech_name']??'Technician', $subject, emailTemplate($emailBody));
        }

        $submitted = true;
    }
}

// Page labels based on mode
$isReschedule  = ($mode === 'reschedule');
$pageTitle     = $isReschedule ? '🔄 Request Reschedule' : '⚠️ Raise a Dispute';
$pageDesc      = $isReschedule
    ? 'Tell us your preferred date and time for the installation. Our team will get back to you to confirm.'
    : 'If something is wrong or was done without your knowledge, tell us. Our management will review and take action.';
$btnLabel      = $isReschedule ? '📅 Send Reschedule Request' : '🚨 Submit Dispute';
$btnColor      = $isReschedule ? '#0E5C5C' : '#c0392b';
$placeholder   = $isReschedule
    ? 'e.g. Please reschedule for Monday 30th June after 10am. My vehicle will be available...'
    : 'e.g. I did not cancel / I did not ask for postponement / The technician did not visit...';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<title><?= htmlspecialchars($pageTitle) ?> — BharatGPS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#16201F;min-height:100vh}
.header{background:linear-gradient(135deg,#0E5C5C,#137272);padding:16px 20px}
.header-title{color:#fff;font-size:16px;font-weight:800}
.header-sub{color:rgba(255,255,255,.6);font-size:12px;margin-top:2px}
.container{max-width:560px;margin:20px auto;padding:0 16px 32px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden}
.card-hd{padding:13px 18px;border-bottom:1px solid #E9EFEE;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:13px;font-weight:800;color:#16201F}
.card-body{padding:16px 18px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.info-item{background:#F3F6F5;border-radius:7px;padding:10px 12px}
.info-lbl{font-size:10px;font-weight:700;color:#8A9A98;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.info-val{font-size:13px;font-weight:700;color:#16201F}
.mode-banner{border-radius:10px;padding:16px 18px;margin-bottom:14px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;font-weight:700;color:#55676A;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.form-group textarea{width:100%;padding:12px 14px;border:1.5px solid #DDE5E4;border-radius:9px;font-size:14px;font-family:inherit;outline:none;resize:vertical;min-height:100px;line-height:1.6;transition:border .2s}
.form-group textarea:focus{border-color:#0E5C5C}
.btn{padding:14px 20px;border-radius:9px;border:none;font-size:14px;font-weight:800;cursor:pointer;width:100%;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{opacity:.85}
.error-box{background:#FCE9E7;border:1.5px solid #E74C3C;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#E74C3C;font-weight:700}
.success-box{background:#E7F7EC;border:2px solid #27AE60;border-radius:12px;padding:28px 20px;text-align:center}
.success-icon{font-size:52px;margin-bottom:12px}
.success-title{font-size:19px;font-weight:800;color:#27AE60;margin-bottom:8px}
.success-sub{font-size:13px;color:#1a6b3a;line-height:1.7}
.footer{text-align:center;padding:20px 0;font-size:12px;color:#8A9A98}
.task-ref{display:inline-block;background:#E0EEEE;color:#0E5C5C;font-size:11px;font-weight:800;padding:2px 8px;border-radius:5px;margin-left:6px}
</style>
</head>
<body>

<div class="header">
  <div class="header-title"><img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" alt="BharatGPS Tracker" style="height:36px;width:auto;vertical-align:middle"></div>
  <div class="header-sub"><?= htmlspecialchars($task['task_id']) ?> · <?= htmlspecialchars($task['customer_name']) ?></div>
</div>

<div class="container">

<?php if($submitted): ?>
  <div class="success-box">
    <div class="success-icon"><?= $isReschedule ? '📅' : '✅' ?></div>
    <div class="success-title"><?= $isReschedule ? 'Reschedule Request Sent!' : 'Dispute Submitted!' ?></div>
    <div class="success-sub">
      <?php if($isReschedule): ?>
        Your reschedule request has been sent to our team.<br><br>
        We will call you shortly to confirm the new date and time.<br><br>
        For urgent queries call <strong>09963222009</strong>.
      <?php else: ?>
        Your dispute has been recorded and sent to our management team.<br><br>
        We will review and take action within <strong>24 hours</strong>.<br><br>
        For urgent matters call <strong>09963222009</strong>.
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <!-- Task Summary -->
  <div class="card">
    <div class="card-hd">
      <div class="card-title">📋 Your Service Request</div>
      <span class="task-ref"><?= htmlspecialchars($task['task_id']) ?></span>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-lbl">Service</div>
          <div class="info-val"><?= htmlspecialchars($task['device_details']??'GPS Service') ?></div>
        </div>
        <div class="info-item">
          <div class="info-lbl">Status</div>
          <div class="info-val"><?= htmlspecialchars($task['task_status']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-lbl">Technician</div>
          <div class="info-val"><?= htmlspecialchars($task['tech_name']??'–') ?></div>
        </div>
        <div class="info-item">
          <div class="info-lbl">Location</div>
          <div class="info-val"><?= htmlspecialchars($task['location']??'–') ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Mode banner -->
  <div class="mode-banner" style="background:<?= $isReschedule?'#E0EEEE':'#FCE9E7' ?>;border:1.5px solid <?= $isReschedule?'#0E5C5C':'#E74C3C' ?>">
    <div style="font-size:14px;font-weight:800;color:<?= $isReschedule?'#0E5C5C':'#E74C3C' ?>;margin-bottom:6px"><?= $pageTitle ?></div>
    <div style="font-size:13px;color:<?= $isReschedule?'#137272':'#7a1e14' ?>;line-height:1.6"><?= $pageDesc ?></div>
  </div>

  <?php if($error): ?>
    <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Form -->
  <div class="card">
    <div class="card-body">
      <form method="POST">
        <div class="form-group">
          <label><?= $isReschedule ? 'Your Message / Preferred Date' : 'What happened?' ?> *</label>
          <textarea name="message" rows="5" placeholder="<?= htmlspecialchars($placeholder) ?>" required><?= htmlspecialchars($_POST['message']??'') ?></textarea>
        </div>
        <button type="submit" class="btn" style="background:<?= $btnColor ?>"><?= $btnLabel ?></button>
      </form>
    </div>
  </div>

<?php endif; ?>

  <div class="footer">
    BharatGPS · 09963222009 · info@bharatgps.com<br>
    This link is private to you. Do not share it with others.
  </div>
</div>

</body>
</html>
