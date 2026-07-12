<?php
// BharatGPS — Customer Document Collection Portal (standalone, token-based)
// Customer opens a link, enters vehicle number(s), uploads RC per vehicle + one ID proof + a selfie.
// Documents are stored against the task in the shared uploads/task_{id}/ folder and task_documents table.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = trim($_GET['token'] ?? '');
if(!$token) die('<p style="font-family:sans-serif;padding:40px;color:red">Invalid link.</p>');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=u943205660_bharatgps;charset=utf8mb4',
        'u943205660_bharatgps','kTrV>Le6+',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch(Exception $e){
    die('<p style="font-family:sans-serif;padding:40px;color:red">Database error. Please call 9849849824.</p>');
}

// Ensure the docs token column + a "documents received" timestamp exist.
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN docs_token VARCHAR(64) NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN docs_received_at DATETIME NULL"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS task_documents (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, doc_type VARCHAR(50), filename VARCHAR(255), original_name VARCHAR(255), uploaded_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

$s = $pdo->prepare("SELECT t.*, u.name AS tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.docs_token=?");
$s->execute([$token]);
$task = $s->fetch();

function page_shell($emoji,$title,$msg){
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BharatGPS</title></head>
<body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">
<div style="background:#fff;border-radius:12px;padding:32px;max-width:440px;width:100%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)">
<div style="font-size:44px;margin-bottom:12px">'.$emoji.'</div>
<h2 style="color:#137272;margin-bottom:8px">'.$title.'</h2>
<p style="color:#4a5568;font-size:14px;line-height:1.6">'.$msg.'</p>
</div></body></html>';
}

if(!$task){
    die(page_shell('❌','Link Expired or Invalid','This document link is no longer valid.<br>Please contact your technician or call <strong>9849849824</strong>.'));
}

// Already submitted?
if(!empty($task['docs_received_at'])){
    $dt = date('d M Y, h:i A', strtotime($task['docs_received_at']));
    die(page_shell('✅','Documents Already Received','Thank you! Your documents were received on '.$dt.'.<br>This link is now inactive.'));
}

$qty = intval($task['device_qty'] ?? 1); if($qty<1) $qty=1;
$taskId = intval($task['id']);
$error = '';
$done  = false;

// ── Handle POST ──────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'){
    $dir = __DIR__.'/uploads/task_'.$taskId.'/';
    if(!is_dir($dir)) @mkdir($dir,0755,true);

    // Accept common image/pdf types, max ~8MB each
    $allowed = ['image/jpeg','image/png','image/webp','image/heic','application/pdf'];
    $maxBytes = 8*1024*1024;

    function saveUpload($pdo,$dir,$taskId,$field,$docType,$label,$allowed,$maxBytes,&$error){
        if(!isset($_FILES[$field]) || $_FILES[$field]['error']===UPLOAD_ERR_NO_FILE){
            $error = $label.' is required.'; return false;
        }
        if($_FILES[$field]['error']!==UPLOAD_ERR_OK){ $error='Upload error for '.$label.'.'; return false; }
        if($_FILES[$field]['size']>$maxBytes){ $error=$label.' is too large (max 8MB).'; return false; }
        $type = mime_content_type($_FILES[$field]['tmp_name']);
        if(!in_array($type,$allowed)){ $error=$label.' must be an image or PDF.'; return false; }
        $ext = pathinfo($_FILES[$field]['name'],PATHINFO_EXTENSION);
        $fn  = $docType.'_'.time().'_'.mt_rand(100,999).($ext?'.'.preg_replace('/[^a-zA-Z0-9]/','',$ext):'');
        if(!move_uploaded_file($_FILES[$field]['tmp_name'],$dir.$fn)){ $error='Could not save '.$label.'.'; return false; }
        $pdo->prepare("INSERT INTO task_documents (task_id,doc_type,filename,original_name) VALUES (?,?,?,?)")
            ->execute([$taskId,$docType,$fn,$_FILES[$field]['name']]);
        return $fn;
    }

    // Vehicle numbers + RC per vehicle
    $vehNums = $_POST['veh_no'] ?? [];
    $ok = true;
    for($i=0;$i<$qty;$i++){
        $vn = trim($vehNums[$i] ?? '');
        if($vn===''){ $error='Please enter the vehicle number for vehicle '.($i+1).'.'; $ok=false; break; }
        $rc = saveUpload($pdo,$dir,$taskId,'rc_'.$i,'rc_v'.($i+1),'RC copy for vehicle '.($i+1).' ('.$vn.')',$allowed,$maxBytes,$error);
        if(!$rc){ $ok=false; break; }
        // Record the vehicle number as a lightweight note document
        $pdo->prepare("INSERT INTO task_documents (task_id,doc_type,filename,original_name) VALUES (?,?,?,?)")
            ->execute([$taskId,'veh_no_v'.($i+1),'',$vn]);
    }

    // ID proof + selfie
    if($ok){ $id = saveUpload($pdo,$dir,$taskId,'id_proof','id_proof','ID proof (Aadhaar)',$allowed,$maxBytes,$error); if(!$id) $ok=false; }
    if($ok){ $sf = saveUpload($pdo,$dir,$taskId,'selfie','customer_selfie','Selfie',$allowed,$maxBytes,$error); if(!$sf) $ok=false; }

    if($ok){
        $pdo->prepare("UPDATE tasks SET docs_received_at=NOW() WHERE id=?")->execute([$taskId]);
        $done = true;
    }
}

if($done){
    die(page_shell('✅','Documents Received — Thank You!','Your RC copies, ID proof and photo have been securely received.<br>You may close this page.'));
}

