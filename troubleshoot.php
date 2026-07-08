<?php
header('Content-Type: text/html; charset=UTF-8');
require_once 'api/db.php';
require_once 'api/req_token.php';
$pdo = getDB();

$SUPPORT_EMAIL = 'sales@bharatgps.com';
$COMPANY_NAME  = 'Bharat GPS Tracker';

$error = '';
$success = false;
$createdTaskId = '';
$createdAmount = 0;

// ---- LINK TOKEN VALIDATION (6h expiry + single use) ----
$FORM_TYPE = 'troubleshoot';
$token = trim($_GET['t'] ?? '');
$LINK_STATE = 'valid'; // valid | expired | used | invalid | none
if ($token === '') {
    $LINK_STATE = 'none'; // allow direct open (no token) — treated as valid open link
} else {
    $tk = reqCheckToken($pdo, $token, $FORM_TYPE);
    if ($tk['valid'])        $LINK_STATE = 'valid';
    elseif ($tk['expired'])  $LINK_STATE = 'expired';
    elseif ($tk['used'])     $LINK_STATE = 'used';
    else                     $LINK_STATE = 'invalid';
}
$TOKEN_HASH = ($token !== '') ? hash('sha256', $token) : '';

// Assigner-set price + GST from signed token (0 = free)
$PRICE = 0; $GST = 0;
if (!empty($tk) && !empty($tk['valid'])) { $PRICE = floatval($tk['price']); $GST = !empty($tk['gst']) ? 1 : 0; }
$GST_AMT = $GST ? round($PRICE * 0.18, 2) : 0;
$TOTAL   = $PRICE + $GST_AMT;

