<?php
// ============================================================
// BHARATGPS — Customer Feedback & Dispute Page
// Public URL — no login required
// token is unique per task, passed in email link
// ============================================================

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/mailer.php';

$pdo   = getDB();
$token = trim($_GET['token'] ?? '');
$submitted = false;
$error     = '';
$task      = null;
$activities= [];

if(!$token){
    die('<h2 style="font-family:sans-serif;color:#c0392b;padding:40px">Invalid link. Please check your email for the correct link.</h2>');
}

// Fetch task by token
$s = $pdo->prepare("SELECT t.*, u.name AS tech_name, u.email AS tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.feedback_token = ?");
$s->execute([$token]);
$task = $s->fetch();

if(!$task){
    die('<h2 style="font-family:sans-serif;color:#c0392b;padding:40px">Link not found or expired. Please contact BharatGPS at 09963222009.</h2>');
}

// Check if dispute already submitted — token set to 'USED'
if($task['feedback_token'] === 'USED'){
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Already Submitted</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
<div style="background:#fff;border-radius:12px;padding:36px;max-width:400px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:48px;margin-bottom:12px">✅</div>
<h2 style="color:#1a3a6b;margin-bottom:8px">Already Submitted</h2>
<p style="color:#4a5568;font-size:14px;line-height:1.7">Your report has already been received and is being reviewed by our management team.<br><br>For urgent matters call <strong>09963222009</strong>.</p>
</div></body></html>');
}

// Fetch activity log (only remark + status_change — no system entries)
$a = $pdo->prepare("SELECT a.*, u.name AS user_name FROM task_activities a LEFT JOIN users u ON a.user_id = u.id WHERE a.task_id = ? AND a.activity_type IN ('remark','status_change') ORDER BY a.created_at ASC");
$a->execute([$task['id']]);
$activities = $a->fetchAll();

// Handle dispute submission
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $feedback = trim($_POST['feedback'] ?? '');

    if(!$feedback){
        $error = 'Please tell us what happened.';
    } else {
        $fullFeedback = $feedback;

        // Log dispute in activity
        $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 0, ?, 'customer_dispute')")
            ->execute([$task['id'], "🚨 CUSTOMER DISPUTE: " . $fullFeedback]);

        // Immediately expire the token — link can never be used again
        $pdo->prepare("UPDATE tasks SET feedback_token='USED' WHERE id=?")
            ->execute([$task['id']]);

        // Fetch admin emails
        $admins = $pdo->query("SELECT name, email FROM users WHERE role IN ('admin','assigner') AND email IS NOT NULL AND email != ''")->fetchAll();

        $disputeContent = '
        <div style="background:#fdecea;border:2px solid #c0392b;border-radius:8px;padding:16px;margin-bottom:16px">
            <div style="font-size:16px;font-weight:800;color:#c0392b;margin-bottom:8px">🚨 Customer Reported False Update</div>
            <div style="font-size:13px;color:#1a1f2e">' . htmlspecialchars($fullFeedback) . '</div>
        </div>
        <div style="background:#f7f8fa;border-radius:8px;padding:14px;font-size:13px">
            <div><strong>Task:</strong> ' . $task['task_id'] . '</div>
            <div><strong>Customer:</strong> ' . htmlspecialchars($task['customer_name']) . '</div>
            <div><strong>Contact:</strong> ' . $task['contact_number'] . '</div>
            <div><strong>Technician:</strong> ' . htmlspecialchars($task['tech_name'] ?? '–') . '</div>
            <div><strong>Status:</strong> ' . $task['task_status'] . '</div>
        </div>
        <p style="font-size:13px;color:#c0392b;font-weight:700;margin-top:14px">⚡ Please review and take action immediately.</p>';

        // Email admins
        foreach($admins as $admin){
            sendMail($admin['email'], $admin['name'],
                '🚨 Customer Dispute — ' . $task['task_id'] . ' | ' . $task['customer_name'],
                emailTemplate($disputeContent));
        }

        // Email technician
        if($task['tech_email']){
            $techContent = '
            <div style="background:#fdecea;border:2px solid #c0392b;border-radius:8px;padding:16px;margin-bottom:16px">
                <div style="font-size:15px;font-weight:800;color:#c0392b;margin-bottom:8px">⚠️ Customer Disputed Your Update</div>
                <div style="font-size:13px;color:#1a1f2e">' . htmlspecialchars($fullFeedback) . '</div>
            </div>
            <p style="font-size:13px;color:#4a5568">Task <strong>' . $task['task_id'] . '</strong> — Customer ' . htmlspecialchars($task['customer_name']) . ' has reported that the update you logged was incorrect. The manager has been notified and will review this.</p>
            <p style="font-size:13px;font-weight:700;color:#c0392b;margin-top:12px">Please be accurate in all future updates. Your updates are sent directly to customers.</p>';

            sendMail($task['tech_email'], $task['tech_name'] ?? 'Technician',
                '⚠️ Customer Disputed Your Update — ' . $task['task_id'],
                emailTemplate($techContent));
        }

        $submitted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Task Update — BharatGPS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#1a1f2e;min-height:100vh}
