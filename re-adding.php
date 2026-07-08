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

$FORM_TYPE = 're-adding';
$token = trim($_GET['t'] ?? '');
$LINK_STATE = 'valid';
if ($token === '') { $LINK_STATE = 'none'; }
else { $tk = reqCheckToken($pdo, $token, $FORM_TYPE); if ($tk['valid']) $LINK_STATE='valid'; elseif ($tk['expired']) $LINK_STATE='expired'; elseif ($tk['used']) $LINK_STATE='used'; else $LINK_STATE='invalid'; }
$TOKEN_HASH = ($token !== '') ? hash('sha256', $token) : '';

$JOB_NAME = 'Re-Adding';
$PRICE = 1700;
try {
    $pr = $pdo->prepare("SELECT price_excl_gst FROM price_list WHERE product_name=? AND is_active=1 LIMIT 1");
    $pr->execute([$JOB_NAME]);
    $found = $pr->fetchColumn();
    if ($found !== false && $found !== null && floatval($found) > 0) $PRICE = floatval($found);
} catch(Exception $e) {}

// Assigner-set price + GST from the signed token override everything
$GST = 0;
if (!empty($tk) && !empty($tk['valid'])) {
    if (floatval($tk['price']) > 0) $PRICE = floatval($tk['price']);
    $GST = !empty($tk['gst']) ? 1 : 0;
}
$GST_AMT = $GST ? round($PRICE * 0.18, 2) : 0;
$TOTAL   = $PRICE + $GST_AMT;