$cust = htmlspecialchars($task['customer_name'] ?? 'Customer');
$tid  = htmlspecialchars($task['task_id'] ?? '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Upload Documents — BharatGPS</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef1f5;color:#1a2332;padding:16px;line-height:1.5}
  .wrap{max-width:480px;margin:0 auto}
  .card{background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:22px 20px;margin-bottom:16px}
  .head{background:linear-gradient(135deg,#137272,#0d5555);color:#fff;border-radius:14px;padding:22px 20px;margin-bottom:16px;text-align:center}
  .head h1{font-size:20px;font-weight:800;margin-bottom:4px}
  .head p{font-size:13px;opacity:.92}
  .lbl{font-size:13px;font-weight:800;color:#2d3a4d;margin-bottom:6px;display:block}
  .req{color:#c0392b}
  input[type=text]{width:100%;padding:11px 12px;border:1.5px solid #d5dbe3;border-radius:9px;font-size:15px;margin-bottom:12px}
  input[type=text]:focus{outline:none;border-color:#137272}
  .file-box{border:1.5px dashed #b8c2ce;border-radius:9px;padding:13px;text-align:center;margin-bottom:14px;background:#fafbfc;cursor:pointer;position:relative}
  .file-box input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}
  .file-box .ico{font-size:24px}
  .file-box .txt{font-size:12.5px;color:#5a6b80;font-weight:600;margin-top:3px}
  .file-box.filled{border-color:#1a7a3a;background:#e8f5ec}
  .file-box.filled .txt{color:#1a7a3a}
  .veh-block{border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;background:#f8fafc}
  .veh-title{font-size:13px;font-weight:800;color:#137272;margin-bottom:10px}
  .note{font-size:12px;color:#8a9ab0;margin-bottom:14px}
  .err{background:#fdecea;border:1.5px solid #e74c3c;color:#c0392b;border-radius:9px;padding:11px 13px;font-size:13px;font-weight:600;margin-bottom:14px}
  .btn{width:100%;background:#137272;color:#fff;border:none;border-radius:10px;padding:15px;font-size:16px;font-weight:800;cursor:pointer}
  .btn:disabled{opacity:.6}
  .sec-title{font-size:15px;font-weight:800;color:#1a2332;margin-bottom:12px;display:flex;align-items:center;gap:7px}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>🛡️ Document Upload</h1>
    <p>BharatGPS — secure verification for your GPS installation</p>
  </div>

  <?php if($error): ?><div class="err">⚠️ <?=htmlspecialchars($error)?></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="docForm">
    <div class="card">
      <div class="sec-title">🚗 Vehicle & RC <span style="font-weight:600;color:#8a9ab0;font-size:12px">(<?=$qty?> vehicle<?=$qty>1?'s':''?>)</span></div>
      <div class="note">Please enter each vehicle number and upload its RC (Registration Certificate) copy.</div>
      <?php for($i=0;$i<$qty;$i++): ?>
      <div class="veh-block">
        <div class="veh-title">Vehicle <?=$i+1?></div>
        <label class="lbl">Vehicle Number <span class="req">*</span></label>
        <input type="text" name="veh_no[]" placeholder="e.g. AP39XY1234" value="<?=htmlspecialchars($_POST['veh_no'][$i]??'')?>" required style="text-transform:uppercase">
        <label class="lbl">RC Copy <span class="req">*</span></label>
        <div class="file-box" id="rcbox-<?=$i?>">
          <div class="ico">📄</div>
          <div class="txt" id="rctxt-<?=$i?>">Tap to upload RC (image or PDF)</div>
          <input type="file" name="rc_<?=$i?>" accept="image/*,application/pdf" onchange="pick(this,'rcbox-<?=$i?>','rctxt-<?=$i?>')" required>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <div class="card">
      <div class="sec-title">🪪 ID Proof</div>
      <div class="note">Upload your Aadhaar or any government ID.</div>
      <div class="file-box" id="idbox">
        <div class="ico">🪪</div>
        <div class="txt" id="idtxt">Tap to upload ID proof (image or PDF)</div>
        <input type="file" name="id_proof" accept="image/*,application/pdf" onchange="pick(this,'idbox','idtxt')" required>
      </div>
    </div>

    <div class="card">
      <div class="sec-title">🤳 Selfie</div>
      <div class="note">Please take a clear selfie for verification.</div>
      <div class="file-box" id="sfbox">
        <div class="ico">📷</div>
        <div class="txt" id="sftxt">Tap to take / upload selfie</div>
        <input type="file" name="selfie" accept="image/*" capture="user" onchange="pick(this,'sfbox','sftxt')" required>
      </div>
    </div>

    <button class="btn" type="submit" id="submitBtn">🔒 Submit Documents Securely</button>
    <p style="text-align:center;font-size:11.5px;color:#8a9ab0;margin-top:12px">Task <?=$tid?> · Your documents are kept confidential and used only for GPS installation verification.</p>
  </form>
</div>
<script>
  function pick(inp,boxId,txtId){
    var f=inp.files&&inp.files[0];
    var box=document.getElementById(boxId), txt=document.getElementById(txtId);
    if(f){ box.classList.add('filled'); txt.textContent='✅ '+f.name; }
    else { box.classList.remove('filled'); }
  }
  document.getElementById('docForm').addEventListener('submit',function(){
    var b=document.getElementById('submitBtn'); b.textContent='⏳ Uploading…'; b.disabled=true;
  });
</script>
</body>
</html>