.header{background:#1a3a6b;padding:16px 20px;display:flex;align-items:center;gap:12px}
.header-title{color:#fff;font-size:16px;font-weight:800}
.header-sub{color:rgba(255,255,255,.55);font-size:12px;margin-top:2px}
.container{max-width:600px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:16px;overflow:hidden}
.card-hd{padding:14px 18px;border-bottom:1px solid #d0d5dd;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:13px;font-weight:800;color:#1a1f2e}
.card-body{padding:16px 18px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.info-item{background:#f7f8fa;border-radius:7px;padding:10px 12px}
.info-lbl{font-size:10px;font-weight:700;color:#8a9ab0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.info-val{font-size:13px;font-weight:700;color:#1a1f2e}
.status-pill{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800}
/* Timeline */
.timeline{position:relative;padding-left:36px}
.tl-item{position:relative;padding-bottom:20px}
.tl-item:last-child{padding-bottom:0}
.tl-dot{position:absolute;left:-36px;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0}
.tl-line{position:absolute;left:-23px;top:30px;bottom:0;width:2px;background:#d0d5dd}
.tl-item:last-child .tl-line{display:none}
.tl-name{font-size:11px;font-weight:800;color:#4a5568}
.tl-time{font-size:10px;color:#8a9ab0;margin-left:8px}
.tl-body{margin-top:6px;background:#f7f8fa;border-radius:7px;padding:10px 12px;border-left:3px solid #d0d5dd}
.tl-remark{font-size:13px;font-weight:600;color:#1a1f2e;margin-bottom:3px}
.tl-detail{font-size:11px;color:#4a5568;margin-top:2px}
/* Report form */
.report-banner{background:#fdecea;border:2px solid #c0392b;border-radius:10px;padding:16px;margin-bottom:16px;cursor:pointer;transition:all .15s}
.report-banner:hover{background:#f9d0cc}
.report-banner-title{font-size:14px;font-weight:800;color:#c0392b;margin-bottom:4px}
.report-banner-sub{font-size:12px;color:#7b1e14}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.form-group textarea{width:100%;padding:10px 12px;border:1.5px solid #d0d5dd;border-radius:8px;font-size:13px;font-family:inherit;outline:none;resize:vertical;min-height:80px;transition:border .2s}
.form-group textarea:focus{border-color:#1a3a6b}
.btn{padding:12px 20px;border-radius:8px;border:none;font-size:14px;font-weight:800;cursor:pointer;width:100%}
.btn-red{background:#c0392b;color:#fff}
.btn-red:hover{background:#a93226}
.success-box{background:#e8f5ec;border:2px solid #1a7a3a;border-radius:10px;padding:24px;text-align:center}
.success-icon{font-size:48px;margin-bottom:12px}
.success-title{font-size:18px;font-weight:800;color:#1a7a3a;margin-bottom:8px}
.success-sub{font-size:13px;color:#2d6a4f;line-height:1.6}
.footer{text-align:center;padding:24px 16px;font-size:12px;color:#8a9ab0}
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="header-title">🛰 BharatGPS — Task Update</div>
    <div class="header-sub">Task <?= htmlspecialchars($task['task_id']) ?> · <?= htmlspecialchars($task['customer_name']) ?></div>
  </div>
</div>

<div class="container">

<?php if($submitted): ?>
  <!-- SUCCESS -->
  <div class="success-box">
    <div class="success-icon">✅</div>
    <div class="success-title">Thank You!</div>
    <div class="success-sub">
      Your feedback has been recorded and sent to our management team.<br><br>
      We will review the update and take appropriate action within <strong>24 hours</strong>.<br><br>
      For urgent matters, call us at <strong>09963222009</strong>.
    </div>
  </div>

<?php else: ?>

  <!-- TASK SUMMARY -->
  <div class="card">
    <div class="card-hd">
      <div class="card-title">📋 Your Service Request</div>
      <?php
        $sColor = ['Open'=>'#e07b00','In Progress'=>'#1a56a0','Task Pending'=>'#d4680a','Awaiting Approval'=>'#5b2d8e','Closed'=>'#1a7a3a','Cancelled'=>'#8a9ab0'];
        $sc = $sColor[$task['task_status']] ?? '#1a3a6b';
      ?>
      <span class="status-pill" style="background:<?=$sc?>22;color:<?=$sc?>"><?= htmlspecialchars($task['task_status']) ?></span>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-lbl">Task ID</div>
          <div class="info-val" style="color:#1a3a6b"><?= htmlspecialchars($task['task_id']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-lbl">Service</div>
          <div class="info-val"><?= htmlspecialchars($task['device_details'] ?: 'GPS Service') ?></div>
        </div>
        <div class="info-item">
          <div class="info-lbl">Location</div>
          <div class="info-val"><?= htmlspecialchars($task['location'] ?: '–') ?></div>
        </div>
        <div class="info-item">
          <div class="info-lbl">Technician</div>
          <div class="info-val"><?= htmlspecialchars($task['tech_name'] ?: 'Unassigned') ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ACTIVITY TIMELINE -->
  <div class="card">
    <div class="card-hd">
      <div class="card-title">🕐 Update History</div>
      <span style="font-size:11px;color:#8a9ab0"><?= count($activities) ?> update<?= count($activities)!=1?'s':'' ?></span>
    </div>
    <div class="card-body">
      <?php if(empty($activities)): ?>
        <p style="font-size:13px;color:#8a9ab0;text-align:center;padding:20px 0">No updates yet. Our team will contact you shortly.</p>
      <?php else: ?>
        <div class="timeline">
          <?php foreach($activities as $i => $act):
            $isLatest = ($i === count($activities)-1);
            $type     = $act['activity_type'] ?? 'remark';
            $dotBg    = $type==='status_change' ? '#1a7a3a' : ($isLatest ? '#1a3a6b' : '#4a5568');
            $border   = $type==='status_change' ? '#1a7a3a' : ($isLatest ? '#1a3a6b' : '#d0d5dd');
            $name     = $act['user_name'] ?: 'BharatGPS Team';
            $dt       = date('d M Y, h:i A', strtotime($act['created_at']));

            // Parse structured remark
            $remark   = $act['remark'] ?? '';
            $parts    = explode(' | ', $remark);
            $main     = ltrim(trim($parts[0]), '📞🔧 ');
            $details  = array_slice($parts, 1);
          ?>
          <div class="tl-item">
            <div class="tl-dot" style="background:<?=$dotBg?>;top:0">
              <?= $type==='status_change' ? '🔄' : ($isLatest ? '🆕' : strtoupper(substr($name,0,1))) ?>
            </div>
            <?php if($i < count($activities)-1): ?><div class="tl-line"></div><?php endif; ?>
            <div>
              <span class="tl-name"><?= htmlspecialchars($name) ?></span>
              <span class="tl-time"><?= $dt ?></span>
              <?php if($isLatest): ?><span style="background:#1a3a6b;color:#fff;font-size:9px;font-weight:800;padding:1px 6px;border-radius:4px;margin-left:6px">LATEST</span><?php endif; ?>
            </div>
            <div class="tl-body" style="border-left-color:<?=$border?>">
              <div class="tl-remark"><?= htmlspecialchars($main) ?></div>
              <?php foreach($details as $d): ?>
                <div class="tl-detail">· <?= htmlspecialchars(trim($d)) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- REPORT FALSE UPDATE -->
  <?php if($error): ?>
    <div style="background:#fdecea;border:1.5px solid #c0392b;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#c0392b;font-weight:700">
      ⚠️ <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-hd">
      <div class="card-title" style="color:#c0392b">⚠️ Report a False Update</div>
    </div>
    <div class="card-body">
      <p style="font-size:13px;color:#4a5568;margin-bottom:16px;line-height:1.6">
        If any of the updates above are <strong>incorrect or false</strong>, please let us know.
        Your report will be sent directly to our management team and the technician will be notified.
      </p>
      <form method="POST">
        <div class="form-group">
          <label>What do you want to tell us? *</label>
          <textarea name="feedback" rows="5" placeholder="Tell us what actually happened..." required style="font-size:14px"><?= htmlspecialchars($_POST['feedback'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-red">🚨 Submit — Notify Manager</button>
      </form>
    </div>
  </div>

<?php endif; ?>

  <div class="footer">
    BharatGPS · 09963222009 · info@bharatgps.com<br>
    This page is private to you. Do not share this link with others.
  </div>
</div>
</body>
</html>
