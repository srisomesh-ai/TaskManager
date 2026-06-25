<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/mailer.php';

$pdo = getDB();
$to  = $_GET['to'] ?? '';
$taskId = $_GET['task'] ?? '';

echo "<pre style='font-family:monospace;font-size:13px;padding:20px;background:#1a1f2e;color:#e2e8f0;min-height:100vh'>";
echo "BharatGPS — Email + Task Diagnostics\n";
echo "=====================================\n\n";

// 1. SMTP connection test
echo "1. SMTP Connection Test\n";
$socket = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 10);
if($socket){ 
    echo "   ✅ smtp.gmail.com:587 CONNECTED\n"; 
    fclose($socket); 
} else { 
    echo "   ❌ FAILED: $errstr ($errno)\n"; 
    echo "   → Hostinger is blocking port 587\n\n";
}

// 2. Check task email field
if($taskId){
    echo "\n2. Task '$taskId' email check\n";
    $s = $pdo->prepare("SELECT id, task_id, customer_name, email, feedback_token FROM tasks WHERE task_id=? OR id=? LIMIT 1");
    $s->execute([$taskId, $taskId]);
    $t = $s->fetch();
    if($t){
        echo "   Task ID:   " . $t['task_id'] . "\n";
        echo "   Customer:  " . $t['customer_name'] . "\n";
        echo "   Email:     " . ($t['email'] ?: '❌ EMPTY — no email stored') . "\n";
        echo "   Token:     " . ($t['feedback_token'] ?: '❌ EMPTY') . "\n";
    } else {
        echo "   ❌ Task not found\n";
    }
}

// 3. Send test email
if($to && filter_var($to, FILTER_VALIDATE_EMAIL)){
    echo "\n3. Sending test email to: $to\n";
    $body = emailTemplate('
        <div class="greeting">Test Email</div>
        <p style="font-size:14px;color:#4a5568">
            This is a test from BharatGPS task manager.<br>
            If you receive this, Gmail SMTP is working correctly.
        </p>
        <div class="details">
            <div class="row"><div class="label">Time</div><div class="value">' . date('d M Y H:i:s') . '</div></div>
            <div class="row"><div class="label">Server</div><div class="value">Hostinger Shared</div></div>
        </div>
    ');
    $result = sendMail($to, 'Test Recipient', 'BharatGPS Email Test — ' . date('H:i:s'), $body);
    echo $result ? "   ✅ Email SENT — check inbox\n" : "   ❌ Email FAILED — check error_log\n";
} else {
    echo "\n3. To send a test email: ?to=your@email.com\n";
}

echo "\n\nUsage: ?to=your@email.com&task=BGT-XXXX\n";
echo "</pre>";