$pref_phone   = trim($_GET['p'] ?? '');
$pref_vehicle = trim($_GET['v'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submitted'])) {
    require_once 'api/mailer.php';
    $postToken = trim($_POST['tok'] ?? $token);
    if ($postToken !== '') { $tk2 = reqCheckToken($pdo, $postToken, $FORM_TYPE); if (!$tk2['valid']) { $error = $tk2['expired'] ? 'This link has expired.' : ($tk2['used'] ? 'This link has already been used.' : 'Invalid link.'); } else { if (floatval($tk2['price']) > 0) $PRICE = floatval($tk2['price']); $GST = !empty($tk2['gst']) ? 1 : 0; $GST_AMT = $GST ? round($PRICE*0.18,2) : 0; $TOTAL = $PRICE + $GST_AMT; } $TOKEN_HASH = $tk2['hash']; }
    $cust_name = trim($_POST['cust_name'] ?? '');
    $vehicle   = trim($_POST['vehicle']   ?? '');
    $reason    = trim($_POST['reason']    ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $email     = trim($_POST['email']     ?? '');
    $location  = trim($_POST['location']  ?? '');
    $geo       = trim($_POST['geo']       ?? '');
    $agree     = isset($_POST['agree']);

    if ($error) {
    } elseif (!$cust_name || !$vehicle || !$phone || !$email || !$location) {
        $error = 'Please fill all required fields.';
    } elseif (!$agree) {
        $error = 'Please accept the service agreement before submitting.';
    } else {
        try {
            $year = date('Y');
            $cnt  = $pdo->query("SELECT COUNT(*) FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetchColumn();
            $taskId = 'ID-'.$year.'-'.str_pad($cnt+1, 4, '0', STR_PAD_LEFT);
            $cb = $pdo->query("SELECT id FROM users WHERE role IN ('admin','assigner') AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 1;
            try { $pdo->exec("ALTER TABLE tasks ADD COLUMN vehicle_number VARCHAR(50) NULL"); } catch(Exception $e) {}

            $notes =
                "🗑️ GPS REMOVAL (customer form)\n"
              . "• Vehicle: $vehicle\n"
              . ($reason ? "• Reason: $reason\n" : '')
              . "• Availability location: $location"
              . ($geo ? "\n• Geo: $geo" : '');

            $pdo->prepare("INSERT INTO tasks
                (task_id,customer_name,contact_number,email,location,lead_type,
                 device_details,device_qty,price_to_collect,payment_mode,
                 task_status,general_notes,created_by,is_urgent,vehicle_number)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)")
                ->execute([
                    $taskId, $cust_name, $phone, $email, $location, 'Existing Customer Lead',
                    $JOB_NAME, 1, $TOTAL, '',
                    'Open', $notes, $cb, $vehicle
                ]);
            $newId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
                ->execute([$newId, $cb,
                    "🌐 Customer GPS re-activation request | Customer: $cust_name | Vehicle: $vehicle | Price: Rs.$PRICE".($GST?" + GST Rs.$GST_AMT = Rs.$TOTAL":"")
                ]);

            if (function_exists('sendMail')) {
                try {
                    $rows =
                        '<tr><td style="padding:6px 0;color:#667;font-size:13px">Task ID</td><td style="padding:6px 0;font-weight:700;color:#2E6BE2;text-align:right">'.htmlspecialchars($taskId).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Customer</td><td style="padding:6px 0;font-weight:700;text-align:right">'.htmlspecialchars($cust_name).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Vehicle</td><td style="padding:6px 0;font-weight:700;text-align:right">'.htmlspecialchars($vehicle).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Phone</td><td style="padding:6px 0;font-weight:700;text-align:right">'.htmlspecialchars($phone).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Email</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($email).'</td></tr>'
                      . ($reason ? '<tr><td style="padding:6px 0;color:#667;font-size:13px">Reason</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($reason).'</td></tr>' : '')
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Price</td><td style="padding:6px 0;font-weight:700;text-align:right">Rs.'.number_format($PRICE).'</td></tr>'
                      . '<tr><td style="padding:6px 0;color:#667;font-size:13px">Location</td><td style="padding:6px 0;text-align:right">'.htmlspecialchars($location).'</td></tr>';
                    $body = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
                      . '<div style="background:#c0392b;color:#fff;padding:18px 20px;border-radius:10px 10px 0 0"><h2 style="margin:0;font-size:18px">🔄 GPS Re-Activation Request</h2></div>'
                      . '<div style="background:#fff;border:1px solid #e5e9f0;border-top:none;padding:18px 20px;border-radius:0 0 10px 10px">'
                      . '<table style="width:100%;border-collapse:collapse">'.$rows.'</table>'
                      . '<p style="margin-top:16px;font-size:14px;font-weight:700"><a href="https://salmon-goldfish-110661.hostingersite.com" style="color:#2E6BE2">Open Task Manager →</a></p>'
                      . '</div></div>';
                    sendMail($SUPPORT_EMAIL, $COMPANY_NAME.' Support',
                        '🔄 GPS Re-Activation – '.$taskId.' | '.$vehicle, $body);

                    $cbody = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
                      . '<div style="background:#c0392b;color:#fff;padding:18px 20px;border-radius:10px 10px 0 0"><h2 style="margin:0;font-size:18px">Request Received - BharatGPS</h2></div>'
                      . '<div style="background:#fff;border:1px solid #e5e9f0;border-top:none;padding:18px 20px;border-radius:0 0 10px 10px">'
                      . '<p style="color:#2a3548;font-size:14px;margin:0 0 10px">Dear '.htmlspecialchars($cust_name).',</p>'
                      . '<p style="color:#4a5568;font-size:13.5px;line-height:1.6;margin:0 0 12px">We have received your <b>GPS Removal</b> request for vehicle <b>'.htmlspecialchars($vehicle).'</b>. Reference: <b>'.$taskId.'</b>. Amount to pay: <b>Rs.'.number_format($TOTAL).'</b>'.($GST?' (incl. 18% GST)':'').'. Our technician will contact you shortly.</p>'
                      . '<div style="background:#fff7e6;border:1px solid #e8a33d;border-radius:8px;padding:12px;font-size:12px;color:#6b4e12;line-height:1.6">If you did <b>not</b> request this service, someone may have used your email by mistake. Please contact us at <b>+91 98498 49824</b> to raise a dispute.</div>'
                      . '<p style="color:#99a;font-size:11px;margin-top:14px">BharatGPS - Fleet Tracking Solutions</p>'
                      . '</div></div>';
                    if ($email) sendMail($email, $cust_name, 'BharatGPS - GPS Re-Activation Request Received ('.$taskId.')', $cbody);
                } catch(Exception $e) {}
            }

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
<title>GPS Re-Activation (Re-Adding) — BharatGPS</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html { width: 100%; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fff; color: #1a2230; line-height: 1.5; -webkit-text-size-adjust: 100%; overflow-x: hidden; }
  .wrap { width: 100%; max-width: 520px; margin: 0 auto; min-height: 100vh; display: flex; flex-direction: column; }
  @media (min-width: 560px){ body { background: #f2f5f9; padding: 20px 12px; } .wrap { min-height: auto; } .card { border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.07); } }
  .card { background: #fff; overflow: hidden; flex: 1; display: flex; flex-direction: column; }
  .hd { background: linear-gradient(135deg,#c0392b,#e05545); color: #fff; padding: 22px 20px; text-align: center; }
  .logo-box { display: inline-flex; align-items: center; justify-content: center; background: #fff; border-radius: 12px; padding: 8px 14px; margin-bottom: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
  .logo-box img { height: 40px; max-width: 180px; object-fit: contain; display: block; }
  .hd h1 { font-size: 19px; font-weight: 800; }
  .hd p { font-size: 12px; opacity: .9; margin-top: 4px; }
  .body { padding: 20px; }
  @media (max-width: 480px){ .hd { padding: 18px 16px; } .body { padding: 16px; } }
  .f { margin-bottom: 16px; }
  .f label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; color: #2a3548; }
  .req { color: #d33; }
  .f input, .f select { width: 100%; padding: 11px 12px; border: 1.5px solid #d5dce7; border-radius: 8px; font-size: 14px; }
  .f input:focus, .f select:focus { outline: none; border-color: #c0392b; }
  .sec-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px; color: #c0392b; margin: 18px 0 12px; }
  .price-tag { background: #fdeceb; border: 1.5px solid #c0392b; border-radius: 10px; padding: 12px 14px; text-align: center; margin-bottom: 16px; }
  .price-tag .lbl { font-size: 11px; color: #c0392b; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
  .price-tag .amt { font-size: 24px; font-weight: 800; color: #a02c20; }
  .geo-btn { width: 100%; padding: 10px; background: #fdeceb; color: #c0392b; border: 1.5px solid #c0392b; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; margin-bottom: 8px; }
  .agree { display: flex; gap: 10px; align-items: flex-start; background: #fff7e6; border: 1.5px solid #e8a33d; border-radius: 10px; padding: 12px; margin: 8px 0 16px; }
  .agree input { margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0; }
  .agree label { font-size: 11.5px; color: #6b4e12; line-height: 1.55; }
  .submit { width: 100%; padding: 14px; background: #c0392b; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 800; cursor: pointer; }
  .submit:disabled { background: #e0b0ab; cursor: not-allowed; }
  .err { background: #fdecec; color: #c0392b; padding: 11px 13px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
  .ok-wrap { text-align: center; padding: 40px 24px; }
  .ok-wrap .ic { font-size: 54px; margin-bottom: 12px; }
  .ok-wrap h2 { font-size: 20px; color: #c0392b; margin-bottom: 8px; }
  .ok-wrap p { font-size: 13.5px; color: #555; }
  .ok-wrap .tid { font-weight: 800; color: #1a2230; }
  .foot { text-align: center; font-size: 11px; color: #99a; padding: 14px 0 20px; background: #fff; }
  .confirm-box { text-align: center; padding: 8px 4px 6px; }
  .confirm-ic { font-size: 40px; margin-bottom: 12px; }
  .confirm-en { font-size: 15px; font-weight: 700; color: #1a2230; margin-bottom: 12px; line-height: 1.55; }
  .confirm-te, .confirm-hi { font-size: 13.5px; color: #4a5568; margin-bottom: 10px; line-height: 1.6; }
  .confirm-yes { width: 100%; padding: 14px; background: #c0392b; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 800; cursor: pointer; margin: 14px 0 8px; }
  .confirm-no { width: 100%; padding: 12px; background: #fff; color: #667; border: 1.5px solid #d5dce7; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
  .qz-opt { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1.5px solid #d5dce7; border-radius: 8px; cursor: pointer; font-size: 13.5px; }
  .qz-opt input { width: 18px; height: 18px; flex-shrink: 0; accent-color: #c0392b; }
  .qz-opt:has(input:checked) { border-color: #c0392b; background: #fdeceb; font-weight: 700; }
</style>
</head>
<body>
<div class="wrap">
<?php if ($success): ?>
  <div class="card"><div class="ok-wrap">
    <div class="ic">✅</div>
    <h2>Request Submitted!</h2>
    <p>Your GPS re-activation request has been received.<br>Reference: <span class="tid"><?= htmlspecialchars($createdTaskId) ?></span></p>
    <div style="background:#fdeceb;border:1.5px solid #c0392b;border-radius:10px;padding:12px;margin:14px 0;font-size:13px;color:#a02c20;font-weight:700">Amount to pay at service: ₹<?= number_format($TOTAL) ?> <span style="font-size:10px;font-weight:400"><?= $GST ? '(incl. 18% GST)' : '' ?></span></div>
    <p style="margin-top:14px">Our technician will reach out shortly. Please keep your vehicle available for the visit.</p>
  </div></div>
  <div class="foot">BharatGPS · Fleet Tracking Solutions</div>
  <script>if(window.history.replaceState){window.history.replaceState(null,'',location.pathname);}window.addEventListener('pageshow',function(e){if(e.persisted)location.reload();});</script>
<?php elseif (in_array($LINK_STATE, ['expired','used','invalid'])): ?>
  <div class="card"><div class="hd"><div class="logo-box"><img src="logo.png" alt="BharatGPS" onerror="this.style.display='none'"></div><h1>🔄 GPS Re-Activation</h1></div>
  <div class="body"><div style="text-align:center;padding:30px 10px"><div style="font-size:48px;margin-bottom:12px"><?= $LINK_STATE==='used'?'✅':'⏳' ?></div>
  <h2 style="font-size:18px;color:#c0392b;margin-bottom:10px"><?= $LINK_STATE==='used'?'Link Already Used':($LINK_STATE==='expired'?'Link Expired':'Invalid Link') ?></h2>
  <p style="font-size:13.5px;color:#555;line-height:1.6"><?= $LINK_STATE==='used'?'This request has already been submitted.':($LINK_STATE==='expired'?'This link is valid for 6 hours only. Please ask BharatGPS for a fresh link.':'This link is not valid.') ?></p>
  <p style="margin-top:14px;font-size:13px;color:#c0392b;font-weight:700">📞 +91 98498 49824</p></div></div></div>
  <div class="foot">BharatGPS · Fleet Tracking Solutions</div>
<?php else: ?>
  <div class="card">
    <div class="hd">
      <div class="logo-box"><img src="logo.png" alt="BharatGPS" onerror="this.style.display='none'"></div>
      <h1>🔄 GPS Re-Activation</h1>
      <p>Renew and re-activate your expired GPS device.</p>
    </div>
    <div class="body">
      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div id="ts-confirm" <?= $error ? 'style="display:none"' : '' ?>>
        <div class="confirm-box">
          <div class="confirm-ic">🚗🔧</div>
          <p class="confirm-en">Your GPS service has expired and you want to renew and re-activate it. Our technician will visit, replace the SIM card, and re-add your device. Is that correct?</p>
          <p class="confirm-te">మీ GPS సేవ గడువు ముగిసింది, దాన్ని పునరుద్ధరించి తిరిగి యాక్టివేట్ చేయాలనుకుంటున్నారు. మా టెక్నీషియన్ వచ్చి సిమ్ కార్డ్ మార్చి పరికరాన్ని తిరిగి యాడ్ చేస్తారు. ఇది సరైనదేనా?</p>
          <p class="confirm-hi">आपकी GPS सेवा समाप्त हो गई है और आप इसे रिन्यू व पुनः चालू कराना चाहते हैं। हमारा तकनीशियन आकर सिम कार्ड बदलेगा और डिवाइस दोबारा जोड़ेगा। क्या यह सही है?</p>
        </div>
        <?php if ($PRICE > 0): ?>
        <div class="price-tag"><div class="lbl">Service Charge</div><div class="amt">₹<?= number_format($TOTAL) ?></div><div style="font-size:10px;color:#c0392b;margin-top:2px"><?= $GST ? ('Base ₹'.number_format($PRICE).' + 18% GST ₹'.number_format($GST_AMT).' = ₹'.number_format($TOTAL)) : '' ?></div></div>
        <?php endif; ?>
        <button type="button" class="confirm-yes" onclick="tsConfirmYes()">✅ Yes / అవును / हाँ</button>
        <button type="button" class="confirm-no" onclick="tsConfirmNo()">No / కాదు / नहीं</button>
        <div id="ts-no-msg" style="display:none;margin-top:14px;background:#fdecec;color:#c0392b;padding:12px 14px;border-radius:8px;font-size:12.5px;line-height:1.6">
          No problem. For any other help, please contact BharatGPS support on <b>+91 98498 49824</b> (call or WhatsApp).
        </div>
      </div>

      <!-- Removal reason questionnaire (shown after confirm Yes) -->
      <form method="POST" id="tsForm" style="display:none" onsubmit="return tsSubmitting()">
        <input type="hidden" name="form_submitted" value="1">
        <input type="hidden" name="tok" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="reason" id="hidden-reason" value="">

        <?php if ($PRICE > 0): ?>
        <div class="price-tag"><div class="lbl">Service Charge</div><div class="amt">₹<?= number_format($TOTAL) ?></div><div style="font-size:10px;color:#c0392b;margin-top:2px"><?= $GST ? ('Base ₹'.number_format($PRICE).' + 18% GST ₹'.number_format($GST_AMT).' = ₹'.number_format($TOTAL)) : '' ?></div></div>
        <?php endif; ?>

        <div class="sec-title">Your Details</div>
        <div class="f"><label>Your Name <span class="req">*</span></label><input type="text" name="cust_name" placeholder="e.g. Ravi Kumar" required value="<?= htmlspecialchars($_POST['cust_name']??'') ?>"></div>
        <div class="f"><label>Vehicle Number <span class="req">*</span></label><input type="text" name="vehicle" placeholder="e.g. AP31AB1234" required value="<?= htmlspecialchars($pref_vehicle) ?>"></div>
        <div class="f"><label>Contact Number <span class="req">*</span></label><input type="tel" name="phone" placeholder="9876543210" required value="<?= htmlspecialchars($pref_phone) ?>"></div>
        <div class="f"><label>Email ID <span class="req">*</span></label><input type="email" name="email" placeholder="your@email.com" required value="<?= htmlspecialchars($_POST['email']??'') ?>"></div>
        <div class="f">
          <label>Vehicle Availability Location <span class="req">*</span></label>
          <button type="button" class="geo-btn" onclick="captureGeo()">📍 Use My Current Location</button>
          <input type="text" name="location" id="locInput" placeholder="Where the vehicle will be available" required>
          <input type="hidden" name="geo" id="geoInput">
        </div>

        <div class="agree">
          <input type="checkbox" name="agree" id="agree" onchange="document.getElementById('sbtn').disabled=!this.checked">
          <label for="agree">I agree to the <b>service charge</b> shown above (if any). I will keep the <b>vehicle available</b> at the time of the technician's visit. If the vehicle is not available, an <b>additional charge may apply</b>.</label>
        </div>
        <button type="submit" class="submit" id="sbtn" disabled>Submit Removal Request</button>
      </form>
    </div>
  </div>
  <div class="foot">BharatGPS · Fleet Tracking Solutions</div>
<?php endif; ?>
</div>
<script>
function tsConfirmYes(){ document.getElementById('ts-confirm').style.display='none'; document.getElementById('tsForm').style.display=''; }
function tsConfirmNo(){ document.getElementById('ts-no-msg').style.display='block'; }



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
