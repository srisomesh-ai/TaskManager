<?php
// Quick email test — visit this URL to test Gmail SMTP
// https://salmon-goldfish-110661.hostingersite.com/test_email.php?to=your@email.com
require_once __DIR__ . '/api/mailer.php';

$to   = $_GET['to'] ?? '';
$test = filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';

echo "<pre style='font-family:monospace;padding:20px'>";
echo "BharatGPS Email Test\n";
echo "====================\n\n";

if(!$test){
    echo "Usage: ?to=your@email.com\n";
    echo "\nSMTP Config:\n";
    echo "  Host: " . MAIL_HOST . "\n";
    echo "  Port: " . MAIL_PORT . "\n";
    echo "  From: " . MAIL_FROM . "\n";
    echo "\nTesting socket connection...\n";
    $socket = @fsockopen(MAIL_HOST, MAIL_PORT, $errno, $errstr, 10);
    if($socket){
        echo "✅ Socket to smtp.gmail.com:587 CONNECTED\n";
        fclose($socket);
    } else {
        echo "❌ Socket FAILED: $errstr ($errno)\n";
        echo "   Hostinger may be blocking outbound SMTP port 587\n";
    }
} else {
    echo "Sending test email to: $test\n\n";
    $body = emailTemplate('<div class="greeting">Test Email</div><p style="font-size:14px;color:#4a5568">If you receive this, the email system is working correctly.</p>');
    $result = sendMail($test, 'Test', 'BharatGPS Email Test — ' . date('H:i:s'), $body);
    if($result){
        echo "✅ Email SENT successfully to $test\n";
        echo "   Check your inbox (may take 1-2 min)\n";
    } else {
        echo "❌ Email FAILED — check error log\n";
        echo "   Common reasons:\n";
        echo "   1. Port 587 blocked by Hostinger\n";
        echo "   2. Gmail App Password wrong\n";
        echo "   3. 2FA not enabled on Gmail\n";
    }
}
echo "</pre>";
