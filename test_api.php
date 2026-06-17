<?php
// DIAGNOSTIC TEST - DELETE AFTER USE
header('Content-Type: text/html; charset=UTF-8');

echo "<h2>🔍 API Diagnostic Test</h2><pre>";

// Step 1: Check db.php exists
echo "1. db.php exists: ";
echo file_exists(__DIR__.'/api/db.php') ? "✅ YES\n" : "❌ NO\n";

// Step 2: Load db.php
echo "2. Loading db.php: ";
try {
    require_once __DIR__.'/api/db.php';
    echo "✅ OK\n";
} catch(Throwable $e) {
    echo "❌ ERROR: ".$e->getMessage()."\n";
    die("</pre>");
}

// Step 3: Connect DB
echo "3. DB Connection: ";
try {
    $pdo = getDB();
    echo "✅ Connected\n";
} catch(Throwable $e) {
    echo "❌ FAILED: ".$e->getMessage()."\n";
    die("</pre>");
}

// Step 4: Check users table
echo "4. Users table: ";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "✅ $count users found\n";
} catch(Throwable $e) {
    echo "❌ ".$e->getMessage()."\n";
}

// Step 5: Check admin user
echo "5. Admin user: ";
try {
    $u = $pdo->prepare("SELECT id,name,email,role,password FROM users WHERE email=?");
    $u->execute(['somesh9346220090@gmail.com']);
    $user = $u->fetch();
    if($user) {
        echo "✅ Found: {$user['name']} ({$user['role']})\n";
        echo "   Password hash: ".substr($user['password'],0,20)."...\n";
        echo "   Test 'password': ".(password_verify('password',$user['password'])?'✅ MATCHES':'❌ NO MATCH')."\n";
    } else {
        echo "❌ Not found\n";
    }
} catch(Throwable $e) {
    echo "❌ ".$e->getMessage()."\n";
}

// Step 6: Check mailer.php
echo "6. mailer.php exists: ";
echo file_exists(__DIR__.'/api/mailer.php') ? "✅ YES\n" : "❌ NO\n";

// Step 7: Test login API directly
echo "7. Login API test: ";
try {
    $ctx = stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>'Content-Type: application/json',
        'content'=>json_encode(['email'=>'somesh9346220090@gmail.com','password'=>'password']),
        'timeout'=>5
    ]]);
    $result = @file_get_contents('http://localhost/api/index.php?action=login', false, $ctx);
    if($result===false) {
        // Try with full URL
        $result = @file_get_contents('https://salmon-goldfish-110661.hostingersite.com/api/index.php?action=login', false, $ctx);
    }
    echo $result ? "Response: $result\n" : "❌ No response\n";
} catch(Throwable $e) {
    echo "❌ ".$e->getMessage()."\n";
}

echo "</pre>";
echo "<p style='color:red'><strong>⚠️ DELETE this file from server after use!</strong></p>";
