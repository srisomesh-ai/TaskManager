<?php
header('Content-Type: text/html; charset=UTF-8');

// Always load DB first
require_once 'api/db.php';
$pdo = getDB();

// Create used_tokens table if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS used_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash VARCHAR(64) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ---- LINK VALIDATION ----
$token    = trim($_GET['t'] ?? '');
$DISCOUNT = 100;
$REF_NAME = '';
$LINK_VALID   = false;
$LINK_EXPIRED = false;
$LINK_USED    = false;
$TOKEN_HASH   = '';

if ($token) {
    $decoded = base64_decode($token, true);
    if ($decoded !== false) {
        $parts = explode('|', $decoded, 3);
        if (count($parts) === 3) {
            $expiry = intval($parts[0]);
            $disc   = intval($parts[1]);
            $ref    = trim($parts[2]);
            $TOKEN_HASH = hash('sha256', $token);
            if (time() > $expiry) {
                $LINK_EXPIRED = true;
            } else {
                try {
                    $chk = $pdo->prepare("SELECT id FROM used_tokens WHERE token_hash=?");
                    $chk->execute([$TOKEN_HASH]);
                    if ($chk->fetch()) {
                        $LINK_USED = true;
                    } else {
                        $LINK_VALID = true;
                        $DISCOUNT   = max(0, $disc);
                        $REF_NAME   = $ref;
                    }
                } catch(Exception $e) {
                    // used_tokens table issue — allow through
                    $LINK_VALID = true;
                    $DISCOUNT   = max(0, $disc);
                    $REF_NAME   = $ref;
                }
            }
        }
    }
} else {
    $DISCOUNT   = max(0, intval($_GET['disc'] ?? 100));
    $REF_NAME   = trim($_GET['ref'] ?? '');
    $LINK_VALID = true;
}

$GPS_TYPES = [
    'Engine Status'    => ['price'=>3500, 'desc'=>'Basic GPS tracking with engine monitoring'],
    'Engine Cut'       => ['price'=>4500, 'desc'=>'GPS with remote engine cut feature'],
    'Micro GPS'        => ['price'=>4000, 'desc'=>'Compact hidden GPS device'],
    'Magnet GPS'       => ['price'=>5500, 'desc'=>'Portable magnetic GPS tracker'],
    'MIC/SOS GPS'      => ['price'=>4500, 'desc'=>'GPS with microphone & SOS button'],
    'VLTD'             => ['price'=>10500,'desc'=>'Vehicle Location Tracking Device (Govt. Approved)'],
    'Re-Adding'        => ['price'=>1700, 'desc'=>'Re-registration of existing GPS device'],
];
$SUPPORT_EMAIL = 'sales@bharatgps.com';
$COMPANY_NAME  = 'Bharat GPS Tracker';
$COMPANY_PHONE = '9849849824';

