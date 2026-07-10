<?php
// ============================================================
// BHARAT GPS — CRON JOB
// Run every hour via Hostinger Cron:
// URL: https://salmon-goldfish-110661.hostingersite.com/api/cron.php?key=BGPS_CRON_2026
// Schedule: Every 1 hour
// ============================================================

// Security key check
$cronKey = 'BGPS_CRON_2026'; // ← change this to something secret
if (($_GET['key'] ?? '') !== $cronKey) {
    http_response_code(403);
    die('Unauthorized');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/fcm_send.php';

$pdo = getDB();
$log = [];
$now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));

// ============================================================
// JOB 1: Push nudges for tasks assigned but NOT OPENED — at ~10, 30, 60 min
// Runs every 10 min. Fires each milestone once (guarded by activity log).
// ============================================================
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN opened_at DATETIME DEFAULT NULL"); } catch(Exception $e){}

$unopen = $pdo->query("
    SELECT t.*, u.name AS tech_name,
           TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) AS mins_since
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.task_status = 'Open'
      AND t.assigned_to IS NOT NULL
      AND t.opened_at IS NULL
      AND t.created_at <= DATE_SUB(NOW(), INTERVAL 9 MINUTE)
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 25 HOUR)
")->fetchAll();

foreach ($unopen as $task) {
    $mins = intval($task['mins_since']);
    // Pick the highest milestone reached
    $milestone = 0;
    if ($mins >= 60) $milestone = 60;
    elseif ($mins >= 30) $milestone = 30;
    elseif ($mins >= 10) $milestone = 10;
    if ($milestone === 0) continue;

    // Fire each milestone only once
    $guard = $pdo->prepare("SELECT 1 FROM task_activities WHERE task_id=? AND activity_type='system' AND remark LIKE ? LIMIT 1");
    $guard->execute([$task['id'], '%unopened push '.$milestone.'m%']);
    if ($guard->fetch()) continue;

    $label = $milestone >= 60 ? '1 hour' : ($milestone.' minutes');
    $title = '⏰ Task waiting — '.$label;
    $bodyMsg = ($task['customer_name'] ?? 'A customer').' · '.($task['device_details'] ?? 'GPS task').' — not opened yet. Tap to attend before the customer is lost.';
    if (!empty($task['assigned_to'])) {
        fcm_send_to_user($pdo, intval($task['assigned_to']), $title, $bodyMsg, [
            'type'    => 'task_reminder',
            'task_id' => (string)$task['id'],
            'url'     => 'task.html?id='.$task['id'],
        ]);
    }
    $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 1, ?, 'system')")
        ->execute([$task['id'], "⏰ Reminder: unopened push {$milestone}m sent to {$task['tech_name']}"]);
    $log[] = "Unopened push {$milestone}m → {$task['tech_name']} for {$task['task_id']}";
}

// ============================================================
// JOB 2: Auto star rating on task close
// Auto-rates tasks that were just closed but have no rating
// ============================================================
function calcStars(string $createdAt, string $closedAt): int {
    $created = new DateTime($createdAt, new DateTimeZone('Asia/Kolkata'));
    $closed  = new DateTime($closedAt,  new DateTimeZone('Asia/Kolkata'));
    $hours   = ($closed->getTimestamp() - $created->getTimestamp()) / 3600;

    if ($hours <= 4)  return 5; // ⭐⭐⭐⭐⭐ Within 4 hours — exceptional
    if ($hours <= 12) return 5; // ⭐⭐⭐⭐⭐ Same day
    if ($hours <= 24) return 4; // ⭐⭐⭐⭐   Within 24 hours
    if ($hours <= 48) return 3; // ⭐⭐⭐     Within 48 hours
    if ($hours <= 72) return 2; // ⭐⭐       Within 3 days
    return 1;                   // ⭐         Over 3 days
}

