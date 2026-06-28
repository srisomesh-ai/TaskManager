<?php
// ============================================================
// BHARAT GPS MAILER — Gmail SMTP
// ============================================================

define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USER',     'info@bharatgps.com');        // ← Gmail address (App Password is for this account)
define('MAIL_PASS',     'rxeumqjrhyrzeeye');          // ← 16-char App Password for info@bharatgps.com
define('MAIL_FROM',     'info@bharatgps.com');        // Must match MAIL_USER for Gmail SMTP
define('MAIL_FROM_NAME','BharatGPS Task Manager');
define('MAIL_REPLY_TO', 'sales@bharatgps.com');       // Replies go to sales@

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    try {
        $socket = fsockopen(MAIL_HOST, MAIL_PORT, $errno, $errstr, 15);
        if (!$socket) throw new Exception("Connect failed: $errstr ($errno)");

        stream_set_timeout($socket, 15);

        smtpRead($socket);
        smtpCmd($socket, "EHLO bharatgps.com");
        smtpCmd($socket, "STARTTLS");
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        smtpCmd($socket, "EHLO bharatgps.com");
        smtpCmd($socket, "AUTH LOGIN");
        smtpCmd($socket, base64_encode(MAIL_USER));
        smtpCmd($socket, base64_encode(MAIL_PASS));
        smtpCmd($socket, "MAIL FROM: <" . MAIL_FROM . ">");
        smtpCmd($socket, "RCPT TO: <$toEmail>");
        smtpCmd($socket, "DATA");

        $msg = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
             . "To: " . $toName . " <" . $toEmail . ">\r\n"
             . "Reply-To: " . MAIL_REPLY_TO . "\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "X-Mailer: BharatGPS/1.0\r\n"
             . "\r\n"
             . $htmlBody
             . "\r\n.";

        $response = smtpCmd($socket, $msg);
        smtpCmd($socket, "QUIT");
        fclose($socket);

        // 250 = success, anything starting with 2xx is ok
        return (substr(trim($response), 0, 1) === '2');

    } catch (Exception $e) {
        error_log("BharatGPS Mailer Error: " . $e->getMessage());
        return false;
    }
}

function smtpCmd($socket, $cmd): string {
    fwrite($socket, $cmd . "\r\n");
    return smtpRead($socket);
}
function smtpRead($socket): string {
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }
    return $response;
}

// ============================================================
// EMAIL TEMPLATES
// ============================================================

