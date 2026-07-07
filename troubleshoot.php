<?php
header('Content-Type: text/html; charset=UTF-8');
require_once 'api/db.php';
$pdo = getDB();

$error = '';
$success = false;
$createdTaskId = '';

// Optional prefill from link
$pref_vehicle = trim($_GET['v'] ?? '');
$pref_phone   = trim($_GET['p'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submitted'])) {
    $q_regular   = trim($_POST['q_regular']   ?? '');   // Yes / No
    $q_battery   = trim($_POST['q_battery']   ?? '');   // Yes / No
    $offline_since = trim($_POST['offline_since'] ?? ''); // date
    $vehicle     = trim($_POST['vehicle']     ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $location    = trim($_POST['location']    ?? '');
    $geo         = trim($_POST['geo']         ?? '');
    $agree       = isset($_POST['agree']);

    if (!$q_regular || !$q_battery || !$offline_since || !$vehicle || !$phone || !$email || !$location) {
        $error = 'Please fill all required fields.';
    } elseif (!$agree) {
        $error = 'Please accept the service agreement before submitting.';
    } else {
        try {
            $year = date('Y');
            $cnt  = $pdo->query("SELECT COUNT(*) FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetchColumn();
            $taskId = 'ID-'.$year.'-'.str_pad($cnt+1, 4, '0', STR_PAD_LEFT);

            $cb = $pdo->query("SELECT id FROM users WHERE role IN ('admin','assigner') AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 1;

            // ensure vehicle_number column exists on tasks (safe if already there)
            try { $pdo->exec("ALTER TABLE tasks ADD COLUMN vehicle_number VARCHAR(50) NULL"); } catch(Exception $e) {}

            $notes =
                "🔧 TROUBLESHOOT REQUEST (customer form)\n"
              . "• Regularly using vehicle: $q_regular\n"
              . "• Recently changed battery: $q_battery\n"
              . "• Device offline since: $offline_since\n"
              . "• Vehicle availability location: $location"
              . ($geo ? "\n• Geo: $geo" : '');

            $pdo->prepare("INSERT INTO tasks
                (task_id,customer_name,contact_number,email,location,lead_type,
                 device_details,device_qty,price_to_collect,payment_mode,
                 task_status,general_notes,created_by,is_urgent,vehicle_number)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)")
                ->execute([
                    $taskId, $vehicle, $phone, $email, $location, 'Troubleshoot',
                    'Troubleshoot/Offline', 1, 0, '',
                    'Open', $notes, $cb, $vehicle
                ]);
            $newId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
                ->execute([$newId, $cb,
                    "🌐 Customer troubleshoot request | Vehicle: $vehicle | Offline since: $offline_since | Regular use: $q_regular | Battery changed: $q_battery"
                ]);

            $success = true;
            $createdTaskId = $taskId;
        } catch (Exception $e) {
            $error = 'Could not submit: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GPS Troubleshoot Request — BharatGPS</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f2f5f9; color: #1a2230; padding: 16px; line-height: 1.5; }
  .wrap { max-width: 460px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.07); overflow: hidden; }
  .hd { background: linear-gradient(135deg,#1e5bd6,#2E6BE2); color: #fff; padding: 22px 20px; }
  .hd h1 { font-size: 19px; font-weight: 800; }
  .hd p { font-size: 12px; opacity: .9; margin-top: 4px; }
  .body { padding: 20px; }
  .f { margin-bottom: 16px; }
  .f label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; color: #2a3548; }
  .req { color: #d33; }
  .f input[type=text], .f input[type=tel], .f input[type=email], .f input[type=date] {
    width: 100%; padding: 11px 12px; border: 1.5px solid #d5dce7; border-radius: 8px; font-size: 14px; }
  .f input:focus { outline: none; border-color: #2E6BE2; }
  .yn { display: flex; gap: 8px; }
  .yn label { flex: 1; margin: 0; }
  .yn input { display: none; }
  .yn span { display: block; text-align: center; padding: 10px; border: 1.5px solid #d5dce7; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; color: #667; }
  .yn input:checked + span { background: #2E6BE2; color: #fff; border-color: #2E6BE2; }
  .sec-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px; color: #2E6BE2; margin: 18px 0 12px; }
  .geo-btn { width: 100%; padding: 10px; background: #eef4ff; color: #2E6BE2; border: 1.5px solid #2E6BE2; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; margin-bottom: 8px; }
  .agree { display: flex; gap: 10px; align-items: flex-start; background: #fff7e6; border: 1.5px solid #e8a33d; border-radius: 10px; padding: 12px; margin: 8px 0 16px; }
  .agree input { margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0; }
  .agree label { font-size: 11.5px; color: #6b4e12; line-height: 1.55; }
  .submit { width: 100%; padding: 14px; background: #1a9d5a; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 800; cursor: pointer; }
  .submit:disabled { background: #9db; cursor: not-allowed; }
  .err { background: #fdecec; color: #c0392b; padding: 11px 13px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
  .ok-wrap { text-align: center; padding: 40px 24px; }
  .ok-wrap .ic { font-size: 54px; margin-bottom: 12px; }
  .ok-wrap h2 { font-size: 20px; color: #1a9d5a; margin-bottom: 8px; }
  .ok-wrap p { font-size: 13.5px; color: #555; }
  .ok-wrap .tid { font-weight: 800; color: #1a2230; }
  .foot { text-align: center; font-size: 11px; color: #99a; margin-top: 14px; }
</style>
</head>
<body>
<div class="wrap">
<?php if ($success): ?>
  <div class="card">
    <div class="ok-wrap">
      <div class="ic">✅</div>
      <h2>Request Submitted!</h2>
      <p>Your troubleshoot request has been received.<br>Reference: <span class="tid"><?= htmlspecialchars($createdTaskId) ?></span></p>
      <p style="margin-top:14px">Our technician will reach out shortly. Please keep your vehicle available for the visit.</p>
    </div>
  </div>
  <div class="foot">BharatGPS · Fleet Tracking Solutions</div>
<?php else: ?>
  <div class="card">
    <div class="hd">
      <h1>🔧 GPS Troubleshoot Request</h1>
      <p>Fill this form so our technician can help fix your device.</p>
    </div>
    <div class="body">
      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="POST" id="tsForm">
        <input type="hidden" name="form_submitted" value="1">

        <div class="sec-title">A few quick questions</div>

        <div class="f">
          <label>Are you regularly using your vehicle? <span class="req">*</span></label>
          <div class="yn">
            <label><input type="radio" name="q_regular" value="Yes" required><span>Yes</span></label>
            <label><input type="radio" name="q_regular" value="No"><span>No</span></label>
          </div>
        </div>

        <div class="f">
          <label>Have you recently changed your vehicle battery? <span class="req">*</span></label>
          <div class="yn">
            <label><input type="radio" name="q_battery" value="Yes" required><span>Yes</span></label>
            <label><input type="radio" name="q_battery" value="No"><span>No</span></label>
          </div>
        </div>

        <div class="f">
          <label>Since when is the device offline? <span class="req">*</span></label>
          <input type="date" name="offline_since" required max="<?= date('Y-m-d') ?>">
        </div>

        <div class="sec-title">Your Details</div>

        <div class="f">
          <label>Vehicle Number <span class="req">*</span></label>
          <input type="text" name="vehicle" placeholder="e.g. AP31AB1234" required value="<?= htmlspecialchars($pref_vehicle) ?>">
        </div>
        <div class="f">
          <label>Contact Number <span class="req">*</span></label>
          <input type="tel" name="phone" placeholder="9876543210" required value="<?= htmlspecialchars($pref_phone) ?>">
        </div>
        <div class="f">
          <label>Email ID <span class="req">*</span></label>
          <input type="email" name="email" placeholder="your@email.com" required>
        </div>
        <div class="f">
          <label>Vehicle Availability Location <span class="req">*</span></label>
          <button type="button" class="geo-btn" onclick="captureGeo()">📍 Use My Current Location</button>
          <input type="text" name="location" id="locInput" placeholder="Where the vehicle will be available" required>
          <input type="hidden" name="geo" id="geoInput">
        </div>

        <div class="agree">
          <input type="checkbox" name="agree" id="agree" onchange="document.getElementById('sbtn').disabled=!this.checked">
          <label for="agree">I agree that this service is <b>completely free</b>, but I will make sure the vehicle is <b>available at the time of the technician's visit</b>. If the vehicle is not available, a <b>service charge will apply</b>.</label>
        </div>

        <button type="submit" class="submit" id="sbtn" disabled>Submit Troubleshoot Request</button>
      </form>
    </div>
  </div>
  <div class="foot">BharatGPS · Fleet Tracking Solutions</div>
<?php endif; ?>
</div>
<script>
function captureGeo(){
  const btn = event.target;
  if(!navigator.geolocation){ alert('Geolocation not supported on this device.'); return; }
  btn.textContent = '📍 Getting location…';
  navigator.geolocation.getCurrentPosition(function(pos){
    const lat = pos.coords.latitude.toFixed(6), lng = pos.coords.longitude.toFixed(6);
    document.getElementById('geoInput').value = lat + ',' + lng;
    const loc = document.getElementById('locInput');
    if(!loc.value) loc.value = 'Lat ' + lat + ', Lng ' + lng;
    btn.textContent = '✅ Location captured';
    btn.style.background = '#e6f7ee'; btn.style.color = '#1a9d5a'; btn.style.borderColor = '#1a9d5a';
  }, function(){
    btn.textContent = '📍 Use My Current Location';
    alert('Could not get location. Please enter it manually.');
  }, { enableHighAccuracy:true, timeout:10000 });
}
</script>
</body>
</html>