$unrated = $pdo->query("
    SELECT * FROM tasks
    WHERE task_status = 'Closed'
      AND closed_at IS NOT NULL
      AND (star_rating IS NULL OR star_rating = 0)
")->fetchAll();

foreach ($unrated as $task) {
    $stars = calcStars($task['created_at'], $task['closed_at']);
    $pdo->prepare("UPDATE tasks SET star_rating = ? WHERE id = ?")
        ->execute([$stars, $task['id']]);
    $log[] = "Rated task {$task['task_id']}: {$stars} stars";
}

// ============================================================
// JOB 3: Follow-up reminder emails to technician
// Runs every hour — finds tasks where reminder_date = today
// and last activity was NOT today (tech hasn't acted yet)
// Sends reminder email to technician via Gmail SMTP
// ============================================================
$todayIST = $now->format('Y-m-d');

$followUps = $pdo->query("
    SELECT t.*,
           u.name  AS tech_name,
           u.email AS tech_email,
           u.phone AS tech_phone,
           (SELECT MAX(ta.created_at) FROM task_activities ta WHERE ta.task_id = t.id) AS last_activity
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE DATE(t.reminder_date) = '$todayIST'
      AND t.task_status NOT IN ('Closed','Cancelled','Awaiting Approval')
      AND t.assigned_to IS NOT NULL
      AND u.email IS NOT NULL
      AND u.email != ''
      AND (
          -- No activity at all today yet
          NOT EXISTS (
              SELECT 1 FROM task_activities ta2
              WHERE ta2.task_id = t.id
                AND DATE(ta2.created_at) = '$todayIST'
          )
          OR
          -- Last activity was before today
          (SELECT MAX(ta3.created_at) FROM task_activities ta3 WHERE ta3.task_id = t.id) < '$todayIST'
      )
      AND NOT EXISTS (
          -- Don't send more than once today for same task
          SELECT 1 FROM task_activities ta4
          WHERE ta4.task_id = t.id
            AND ta4.activity_type = 'system'
            AND ta4.remark LIKE '%Follow-up reminder email sent%'
            AND DATE(ta4.created_at) = '$todayIST'
      )
")->fetchAll();

foreach ($followUps as $task) {

    // Work out how long since creation
    $createdDt  = new DateTime($task['created_at'], new DateTimeZone('Asia/Kolkata'));
    $diffDays   = $now->diff($createdDt)->days;

    // Urgency colour based on age
    $urgColor   = $diffDays >= 3 ? '#c0392b' : ($diffDays >= 1 ? '#e07b00' : '#1a3a6b');
    $urgLabel   = $diffDays >= 3 ? '🚨 URGENT' : ($diffDays >= 1 ? '⚠️ Follow Up' : '📋 Reminder');

    $content = '
    <div class="greeting">Hi ' . htmlspecialchars($task['tech_name']) . ',</div>
    <p style="font-size:14px;font-weight:700;color:' . $urgColor . ';margin-bottom:14px">'
        . $urgLabel . ' — This task needs your attention today.</p>
    <div class="details">
        <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
        <div class="row"><div class="label">Customer</div><div class="value">' . htmlspecialchars($task['customer_name']) . '</div></div>
        <div class="row"><div class="label">Contact</div><div class="value highlight">
            <a href="tel:' . $task['contact_number'] . '" style="color:#1a3a6b;font-size:16px;font-weight:800">'
            . $task['contact_number'] . '</a>
        </div></div>
        <div class="row"><div class="label">Location</div><div class="value">' . htmlspecialchars($task['location'] ?? '–') . '</div></div>
        <div class="row"><div class="label">Job Type</div><div class="value">' . htmlspecialchars($task['device_details'] ?? '–') . '</div></div>
        <div class="row"><div class="label">Amount</div><div class="value highlight">₹' . number_format(floatval($task['price_to_collect']), 0) . '</div></div>
        <div class="row"><div class="label">Status</div><div class="value">' . htmlspecialchars($task['task_status']) . '</div></div>
        <div class="row"><div class="label">Open Since</div><div class="value" style="color:' . $urgColor . ';font-weight:700">' . $diffDays . ' day' . ($diffDays != 1 ? 's' : '') . '</div></div>
    </div>
    <div style="background:#fdecea;border-left:4px solid #c0392b;border-radius:6px;padding:14px;margin:16px 0">
        <div style="font-size:13px;font-weight:800;color:#c0392b;margin-bottom:6px">Action Required Today</div>
        <div style="font-size:13px;color:#1a1f2e">Please contact the customer, complete the job or update the task with the current status. <strong>The customer is waiting.</strong></div>
    </div>
    <p style="font-size:13px;color:#4a5568;margin-top:8px">Log in to the task manager to update your progress after contacting the customer.</p>
    <p style="font-size:12px;color:#8a9ab0;margin-top:12px">This is an automated reminder triggered because this task is scheduled for follow-up today.</p>';

    $sent = sendMail(
        $task['tech_email'],
        $task['tech_name'],
        $urgLabel . ': Follow-up Required – ' . $task['task_id'] . ' | ' . $task['customer_name'],
        emailTemplate($content)
    );

    if ($sent) {
        // Log that reminder was sent so we don't double-send today
        $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 1, ?, 'system')")
            ->execute([
                $task['id'],
                "⏰ Follow-up reminder email sent to technician {$task['tech_name']} ({$task['tech_email']})"
            ]);
        $log[] = "Follow-up reminder → {$task['tech_name']} for {$task['task_id']} (open {$diffDays}d)";
    }
    // Push notification for the follow-up (payment/reminder date reached)
    if (!empty($task['assigned_to'])) {
        fcm_send_to_user($pdo, intval($task['assigned_to']),
            '🔔 Follow-up due today',
            ($task['customer_name'] ?? 'Customer').' · '.($task['device_details'] ?? 'task').' — scheduled follow-up today. Tap to act.',
            ['type'=>'task_reminder','task_id'=>(string)$task['id'],'url'=>'task.html?id='.$task['id']]);
    }
}

// ============================================================
// JOB 4 — Payment reminder T+1 hour after installation
// Finds tasks where device was installed but no payment yet
// ============================================================
$oneHourAgo = (clone $now)->modify('-1 hour')->format('Y-m-d H:i:s');
$oneDayAgo  = (clone $now)->modify('-24 hours')->format('Y-m-d H:i:s');

$unpaidTasks = $pdo->query("
    SELECT t.*,
           u.name  AS tech_name,
           u.email AS tech_email,
           c.email AS customer_email,
           c.name  AS customer_name_col,
           (SELECT MIN(di.saved_at) FROM task_device_installs di WHERE di.task_id=t.id) AS install_time
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to=u.id
    LEFT JOIN users c ON t.created_by=c.id
    WHERE t.task_status NOT IN ('Closed','Cancelled','Awaiting Approval')
      AND (t.amount_collected IS NULL OR t.amount_collected=0)
      AND EXISTS (SELECT 1 FROM task_device_installs di2 WHERE di2.task_id=t.id AND di2.gps_serial_no IS NOT NULL)
      AND t.assigned_to IS NOT NULL
")->fetchAll();

foreach($unpaidTasks as $task){
    $installTime = $task['install_time'] ?? null;
    if(!$installTime) continue;

    $installDt = new DateTime($installTime, new DateTimeZone('Asia/Kolkata'));
    $diffMins  = ($now->getTimestamp() - $installDt->getTimestamp()) / 60;

    // T+1 hour reminder (between 60-90 mins since install)
    if($diffMins >= 60 && $diffMins < 90){
        // Check not already sent today
        $alreadySent = $pdo->prepare("
            SELECT COUNT(*) FROM task_activities
            WHERE task_id=? AND activity_type='system'
            AND remark LIKE '%T+1h payment reminder%'
            AND DATE(created_at)=?");
        $alreadySent->execute([$task['id'], $todayIST]);
        if($alreadySent->fetchColumn() > 0) continue;

        require_once __DIR__.'/mailer.php';
        $price = number_format(floatval($task['price_to_collect']),0);

        // Email to technician
        if($task['tech_email']){
            $techContent = '
            <div class="greeting">Hi ' . htmlspecialchars($task['tech_name']) . ',</div>
            <p style="font-size:14px;font-weight:700;color:#e07b00;margin-bottom:14px">💳 Payment Collection Reminder</p>
            <div class="details">
                <div class="row"><div class="label">Task</div><div class="value blue">' . $task['task_id'] . '</div></div>
                <div class="row"><div class="label">Customer</div><div class="value">' . htmlspecialchars($task['customer_name']) . '</div></div>
                <div class="row"><div class="label">Contact</div><div class="value highlight"><a href="tel:' . $task['contact_number'] . '" style="color:#1a3a6b;font-weight:800">' . $task['contact_number'] . '</a></div></div>
                <div class="row"><div class="label">Amount Due</div><div class="value highlight">&#8377;' . $price . '</div></div>
            </div>
            <div style="background:#fff3cd;border:1.5px solid #e07b00;border-radius:8px;padding:14px;margin:14px 0">
                <div style="font-size:13px;font-weight:800;color:#e07b00;margin-bottom:6px">⚠️ Payment Not Collected Yet</div>
                <div style="font-size:13px;color:#1a1f2e">Installation was done 1+ hour ago but payment has not been recorded. Please collect &#8377;' . $price . ' from the customer immediately.</div>
            </div>
            <p style="font-size:12px;color:#4a5568">If customer is unable to pay now, record the payment commitment with a date in the task manager.</p>';
            sendMail($task['tech_email'], $task['tech_name'],
                '⚠️ Payment Pending — ' . $task['task_id'] . ' | ' . $task['customer_name'],
                emailTemplate($techContent));
        }

        // Email to customer
        if($task['email']){
            $custContent = '
            <div class="greeting">Dear ' . htmlspecialchars($task['customer_name']) . ',</div>
            <p style="font-size:14px;color:#4a5568;margin-bottom:14px">
                Your BharatGPS device has been successfully installed. Please complete your payment to activate full service.
            </p>
            <div class="details">
                <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
                <div class="row"><div class="label">Service</div><div class="value">' . htmlspecialchars($task['device_details']??'GPS Service') . '</div></div>
                <div class="row"><div class="label">Amount Due</div><div class="value highlight">&#8377;' . $price . '</div></div>
            </div>
            <p style="font-size:13px;color:#4a5568;margin-top:14px">Please complete your payment at the earliest. For assistance call <strong>9849849824</strong>.</p>';
            sendMail($task['email'], $task['customer_name'],
                'Payment Pending — BharatGPS ' . $task['task_id'],
                emailTemplate($custContent));
        }

        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,1,?,'system')")
            ->execute([$task['id'], "⏰ T+1h payment reminder sent to technician and customer"]);
        // Push notification to technician for pending payment
        if(!empty($task['assigned_to'])){
            fcm_send_to_user($pdo, intval($task['assigned_to']),
                '💳 Payment pending',
                ($task['customer_name'] ?? 'Customer').' — ₹'.$price.' not collected yet. Tap to collect.',
                ['type'=>'task_reminder','task_id'=>(string)$task['id'],'url'=>'task.html?id='.$task['id']]);
        }
        // Mark urgent
        $pdo->prepare("UPDATE tasks SET is_urgent=1 WHERE id=?")->execute([$task['id']]);
        $log[] = "T+1h payment reminder → {$task['task_id']}";
    }

    // T+24 hour final warning
    if($diffMins >= 1440 && $diffMins < 1470){
        $alreadySent = $pdo->prepare("
            SELECT COUNT(*) FROM task_activities
            WHERE task_id=? AND activity_type='system'
            AND remark LIKE '%T+24h final payment warning%'");
        $alreadySent->execute([$task['id']]);
        if($alreadySent->fetchColumn() > 0) continue;

        require_once __DIR__.'/mailer.php';
        $price = number_format(floatval($task['price_to_collect']),0);

        // Final warning to technician
        if($task['tech_email']){
            $finalTech = '
            <div class="greeting">Hi ' . htmlspecialchars($task['tech_name']) . ',</div>
            <p style="font-size:14px;font-weight:800;color:#c0392b;margin-bottom:14px">🚨 URGENT — Payment Not Received After 24 Hours</p>
            <div class="details">
                <div class="row"><div class="label">Task</div><div class="value blue">' . $task['task_id'] . '</div></div>
                <div class="row"><div class="label">Customer</div><div class="value">' . htmlspecialchars($task['customer_name']) . '</div></div>
                <div class="row"><div class="label">Contact</div><div class="value highlight"><a href="tel:' . $task['contact_number'] . '" style="color:#c0392b;font-weight:800">' . $task['contact_number'] . '</a></div></div>
                <div class="row"><div class="label">Amount Due</div><div class="value highlight">&#8377;' . $price . '</div></div>
            </div>
            <div style="background:#fdecea;border:2px solid #c0392b;border-radius:8px;padding:14px;margin:14px 0">
                <div style="font-size:13px;font-weight:800;color:#c0392b;margin-bottom:8px">Action Required Immediately</div>
                <div style="font-size:13px;color:#1a1f2e;line-height:1.7">Payment has not been collected 24 hours after installation. Please contact the customer immediately and collect payment, or escalate to your manager for recovery of the GPS device.</div>
            </div>';
            sendMail($task['tech_email'], $task['tech_name'],
                '🚨 24H Payment Overdue — ' . $task['task_id'],
                emailTemplate($finalTech));
        }

        // Final warning to customer
        if($task['email']){
            $finalCust = '
            <div class="greeting">Dear ' . htmlspecialchars($task['customer_name']) . ',</div>
            <div style="background:#fdecea;border:2px solid #c0392b;border-radius:8px;padding:16px;margin-bottom:16px">
                <div style="font-size:15px;font-weight:800;color:#c0392b;margin-bottom:8px">⚠️ Payment Not Received — Action Required</div>
                <p style="font-size:13px;color:#1a1f2e;line-height:1.7">
                    Your BharatGPS device (Task <strong>' . $task['task_id'] . '</strong>) was installed but payment of 
                    <strong>&#8377;' . $price . '</strong> has not been received.
                </p>
            </div>
            <p style="font-size:13px;color:#4a5568;line-height:1.7;margin-bottom:14px">
                If payment is not received, <strong>the GPS device may be deactivated</strong> and our technician will be sent to recover the device from your vehicle.
            </p>
            <p style="font-size:13px;color:#4a5568">Please call us immediately at <strong>9849849824</strong> to resolve this.</p>';
            sendMail($task['email'], $task['customer_name'],
                '⚠️ IMPORTANT: Payment Due — BharatGPS ' . $task['task_id'],
                emailTemplate($finalCust));
        }

        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,1,?,'system')")
            ->execute([$task['id'], "🚨 T+24h PAYMENT OVERDUE — Final warning sent to customer and technician. Device deactivation warning issued."]);
        $log[] = "T+24h final warning → {$task['task_id']}";
    }
}

// ============================================================
// DONE
// ============================================================
echo json_encode([
    'status'      => 'ok',
    'time'        => $now->format('Y-m-d H:i:s'),
    'reminders'   => count($unopen),
    'rated'       => count($unrated),
    'follow_ups'  => count($followUps),
    'payment_rem' => count($unpaidTasks),
    'log'         => $log,
]);

// ── JOB: Demo Follow-up Reminder — send email on follow-up date ───────────
$demoFollowups = $pdo->query("
    SELECT t.*, u.name as tech_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.task_status = 'Demo Done'
    AND t.demo_followup_date IS NOT NULL
    AND DATE(t.demo_followup_date) = CURDATE()
    AND t.email IS NOT NULL AND t.email != ''
    AND NOT EXISTS (
        SELECT 1 FROM task_activities ta
        WHERE ta.task_id = t.id
        AND ta.remark LIKE '%Follow-up reminder sent%'
        AND DATE(ta.created_at) = CURDATE()
    )
")->fetchAll();

foreach($demoFollowups as $task){
    sendDemoFollowupCustomer($task);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")
        ->execute([$task['id'], 0, '📧 Follow-up reminder sent to customer — ' . $task['email']]);
}
if(count($demoFollowups)) echo "Demo follow-ups sent: " . count($demoFollowups) . "\n";