// Optional prefill from link
$pref_vehicle = trim($_GET['v'] ?? '');
$pref_phone   = trim($_GET['p'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submitted'])) {
    require_once 'api/mailer.php';
    // Re-validate token on submit
    $postToken = trim($_POST['tok'] ?? $token);
    if ($postToken !== '') {
        $tk2 = reqCheckToken($pdo, $postToken, $FORM_TYPE);
        if ($tk2['valid']) { $PRICE = floatval($tk2['price']); $GST = !empty($tk2['gst']) ? 1 : 0; $GST_AMT = $GST ? round($PRICE*0.18,2) : 0; $TOTAL = $PRICE + $GST_AMT; }
        if (!$tk2['valid']) {
            $error = $tk2['expired'] ? 'This link has expired.' : ($tk2['used'] ? 'This link has already been used.' : 'Invalid link.');
        }
        $TOKEN_HASH = $tk2['hash'];
    }
    $cust_name   = trim($_POST['cust_name']   ?? '');
    $q_regular   = trim($_POST['q_regular']   ?? '');   // Yes / No
    $q_battery   = trim($_POST['q_battery']   ?? '');   // Yes / No
    $offline_since = trim($_POST['offline_since'] ?? ''); // date
    $vehicle     = trim($_POST['vehicle']     ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $location    = trim($_POST['location']    ?? '');
    $geo         = trim($_POST['geo']         ?? '');
    $agree       = isset($_POST['agree']);

    if ($error) {
        // token error already set — do not proceed
    } elseif (!$cust_name || !$q_regular || !$q_battery || !$offline_since || !$vehicle || !$phone || !$email || !$location) {
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
              . "• Vehicle Number: $vehicle\n"
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
                    $taskId, $cust_name, $phone, $email, $location, 'Troubleshoot',
                    'Troubleshoot/Offline', 1, $TOTAL, '',
                    'Open', $notes, $cb, $vehicle
                ]);
            $newId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
                ->execute([$newId, $cb,
                    "🌐 Customer troubleshoot request | Customer: $cust_name | Vehicle: $vehicle | Offline since: $offline_since | Regular use: $q_regular | Battery changed: $q_battery"
                ]);

            // Email notification to admin/assigner
            if (function_exists('sendMail')) {
                try {
                    $rows =
                        '<tr><td style="padding:6px 0;color:#667;font-size:13px">Task ID</td><td style="padding:6px 0;font-weight:700;color:#2E6BE2;text-align:right">'.htmlspecialchars($taskId).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Customer</td><td style="padding:6px 0;font-weight:700;text-align:right">'.htmlspecialchars($cust_name).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Vehicle No.</td><td style="padding:6px 0;font-weight:700;text-align:right">'.htmlspecialchars($vehicle).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Phone</td><td style="padding:6px 0;font-weight:700;text-align:right">'.htmlspecialchars($phone).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Email</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($email).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Offline Since</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($offline_since).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Regular Use</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($q_regular).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Battery Changed</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($q_battery).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Location</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($location).'</td></tr>';
                    $body = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
                      . '<div style="background:#2E6BE2;color:#fff;padding:18px 20px;border-radius:10px 10px 0 0"><h2 style="margin:0;font-size:18px">🔧 New Troubleshoot Request</h2></div>'
                      . '<div style="background:#fff;border:1px solid #e5e9f0;border-top:none;padding:18px 20px;border-radius:0 0 10px 10px">'
                      . '<p style="color:#4a5568;font-size:14px;margin:0 0 12px">A customer submitted a troubleshoot request via the form.</p>'
                      . '<table style="width:100%;border-collapse:collapse">'.$rows.'</table>'
                      . '<p style="margin-top:16px;font-size:14px;font-weight:700"><a href="https://salmon-goldfish-110661.hostingersite.com" style="color:#2E6BE2">Open Task Manager →</a></p>'
                      . '</div></div>';
                    sendMail($SUPPORT_EMAIL, $COMPANY_NAME.' Support',
                        '🔧 Troubleshoot Request – '.$taskId.' | '.$vehicle, $body);

                    // Confirmation email to the CUSTOMER (dispute awareness)
                    $cbody = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
                      . '<div style="background:#2E6BE2;color:#fff;padding:18px 20px;border-radius:10px 10px 0 0"><h2 style="margin:0;font-size:18px">✅ Request Received — BharatGPS</h2></div>'
                      . '<div style="background:#fff;border:1px solid #e5e9f0;border-top:none;padding:18px 20px;border-radius:0 0 10px 10px">'
                      . '<p style="color:#2a3548;font-size:14px;margin:0 0 10px">Dear '.htmlspecialchars($cust_name).',</p>'
                      . '<p style="color:#4a5568;font-size:13.5px;line-height:1.6;margin:0 0 12px">We have received your <b>GPS Troubleshoot</b> request for vehicle <b>'.htmlspecialchars($vehicle).'</b>. Your reference number is <b>'.$taskId.'</b>. Our technician will contact you shortly.</p>'
                      . '<div style="background:#fff7e6;border:1px solid #e8a33d;border-radius:8px;padding:12px;font-size:12px;color:#6b4e12;line-height:1.6">If you did <b>not</b> request this service, someone may have used your email by mistake. Please contact us immediately at <b>+91 98498 49824</b> to raise a dispute.</div>'
                      . '<p style="color:#99a;font-size:11px;margin-top:14px">BharatGPS · Fleet Tracking Solutions</p>'
                      . '</div></div>';
                    if ($email) sendMail($email, $cust_name, 'BharatGPS — Troubleshoot Request Received ('.$taskId.')', $cbody);
                } catch(Exception $e) {}
            }

            // mark link used (single-use)
            if (!empty($TOKEN_HASH)) reqMarkUsed($pdo, $TOKEN_HASH);

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
  html { width: 100%; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fff; color: #1a2230; line-height: 1.5; -webkit-text-size-adjust: 100%; overflow-x: hidden; }
  .wrap { width: 100%; max-width: 520px; margin: 0 auto; min-height: 100vh; display: flex; flex-direction: column; }
  @media (min-width: 560px){
    body { background: #f2f5f9; padding: 20px 12px; }
    .wrap { min-height: auto; }
    .card { border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.07); }
  }
  .card { background: #fff; overflow: hidden; flex: 1; display: flex; flex-direction: column; }
  .hd { background: linear-gradient(135deg,#1e5bd6,#2E6BE2); color: #fff; padding: 22px 20px; text-align: center; }
  .logo-box { display: inline-flex; align-items: center; justify-content: center; background: #fff; border-radius: 12px; padding: 8px 14px; margin-bottom: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
  .logo-box img { height: 40px; max-width: 180px; object-fit: contain; display: block; }
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
  .foot { text-align: center; font-size: 11px; color: #99a; padding: 14px 0 20px; background: #fff; }
  .confirm-box { text-align: center; padding: 8px 4px 6px; }
  .confirm-ic { font-size: 40px; margin-bottom: 12px; }
  .confirm-en { font-size: 15px; font-weight: 700; color: #1a2230; margin-bottom: 12px; line-height: 1.55; }
  .confirm-te, .confirm-hi { font-size: 13.5px; color: #4a5568; margin-bottom: 10px; line-height: 1.6; }
  .price-tag { background: #e8f2ff; border: 1.5px solid #2E6BE2; border-radius: 10px; padding: 12px 14px; text-align: center; margin-bottom: 16px; }
  .price-tag .lbl { font-size: 11px; color: #1e5bd6; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
  .price-tag .amt { font-size: 24px; font-weight: 800; color: #1a3a6b; }
  .confirm-yes { width: 100%; padding: 14px; background: #1a9d5a; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 800; cursor: pointer; margin: 14px 0 8px; }
  .confirm-no { width: 100%; padding: 12px; background: #fff; color: #667; border: 1.5px solid #d5dce7; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
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
  <script>
    // Prevent resubmission via back button
    if (window.history.replaceState) { window.history.replaceState(null, '', location.pathname); }
    window.addEventListener('pageshow', function(e){ if(e.persisted){ location.reload(); } });
    window.addEventListener('popstate', function(){ window.close(); location.href = 'about:blank'; });
  </script>
<?php elseif (in_array($LINK_STATE, ['expired','used','invalid'])): ?>
  <div class="card">
    <div class="hd">
      <div class="logo-box"><img src="logo.png" alt="BharatGPS" onerror="this.style.display='none'"></div>
      <h1>🔧 GPS Troubleshoot Request</h1>
    </div>
    <div class="body">
      <div style="text-align:center;padding:30px 10px">
        <div style="font-size:48px;margin-bottom:12px"><?= $LINK_STATE==='used' ? '✅' : '⏳' ?></div>
        <h2 style="font-size:18px;color:#c0392b;margin-bottom:10px"><?= $LINK_STATE==='used' ? 'Link Already Used' : ($LINK_STATE==='expired' ? 'Link Expired' : 'Invalid Link') ?></h2>
        <p style="font-size:13.5px;color:#555;line-height:1.6"><?= $LINK_STATE==='used' ? 'This request has already been submitted. If you need more help, please contact us.' : ($LINK_STATE==='expired' ? 'This link is valid for 6 hours only. Please ask BharatGPS to send you a fresh link.' : 'This link is not valid. Please ask BharatGPS to send you a new one.') ?></p>
        <p style="margin-top:14px;font-size:13px;color:#2E6BE2;font-weight:700">📞 +91 98498 49824</p>
      </div>
    </div>
  </div>
  <div class="foot">BharatGPS · Fleet Tracking Solutions</div>
<?php else: ?>
  <div class="card">
    <div class="hd">
      <div class="logo-box"><img src="logo.png" alt="BharatGPS" onerror="this.style.display='none'"></div>
      <h1>🔧 GPS Troubleshoot Request</h1>
      <p>Fill this form so our technician can help fix your device.</p>
    </div>
    <div class="body">
      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <!-- Confirmation gate — shown first -->
      <div id="ts-confirm" <?= $error ? 'style="display:none"' : '' ?>>
        <div class="confirm-box">
          <div class="confirm-ic">🚗📡</div>
          <p class="confirm-en">You are here to submit a request to check your vehicle's GPS because it is showing offline. Is that correct?</p>
          <p class="confirm-te">మీ వాహనం GPS ఆఫ్‌లైన్‌లో ఉన్నందున దాన్ని తనిఖీ చేయడానికి అభ్యర్థన సమర్పించడానికి మీరు ఇక్కడ ఉన్నారు. ఇది సరైనదేనా?</p>
          <p class="confirm-hi">आप अपने वाहन का GPS ऑफ़लाइन दिखने के कारण उसकी जाँच के लिए अनुरोध भेजने आए हैं। क्या यह सही है?</p>
        </div>
        <?php if ($PRICE > 0): ?>
        <div class="price-tag"><div class="lbl">Service Charge</div><div class="amt">₹<?= number_format($TOTAL) ?></div><div style="font-size:10px;color:#1e5bd6;margin-top:2px"><?= $GST ? ('Base ₹'.number_format($PRICE).' + 18% GST ₹'.number_format($GST_AMT).' = ₹'.number_format($TOTAL)) : '' ?></div></div>
        <?php else: ?>
        <div class="price-tag" style="background:#e6f7ee;border-color:#1a9d5a"><div class="lbl" style="color:#1a7a3a">Service Charge</div><div class="amt" style="color:#1a7a3a">FREE</div></div>
        <?php endif; ?>
        <button type="button" class="confirm-yes" onclick="tsConfirmYes()">✅ Yes / అవును / हाँ</button>
        <button type="button" class="confirm-no" onclick="tsConfirmNo()">No / కాదు / नहीं</button>
        <div id="ts-no-msg" style="display:none;margin-top:14px;background:#fdecec;color:#c0392b;padding:12px 14px;border-radius:8px;font-size:12.5px;line-height:1.6">
          No problem. For any other help, please contact BharatGPS support on <b>+91 98498 49824</b> (call or WhatsApp).
        </div>
      </div>

      <form method="POST" id="tsForm" style="display:none" onsubmit="return tsSubmitting()">
        <input type="hidden" name="form_submitted" value="1">
        <input type="hidden" name="tok" value="<?= htmlspecialchars($token) ?>">

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
          <label>Your Name <span class="req">*</span></label>
          <input type="text" name="cust_name" placeholder="e.g. Ravi Kumar" required value="<?= htmlspecialchars($_POST['cust_name'] ?? '') ?>">
        </div>
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
function tsConfirmYes(){
  document.getElementById('ts-confirm').style.display = 'none';
  document.getElementById('tsForm').style.display = '';
}
function tsConfirmNo(){
  document.getElementById('ts-no-msg').style.display = 'block';
}
function tsSubmitting(){
  var b = document.getElementById('sbtn');
  if(b){ b.disabled = true; b.textContent = '⏳ Submitting… please wait'; b.style.opacity='0.75'; }
  return true;
}
function captureGeo(){
  const btn = event.target;
  if(!navigator.geolocation){ alert('Geolocation not supported on this device.'); return; }
  btn.textContent = '📍 Getting location…';
  navigator.geolocation.getCurrentPosition(function(pos){
    const lat = pos.coords.latitude.toFixed(6), lng = pos.coords.longitude.toFixed(6);
    document.getElementById('geoInput').value = lat + ',' + lng;
    const loc = document.getElementById('locInput');
    btn.textContent = '📍 Getting address…';
    // Reverse-geocode to a readable address (free, no key)
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18&addressdetails=1', { headers: { 'Accept':'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d && d.display_name){ loc.value = d.display_name; }
        else if(!loc.value){ loc.value = 'Lat ' + lat + ', Lng ' + lng; }
        btn.textContent = '✅ Location captured';
        btn.style.background = '#e6f7ee'; btn.style.color = '#1a9d5a'; btn.style.borderColor = '#1a9d5a';
      })
      .catch(function(){
        if(!loc.value) loc.value = 'Lat ' + lat + ', Lng ' + lng;
        btn.textContent = '✅ Location captured';
        btn.style.background = '#e6f7ee'; btn.style.color = '#1a9d5a'; btn.style.borderColor = '#1a9d5a';
      });
  }, function(){
    btn.textContent = '📍 Use My Current Location';
    alert('Could not get location. Please enter it manually.');
  }, { enableHighAccuracy:true, timeout:10000 });
}
</script>
</body>
</html>
