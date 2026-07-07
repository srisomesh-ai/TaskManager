<?php
header('Content-Type: text/html; charset=UTF-8');
require_once 'api/db.php';
$pdo = getDB();

$token = trim($_GET['t'] ?? $_POST['t'] ?? '');
$state = 'form'; // form | saved | invalid
$custName = '';
$vehicle = '';

// ensure columns
foreach(["cust_loc_lat DECIMAL(10,6)","cust_loc_lng DECIMAL(10,6)","cust_loc_at DATETIME","loc_token VARCHAR(64)"] as $c){
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN $c DEFAULT NULL"); } catch(Exception $e){}
}

$task = null;
if($token !== ''){
    $s = $pdo->prepare("SELECT id, customer_name, vehicle_number, cust_loc_at FROM tasks WHERE loc_token=? LIMIT 1");
    $s->execute([$token]);
    $task = $s->fetch();
}
if(!$task){ $state = 'invalid'; }
else {
    $custName = $task['customer_name'] ?? '';
    $vehicle  = $task['vehicle_number'] ?? '';
    if(!empty($task['cust_loc_at'])) $state = 'saved'; // already shared
}

// handle save
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_loc']) && $task){
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    if($lat && $lng){
        $pdo->prepare("UPDATE tasks SET cust_loc_lat=?, cust_loc_lng=?, cust_loc_at=NOW() WHERE id=?")
            ->execute([$lat, $lng, $task['id']]);
        try {
            $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
                ->execute([$task['id'], 0, "📍 Customer shared their location"]);
        } catch(Exception $e){}
        $state = 'saved';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Share Your Location — BharatGPS</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html{width:100%}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#eef1f6;color:#12161c;line-height:1.5;-webkit-text-size-adjust:100%;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
  .wrap{width:100%;max-width:420px}
  .hero{background:linear-gradient(150deg,#1f5fd6,#3f7ae6);color:#fff;border-radius:16px 16px 0 0;padding:28px 22px;text-align:center}
  .logo-box{display:inline-flex;align-items:center;justify-content:center;background:#fff;border-radius:12px;padding:8px 14px;margin-bottom:12px;box-shadow:0 2px 10px rgba(0,0,0,.15)}
  .logo-box img{height:36px;max-width:160px;object-fit:contain;display:block}
  .hero .ic{font-size:38px}
  .hero h1{font-size:19px;font-weight:800;margin-top:6px}
  .hero p{font-size:12.5px;opacity:.92;margin-top:6px}
  .card{background:#fff;border-radius:0 0 16px 16px;padding:22px;text-align:center}
  .muted{font-size:12.5px;color:#8791a0}
  .muted b{color:#3b444f}
  .btn{display:block;width:100%;padding:15px;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;margin-top:16px}
  .btn-go{background:#1f5fd6;color:#fff}
  .btn:disabled{opacity:.6}
  .ok{background:#e6f6ee;border:1.5px solid #0f9d58;border-radius:10px;padding:16px;color:#0c6b3d;font-weight:700;margin-top:6px}
  .err{background:#fff5e6;border:1.5px solid #e08a1e;border-radius:10px;padding:12px;color:#7a5410;font-size:12.5px;margin-top:12px}
  .ic-big{font-size:52px}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div class="logo-box"><img src="logo.png" alt="BharatGPS" onerror="this.style.display='none'"></div>
    <div class="ic">📍</div>
    <h1>Share Your Location</h1>
    <p>So our technician can reach you quickly</p>
  </div>
  <div class="card">
    <?php if($state === 'invalid'): ?>
      <div class="ic-big">⛔</div>
      <h2 style="font-size:17px;margin:8px 0;color:#d93a3a">Invalid or expired link</h2>
      <p class="muted">Please ask BharatGPS to send a fresh location link.</p>
      <p class="muted" style="margin-top:10px">📞 +91 98498 49824</p>

    <?php elseif($state === 'saved'): ?>
      <div class="ic-big">✅</div>
      <h2 style="font-size:18px;margin:8px 0;color:#0f9d58">Location shared!</h2>
      <p class="muted">Thank you<?= $custName ? ', '.htmlspecialchars($custName) : '' ?>. Your technician can now navigate to you.<br>You can close this page.</p>

    <?php else: ?>
      <p class="muted"><?= $vehicle ? 'Vehicle <b>'.htmlspecialchars($vehicle).'</b>' : 'BharatGPS service' ?></p>
      <p class="muted" style="margin-top:8px">Tap the button and allow location access when your phone asks.</p>
      <button class="btn btn-go" id="shareBtn" onclick="shareLoc()">📍 Share My Current Location</button>
      <div id="msg"></div>
      <form method="POST" id="locForm" style="display:none">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="save_loc" value="1">
        <input type="hidden" name="lat" id="f-lat">
        <input type="hidden" name="lng" id="f-lng">
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
function shareLoc(){
  var btn = document.getElementById('shareBtn');
  var msg = document.getElementById('msg');
  if(!('geolocation' in navigator) || !window.isSecureContext){
    msg.innerHTML = '<div class="err">Location needs a secure connection. Please open the link directly (https).</div>';
    return;
  }
  btn.disabled = true; btn.textContent = '⏳ Getting your location…';
  navigator.geolocation.getCurrentPosition(function(pos){
    document.getElementById('f-lat').value = pos.coords.latitude.toFixed(6);
    document.getElementById('f-lng').value = pos.coords.longitude.toFixed(6);
    document.getElementById('locForm').submit();
  }, function(e){
    btn.disabled = false; btn.textContent = '📍 Share My Current Location';
    var m = 'Could not get your location.';
    if(e && e.code === 1) m = 'Permission denied. Tap the lock icon near the address bar and allow Location, then try again.';
    else if(e && e.code === 2) m = 'Turn on your phone GPS / Location and try again.';
    else if(e && e.code === 3) m = 'Timed out. Move near a window or step outside, then try again.';
    msg.innerHTML = '<div class="err">'+m+'</div>';
  }, {enableHighAccuracy:true, timeout:15000, maximumAge:0});
}
</script>
</body>
</html>
