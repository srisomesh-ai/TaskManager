<?php
require_once 'api/mailer.php';

$result = '';
$sent = false;

if ($_POST['test_email'] ?? '') {
    $to    = trim($_POST['test_email']);
    $type  = $_POST['test_type'] ?? 'task_created';

    // Dummy task data
    $task = [
        'task_id'         => 'ID-2026-TEST',
        'customer_name'   => 'Test Customer',
        'contact_number'  => '9999999999',
        'email'           => $to,
        'location'        => 'Visakhapatnam',
        'lead_type'       => 'New Lead',
        'device_details'  => 'Engine Status',
        'device_qty'      => 1,
        'price_to_collect'=> 3500,
        'payment_mode'    => 'UPI',
        'amount_collected'=> 3500,
        'general_notes'   => 'Test task — please ignore',
    ];

    try {
        if ($type === 'task_created_customer') {
            $ok = sendTaskCreatedCustomer($task, 'Pilli Srinu', '9963649804');
            $result = $ok ? '✅ Customer task-created email sent!' : '❌ Failed to send';
        } elseif ($type === 'task_created_tech') {
            $ok = sendTaskCreatedTech($task, $to, 'Test Technician');
            $result = $ok ? '✅ Technician task-created email sent!' : '❌ Failed to send';
        } elseif ($type === 'task_closed') {
            $ok = sendTaskClosedCustomer($task);
            $result = $ok ? '✅ Task-closed email sent!' : '❌ Failed to send';
        } elseif ($type === 'raw') {
            $ok = sendMail($to, 'Test', 'BharatGPS Test Email', '<h2>✅ Email is working!</h2><p>Sent from Bharat GPS Tracker at ' . date('d M Y H:i:s') . '</p>');
            $result = $ok ? '✅ Raw test email sent!' : '❌ Failed to send';
        }
        $sent = $ok ?? false;
    } catch (Exception $e) {
        $result = '❌ Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email Test — Bharat GPS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Calibri,sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:12px;padding:28px;max-width:480px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.1)}
h2{color:#1a3a6b;font-size:20px;margin-bottom:6px}
.sub{color:#888;font-size:13px;margin-bottom:24px}
label{display:block;font-size:12px;font-weight:700;color:#4a5568;text-transform:uppercase;margin-bottom:5px}
input,select{width:100%;padding:11px 13px;border:1.5px solid #d0d5dd;border-radius:7px;font-family:inherit;font-size:14px;outline:none;margin-bottom:14px}
input:focus,select:focus{border-color:#2451a3;box-shadow:0 0 0 3px rgba(36,81,163,.1)}
.btn{width:100%;padding:13px;background:#1a3a6b;color:#fff;border:none;border-radius:7px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
.btn:hover{background:#2451a3}
.result{margin-top:16px;padding:14px;border-radius:8px;font-size:14px;font-weight:700;text-align:center}
.ok{background:#e8f5ec;color:#1a7a3a;border:1.5px solid rgba(26,122,58,.3)}
.fail{background:#fdecea;color:#c0392b;border:1.5px solid rgba(192,57,43,.3)}
.config{background:#f7f8fa;border:1px solid #d0d5dd;border-radius:8px;padding:12px;margin-bottom:20px;font-size:12px}
.config .row{display:flex;justify-content:space-between;padding:3px 0}
.config .lbl{color:#888;font-weight:700}
.config .val{color:#1a3a6b;font-weight:700}
a{display:block;text-align:center;margin-top:12px;color:#888;font-size:12px;text-decoration:none}
</style>
</head>
<body>
<div class="card">
  <h2>📧 Email Test</h2>
  <div class="sub">Test your Gmail SMTP setup</div>

  <div class="config">
    <div class="row"><span class="lbl">SMTP Host</span><span class="val"><?= MAIL_HOST ?>:<?= MAIL_PORT ?></span></div>
    <div class="row"><span class="lbl">From</span><span class="val"><?= MAIL_FROM ?></span></div>
    <div class="row"><span class="lbl">App Password</span><span class="val"><?= MAIL_PASS === 'YOUR_GMAIL_APP_PASSWORD' ? '❌ Not set yet!' : '✅ Configured ('.strlen(MAIL_PASS).' chars)' ?></span></div>
  </div>

  <?php if (MAIL_PASS === 'YOUR_GMAIL_APP_PASSWORD'): ?>
  <div style="background:#fff3e0;border:1.5px solid #e07b00;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#d4680a">
    ⚠️ <strong>App password not set.</strong> Open <code>api/mailer.php</code> and replace <code>YOUR_GMAIL_APP_PASSWORD</code> with your 16-char Gmail App Password.
  </div>
  <?php endif; ?>

  <form method="POST">
    <label>Send Test To</label>
    <input type="email" name="test_email" placeholder="your@email.com" required value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>">

    <label>Email Type</label>
    <select name="test_type">
      <option value="raw">📧 Simple Test (just checks connection)</option>
      <option value="task_created_customer">👤 Task Created — Customer Email</option>
      <option value="task_created_tech">👨‍🔧 Task Created — Technician Email</option>
      <option value="task_closed">✅ Task Closed — Customer Email</option>
    </select>

    <button type="submit" class="btn">🚀 Send Test Email</button>
  </form>

  <?php if ($result): ?>
  <div class="result <?= $sent ? 'ok' : 'fail' ?>"><?= $result ?></div>
  <?php endif; ?>

  <a href="index.html">← Back to Task Manager</a>
  <div style="text-align:center;margin-top:8px;font-size:11px;color:#ccc">⚠️ Delete this file after testing</div>
</div>
</body>
</html>