function emailTemplate(string $content): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Calibri,Arial,sans-serif;background:#f0f2f5;color:#1a1f2e}
.wrap{max-width:560px;margin:24px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.header{background:linear-gradient(135deg,#0E5C5C,#137272);padding:20px 28px;text-align:center}
.header img{height:60px;object-fit:contain;display:block;margin:0 auto}
.header h1{color:#fff;font-size:13px;font-weight:700;margin-top:8px;letter-spacing:1px;opacity:.7}
.body{padding:28px}
.greeting{font-size:16px;font-weight:700;color:#1a1f2e;margin-bottom:16px}
.divider{border:none;border-top:2px solid #e8eef8;margin:16px 0}
.details{background:#f7f8fa;border-radius:8px;padding:16px;margin:16px 0}
.row{display:flex;padding:7px 0;border-bottom:1px solid #eee;align-items:flex-start}
.row:last-child{border-bottom:none}
.label{font-size:13px;font-weight:700;color:#4a5568;width:160px;flex-shrink:0}
.value{font-size:13px;color:#1a1f2e;flex:1;font-weight:600}
.value.highlight{color:#1a7a3a;font-size:15px;font-weight:800}
.value.blue{color:#1a3a6b}
.tech-box{background:#e8eef8;border-radius:8px;padding:14px;margin:16px 0;border-left:4px solid #1a3a6b}
.tech-box .name{font-size:15px;font-weight:800;color:#1a3a6b}
.tech-box .phone{font-size:13px;color:#4a5568;margin-top:4px}
.footer{background:#f7f8fa;padding:16px 28px;text-align:center;border-top:1px solid #eee}
.footer p{font-size:12px;color:#8a9ab0;line-height:1.6}
.badge{display:inline-block;background:#e8f5ec;color:#1a7a3a;padding:3px 10px;border-radius:4px;font-size:13px;font-weight:700}
.badge.pending{background:#fff3e0;color:#d4680a}
.badge.free{background:#e8f5ec;color:#1a7a3a}
</style></head><body>
<div class="wrap">
<div class="header">
<table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 8px auto">
<tr><td style="background:#ffffff;border-radius:10px;padding:8px 20px;line-height:0">
<img src="https://salmon-goldfish-110661.hostingersite.com/logo.png" alt="BharatGPS Tracker"
  width="180" height="40"
  style="display:block;width:180px;height:40px;max-width:180px;border:0;outline:none">
</td></tr></table>
<div style="color:rgba(255,255,255,.75);font-size:11px;letter-spacing:1px;font-weight:600">TASK MANAGER NOTIFICATION</div>
</div>
<div class="body">' . $content . '</div>
<div class="footer">
<p>📍 Bharat GPS Tracker · Visakhapatnam, India<br>
📞 9849849824 &nbsp;·&nbsp; ✉️ sales@bharatgps.com<br>
🌐 <a href="https://bharatgpstracker.com" style="color:#0E5C5C">https://bharatgpstracker.com</a><br>
<span style="color:#b0b8c8;font-size:11px">This is an automated message from BharatGPS Task Manager.</span></p>
</div>
</div></body></html>';
}

// ---- TASK CREATED — Customer Email ----
function sendTaskCreatedCustomer(array $task, string $techName, string $techPhone): void {
    if (!$task['email']) return;
    $price = floatval($task['price_to_collect']);
    $priceStr = $price === 0.0
        ? '<span class="badge free">🎁 Free Service</span>'
        : '₹' . number_format($price, 0) . ' via ' . ($task['payment_mode'] ?: 'UPI');

    $content = '
    <div class="greeting">Dear ' . htmlspecialchars($task['customer_name']) . ',</div>
    <p style="font-size:14px;color:#4a5568;margin-bottom:16px">This is a confirmation of your service request with <strong>Bharat GPS Tracker</strong>.</p>
    <hr class="divider">
    <div class="details">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
        <div class="row"><div class="label">Service Type</div><div class="value">' . htmlspecialchars($task['device_details'] ?: $task['lead_type']) . '</div></div>
        ' . ((strpos(strtolower($task['device_details'] ?? ''), 'demonstration') !== false || strpos(strtolower($task['device_details'] ?? ''), 'demo') !== false || strpos(strtolower($task['device_details'] ?? ''), 'troubleshoot') !== false || strpos(strtolower($task['device_details'] ?? ''), 'offline') !== false) ? '' :
        '<div class="row"><div class="label">Device Qty</div><div class="value">' . intval($task['device_qty']) . '</div></div>') . '
        <div class="row"><div class="label">Amount to Pay</div><div class="value highlight">' . $priceStr . '</div></div>
        <div class="row"><div class="label">Location</div><div class="value">' . htmlspecialchars($task['location'] ?: '–') . '</div></div>
    </div>
    ' . ($techName ? '
    <div class="tech-box">
        <div style="font-size:12px;font-weight:700;color:#4a5568;text-transform:uppercase;margin-bottom:6px">👨‍🔧 Assigned Technician</div>
        <div class="name">' . htmlspecialchars($techName) . '</div>
        ' . ($techPhone ? '<div class="phone">📞 ' . htmlspecialchars($techPhone) . '</div>' : '') . '
    </div>' : '') . '
    <p style="font-size:14px;color:#4a5568;margin-top:16px">Our technician will contact you shortly to schedule the visit.</p>
    <p style="font-size:14px;font-weight:700;color:#1a3a6b;margin-top:12px">Thank you for choosing Bharat GPS Tracker! 🙏</p>';

    sendMail(
        $task['email'],
        $task['customer_name'],
        'Service Confirmation – ' . $task['task_id'] . ' | Bharat GPS Tracker',
        emailTemplate($content)
    );
}

// ---- TASK CREATED — Technician Email ----
function sendTaskCreatedTech(array $task, string $techEmail, string $techName): void {
    if (!$techEmail) return;
    $price = floatval($task['price_to_collect']);
    $priceStr = $price === 0.0 ? 'Free Service' : '₹' . number_format($price, 0) . ' via ' . ($task['payment_mode'] ?: 'UPI');

    $content = '
    <div class="greeting">Hi ' . htmlspecialchars($techName) . ',</div>
    <p style="font-size:14px;color:#4a5568;margin-bottom:16px">A new task has been assigned to you. Please contact the customer at the earliest.</p>
    <hr class="divider">
    <div class="details">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
        <div class="row"><div class="label">Customer</div><div class="value">' . htmlspecialchars($task['customer_name']) . '</div></div>
        <div class="row"><div class="label">Contact</div><div class="value highlight"><a href="tel:' . $task['contact_number'] . '" style="color:#1a3a6b">' . $task['contact_number'] . '</a></div></div>
        <div class="row"><div class="label">Location</div><div class="value">' . htmlspecialchars($task['location'] ?: '–') . '</div></div>
        <div class="row"><div class="label">Service Type</div><div class="value">' . htmlspecialchars($task['lead_type']) . '</div></div>
        <div class="row"><div class="label">GPS Type</div><div class="value">' . htmlspecialchars($task['device_details'] ?: 'Engine Status') . '</div></div>
        <div class="row"><div class="label">Device Qty</div><div class="value">' . intval($task['device_qty']) . '</div></div>
        <div class="row"><div class="label">Amount to Collect</div><div class="value highlight">' . htmlspecialchars($priceStr) . '</div></div>
        ' . ($task['general_notes'] ? '<div class="row"><div class="label">Manager Notes</div><div class="value">' . htmlspecialchars($task['general_notes']) . '</div></div>' : '') . '
    </div>
    <p style="font-size:13px;color:#4a5568;margin-top:12px">Please log in to the task manager to update your progress.</p>';

    sendMail(
        $techEmail,
        $techName,
        'New Task Assigned – ' . $task['task_id'] . ' | ' . $task['customer_name'],
        emailTemplate($content)
    );
}

// ---- TASK CLOSED — Customer Email ----
function sendTaskClosedCustomer(array $task): void {
    if (!$task['email']) return;
    $collected = floatval($task['amount_collected']);
    $expected  = floatval($task['price_to_collect']);
    $balance   = max(0, $expected - $collected);

    $content = '
    <div class="greeting">Dear ' . htmlspecialchars($task['customer_name']) . ',</div>
    <p style="font-size:14px;color:#4a5568;margin-bottom:16px">Your service request has been <strong style="color:#1a7a3a">successfully completed</strong>. Thank you for trusting Bharat GPS Tracker!</p>
    <hr class="divider">
    <div class="details">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
        <div class="row"><div class="label">Service</div><div class="value">' . htmlspecialchars($task['device_details'] ?: 'GPS Installation') . '</div></div>
        <div class="row"><div class="label">Status</div><div class="value"><span class="badge">✅ Closed</span></div></div>
        <div class="row"><div class="label">Amount Billed</div><div class="value">₹' . number_format($expected, 0) . '</div></div>
        <div class="row"><div class="label">Amount Paid</div><div class="value highlight">₹' . number_format($collected, 0) . '</div></div>
        ' . ($balance > 0 ? '<div class="row"><div class="label">Balance Due</div><div class="value" style="color:#c0392b;font-weight:700">₹' . number_format($balance, 0) . '</div></div>' : '') . '
    </div>
    <p style="font-size:14px;font-weight:700;color:#1a3a6b;margin-top:16px">Thank you for choosing Bharat GPS Tracker! 🙏</p>
    <p style="font-size:13px;color:#4a5568;margin-top:6px">For any support, call us at <strong>9849849824</strong>.</p>';

    sendMail(
        $task['email'],
        $task['customer_name'],
        'Service Completed – ' . $task['task_id'] . ' | Bharat GPS Tracker',
        emailTemplate($content)
    );
}

// ---- TASK UPDATE — Customer Email with full history + Report button ----
function sendTaskUpdateCustomer(array $task, string $remark, string $techName, array $activities = []): void {
    if (empty($task['email'])) return;

    $BASE_URL = 'https://salmon-goldfish-110661.hostingersite.com';
    $token    = $task['feedback_token'] ?? '';
    $feedbackUrl = $BASE_URL . '/feedback.php?token=' . urlencode($token);

    // Parse latest remark into structured parts
    $parts      = explode(' | ', $remark);
    $mainRemark = ltrim(trim($parts[0]), '📞🔧 ');
    $details    = array_slice($parts, 1);

    // Status colour
    $statusColors = [
        'Open'=>'#e07b00','In Progress'=>'#1a56a0','Task Pending'=>'#d4680a',
        'Awaiting Approval'=>'#5b2d8e','Closed'=>'#1a7a3a','Cancelled'=>'#8a9ab0',
    ];
    $sc = $statusColors[$task['task_status']] ?? '#1a3a6b';

    // Build full activity history HTML (newest at bottom = chronological for customer)
    $historyHtml = '';
    if(!empty($activities)){
        $historyHtml .= '<div style="margin:20px 0"><div style="font-size:11px;font-weight:700;color:#8a9ab0;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">📋 Full Update History</div>';
        foreach($activities as $i => $act){
            $aType   = $act['activity_type'] ?? 'remark';
            if($aType === 'system') continue; // skip system entries
            $aName   = htmlspecialchars($act['user_name'] ?? 'BharatGPS Team');
            $aDt     = date('d M Y, h:i A', strtotime($act['created_at']));
            $aRemark = $act['remark'] ?? '';
            $aParts  = explode(' | ', $aRemark);
            $aMain   = htmlspecialchars(ltrim(trim($aParts[0]), '📞🔧 '));
            $aDets   = array_slice($aParts, 1);
            $isNew   = ($i === count($activities)-1);
            $border  = $isNew ? '#1a3a6b' : '#d0d5dd';
            $bg      = $isNew ? '#e8eef8' : '#f7f8fa';

            $historyHtml .= '<div style="margin-bottom:10px;border-left:3px solid '.$border.';padding:10px 12px;background:'.$bg.';border-radius:0 6px 6px 0">';
            $historyHtml .= '<div style="display:flex;justify-content:space-between;margin-bottom:5px">';
            $historyHtml .= '<span style="font-size:11px;font-weight:700;color:#4a5568">'.$aName.'</span>';
            if($isNew) $historyHtml .= '<span style="background:#1a3a6b;color:#fff;font-size:9px;font-weight:800;padding:1px 7px;border-radius:4px">LATEST</span>';
            $historyHtml .= '<span style="font-size:10px;color:#8a9ab0">'.$aDt.'</span></div>';
            $historyHtml .= '<div style="font-size:13px;font-weight:600;color:#1a1f2e">'.$aMain.'</div>';
            foreach($aDets as $det){
                $historyHtml .= '<div style="font-size:11px;color:#4a5568;margin-top:2px">· '.htmlspecialchars(trim($det)).'</div>';
            }
            $historyHtml .= '</div>';
        }
        $historyHtml .= '</div>';
    }

    // Detail rows for this update
    $detailRows = '';
    foreach($details as $d){
        if(!trim($d)) continue;
        if(strpos($d,': ') !== false){
            [$k,$v] = explode(': ', $d, 2);
            $detailRows .= '<div class="row"><div class="label">'.htmlspecialchars(trim($k)).'</div><div class="value">'.htmlspecialchars(trim($v)).'</div></div>';
        }
    }

    // Report button — small, inline, next to latest update label
    $reportBtn = $token
        ? '<a href="' . $feedbackUrl . '" style="display:inline-block;background:#fdecea;color:#c0392b;border:1px solid #c0392b;padding:4px 10px;border-radius:5px;font-size:11px;font-weight:800;text-decoration:none">⚠️ Report False Update</a>'
        : '';

    $emailContent = '
    <div class="greeting">Dear ' . htmlspecialchars($task['customer_name']) . ',</div>
    <p style="font-size:14px;color:#4a5568;margin-bottom:16px">
        Your service request <strong style="color:#1a3a6b">' . $task['task_id'] . '</strong>
        has been updated by your technician.
    </p>

    <!-- Latest update box — report button inline in header -->
    <div style="background:#e8eef8;border-left:4px solid #1a3a6b;border-radius:8px;padding:16px;margin-bottom:14px">
        <div style="display:table;width:100%;margin-bottom:8px">
            <div style="display:table-cell;vertical-align:middle">
                <span style="font-size:10px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">📋 Latest Update</span>
            </div>
            <div style="display:table-cell;text-align:right;vertical-align:middle">
                ' . $reportBtn . '
            </div>
        </div>
        <div style="font-size:15px;font-weight:700;color:#1a1f2e;line-height:1.5">' . htmlspecialchars($mainRemark) . '</div>
        ' . ($techName ? '<div style="font-size:12px;color:#4a5568;margin-top:6px">— ' . htmlspecialchars($techName) . '</div>' : '') . '
    </div>

    ' . ($detailRows ? '<div class="details">'.$detailRows.'</div>' : '') . '

    <!-- Task status -->
    <div class="details" style="margin-bottom:0">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
        <div class="row"><div class="label">Service</div><div class="value">' . htmlspecialchars($task['device_details'] ?: 'GPS Service') . '</div></div>
        <div class="row"><div class="label">Status</div><div class="value"><span style="background:'.$sc.'22;color:'.$sc.';padding:2px 8px;border-radius:4px;font-weight:700">' . $task['task_status'] . '</span></div></div>
        <div class="row"><div class="label">Technician</div><div class="value">' . htmlspecialchars($techName ?: 'BharatGPS Team') . '</div></div>
    </div>

    <!-- Full history -->
    ' . $historyHtml . '

    <p style="font-size:12px;color:#8a9ab0;margin-top:16px">
        For help contact us at <strong>9849849824</strong> · <strong>sales@bharatgps.com</strong>
    </p>';

    sendMail(
        $task['email'],
        $task['customer_name'],
        'Task Update – ' . $task['task_id'] . ' | ' . htmlspecialchars($task['customer_name']) . ' | Bharat GPS',
        emailTemplate($emailContent)
    );
}

// ---- CONSENT REQUEST — sent to customer before installation ----
function sendConsentRequest(array $task, string $techName): void {
    if (empty($task['email'])) return;
    $BASE_URL   = 'https://salmon-goldfish-110661.hostingersite.com';
    $consentUrl = $BASE_URL . '/consent.php?token=' . urlencode($task['consent_token'] ?? '');
    $price      = number_format(floatval($task['price_to_collect'] ?? 0), 0);
    $isFree     = floatval($task['price_to_collect'] ?? 0) == 0 || ($task['payment_mode']??'') === 'Free Service';

    // Detect job type for correct wording
    $jobRaw = strtolower($task['device_details'] ?? '');
    if(strpos($jobRaw,'troubleshoot')!==false || strpos($jobRaw,'offline')!==false) {
        $jobLabel    = 'Troubleshoot / Offline Fix';
        $actionLine  = 'Our technician <strong>' . htmlspecialchars($techName) . '</strong> is coming to fix your GPS device and bring it back online.';
        $actionTitle = '⚠️ Action Required — Please Confirm Your Availability';
        $actionDesc  = 'This is a <strong>free service visit</strong>. Please confirm your vehicle will be available. If the vehicle is not available when the technician arrives, a <strong>₹300 visit charge</strong> will apply.';
        $btnText     = '✅ Confirm Vehicle Availability';
        $emailSubject= 'Action Required: Confirm GPS Troubleshoot Visit — ' . $task['task_id'];
        $amountRow   = '<div class="row"><div class="label">Charge</div><div class="value" style="color:#1a7a3a">FREE (₹300 if vehicle unavailable)</div></div>';
    } elseif(strpos($jobRaw,'vehicle to vehicle')!==false || strpos($jobRaw,'v2v')!==false) {
        $jobLabel    = 'Vehicle to Vehicle Change';
        $actionLine  = 'Our technician <strong>' . htmlspecialchars($techName) . '</strong> is coming to transfer your GPS device from your old vehicle to your new vehicle.';
        $actionTitle = '⚠️ Action Required — Please Confirm & Pay';
        $actionDesc  = 'Please ensure <strong>both vehicles are available</strong> at the time of visit and confirm your payment of <strong>&#8377;' . $price . '</strong>.';
        $btnText     = '✅ Confirm & Accept V2V Change';
        $emailSubject= 'Action Required: Confirm V2V Change — ' . $task['task_id'];
        $amountRow   = '<div class="row"><div class="label">V2V Charge</div><div class="value highlight">&#8377;' . $price . '</div></div>';
    } elseif(strpos($jobRaw,'re-adding')!==false || strpos($jobRaw,'re adding')!==false) {
        $jobLabel    = 'Re-Adding Service';
        $actionLine  = 'Our technician <strong>' . htmlspecialchars($techName) . '</strong> is coming to re-add your vehicle to the GPS server.';
        $actionTitle = '⚠️ Action Required — Please Confirm & Pay';
        $actionDesc  = 'Please ensure your <strong>vehicle is available and running</strong> and confirm your payment of <strong>&#8377;' . $price . '</strong>.';
        $btnText     = '✅ Confirm Re-Adding Service';
        $emailSubject= 'Action Required: Confirm Re-Adding Service — ' . $task['task_id'];
        $amountRow   = '<div class="row"><div class="label">Service Charge</div><div class="value highlight">&#8377;' . $price . '</div></div>';
    } elseif(strpos($jobRaw,'only remove')!==false || strpos($jobRaw,'remove only')!==false) {
        $jobLabel    = 'GPS Device Removal';
        $actionLine  = 'Our technician <strong>' . htmlspecialchars($techName) . '</strong> is coming to permanently remove your GPS device.';
        $actionTitle = '⚠️ Action Required — Please Confirm Removal';
        $actionDesc  = 'Please ensure your <strong>vehicle is available</strong>. Note: This action is <strong>permanent</strong> — the device will be taken back and cannot be reinstalled without a new order. <strong>No charge</strong> for removal.';
        $btnText     = '✅ Confirm GPS Removal';
        $emailSubject= 'Action Required: Confirm GPS Removal — ' . $task['task_id'];
        $amountRow   = '<div class="row"><div class="label">Removal Charge</div><div class="value" style="color:#1a7a3a">FREE</div></div>';
    } elseif(strpos($jobRaw,'demonstration')!==false || strpos($jobRaw,'demo')!==false) {
        $jobLabel    = 'GPS Demonstration';
        $actionLine  = 'Our technician <strong>' . htmlspecialchars($techName) . '</strong> is coming to demonstrate the BharatGPS system to you.';
        $actionTitle = '✅ Confirm Your Availability';
        $actionDesc  = 'This is a <strong>free demonstration</strong> — no GPS will be installed and there is <strong>no obligation to purchase</strong>. Please confirm you will be available at the location.';
        $btnText     = '✅ Confirm I Will Be Available';
        $emailSubject= 'Action Required: Confirm Demo Visit — ' . $task['task_id'];
        $amountRow   = '<div class="row"><div class="label">Demo Charge</div><div class="value" style="color:#1a7a3a">FREE</div></div>';
    } else {
        // Default: GPS Installation
        $jobLabel    = $task['device_details'] ?? 'GPS Installation';
        $actionLine  = 'Your BharatGPS technician <strong>' . htmlspecialchars($techName) . '</strong> is ready to begin your <strong>' . htmlspecialchars($task['device_details'] ?? 'GPS') . '</strong> installation.';
        $actionTitle = '⚠️ Action Required Before Installation Begins';
        $actionDesc  = 'Please read and accept our Terms &amp; Conditions and confirm your payment of <strong style="font-size:18px;color:#1a7a3a">&#8377;' . $price . '</strong>. The technician will wait for your confirmation.';
        $btnText     = '✅ Read T&C & Confirm Payment';
        $emailSubject= 'Action Required: Confirm Your GPS Installation — ' . $task['task_id'];
        $amountRow   = '<div class="row"><div class="label">Amount Agreed</div><div class="value highlight">&#8377;' . $price . '</div></div>';
    }

    $custName = htmlspecialchars($task['customer_name']);
    $jobLabelEsc = htmlspecialchars($jobLabel);
    $techNameEsc = htmlspecialchars($techName);
    $taskIdEsc   = htmlspecialchars($task['task_id']);
    $emailContent = "<div class=\"greeting\">Dear {$custName},</div>"
        . "<p style=\"font-size:14px;color:#4a5568;margin-bottom:16px\">{$actionLine}</p>"
        . "<div style=\"background:#e8f5ec;border:2px solid #1a7a3a;border-radius:10px;padding:18px;margin-bottom:16px;text-align:center\">"
        . "<div style=\"font-size:13px;font-weight:700;color:#1a7a3a;margin-bottom:8px\">{$actionTitle}</div>"
        . "<p style=\"font-size:13px;color:#1a1f2e;margin-bottom:14px;line-height:1.6\">{$actionDesc}</p>"
        . "<a href=\"{$consentUrl}\" style=\"display:inline-block;background:#0E5C5C;color:#fff;padding:14px 28px;border-radius:8px;font-size:15px;font-weight:800;text-decoration:none\">"
        . "{$btnText}</a>"
        . "<p style=\"font-size:11px;color:#8a9ab0;margin-top:10px;word-break:break-all\">Cannot click? Copy this link: {$consentUrl}</p>"
        . "</div>"
        . "<div class=\"details\">"
        . "<div class=\"row\"><div class=\"label\">Task ID</div><div class=\"value blue\">{$taskIdEsc}</div></div>"
        . "<div class=\"row\"><div class=\"label\">Service</div><div class=\"value\">{$jobLabelEsc}</div></div>"
        . $amountRow
        . "<div class=\"row\"><div class=\"label\">Technician</div><div class=\"value\">{$techNameEsc}</div></div>"
        . "</div>"
        . "<p style=\"font-size:12px;color:#8a9ab0;margin-top:16px\">For help call <strong>9849849824</strong></p>";

    sendMail(
        $task['email'],
        $task['customer_name'],
        $emailSubject,
        emailTemplate($emailContent)
    );
}

// ── Cancellation email to customer ────────────────────────────────────────

// ── Postponement email to customer (after consent) ────────────────────────
function sendPostponeCustomer($task, $reason, $details, $rescheduleDate, $techName){
    if(empty($task['email'])) return;

    $price      = number_format(floatval($task['price_to_collect']??0), 0);
    $service    = htmlspecialchars($task['device_details']??'GPS Installation');
    $customer   = htmlspecialchars($task['customer_name']??'');
    $taskId     = htmlspecialchars($task['task_id']??'');
    $reschedule = $rescheduleDate ? date('d M Y', strtotime($rescheduleDate)) : 'To be confirmed';

    $content = '
    <div class="greeting">Dear ' . $customer . ',</div>
    <p style="font-size:14px;color:#4a5568;margin-bottom:14px;line-height:1.7">
        Your BharatGPS installation scheduled for today has been
        <strong style="color:#e07b00">postponed</strong>. We sincerely apologise for any inconvenience.
    </p>
    <div class="details">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $taskId . '</div></div>
        <div class="row"><div class="label">Service</div><div class="value">' . $service . '</div></div>
        <div class="row"><div class="label">Reason</div><div class="value">' . htmlspecialchars($reason) . '</div></div>
        ' . ($details ? '<div class="row"><div class="label">Details</div><div class="value">' . htmlspecialchars($details) . '</div></div>' : '') . '
        <div class="row"><div class="label">Rescheduled For</div><div class="value highlight">' . $reschedule . '</div></div>
        <div class="row"><div class="label">Technician</div><div class="value">' . htmlspecialchars($techName) . '</div></div>
    </div>
    <div style="background:#fff3e0;border:1.5px solid #e07b00;border-radius:8px;padding:16px;margin:16px 0">
        <div style="font-size:13px;font-weight:800;color:#e07b00;margin-bottom:8px">&#129309; Is Everything Okay?</div>
        <div style="font-size:13px;color:#1a1f2e;line-height:1.7">
            If your vehicle was unavailable or you needed more time — no worries at all.<br><br>
            <strong>Simply reply to this email</strong> and let us know a convenient time.
        </div>
    </div>
    <p style="font-size:13px;color:#4a5568;margin-top:12px">
        For immediate assistance call <strong>9849849824</strong>.
    </p>';

    $content .= customerActionButtons($task);

    sendMail(
        $task['email'],
        $customer,
        '&#9208; Installation Postponed — ' . $taskId . ' | Rescheduled: ' . $reschedule,
        emailTemplate($content)
    );
}


function customerActionButtons($task){
    $token = $task['feedback_token'] ?? '';
    if(!$token || $token === 'USED') return '';
    $base          = 'https://salmon-goldfish-110661.hostingersite.com/feedback.php?token=' . urlencode($token);
    $disputeUrl    = $base . '&mode=dispute';
    $rescheduleUrl = $base . '&mode=reschedule';
    return '
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e0e0e0">
      <div style="font-size:12px;color:#8A9A98;text-align:center;margin-bottom:12px">What would you like to do?</div>
      <div style="text-align:center">
        <a href="' . $disputeUrl . '" style="display:inline-block;margin:4px;padding:11px 20px;background:#E74C3C;color:#fff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800">&#9888;&#65039; Raise Dispute</a>
        <a href="' . $rescheduleUrl . '" style="display:inline-block;margin:4px;padding:11px 20px;background:#0E5C5C;color:#fff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800">&#128260; Reschedule Installation</a>
      </div>
    </div>';
}

function sendCancelCustomer($task, $reason, $details, $techName){
    if(empty($task['email'])) return;

    $price    = number_format(floatval($task['price_to_collect']??0), 0);
    $service  = htmlspecialchars($task['device_details']??'GPS Installation');
    $customer = htmlspecialchars($task['customer_name']??'');
    $taskId   = htmlspecialchars($task['task_id']??'');

    $content = '
    <div class="greeting">Dear ' . $customer . ',</div>
    <p style="font-size:14px;color:#4a5568;margin-bottom:14px;line-height:1.7">
        We are writing to inform you that your BharatGPS installation request has been 
        <strong style="color:#E74C3C">cancelled</strong>.
    </p>
    <div class="details">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $taskId . '</div></div>
        <div class="row"><div class="label">Service</div><div class="value">' . $service . '</div></div>
        <div class="row"><div class="label">Reason</div><div class="value">' . htmlspecialchars($reason) . '</div></div>
        ' . ($details ? '<div class="row"><div class="label">Details</div><div class="value">' . htmlspecialchars($details) . '</div></div>' : '') . '
        <div class="row"><div class="label">Technician</div><div class="value">' . htmlspecialchars($techName) . '</div></div>
    </div>
    <div style="background:#FCE9E7;border:1.5px solid #E74C3C;border-radius:8px;padding:14px 16px;margin:16px 0">
        <div style="font-size:12px;font-weight:800;color:#E74C3C;margin-bottom:6px">❓ Was this a mistake?</div>
        <div style="font-size:13px;color:#1a1f2e;line-height:1.7">
            If you did not request this cancellation or if this was done in error, 
            please raise a dispute using the button below. Our team will review and 
            restore your installation immediately.
        </div>
    </div>
    <p style="font-size:13px;color:#4a5568;line-height:1.7">
        If you still wish to get BharatGPS installed, use the reschedule button below 
        and we will set up a new appointment for you.
    </p>
    <p style="font-size:13px;color:#4a5568;margin-top:12px">
        For immediate assistance call <strong>9849849824</strong>.
    </p>';

    $content .= customerActionButtons($task);
    sendMail(
        $task['email'],
        $customer,
        '❌ Installation Cancelled — ' . $taskId . ' | ' . $customer,
        emailTemplate($content)
    );
}

