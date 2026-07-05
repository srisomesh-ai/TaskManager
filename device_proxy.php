<?php
// device_proxy.php — CORS-safe passthrough for the Device Tracker TEST page.
// Usage: device_proxy.php?base=https://bharatgps.in&hash=YOUR_HASH
// Calls <base>/api/get_devices?user_api_hash=<hash> server-side and returns JSON.

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$base = isset($_GET['base']) ? rtrim(trim($_GET['base']), '/') : '';
$hash = isset($_GET['hash']) ? trim($_GET['hash']) : '';

if ($base === '' || $hash === '') {
    http_response_code(400);
    echo json_encode(['error' => 'base and hash are required']);
    exit;
}

// Basic allow-list so this proxy can only hit bharatgps servers.
$host = parse_url($base, PHP_URL_HOST);
$allowed = ['bharatgps.com','bharatgps.in','bharatgps.school','bharatgps.org','bharatgps.net'];
$ok = false;
foreach ($allowed as $a) { if ($host === $a || substr($host, -strlen('.'.$a)) === '.'.$a) { $ok = true; break; } }
if (!$ok) {
    http_response_code(403);
    echo json_encode(['error' => 'host not allowed: ' . $host]);
    exit;
}

$url = $base . '/api/get_devices?user_api_hash=' . urlencode($hash);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream fetch failed', 'detail' => $cerr]);
    exit;
}

// Pass through the upstream body and status as-is.
http_response_code($code ?: 200);
echo $resp;