// Handle form submission
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_submitted'])){
    require_once 'api/mailer.php';

    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $gpsType  = trim($_POST['gps_type'] ?? '');
    $qty      = max(1, intval($_POST['qty'] ?? 1));
    $vehicle  = trim($_POST['vehicle']  ?? '');
    $location = trim($_POST['location'] ?? '');
    $prefTime = trim($_POST['pref_time']?? '');
    $payMode  = trim($_POST['pay_mode'] ?? 'UPI');
    $comments = trim($_POST['comments'] ?? '');
    $pricePerDevice = floatval($_POST['price']  ?? 0); // discounted per-device price
    $discount       = max(0, intval($_POST['discount_amount'] ?? 100));
    $totalPrice     = $pricePerDevice * $qty; // total for all devices

    if(!$name || !$phone || !$email || !$gpsType || !$vehicle || !$prefTime || !$location){
        $error = 'Please fill all required fields marked with *';
    } else {
        // Generate task ID
        $year   = date('Y');
        $cnt    = $pdo->query("SELECT COUNT(*) FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetchColumn();
        $taskId = 'ID-'.$year.'-'.str_pad($cnt+1,4,'0',STR_PAD_LEFT);

        // Created by (first admin/assigner)
        $cb = $pdo->query("SELECT id FROM users WHERE role IN ('admin','assigner') AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 1;

        // Clean general notes
        $notes = trim(
            "📅 Preferred Visit: $prefTime\n"
          . "🚗 Vehicle Type: $vehicle\n"
          . "💳 Payment Mode: $payMode\n"
          . "🏷️ Discount Applied: ₹" . $discount . "/device"
          . ($comments ? "\n💬 Customer Note: $comments" : '')
        );

        // Insert task — location properly stored
        $pdo->prepare("INSERT INTO tasks
            (task_id,customer_name,contact_number,email,location,lead_type,
             device_details,device_qty,price_to_collect,payment_mode,
             task_status,general_notes,created_by,is_urgent)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0)")
            ->execute([
                $taskId, $name, $phone, $email, $location, 'New Lead',
                $gpsType, $qty, $totalPrice, $payMode,
                'Open', $notes, $cb
            ]);

        $newId = $pdo->lastInsertId();

        // Activity log
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
            ->execute([$newId, $cb,
                "🌐 Customer self-enquiry via booking link | GPS: $gpsType ×$qty | Vehicle: $vehicle | Time: $prefTime | Payment: $payMode | Location: ".($location?:'Not specified')
            ]);

        // Email notification to support
        $emailBody  = emailTemplate('
        <div class="greeting">New Customer Enquiry Received!</div>
        <p style="font-size:14px;color:#4a5568;margin-bottom:16px">A customer just submitted a GPS installation request through the booking link.</p>
        <hr style="border:none;border-top:2px solid #e8eef8;margin:16px 0">
        <div class="details">
            <div class="row"><div class="label">Task ID</div><div class="value blue">'.$taskId.'</div></div>
            <div class="row"><div class="label">Customer</div><div class="value">'.htmlspecialchars($name).'</div></div>
            <div class="row"><div class="label">Phone</div><div class="value highlight">'.htmlspecialchars($phone).'</div></div>
            '.($email?'<div class="row"><div class="label">Email</div><div class="value">'.htmlspecialchars($email).'</div></div>':'').'
            <div class="row"><div class="label">Location</div><div class="value">'.htmlspecialchars($location?:'Not specified').'</div></div>
            <div class="row"><div class="label">GPS Type</div><div class="value"><strong>'.htmlspecialchars($gpsType).'</strong></div></div>
            <div class="row"><div class="label">Quantity</div><div class="value">'.$qty.' device'.($qty>1?'s':'').'</div></div>
            <div class="row"><div class="label">Vehicle</div><div class="value">'.htmlspecialchars($vehicle).'</div></div>
            <div class="row"><div class="label">Visit Time</div><div class="value">'.htmlspecialchars($prefTime).'</div></div>
            <div class="row"><div class="label">Payment Mode</div><div class="value">'.htmlspecialchars($payMode).'</div></div>
            <div class="row"><div class="label">Total Price</div><div class="value highlight">₹'.number_format($totalPrice).'</div></div>
            '.($comments?'<div class="row"><div class="label">Comments</div><div class="value">'.htmlspecialchars($comments).'</div></div>':'').'
        </div>
        <div style="background:#fff3e0;border:1.5px solid #e07b00;border-radius:8px;padding:14px;margin-top:16px">
            <div style="font-size:13px;font-weight:800;color:#d4680a;margin-bottom:6px">⚡ Action Required</div>
            <div style="font-size:13px;color:#4a5568">Please log in and assign this task to a technician as soon as possible.</div>
        </div>
        <p style="font-size:14px;font-weight:700;color:#1a3a6b;margin-top:16px">Log in to assign: <a href="https://salmon-goldfish-110661.hostingersite.com" style="color:#2451a3">Task Manager →</a></p>
        ');

        sendMail($SUPPORT_EMAIL, $COMPANY_NAME.' Support',
            '🆕 New Booking – '.$taskId.' | '.htmlspecialchars($name),
            $emailBody
        );

        $success = true;
        $createdTaskId = $taskId;

        // Mark token as used so link cannot be resubmitted
        if ($TOKEN_HASH) {
            try {
                $pdo->prepare("INSERT IGNORE INTO used_tokens (token_hash) VALUES (?)")->execute([$TOKEN_HASH]);
            } catch(Exception $e) {}
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Book GPS Installation – Bharat GPS Tracker</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:#1a3a6b;--acc2:#2451a3;--grn:#1a7a3a;--grnb:#e8f5ec;
  --red:#c0392b;--org:#d4680a;--saf:#e07b00;--safb:#fff3e0;
  --bg:#f0f2f5;--sur:#fff;--sur2:#f7f8fa;--bdr:#d0d5dd;
  --tx:#1a1f2e;--tx2:#4a5568;--tx3:#8a9ab0;--r:12px;--rs:8px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Calibri,sans-serif;background:var(--bg);color:var(--tx);min-height:100vh}
.header{background:linear-gradient(135deg,#1a3a6b,#2451a3);padding:20px 16px 24px;text-align:center}
.header img{height:80px;object-fit:contain;margin-bottom:4px;filter:drop-shadow(0 2px 8px rgba(0,0,0,.3))}
.header h1{color:#fff;font-size:20px;font-weight:800;margin-top:6px}
.header p{color:rgba(255,255,255,.7);font-size:13px;margin-top:4px}
.wrap{max-width:520px;margin:0 auto;padding:16px}
.card{background:var(--sur);border-radius:var(--r);padding:20px;margin-bottom:14px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.card h3{font-size:14px;font-weight:800;color:var(--acc);margin-bottom:14px}
.f{margin-bottom:14px}.f:last-child{margin-bottom:0}
.f label{display:block;font-size:11px;font-weight:800;color:var(--tx2);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.f input,.f select,.f textarea{width:100%;padding:12px 13px;background:var(--sur2);border:1.5px solid var(--bdr);border-radius:var(--rs);color:var(--tx);font-family:inherit;font-size:14px;outline:none;transition:border-color .2s}
.f input:focus,.f select:focus,.f textarea:focus{border-color:var(--acc2);box-shadow:0 0 0 3px rgba(36,81,163,.1)}
.f textarea{resize:vertical;min-height:70px}
.req{color:var(--red)}
.gps-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.gps-card{border:2px solid var(--bdr);border-radius:var(--rs);padding:12px;cursor:pointer;transition:all .2s;background:var(--sur2)}
.gps-card.sel{border-color:var(--acc);background:var(--acc);color:#fff}
.gps-card.sel .gps-price,.gps-card.sel .gps-desc,.gps-card.sel .disc{color:rgba(255,255,255,.8)!important}
.gps-name{font-size:13px;font-weight:800}
.gps-price{font-size:12px;color:var(--grn);margin-top:3px;font-weight:700}
.disc{font-size:11px;color:var(--tx3);text-decoration:line-through;margin-left:4px}
.gps-desc{font-size:10px;color:var(--tx3);margin-top:4px;line-height:1.4}
.dbadge{display:inline-block;background:var(--safb);color:var(--saf);border:1px solid rgba(224,123,0,.3);border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;margin-top:4px}
.price-box{background:linear-gradient(135deg,var(--acc),var(--acc2));border-radius:var(--rs);padding:14px;color:#fff;margin-top:12px;display:none}
.price-row{display:flex;justify-content:space-between;align-items:center;margin-top:4px}
.price-val{font-size:20px;font-weight:800}
.qty-row{display:flex;align-items:center;gap:14px}
.qty-btn{width:38px;height:38px;border-radius:50%;border:2px solid var(--bdr);background:var(--sur);font-size:20px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--acc);line-height:1}
.qty-val{font-size:22px;font-weight:800;min-width:32px;text-align:center}
/* Pay mode pills */
.pay-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.pay-pill{border:2px solid var(--bdr);border-radius:var(--rs);padding:10px 8px;text-align:center;cursor:pointer;background:var(--sur2);transition:all .2s}
.pay-pill.sel{border-color:var(--grn);background:var(--grnb)}
.pay-pill .pico{font-size:18px;margin-bottom:3px}
.pay-pill .plbl{font-size:12px;font-weight:700;color:var(--tx2)}
.pay-pill.sel .plbl{color:var(--grn)}
.submit-btn{width:100%;padding:16px;background:linear-gradient(135deg,var(--grn),#22c55e);color:#fff;border:none;border-radius:var(--rs);font-size:16px;font-weight:800;cursor:pointer;font-family:inherit;margin-top:4px}
.submit-btn:active{transform:scale(.98)}
.error{background:#fdecea;border:1.5px solid rgba(192,57,43,.3);border-radius:var(--rs);padding:12px;font-size:13px;color:var(--red);font-weight:700;margin-bottom:14px}
.success-wrap{text-align:center;padding:40px 20px}
.success-icon{font-size:64px;margin-bottom:16px}
.task-badge{display:inline-block;background:var(--grnb);border:2px solid var(--grn);border-radius:var(--rs);padding:8px 20px;font-size:18px;font-weight:800;color:var(--grn);margin:14px auto}
.trust-bar{display:flex;justify-content:center;gap:16px;margin-top:16px;padding:12px;background:var(--sur2);border-radius:var(--rs)}
.trust-item{text-align:center;font-size:11px;color:var(--tx3);font-weight:700}
.trust-item span{display:block;font-size:20px;margin-bottom:2px}
</style>
</head>
<body>

<div class="header">
  <img src="data:image/png;base64,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" style="height:80px;object-fit:contain;margin-bottom:4px" alt="Bharat GPS Tracker">
  <h1>Book GPS Installation</h1>
  <p>India's Best Vehicle Tracking System</p>
</div>

<div class="wrap">

<?php if($DISCOUNT > 100): ?>
<div style="background:linear-gradient(135deg,#1a7a3a,#22c55e);border-radius:var(--r);padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px;box-shadow:0 4px 14px rgba(26,122,58,.3)">
  <div style="font-size:32px">🎁</div>
  <div>
    <div style="color:#fff;font-size:16px;font-weight:800">Special Offer — ₹<?= number_format($DISCOUNT) ?> OFF!</div>
    <div style="color:rgba(255,255,255,.85);font-size:12px;margin-top:2px">Exclusive discount applied to your booking. Valid today only.</div>
  </div>
</div>
<?php elseif($DISCOUNT > 0): ?>
<div style="background:var(--safb);border:1.5px solid rgba(224,123,0,.3);border-radius:var(--rs);padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
  <div style="font-size:22px">🏷️</div>
  <div style="font-size:13px;font-weight:700;color:var(--saf)">₹<?= number_format($DISCOUNT) ?> discount applied on every device — exclusive offer!</div>
</div>
<?php endif; ?>

<?php if($LINK_EXPIRED): ?>
<div class="wrap">
<div class="card" style="text-align:center;padding:40px 20px">
  <div style="font-size:56px;margin-bottom:16px">⏰</div>
  <div style="font-size:20px;font-weight:800;color:var(--red);margin-bottom:8px">Link Expired</div>
  <div style="font-size:14px;color:var(--tx2);line-height:1.7;margin-bottom:20px">
    This booking link was valid for 24 hours and has now expired.<br>
    Please contact us to get a new link.
  </div>
  <a href="tel:9849849824" style="display:inline-block;background:var(--acc);color:#fff;padding:12px 28px;border-radius:var(--rs);font-weight:800;text-decoration:none;font-size:15px">📞 Call Us Now</a>
  <div style="margin-top:14px;font-size:12px;color:var(--tx3)">9849849824 · support@bharatgps.com</div>
</div>
</div>

<?php elseif($LINK_USED): ?>
<div class="wrap">
<div class="card" style="text-align:center;padding:40px 20px">
  <div style="font-size:56px;margin-bottom:16px">✅</div>
  <div style="font-size:20px;font-weight:800;color:var(--grn);margin-bottom:8px">Already Submitted</div>
  <div style="font-size:14px;color:var(--tx2);line-height:1.7;margin-bottom:20px">
    This booking link has already been used.<br>
    Your request is with our team — we will contact you shortly.
  </div>
  <a href="tel:9849849824" style="display:inline-block;background:var(--acc);color:#fff;padding:12px 28px;border-radius:var(--rs);font-weight:800;text-decoration:none;font-size:15px">📞 Call Us</a>
  <div style="margin-top:14px;font-size:12px;color:var(--tx3)">9849849824 · support@bharatgps.com</div>
</div>
</div>

<?php else: ?>

<?php if(isset($success)): ?>
<div class="card">
  <div class="success-wrap">
    <div class="success-icon">✅</div>
    <div style="font-size:22px;font-weight:800;color:var(--grn);margin-bottom:8px">Booking Confirmed!</div>
    <div style="font-size:14px;color:var(--tx2);line-height:1.6">Thank you <strong><?= htmlspecialchars($name) ?></strong>!<br>Your request has been received.</div>
    <div class="task-badge"><?= $createdTaskId ?></div>
    <div style="font-size:13px;color:var(--tx2);margin-bottom:16px">Our team will call <strong><?= htmlspecialchars($phone) ?></strong> within 30 minutes.</div>
    <div class="trust-bar">
      <div class="trust-item"><span>⚡</span>Fast Install</div>
      <div class="trust-item"><span>🔒</span>Genuine Device</div>
      <div class="trust-item"><span>📞</span>24/7 Support</div>
      <div class="trust-item"><span>✅</span>Warranty</div>
    </div>
  </div>
</div>
<script>
// Prevent back button returning to the form
history.replaceState(null, '', window.location.href);
window.addEventListener('popstate', function(){ history.pushState(null, '', window.location.href); });
</script>

<?php else: ?>

<?php if(isset($error)): ?><div class="error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" id="booking-form">
  <input type="hidden" name="discount_amount" value="<?= $DISCOUNT ?>">

  <div class="card">
    <h3>👤 Your Details</h3>
    <div class="f"><label>Full Name <span class="req">*</span></label><input type="text" name="name" placeholder="e.g. Ravi Kumar" required value="<?= htmlspecialchars($_POST['name'] ?? $_GET['name'] ?? $REF_NAME) ?>"></div>
    <div class="f"><label>WhatsApp / Mobile <span class="req">*</span></label><input type="tel" name="phone" placeholder="9876543210" required value="<?= htmlspecialchars($_POST['phone'] ?? $_GET['phone'] ?? '') ?>"></div>
    <div class="f"><label>Email ID <span class="req">*</span></label><input type="email" name="email" placeholder="your@email.com" required value="<?= htmlspecialchars($_POST['email']??'') ?>"></div>
    <div class="f"><label>Your Location / Area <span class="req">*</span></label><input type="text" name="location" placeholder="e.g. Maddilapalem, Visakhapatnam" required required value="<?= htmlspecialchars($_POST['location']??'') ?>"></div>
  </div>

  <div class="card">
    <h3>📡 Select GPS Type <span class="req">*</span></h3>
    <div class="gps-grid" id="gps-grid">
      <?php foreach($GPS_TYPES as $type=>$info):
        $disc = $info['price'] - $DISCOUNT;
        $sel  = (($_POST['gps_type']??'')===$type) ? 'sel' : '';
      ?>
      <div class="gps-card <?= $sel ?>" onclick="selectGPS('<?= addslashes($type) ?>',<?= $info['price'] ?>)" id="gc-<?= md5($type) ?>">
        <div class="gps-name"><?= htmlspecialchars($type) ?></div>
        <div class="gps-price">₹<?= number_format($disc) ?> <span class="disc">₹<?= number_format($info['price']) ?></span></div>
        <div class="gps-desc"><?= htmlspecialchars($info['desc']) ?></div>
        <div class="dbadge">₹<?= $DISCOUNT ?> OFF</div>
      </div>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="gps_type" id="gps_type" value="<?= htmlspecialchars($_POST['gps_type']??'') ?>" required>
    <input type="hidden" name="price" id="price_val" value="<?= htmlspecialchars($_POST['price']??'') ?>">
    <div class="price-box" id="price-box">
      <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.3px">Total Price (₹<span id="disc-label"><?= $DISCOUNT ?></span> OFF × <span id="qty-label">1</span> device)</div>
      <div class="price-row">
        <div class="price-val" id="price-display">₹0</div>
        <div style="text-align:right"><div style="font-size:11px;opacity:.7">You save</div><div style="font-size:15px;font-weight:800;color:#4ade80" id="save-display">₹0</div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>🚗 Vehicle Details</h3>
    <div class="f">
      <label>Number of Devices <span class="req">*</span></label>
      <div class="qty-row">
        <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
        <div class="qty-val" id="qty-display"><?= intval($_POST['qty']??1) ?></div>
        <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
        <span style="font-size:12px;color:var(--tx3)">device(s)</span>
      </div>
      <input type="hidden" name="qty" id="qty" value="<?= intval($_POST['qty']??1) ?>">
    </div>
    <div class="f"><label>Type of Vehicle <span class="req">*</span></label>
      <select name="vehicle" required>
        <option value="">Select vehicle type</option>
        <?php foreach(['Car','Truck','Auto','Bike','Bus','JCB','Tractor','Van','Tempo','Other'] as $v): ?>
        <option value="<?= $v ?>" <?= (($_POST['vehicle']??'')===$v)?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="card">
    <h3>💳 Preferred Payment Mode <span class="req">*</span></h3>
    <input type="hidden" name="pay_mode" id="pay_mode" value="<?= htmlspecialchars($_POST['pay_mode']??'Cash') ?>">
    <div class="pay-grid">
      <?php foreach([
        ['Cash','💵','Cash'],
        ['UPI','📱','UPI / GPay'],
        ['Bank Transfer','🏦','Bank Transfer'],
      ] as $pm): ?>
      <div class="pay-pill <?= (($_POST['pay_mode']??'Cash')===$pm[0])?'sel':'' ?>" onclick="selectPay('<?= $pm[0] ?>')" id="pp-<?= md5($pm[0]) ?>">
        <div class="pico"><?= $pm[1] ?></div>
        <div class="plbl"><?= $pm[2] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h3>📅 Schedule</h3>
    <div class="f"><label>Preferred Visit Time <span class="req">*</span></label>
      <select name="pref_time" required>
        <option value="">Select preferred time</option>
        <?php foreach(['Morning (9AM - 12PM)','Afternoon (12PM - 3PM)','Evening (3PM - 6PM)','Anytime'] as $t): ?>
        <option <?= (($_POST['pref_time']??'')===$t)?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="f"><label>Any Comments or Special Requirements</label>
      <textarea name="comments" placeholder="e.g. Vehicle is at workshop, please call before coming..."><?= htmlspecialchars($_POST['comments']??'') ?></textarea>
    </div>
  </div>

  <input type="hidden" name="form_submitted" value="1">
  <button type="button" class="submit-btn" id="submit-btn" onclick="handleSubmit(this)">🚀 Submit Booking Request</button>
  <div style="text-align:center;font-size:12px;color:var(--tx3);margin-top:10px;padding-bottom:24px">Our team will contact you within 30 minutes · Free cancellation</div>
</form>

<?php endif; ?>
</div>

<script>
var GPS_MAP = {};
<?php foreach($GPS_TYPES as $type=>$info): ?>
GPS_MAP[<?= json_encode($type) ?>] = {price:<?= $info['price'] ?>, id:'gc-<?= md5($type) ?>'};
<?php endforeach; ?>
var DISCOUNT = <?= $DISCOUNT ?>;
var qty = <?= intval($_POST['qty']??1) ?>;
var selPrice = <?= floatval($_POST['price']??0) ?>;
var selType  = <?= json_encode($_POST['gps_type']??'') ?>;

function selectGPS(type, price) {
  selType = type; selPrice = price;
  document.getElementById('gps_type').value = type;
  Object.keys(GPS_MAP).forEach(function(t){
    var el = document.getElementById(GPS_MAP[t].id);
    if(el) el.className = 'gps-card' + (t===type?' sel':'');
  });
  updatePrice();
}

function updatePrice() {
  if(!selType) return;
  var discPerDevice = selPrice - DISCOUNT;
  var total = discPerDevice * qty;
  var saved = DISCOUNT * qty;
  document.getElementById('price_val').value = discPerDevice; // per device — PHP multiplies by qty
  document.getElementById('price-box').style.display = 'block';
  document.getElementById('price-display').textContent = '₹' + total.toLocaleString('en-IN');
  document.getElementById('save-display').textContent = '₹' + saved.toLocaleString('en-IN');
}

function changeQty(d) {
  qty = Math.max(1, Math.min(20, qty + d));
  document.getElementById('qty').value = qty;
  document.getElementById('qty-display').textContent = qty;
  var ql = document.getElementById('qty-label');
  if(ql) ql.textContent = qty + (qty>1?' devices':' device');
  updatePrice();
}

var PAY_IDS = {};
<?php foreach([['Cash','pp-'.md5('Cash')],['UPI','pp-'.md5('UPI')],['Bank Transfer','pp-'.md5('Bank Transfer')]] as $pm): ?>
PAY_IDS[<?= json_encode($pm[0]) ?>] = '<?= $pm[1] ?>';
<?php endforeach; ?>

function selectPay(mode) {
  document.getElementById('pay_mode').value = mode;
  Object.keys(PAY_IDS).forEach(function(m){
    var el = document.getElementById(PAY_IDS[m]);
    if(el) el.className = 'pay-pill' + (m===mode?' sel':'');
  });
}

// Init
if(selType) updatePrice();

function handleSubmit(btn) {
  if(!document.getElementById('gps_type').value){
    alert('Please select a GPS Type');
    document.getElementById('gps-grid').scrollIntoView({behavior:'smooth'});
    return;
  }
  var form = document.getElementById('booking-form');
  if(!form.checkValidity()){
    form.reportValidity();
    return;
  }
  btn.disabled = true;
  btn.innerHTML = '⏳ Submitting... Please wait';
  btn.style.background = 'linear-gradient(135deg,#555,#888)';
  btn.style.cursor = 'not-allowed';
  form.submit();
}
</script>
<?php endif; // end LINK_EXPIRED else ?>
</body>
</html>
