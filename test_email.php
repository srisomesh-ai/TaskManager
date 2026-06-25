<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/mailer.php';

$to     = $_GET['to'] ?? '';
$taskId = $_GET['task'] ?? '';

header('Content-Type: text/plain');

echo "BharatGPS Email Diagnostics\n";
echo "============================\n\n";

// 1. SMTP socket test
echo "1. SMTP Socket (smtp.gmail.com:587)\n";
$socket = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 10);
if($socket){
    echo "   PASS - connected\n";
    fclose($socket);
} else {
    echo "   FAIL - $errstr ($errno)\n";
}

// 2. Task email check
if($taskId){
    echo "\n2. Task email check for: $taskId\n";
    try {
        $pdo = getDB();
        $s = $pdo->prepare("SELECT task_id, customer_name, email, feedback_token FROM tasks WHERE task_id=? LIMIT 1");
        $s->execute([$taskId]);
        $t = $s->fetch();
        if($t){
            echo "   customer: " . $t['customer_name'] . "\n";
            echo "   email: " . ($t['email'] ?: 'EMPTY - no email on this task') . "\n";
            echo "   token: " . ($t['feedback_token'] ?: 'EMPTY') . "\n";
        } else {
            echo "   Task not found\n";
        }
    } catch(Exception $e){
        echo "   DB Error: " . $e->getMessage() . "\n";
    }
}

// 3. Send test email
if($to && filter_var($to, FILTER_VALIDATE_EMAIL)){
    echo "\n3. Sending test email to: $to\n";
    try {
        $html = emailTemplate('<div class="greeting">Test</div><p style="font-size:14px;color:#4a5568">BharatGPS SMTP test at ' . date('H:i:s') . '</p>');
        $ok = sendMail($to, 'Test', 'BharatGPS Test ' . date('H:i:s'), $html);
        echo $ok ? "   SENT ok\n" : "   FAILED - check php error_log\n";
    } catch(Exception $e){
        echo "   Exception: " . $e->getMessage() . "\n";
    }
} else {
    echo "\n3. Add ?to=your@email.com to send a test\n";
}

echo "\nDone.\n";
