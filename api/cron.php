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

$pdo = getDB();
$log = [];
$now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));

// ============================================================
// JOB 1: Technician reminder — assigned but not opened in 1hr
// ============================================================
$unopen = $pdo->query("
    SELECT t.*, u.name AS tech_name, u.email AS tech_email, u.phone AS tech_phone,
           cb.name AS manager_name, cb.email AS manager_email
    FROM tasks t
    LEFT JOIN users u  ON t.assigned_to = u.id
    LEFT JOIN users cb ON t.created_by  = cb.id
    WHERE t.task_status = 'Open'
      AND t.assigned_to IS NOT NULL
      AND t.created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 25 HOUR)
      AND NOT EXISTS (
          SELECT 1 FROM task_activities ta
          WHERE ta.task_id = t.id
            AND ta.activity_type IN ('remark','status_change')
            AND ta.user_id = t.assigned_to
      )
")->fetchAll();

foreach ($unopen as $task) {
    // Send reminder to technician
    if ($task['tech_email']) {
        $content = '
        <div class="greeting">Hi ' . htmlspecialchars($task['tech_name']) . ',</div>
        <p style="font-size:14px;color:#c0392b;font-weight:700;margin-bottom:14px">⚠️ You have an unattended task assigned over 1 hour ago.</p>
        <div class="details">
            <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
            <div class="row"><div class="label">Customer</div><div class="value">' . htmlspecialchars($task['customer_name']) . '</div></div>
            <div class="row"><div class="label">Contact</div><div class="value highlight"><a href="tel:' . $task['contact_number'] . '" style="color:#1a3a6b">' . $task['contact_number'] . '</a></div></div>
            <div class="row"><div class="label">Location</div><div class="value">' . htmlspecialchars($task['location'] ?? '–') . '</div></div>
            <div class="row"><div class="label">GPS Type</div><div class="value">' . htmlspecialchars($task['device_details'] ?? 'Engine Status') . '</div></div>
            <div class="row"><div class="label">Assigned At</div><div class="value">' . $task['created_at'] . '</div></div>
        </div>
        <p style="font-size:14px;color:#c0392b;margin-top:14px;font-weight:700">Please call the customer immediately and update the task.</p>
        <p style="font-size:13px;color:#4a5568;margin-top:8px">Log in to the task manager to update your progress.</p>';

        sendMail(
            $task['tech_email'],
            $task['tech_name'],
            '⚠️ REMINDER: Unattended Task – ' . $task['task_id'],
            emailTemplate($content)
        );
        $log[] = "Reminder sent to tech: {$task['tech_name']} for {$task['task_id']}";
    }

    // Also notify manager
    if ($task['manager_email'] && $task['manager_email'] !== $task['tech_email']) {
        $content = '
        <div class="greeting">Hi ' . htmlspecialchars($task['manager_name']) . ',</div>
        <p style="font-size:14px;color:#c0392b;font-weight:700;margin-bottom:14px">⚠️ Task not attended by technician for over 1 hour.</p>
        <div class="details">
            <div class="row"><div class="label">Task ID</div><div class="value blue">' . $task['task_id'] . '</div></div>
            <div class="row"><div class="label">Customer</div><div class="value">' . htmlspecialchars($task['customer_name']) . '</div></div>
            <div class="row"><div class="label">Technician</div><div class="value" style="color:#c0392b;font-weight:700">' . htmlspecialchars($task['tech_name']) . '</div></div>
            <div class="row"><div class="label">Contact</div><div class="value">' . $task['contact_number'] . '</div></div>
            <div class="row"><div class="label">Assigned At</div><div class="value">' . $task['created_at'] . '</div></div>
        </div>
        <p style="font-size:14px;color:#c0392b;margin-top:14px">Please follow up with ' . htmlspecialchars($task['tech_name']) . ' immediately.</p>';

        sendMail(
            $task['manager_email'],
            $task['manager_name'],
            '⚠️ Unattended Task Alert – ' . $task['task_id'] . ' | ' . $task['tech_name'],
            emailTemplate($content)
        );
        $log[] = "Alert sent to manager: {$task['manager_name']} for {$task['task_id']}";
    }

    // Log reminder in activity
    $pdo->prepare("INSERT INTO task_activities (task_id, user_id, remark, activity_type) VALUES (?, 1, ?, 'system')")
        ->execute([$task['id'], "⏰ Reminder sent: technician {$task['tech_name']} has not opened task after 1 hour"]);
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
    'log'         => $log,
]);
