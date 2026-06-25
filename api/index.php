<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');


header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($body, $_POST);
}

$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_GET['token'] ?? '';

$pdo = getDB();
// Migration: admin_viewed_at per task (track when admin last viewed)
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN admin_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}


// Auth
$skipAuth = ['login','ping','verify_pin'];
$cu = null; $userId = null; $userRole = null;
if (!in_array($action, $skipAuth)) {
    if ($token) {
        $s = $pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");
        $s->execute([$token]);
        $cu = $s->fetch() ?: null;
    }
    if (!$cu) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
    $userId   = $cu['id'];
    $userRole = $cu['role'];
}

switch ($action) {

// ---- PING ----
case 'ping':
    echo json_encode(['ok'=>true]);
    break;

// ---- LOGIN ----
case 'login':
    $email = trim($body['email'] ?? '');
    $pass  = $body['password'] ?? '';
    $s = $pdo->prepare("SELECT * FROM users WHERE email=? AND is_active=1");
    $s->execute([$email]);
    $user = $s->fetch();
    if ($user && password_verify($pass, $user['password'])) {
        $tok = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE users SET auth_token=?, last_active=NOW() WHERE id=?")->execute([$tok, $user['id']]);
        echo json_encode(['success'=>true,'token'=>$tok,'user'=>[
            'id'=>$user['id'],'name'=>$user['name'],'role'=>$user['role'],'email'=>$user['email']
        ]]);
    } else {
        http_response_code(401);
        echo json_encode(['error'=>'Invalid email or password']);
    }
    break;

// ---- LOGOUT ----
case 'logout':
    if ($userId) $pdo->prepare("UPDATE users SET auth_token=NULL, last_active=NOW() WHERE id=?")->execute([$userId]);
    echo json_encode(['success'=>true]);
    break;

// ---- ME ----
case 'me':
    echo json_encode(['user'=>['id'=>$cu['id'],'name'=>$cu['name'],'role'=>$cu['role'],'email'=>$cu['email']]]);
    break;

// ---- GET SYNC ----
case 'get_sync':
    $last = $pdo->query("SELECT MAX(updated_at) FROM tasks")->fetchColumn();
    $active = $pdo->query("SELECT name,role FROM users WHERE last_active > DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND is_active=1")->fetchAll();
    // Role-filtered counts
    if ($userRole === 'technician') {
        $taskCount = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=?"); $taskCount->execute([$userId]); $tc=$taskCount->fetchColumn();
        $urgentCount = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND task_status IN ('Open','In Progress') AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)"); $urgentCount->execute([$userId]); $uc=$urgentCount->fetchColumn();
    } else {
        $tc = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
        $uc = $pdo->query("SELECT COUNT(*) FROM tasks WHERE task_status IN ('Open','In Progress') AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
    echo json_encode(['last_task_update'=>$last,'active_users'=>$active,'server_time'=>date('Y-m-d H:i:s'),'task_count'=>intval($tc),'urgent_count'=>intval($uc)]);
    break;

// ---- GET STATS ----
case 'get_stats':
    $sql = "SELECT
        COUNT(*) total,
        SUM(task_status IN ('Open','In Progress','Task Pending')) open,
        SUM(task_status='Closed') closed,
        SUM(task_status='Cancelled') cancelled,
        SUM(task_status='Demo Sent') demo_sent,
        SUM(task_status='Awaiting Approval') awaiting_approval,
        SUM(CASE WHEN task_status='Closed'
            AND device_details NOT IN ('Troubleshoot/Offline','Troubleshoot','Offline','Demo','Demonstration','Only Remove')
            THEN COALESCE(device_qty,1) ELSE 0 END) devices_installed,
        SUM(CASE WHEN task_status='Closed'
            AND device_details IN ('Troubleshoot/Offline','Troubleshoot','Offline')
            THEN 1 ELSE 0 END) troubleshoot_done,
        SUM(CASE WHEN task_status NOT IN ('Closed','Cancelled')
            AND amount_collected IS NOT NULL AND amount_collected < price_to_collect - 15
            AND EXISTS (SELECT 1 FROM task_device_installs di WHERE di.task_id=tasks.id AND di.gps_serial_no IS NOT NULL)
            THEN 1 ELSE 0 END) payment_pending
        FROM tasks";
    if($userRole === 'technician'){
        $st = $pdo->prepare($sql . " WHERE assigned_to=?");
        $st->execute([$userId]);
    } else {
        $st = $pdo->prepare($sql);
        $st->execute([]);
    }
    echo json_encode(['stats' => $st->fetch()]);
    break;

// ---- GET USERS ----
case 'get_users':
    $role = $_GET['role'] ?? '';
    if ($role) {
        $s = $pdo->prepare("SELECT id,name,email,role,phone,is_active,last_active FROM users WHERE role=? AND is_active=1 ORDER BY name");
        $s->execute([$role]);
    } else {
        $s = $pdo->query("SELECT id,name,email,role,phone,is_active,last_active FROM users WHERE is_active=1 ORDER BY role,name");
    }
    echo json_encode(['users'=>$s->fetchAll()]);
    break;

// ---- SAVE USER ----
case 'save_user':
    if (!in_array($userRole,['admin'])) { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $uid  = intval($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $email= trim($body['email'] ?? '');
    $role = $body['role'] ?? 'technician';
    $phone= trim($body['phone'] ?? '');
    if (!$name||!$email) { echo json_encode(['error'=>'Name and email required']); break; }
    if ($uid) {
        $sets=[]; $vals=[];
        $sets[]='name=?';  $vals[]=$name;
        $sets[]='email=?'; $vals[]=$email;
        $sets[]='role=?';  $vals[]=$role;
        $sets[]='phone=?'; $vals[]=$phone;
        if (!empty($body['password'])) {
            $sets[]='password=?';
            $vals[]=password_hash($body['password'],PASSWORD_DEFAULT);
            $sets[]='auth_token=?';
            $vals[]=null; // Force user to re-login with new password
        }
        $vals[]=$uid;
        $pdo->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    } else {
        $hash = password_hash($body['password']??'Bharat@123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (name,email,password,role,phone,is_active) VALUES (?,?,?,?,?,1)")->execute([$name,$email,$hash,$role,$phone]);
    }
    echo json_encode(['success'=>true]);
    break;

// ---- RESET PASSWORD ----
case 'admin_reset_password':
    if ($userRole !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $uid  = intval($body['id'] ?? 0);
    $pass = trim($body['password'] ?? '');
    if (!$uid || strlen($pass) < 6) { echo json_encode(['error'=>'User ID and password (min 6 chars) required']); break; }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password=?, auth_token=NULL WHERE id=?")->execute([$hash, $uid]);
    echo json_encode(['success'=>true]);
    break;

// ---- DEACTIVATE USER ----
case 'deactivate_user':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $pdo->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([intval($body['id']??0)]);
    echo json_encode(['success'=>true]);
    break;

// ---- GET TASKS ----
case 'get_tasks':
    $where=[]; $params=[];

    // ROLE-BASED FILTER — enforced server-side
    if ($userRole === 'technician') {
        $where[] = "t.assigned_to=?";
        $params[] = $userId;
    } else {
        // Admin/assigner filters
        if (!empty($_GET['assigned_to'])) { $where[]="t.assigned_to=?"; $params[]=$_GET['assigned_to']; }
        if (!empty($_GET['technician']))  { $where[]="t.assigned_to=?"; $params[]=$_GET['technician']; }
    }

    if (!empty($_GET['status']))    { $where[]="t.task_status=?"; $params[]=$_GET['status']; }
    if (!empty($_GET['lead_type'])) { $where[]="t.lead_type=?";   $params[]=$_GET['lead_type']; }
    if (!empty($_GET['date_from'])) { $where[]="DATE(t.created_at)>=?"; $params[]=$_GET['date_from']; }
    if (!empty($_GET['date_to']))   { $where[]="DATE(t.created_at)<=?"; $params[]=$_GET['date_to']; }
    // status_group filter: 'active' = exclude Closed/Cancelled
    if (!empty($_GET['status_group'])) {
        if($_GET['status_group'] === 'active'){
            $where[] = "t.task_status NOT IN ('Closed','Cancelled')";
        }
    }
    if (!empty($_GET['search']))    {
        $q='%'.$_GET['search'].'%';
        $where[]="(t.customer_name LIKE ? OR t.contact_number LIKE ? OR t.task_id LIKE ? OR t.location LIKE ?)";
        $params[]=$q; $params[]=$q; $params[]=$q; $params[]=$q;
    }

    $limit = min(intval($_GET['limit'] ?? 500), 1000);
    // Ensure admin_viewed_at column exists
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN admin_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}

    $sql = "SELECT t.*,u.name as tech_name,u.name as technician_name,u.phone as tech_phone,c.name as creator_name,
            (SELECT MAX(a.created_at) FROM task_activities a WHERE a.task_id=t.id AND a.activity_type='remark') as last_tech_activity,
            t.admin_viewed_at
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to=u.id
            LEFT JOIN users c ON t.created_by=c.id"
         . ($where ? " WHERE ".implode(" AND ",$where) : "")
         . " ORDER BY t.created_at DESC LIMIT $limit";
    $s = $pdo->prepare($sql); $s->execute($params);
    $tasks = $s->fetchAll();

    // Build task ID list for bulk queries
    $taskIds = array_column($tasks, 'id');

    // Ensure device installs table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS task_device_installs (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, device_index INT DEFAULT 1, vehicle_number VARCHAR(50), vehicle_type VARCHAR(50), gps_serial_no VARCHAR(100), name_on_server VARCHAR(200), server_name VARCHAR(50), remarks TEXT, saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    // Bulk fetch: which tasks have device installs done
    $addingDoneIds = [];
    if(!empty($taskIds)){
        try {
            $in = implode(',', array_map('intval', $taskIds));
            $diRows = $pdo->query("SELECT DISTINCT task_id FROM task_device_installs WHERE task_id IN ($in) AND gps_serial_no IS NOT NULL AND gps_serial_no != ''")->fetchAll(PDO::FETCH_ASSOC);
            $addingDoneIds = array_column($diRows, 'task_id');
        } catch(Exception $e){ $addingDoneIds = []; }
    }

    // Compute workflow_state for each task
    foreach($tasks as &$task){
        $id           = $task['id'];
        $status       = $task['task_status']??'';
        $amtCollected = floatval($task['amount_collected']??0);
        $consentAt    = trim($task['customer_consent_at']??'');
        $consentToken = $task['consent_token']??'';
        $addingDone   = in_array($id, $addingDoneIds);
        $lastActivity = $task['last_tech_activity']??null;
        $adminViewed  = $task['admin_viewed_at']??null;
        $hasUnseenUpdate = $lastActivity && (!$adminViewed || strcmp($lastActivity, $adminViewed) > 0);

        // Priority order: most actionable first
        if($status === 'Awaiting Approval'){
            $task['workflow_state'] = 'approve_now';
        } elseif($status === 'Closed' || $status === 'Cancelled'){
            $task['workflow_state'] = '';
        } elseif($addingDone && $amtCollected <= 0){
            $task['workflow_state'] = 'payment_pending';
        } elseif($status === 'Task Pending' && $consentAt !== ''){
            // Postponed AFTER customer already consented — needs attention
            $task['workflow_state'] = 'postponed_after_consent';
        } elseif($consentAt !== '' && !$addingDone){
            $task['workflow_state'] = 'ready_to_add';
        } elseif($consentAt !== '' && $addingDone){
            $task['workflow_state'] = '';
        } elseif($consentToken && $consentToken !== 'USED' && $consentToken !== ''){
            $task['workflow_state'] = 'consent_sent';
        } elseif($hasUnseenUpdate){
            $task['workflow_state'] = 'tech_updated';
        } else {
            $task['workflow_state'] = '';
        }
    }
    unset($task);

    echo json_encode(['tasks'=>$tasks]);
    break;

// ---- GET TASK ----
case 'get_task':
    $id = intval($_GET['id'] ?? 0);
    $s = $pdo->prepare("SELECT t.*,u.name as technician_name,u.phone as tech_phone,c.name as creator_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN users c ON t.created_by=c.id WHERE t.id=?");
    $s->execute([$id]); $task=$s->fetch();
    if (!$task) { echo json_encode(['error'=>'Not found']); break; }
    // Technicians can only view their own assigned tasks
    if ($userRole === 'technician' && $task['assigned_to'] != $userId) {
        http_response_code(403); echo json_encode(['error'=>'Not authorized']); break;
    }
    $a=$pdo->prepare("SELECT a.*,u.name as user_name FROM task_activities a LEFT JOIN users u ON a.user_id=u.id WHERE a.task_id=? ORDER BY a.created_at ASC"); $a->execute([$id]); $task['activities']=$a->fetchAll();
    $d=$pdo->prepare("SELECT * FROM task_documents WHERE task_id=?"); $d->execute([$id]); $task['documents']=$d->fetchAll();
    $p=$pdo->prepare("SELECT p.*,u.name as collector_name FROM payments p LEFT JOIN users u ON p.collected_by=u.id WHERE p.task_id=?"); $p->execute([$id]); $task['payments']=$p->fetchAll();
    // Include device installs so frontend knows if already added
    try {
        $di=$pdo->prepare("SELECT * FROM task_device_installs WHERE task_id=? ORDER BY device_index ASC");
        $di->execute([$id]); $task['device_installs']=$di->fetchAll();
    } catch(Exception $e){ $task['device_installs']=[]; }
    echo json_encode(['task'=>$task]);
    break;

// ---- CREATE TASK ----
case 'create_task':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $year = date('Y');
    $cnt  = $pdo->query("SELECT COUNT(*) FROM tasks WHERE task_id LIKE 'ID-$year-%'")->fetchColumn();
    $taskId = "ID-$year-".str_pad($cnt+1,4,'0',STR_PAD_LEFT);
    $at  = !empty($body['assigned_to']) ? intval($body['assigned_to']) : null;
    $rd  = !empty($body['reminder_date']) ? $body['reminder_date'] : null;
    $prd = !empty($body['payment_reminder_date']) ? $body['payment_reminder_date'] : null;
    // Ensure discount columns exist
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN discount_given DECIMAL(10,2) DEFAULT 0"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN discount_reason VARCHAR(200) DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN discount_incharge VARCHAR(100) DEFAULT NULL"); } catch(Exception $e){}

    try {
    $pdo->prepare("INSERT INTO tasks (task_id,customer_name,contact_number,email,location,lead_type,device_qty,price_to_collect,payment_mode,assigned_to,task_status,is_outstation,customer_requested_delay,is_urgent,general_notes,reminder_date,device_details,created_by,payment_reminder_date,profile,outstation_location,outstation_travel_paid_by,outstation_customer_travel_amount,outstation_claim_cap,discount_given,discount_reason,discount_incharge,feedback_token)
        VALUES (?,?,?,?,?,?,?,?,?,?,'Open',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $taskId,
            trim($body['customer_name']??''), trim($body['contact_number']??''),
            trim($body['email']??''), trim($body['location']??''),
            $body['lead_type']??'New Lead', intval($body['device_qty']??1),
            floatval($body['price_to_collect']??0), $body['payment_mode']??'',
            $at, intval($body['is_outstation']??0), intval($body['customer_requested_delay']??0),
            intval($body['is_urgent']??0), trim($body['general_notes']??''),
            $rd, trim($body['device_details']??''), $userId, $prd,
            $body['profile']??'BGPT',
            $body['outstation_location']??null,
            $body['outstation_travel_paid_by']??null,
            $body['outstation_customer_travel_amount']??null,
            $body['outstation_claim_cap']??null,
            floatval($body['discount_given']??0),
            trim($body['discount_reason']??''),
            trim($body['discount_incharge']??''),
            $fbToken,
        ]);
    } catch(Exception $insertEx){
        echo json_encode(['error'=>'Database error: '.$insertEx->getMessage()]);
        break;
    }
    // Override status for yellow outstation
    if (!empty($body['outstation_travel_paid_by']) && $body['outstation_travel_paid_by']==='COMPANY') {
        $newId2 = $pdo->lastInsertId();
        if (isset($body['task_status']) && $body['task_status']==='Pending Outstation Approval') {
            $pdo->prepare("UPDATE tasks SET task_status='Pending Outstation Approval' WHERE id=?")->execute([$newId2]);
            // Email admin
            try {
                require_once __DIR__.'/mailer.php';
                $adminEmail = 'somesh9346220090@gmail.com';
                $adminBody = emailTemplate('<div class="greeting">⚠️ Outstation Approval Required</div>
                <p style="font-size:14px;color:#4a5568">An outstation task needs your approval before assignment.</p>
                <div class="details">
                    <div class="row"><div class="label">Task ID</div><div class="value blue">'.$taskId.'</div></div>
                    <div class="row"><div class="label">Customer</div><div class="value">'.htmlspecialchars($body['customer_name']??'').'</div></div>
                    <div class="row"><div class="label">Location</div><div class="value">'.htmlspecialchars($body['outstation_location']??'').'</div></div>
                    <div class="row"><div class="label">Device</div><div class="value">'.htmlspecialchars($body['device_details']??'').' × '.$body['device_qty'].'</div></div>
                    <div class="row"><div class="label">Price</div><div class="value highlight">₹'.number_format($body['price_to_collect']??0).'</div></div>
                </div>
                <p style="margin-top:16px"><a href="https://salmon-goldfish-110661.hostingersite.com" style="background:#1a3a6b;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px">→ Review in Task Manager</a></p>');
                sendMail($adminEmail, 'Admin', '⚠️ Outstation Approval Required — '.$taskId, $adminBody);
            } catch(Exception $e) {}
        }
    }
    $newId = $pdo->lastInsertId();
    if (!empty($body['remark'])) $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")->execute([$newId,$userId,$body['remark']]);
    if ($at) {
        $tn=$pdo->prepare("SELECT name FROM users WHERE id=?"); $tn->execute([$at]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'assignment')")->execute([$newId,$userId,"Task assigned to ".$tn->fetchColumn()]);
    }
    echo json_encode(['success'=>true,'task_id'=>$taskId,'id'=>$newId]);
    // Send emails
    if ($at && !empty($body['email'])) {
        try {
            require_once __DIR__.'/mailer.php';
            $tr=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $tr->execute([$newId]); $td=$tr->fetch();
            $tech=$pdo->prepare("SELECT name,email,phone FROM users WHERE id=?"); $tech->execute([$at]); $tc=$tech->fetch();
            if ($td) sendTaskCreatedCustomer($td, $tc['name']??'', $tc['phone']??'');
            if ($tc && $tc['email']) sendTaskCreatedTech($td, $tc['email'], $tc['name']);
        } catch(Exception $e) {}
    }
    break;

// ---- UPDATE TASK ----
case 'update_task':
    $id = intval($body['id'] ?? 0);
    $ex = $pdo->prepare("SELECT * FROM tasks WHERE id=?"); $ex->execute([$id]); $existing=$ex->fetch();
    if (!$existing) { echo json_encode(['error'=>'Not found']); break; }
    $fields = ['task_status','payment_status','amount_collected','payment_mode','device_details','general_notes','reminder_date','customer_requested_delay','is_outstation','payment_reminder_date','is_urgent','star_rating',
               // Balance sheet linkage fields
               'gps_serial_no','name_on_server','server_name','invoice_no','payment_received_on','payment_transaction_details','gst_amount','pending_reason','discount_reason','discount_incharge','profile',
               // Outstation fields
               'outstation_location','outstation_travel_paid_by','outstation_customer_travel_amount','outstation_claim_cap','outstation_claim_submitted','outstation_claim_status'];
    if (in_array($userRole,['admin','assigner'])) $fields=array_merge($fields,['customer_name','contact_number','email','location','lead_type','device_qty','price_to_collect','assigned_to']);
    $sets=[]; $vals=[];
    foreach ($fields as $f) {
        if (array_key_exists($f,$body)) {
            $sets[]="$f=?";
            $vals[]=($body[$f]===''&&in_array($f,['assigned_to','reminder_date']))?null:$body[$f];
        }
    }
    if (isset($body['task_status'])&&$body['task_status']==='Closed'&&$existing['task_status']!=='Closed') $sets[]="closed_at=NOW()";
    if ($sets) { $vals[]=$id; $pdo->prepare("UPDATE tasks SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    if (!empty($body['remark'])) {
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")->execute([$id,$userId,$body['remark']]);
    }
    if (isset($body['task_status'])&&$body['task_status']!==$existing['task_status'])
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,"Status: {$existing['task_status']} → {$body['task_status']}"]);

    // ── Respond to browser immediately — DB is updated, that is what matters ──
    echo json_encode(['success'=>true]);

    // ── Send email after responding — slow SMTP won't affect browser ──────
    if (!empty($body['remark'])) {
        try {
            require_once __DIR__.'/mailer.php';
            $taskForEmail = $pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
            $taskForEmail->execute([$id]);
            $taskData = $taskForEmail->fetch();
            if($taskData && !empty($taskData['email'])) {
                $remark = $body['remark'];

                // Filter: only email customer for relevant updates
                // ✅ Call/visit updates (📞) — only BEFORE device adding is done
                // ✅ Payment collected (💰) — always send
                // ❌ Device push to server (🛰), system entries, consent logs — never send
                $isPayment   = mb_strpos($remark,'💰')!==false;
                $isDevPush   = mb_strpos($remark,'🛰')!==false;
                $isSystem    = mb_strpos($remark,'⏰')!==false || mb_strpos($remark,'T+')!==false;
                $isCall      = mb_strpos($remark,'📞')!==false;
                $isPostpone  = mb_strpos($remark,'⏸️')!==false || mb_strpos($remark,'postponed')!==false;
                $isCancel    = mb_strpos($remark,'❌')!==false && mb_strpos($remark,'cancelled')!==false;

                // Check if adding is done
                $addChk = $pdo->prepare("SELECT COUNT(*) FROM task_device_installs WHERE task_id=? AND gps_serial_no IS NOT NULL");
                $addChk->execute([$id]);
                $addingDone = $addChk->fetchColumn() > 0;

                // Check if customer had consented (postpone email only relevant post-consent)
                $hadConsent = !empty($taskData['customer_consent_at']);

                $shouldEmail = false;
                if($isPayment)                        $shouldEmail = true;
                elseif($isDevPush)                    $shouldEmail = false;
                elseif($isSystem)                     $shouldEmail = false;
                elseif($isPostpone && $hadConsent)    $shouldEmail = true;  // Postpone after consent
                elseif($isCall && !$addingDone)       $shouldEmail = true;

                if($shouldEmail){
                    if(empty($taskData['feedback_token']) || $taskData['feedback_token']==='USED'){
                        $newToken = bin2hex(random_bytes(24));
                        try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN feedback_token VARCHAR(64) DEFAULT NULL")->execute(); } catch(Exception $ex){}
                        $pdo->prepare("UPDATE tasks SET feedback_token=? WHERE id=?")->execute([$newToken, $id]);
                        $taskData['feedback_token'] = $newToken;
                    }
                    $updaterName = $pdo->prepare("SELECT name FROM users WHERE id=?");
                    $updaterName->execute([$userId]);
                    $updater = $updaterName->fetch();
                    // Only send remark-type activities to customer (not system/push entries)
                    $actStmt = $pdo->prepare("SELECT a.*, u.name AS user_name FROM task_activities a LEFT JOIN users u ON a.user_id=u.id WHERE a.task_id=? AND a.activity_type='remark' AND (a.remark LIKE '%📞%' OR a.remark LIKE '%💰%') ORDER BY a.created_at ASC");
                    $actStmt->execute([$id]);
                    $allActivities = $actStmt->fetchAll();
                    // Use specific postpone template if this is a postponement
                    if($isPostpone && $hadConsent){
                        require_once __DIR__.'/mailer.php';
                        // Extract postpone details from remark
                        $pReason  = ''; $pDetails = ''; $pDate = '';
                        if(preg_match('/Reason: ([^|]+)/', $remark, $m)) $pReason  = trim($m[1]);
                        if(preg_match('/Details: ([^|]+)/', $remark, $m)) $pDetails = trim($m[1]);
                        if(preg_match('/Reschedule date: ([^|]+)/', $remark, $m)) $pDate = trim($m[1]);
                        $techNameRow = $pdo->prepare("SELECT name FROM users WHERE id=?");
                        $techNameRow->execute([$taskData['assigned_to']??0]);
                        $tName = $techNameRow->fetchColumn() ?: 'BharatGPS Technician';
                        sendPostponeCustomer($taskData, $pReason, $pDetails, $pDate, $tName);
                    } else {
                        sendTaskUpdateCustomer($taskData, $remark, $updater['name'] ?? 'BharatGPS Team', $allActivities);
                    }
                }
            }
        } catch(Exception $e) {
            error_log('Update email error: ' . $e->getMessage());
        }
    }
    break;
    // Create BS entry when technician submits (Awaiting Approval) — shows installation done, payment with tech
    if (isset($body['task_status']) && $body['task_status']==='Awaiting Approval' && $existing['task_status']!=='Awaiting Approval') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Refresh task data
            $bt=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?"); $bt->execute([$id]); $btask=$bt->fetch();
            if ($btask && !$btask['bs_entry_id']) {
                $bqty=floatval($btask['device_qty']??1);
                $btotal=floatval($btask['price_to_collect']??0);
                $bunit=$bqty>0?$btotal/$bqty:$btotal;
                $brecv=floatval($btask['amount_collected']??0);
                $bpend=max(0,$btotal-$brecv);
                $bpayStatus=$brecv>=$btotal&&$btotal>0?'With Technician — Collected':'With Technician — Pending';
                $bprofile=!empty($btask['profile'])?$btask['profile']:'BGPT';
                $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code) VALUES ('sales',?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$bprofile,$btask['task_id'],$id,$btask['gps_serial_no']??null,$btask['lead_type']??null,$btask['name_on_server']??null,$btask['server_name']??null,$btask['device_details']??null,$bqty,$bunit,floatval($btask['gst_amount']??0),$btotal,$bpayStatus,$brecv,$bpend,$btask['payment_mode']??null,$btask['tech_name']??null,$btask['location']??null,$btask['general_notes']??null,$cu['name']]);
                $bsId=$pdo->lastInsertId();
                $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$id]);
            }
        } catch(Exception $e) { error_log('BS awaiting error: '.$e->getMessage()); }
    }
    break;

// ---- DELETE TASK ----
case 'delete_task':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id=intval($body['id']??$_GET['id']??0);
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    break;

// ---- TRANSFER TASK ----
case 'transfer_task':
    $id=intval($body['task_id']??0); $toId=intval($body['to_user_id']??0);
    if (!$id||!$toId) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
    $ex=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $ex->execute([$id]); $task=$ex->fetch();
    if (!$task) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }
    $tu=$pdo->prepare("SELECT name FROM users WHERE id=? AND is_active=1"); $tu->execute([$toId]); $toName=$tu->fetchColumn();
    $pdo->prepare("UPDATE tasks SET assigned_to=?,transferred_from=?,task_status='Open' WHERE id=?")->execute([$toId,$task['assigned_to'],$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'assignment')")->execute([$id,$userId,"Transferred to $toName".(!empty($body['note'])?": {$body['note']}":"")]);
    echo json_encode(['success'=>true]);
    break;

// ---- APPROVE TASK ----
case 'approve_task':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id=intval($body['id']??0);
    // Fetch task first to check payment
    $h=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?"); $h->execute([$id]); $t=$h->fetch();
    if (!$t) { echo json_encode(['error'=>'Task not found']); break; }
    $totalPrice = floatval($t['price_to_collect']??0);
    $collected  = floatval($t['amount_collected']??0);
    $pending    = max(0, $totalPrice - $collected);
    // Hard block if payment pending
    if ($pending > 0) { echo json_encode(['error'=>'Cannot close — ₹'.number_format($pending,0).' still pending. Collect full payment first.','pending'=>$pending]); break; }
    // Close the task
    $pdo->prepare("UPDATE tasks SET task_status='Closed',closed_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,'Task approved and closed by manager. Full payment confirmed.']);
    // Star rating
    $hrs=(time()-strtotime($t['created_at']))/3600;
    $stars=$hrs<=12?5:($hrs<=24?4:($hrs<=48?3:($hrs<=72?2:1)));
    $pdo->prepare("UPDATE tasks SET star_rating=? WHERE id=? AND (star_rating IS NULL OR star_rating=0)")->execute([$stars,$id]);
    // Update BS entry if exists — mark payment as received by company
    if ($t['bs_entry_id']) {
        $pdo->prepare("UPDATE balance_sheet_entries SET payment_status='Collected',payment_received=?,pending_payment=0,payment_received_on=CURDATE() WHERE id=?")->execute([$collected,$t['bs_entry_id']]);
    } else {
        // Create BS entry now (fallback if not created at Awaiting Approval)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $qty2=floatval($t['device_qty']??1); $total2=floatval($t['price_to_collect']??0);
            $unit2=$qty2>0?$total2/$qty2:$total2;
            $taskProfile=!empty($t['profile'])?$t['profile']:'BGPT';
            $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code,payment_received_on) VALUES ('sales',?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())")
                ->execute([$taskProfile,$t['task_id'],$id,$t['gps_serial_no']??null,$t['lead_type']??null,$t['name_on_server']??null,$t['server_name']??null,$t['device_details']??null,$qty2,$unit2,floatval($t['gst_amount']??0),$total2,'Collected',$collected,0,$t['payment_mode']??null,$t['tech_name']??null,$t['location']??null,$t['general_notes']??null,$cu['name']]);
            $bsId=$pdo->lastInsertId();
            $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$id]);
        } catch(Exception $e) { error_log('BS close error: '.$e->getMessage()); }
    }
    echo json_encode(['success'=>true]);
    break;

// ---- REJECT TASK ----
case 'reject_task':
    $id=intval($body['id']??0);
    $pdo->prepare("UPDATE tasks SET task_status='In Progress' WHERE id=?")->execute([$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,'Sent back to technician: '.($body['reason']??'Needs revision')]);
    echo json_encode(['success'=>true]);
    break;

// ---- ADD PAYMENT ----
case 'add_payment':
    $tid=intval($body['task_id']??0); $amt=floatval($body['amount']??0);
    if (!$tid||!$amt) { echo json_encode(['error'=>'Missing params']); break; }
    $pdo->prepare("INSERT INTO payments (task_id,amount,payment_mode,transaction_ref,collected_by) VALUES (?,?,?,?,?)")
        ->execute([$tid,$amt,$body['payment_mode']??'Cash',$body['transaction_ref']??'',$userId]);
    $total=$pdo->prepare("SELECT SUM(amount) FROM payments WHERE task_id=?"); $total->execute([$tid]); $col=$total->fetchColumn();
    $pdo->prepare("UPDATE tasks SET amount_collected=?,payment_status=IF(?>=price_to_collect,'Collected','Partial') WHERE id=?")->execute([$col,$col,$tid]);
    echo json_encode(['success'=>true,'total_collected'=>$col]);
    break;

// ---- GET URGENT TASKS ----
case 'get_urgent_tasks':
    $urgSql = "SELECT t.*, u.name as tech_name, u.name as technician_name,
        TIMESTAMPDIFF(HOUR, t.created_at, NOW()) as age_hours
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.task_status IN ('Open','In Progress','Task Pending')
        AND t.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    if($userRole === 'technician'){
        $us = $pdo->prepare($urgSql . " AND t.assigned_to=? ORDER BY t.created_at ASC");
        $us->execute([$userId]);
    } else {
        $us = $pdo->prepare($urgSql . " ORDER BY t.created_at ASC");
        $us->execute([]);
    }
    echo json_encode(['tasks' => $us->fetchAll()]);
    break;

// ---- GET APPROVALS ----
case 'get_approvals':
    $apSql = "SELECT t.*, u.name as tech_name, u.name as technician_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.task_status = 'Awaiting Approval'
        ORDER BY t.updated_at DESC";
    if($userRole === 'technician'){
        $as = $pdo->prepare($apSql . " AND t.assigned_to=?");
        // Techs only see their own — rewrite
        $as = $pdo->prepare("SELECT t.*, u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.task_status='Awaiting Approval' AND t.assigned_to=? ORDER BY t.updated_at DESC");
        $as->execute([$userId]);
    } else {
        $as = $pdo->prepare($apSql);
        $as->execute([]);
    }
    echo json_encode(['tasks' => $as->fetchAll()]);
    break;

// ---- DAILY REPORT ----
case 'daily_report':
    $date=$_GET['date']??date('Y-m-d');
    $s=$pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE DATE(t.created_at)=? ORDER BY t.created_at DESC"); $s->execute([$date]);
    $tasks=$s->fetchAll();
    $rev=$pdo->prepare("SELECT COALESCE(SUM(amount_collected),0) FROM tasks WHERE DATE(created_at)=?"); $rev->execute([$date]);
    echo json_encode(['tasks'=>$tasks,'revenue'=>$rev->fetchColumn()]);
    break;

// ---- GET TECH STATS ----
case 'get_tech_stats':
    $tid=intval($_GET['tech_id']??0);
    $s=$pdo->prepare("SELECT COUNT(*) total, SUM(task_status='Closed') closed, SUM(task_status='Awaiting Approval') awaiting, SUM(device_qty) devices_installed FROM tasks WHERE assigned_to=?");
    $s->execute([$tid]); $stats=$s->fetch();
    echo json_encode(['stats'=>$stats]);
    break;

// ---- BULK DELETE ----
case 'bulk_delete':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $status=$body['status']??'';
    if (!in_array($status,['Closed','Cancelled'])) { echo json_encode(['error'=>'Invalid status']); break; }
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM tasks WHERE task_status=?"); $cnt->execute([$status]); $count=$cnt->fetchColumn();
    $pdo->prepare("DELETE FROM tasks WHERE task_status=?")->execute([$status]);
    echo json_encode(['success'=>true,'count'=>$count]);
    break;

// ---- ERASE ALL TASKS ----
case 'erase_all_tasks':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $count=$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("TRUNCATE TABLE task_activities");
    $pdo->exec("TRUNCATE TABLE task_documents");
    $pdo->exec("TRUNCATE TABLE payments");
    $pdo->exec("TRUNCATE TABLE sync_log");
    $pdo->exec("TRUNCATE TABLE tasks");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo json_encode(['success'=>true,'count'=>$count]);
    break;

// ---- UPLOAD DOCUMENT ----
case 'upload_document':
    $tid=intval($body['task_id']??$_POST['task_id']??0);
    $dtype=trim($body['doc_type']??$_POST['doc_type']??'other');
    if (!$tid||!isset($_FILES['file'])) { echo json_encode(['error'=>'Missing file']); break; }
    $dir=__DIR__.'/../uploads/task_'.$tid.'/'; if(!is_dir($dir)) mkdir($dir,0755,true);
    $fn=time().'_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$_FILES['file']['name']);
    if (move_uploaded_file($_FILES['file']['tmp_name'],$dir.$fn)) {
        $pdo->prepare("INSERT INTO task_documents (task_id,doc_type,filename,original_name,uploaded_by) VALUES (?,?,?,?,?)")->execute([$tid,$dtype,$fn,$_FILES['file']['name'],$userId]);
        echo json_encode(['success'=>true,'filename'=>$fn]);
    } else { echo json_encode(['error'=>'Upload failed']); }
    break;

// ---- BALANCE SHEET ----
// ---- SAVE DEVICE INSTALL ----
case 'save_device_install':
    $tid = intval($body['task_id']??0);
    $idx = intval($body['device_index']??1);
    if (!$tid||!$idx) { echo json_encode(['error'=>'Missing params']); break; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_device_installs (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, device_index INT NOT NULL DEFAULT 1, vehicle_number VARCHAR(50), vehicle_type VARCHAR(50), gps_serial_no VARCHAR(100), name_on_server VARCHAR(200), server_name VARCHAR(50), rc_photo VARCHAR(200), selfie_photo VARCHAR(200), remarks TEXT, saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_device (task_id, device_index)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e) {}
    $pdo->prepare("INSERT INTO task_device_installs (task_id,device_index,vehicle_number,vehicle_type,gps_serial_no,name_on_server,server_name,remarks) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vehicle_number=VALUES(vehicle_number),vehicle_type=VALUES(vehicle_type),gps_serial_no=VALUES(gps_serial_no),name_on_server=VALUES(name_on_server),server_name=VALUES(server_name),remarks=VALUES(remarks),saved_at=NOW()")
        ->execute([$tid,$idx,trim($body['vehicle_number']??''),trim($body['vehicle_type']??''),trim($body['gps_serial_no']??''),trim($body['name_on_server']??''),trim($body['server_name']??''),trim($body['remarks']??'')]);
    if ($idx===1) {
        $pdo->prepare("UPDATE tasks SET gps_serial_no=?,name_on_server=?,server_name=? WHERE id=?")->execute([trim($body['gps_serial_no']??''),trim($body['name_on_server']??''),trim($body['server_name']??''),$tid]);
    }
    echo json_encode(['success'=>true]);
    break;

// ============================================================
// BALANCE SHEET ENTRIES
// ============================================================

case 'get_balance_sheet':
    // Legacy — tasks based (kept for backward compat)
    $from=$_GET['from']??date('Y-m-01'); $to=$_GET['to']??date('Y-m-d');
    $s=$pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC"); $s->execute([$from,$to]);
    echo json_encode(['tasks'=>$s->fetchAll()]);
    break;

case 'bs_get_entries':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // Ensure table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}
    $where=[]; $params=[];
    $profile = $_GET['profile'] ?? 'BGPT';
    $where[] = "profile=?"; $params[] = $profile;
    if (!empty($_GET['type']))     { $where[]="type=?";          $params[]=$_GET['type']; }
    if (!empty($_GET['from']))     { $where[]="date>=?";         $params[]=$_GET['from']; }
    if (!empty($_GET['to']))       { $where[]="date<=?";         $params[]=$_GET['to']; }
    if (!empty($_GET['search']))   { $q='%'.$_GET['search'].'%'; $where[]="(task_id LIKE ? OR name_on_server LIKE ? OR gps_serial_no LIKE ? OR invoice_no LIKE ? OR technician_name LIKE ?)"; $params=array_merge($params,[$q,$q,$q,$q,$q]); }
    if (!empty($_GET['pending']))  { $where[]="pending_payment > 0"; }
    $sql = "SELECT * FROM balance_sheet_entries" . ($where?" WHERE ".implode(" AND ",$where):"") . " ORDER BY date DESC, created_at DESC LIMIT 1000";
    $s = $pdo->prepare($sql); $s->execute($params);
    $entries = $s->fetchAll();
    // Stats
    $stats = [
        'total_sales'       => 0, 'total_license'     => 0,
        'payment_received'  => 0, 'pending_payment'   => 0,
        'devices_sold'      => 0, 'license_count'     => 0,
    ];
    foreach ($entries as $e) {
        if ($e['type']==='sales')   { $stats['total_sales']   += $e['total_price']; $stats['devices_sold']   += $e['qty']; }
        if ($e['type']==='license') { $stats['total_license'] += $e['total_price']; $stats['license_count'] += $e['qty']; }
        $stats['payment_received'] += $e['payment_received'];
        $stats['pending_payment']  += $e['pending_payment'];
    }
    echo json_encode(['entries'=>$entries,'stats'=>$stats,'count'=>count($entries)]);
    break;

case 'bs_add_entry':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $b = $body;
    $qty   = floatval($b['qty']??1);
    $unit  = floatval($b['unit_price']??0);
    $gst   = floatval($b['gst']??0);
    $total = floatval($b['total_price']??($unit*$qty+$gst));
    $recv  = floatval($b['payment_received']??0);
    $pend  = floatval($b['pending_payment']??($total-$recv));
    $pdo->prepare("INSERT INTO balance_sheet_entries
        (type,profile,task_id,task_db_id,date,invoice_no,gps_serial_no,customer_type,
         name_on_server,server_name,device_model,service_type,license_plan,
         qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,
         payment_mode,payment_received_on,payment_transaction_details,
         pending_reason,discount_given,discount_reason,discount_incharge,payment_reminder_date,
         technician_name,location,remarks,created_by_code)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $b['type']??'sales', $b['profile']??'BGPT',
            $b['task_id']??null, $b['task_db_id']??null,
            $b['date']??date('Y-m-d'), $b['invoice_no']??null,
            $b['gps_serial_no']??null, $b['customer_type']??null,
            $b['name_on_server']??null, $b['server_name']??null,
            $b['device_model']??null, $b['service_type']??null, $b['license_plan']??null,
            $qty, $unit, $gst, $total,
            $b['payment_status']??null, $recv, $pend,
            $b['payment_mode']??null,
            !empty($b['payment_received_on'])?$b['payment_received_on']:null,
            $b['payment_transaction_details']??null,
            $b['pending_reason']??null, floatval($b['discount_given']??0),
            $b['discount_reason']??null, $b['discount_incharge']??null,
            !empty($b['payment_reminder_date'])?$b['payment_reminder_date']:null,
            $b['technician_name']??null, $b['location']??null,
            $b['remarks']??null, $cu['name'],
        ]);
    $newId = $pdo->lastInsertId();
    // Link back to task if task_db_id provided
    if (!empty($b['task_db_id'])) {
        $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$newId, $b['task_db_id']]);
    }
    echo json_encode(['success'=>true,'id'=>$newId]);
    break;

case 'bs_update_entry':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = intval($body['id']??0);
    if (!$id) { echo json_encode(['error'=>'Missing id']); break; }
    $allowed = ['date','invoice_no','gps_serial_no','customer_type','name_on_server','server_name',
                'device_model','service_type','license_plan','qty','unit_price','gst','total_price',
                'payment_status','payment_received','pending_payment','payment_mode','payment_received_on',
                'payment_transaction_details','pending_reason','discount_given','discount_reason',
                'discount_incharge','payment_reminder_date','technician_name','location','remarks','profile'];
    $sets=[]; $vals=[];
    foreach ($allowed as $f) {
        if (array_key_exists($f,$body)) { $sets[]="$f=?"; $vals[]=($body[$f]===''?null:$body[$f]); }
    }
    if ($sets) { $vals[]=$id; $pdo->prepare("UPDATE balance_sheet_entries SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    echo json_encode(['success'=>true]);
    break;

case 'bs_delete_entry':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id = intval($body['id']??0);
    $pdo->prepare("DELETE FROM balance_sheet_entries WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    break;

case 'bs_from_task':
    // Auto-create BS entry from a closed task
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    // Ensure table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}
    // Ensure bs_entry_id column exists on tasks
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS bs_entry_id INT NULL"); } catch(Exception $e) {}
    $taskDbId = intval($body['task_db_id']??0);
    if (!$taskDbId) { echo json_encode(['error'=>'Missing task_db_id']); break; }
    $tr=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
    $tr->execute([$taskDbId]); $t=$tr->fetch();
    if (!$t) { echo json_encode(['error'=>'Task not found']); break; }
    // Check if already has BS entry
    if ($t['bs_entry_id']) { echo json_encode(['success'=>true,'id'=>$t['bs_entry_id'],'existing'=>true]); break; }
    $qty   = floatval($t['device_qty']??1);
    $total = floatval($t['price_to_collect']??0);
    $unit  = $qty>0 ? $total/$qty : $total;
    $recv  = floatval($t['amount_collected']??0);
    $pend  = max(0,$total-$recv);
    $profile = $body['profile']??'BGPT';
    $pdo->prepare("INSERT INTO balance_sheet_entries
        (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,
         name_on_server,server_name,device_model,qty,unit_price,gst,total_price,
         payment_status,payment_received,pending_payment,payment_mode,
         payment_received_on,payment_transaction_details,
         pending_reason,discount_reason,discount_incharge,payment_reminder_date,
         technician_name,location,remarks,created_by_code)
        VALUES ('sales',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $profile, $t['task_id'], $taskDbId,
            $t['closed_at']?date('Y-m-d',strtotime($t['closed_at'])):date('Y-m-d'),
            $t['gps_serial_no']??null,
            $t['lead_type']??null,
            $t['name_on_server']??null, $t['server_name']??null,
            $t['device_details']??null, $qty, $unit,
            floatval($t['gst_amount']??0), $total,
            $t['payment_status']??'Pending', $recv, $pend,
            $t['payment_mode']??null,
            $t['payment_received_on']??null, $t['payment_transaction_details']??null,
            $t['pending_reason']??null, $t['discount_reason']??null,
            $t['discount_incharge']??null, $t['payment_reminder_date']??null,
            $t['tech_name']??null, $t['location']??null,
            $t['general_notes']??null, $cu['name'],
        ]);
    $newId = $pdo->lastInsertId();
    $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$newId,$taskDbId]);
    echo json_encode(['success'=>true,'id'=>$newId,'task_id'=>$t['task_id']]);
    break;

// ============================================================
// FINANCE PORTAL ACTIONS
// ============================================================
case 'verify_pin':
    $pin=trim($body['pin']??'');
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS finance_settings(id INT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(50) UNIQUE,setting_value TEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    try{$pdo->exec("INSERT IGNORE INTO finance_settings(setting_key,setting_value)VALUES('finance_pin','9999')");}catch(Exception $e){}
    $s=$pdo->prepare("SELECT setting_value FROM finance_settings WHERE setting_key='finance_pin'");$s->execute();$stored=$s->fetchColumn()?:'9999';
    echo json_encode($pin===$stored?['success'=>true]:['success'=>false,'error'=>'Wrong PIN']);break;

case 'update_pin':
    $pin=trim($body['pin']??'');if(strlen($pin)<4){echo json_encode(['error'=>'Min 4 digits']);break;}
    $pdo->prepare("INSERT INTO finance_settings(setting_key,setting_value)VALUES('finance_pin',?)ON DUPLICATE KEY UPDATE setting_value=?")->execute([$pin,$pin]);
    echo json_encode(['success'=>true]);break;

case 'get_suppliers':
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS suppliers(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',name VARCHAR(150) NOT NULL,contact_person VARCHAR(100),phone VARCHAR(20),email VARCHAR(150),address TEXT,gst_no VARCHAR(20),device_types TEXT,notes TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    $s=$pdo->prepare("SELECT * FROM suppliers WHERE company=? AND is_active=1 ORDER BY name");$s->execute([$_GET['company']??'BGPT']);echo json_encode(['suppliers'=>$s->fetchAll()]);break;

case 'save_supplier':
    $id=intval($body['id']??0);$name=trim($body['name']??'');if(!$name){echo json_encode(['error'=>'Name required']);break;}
    if($id){$pdo->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,gst_no=?,device_types=?,notes=?,company=? WHERE id=?")->execute([trim($body['name']),trim($body['contact_person']??''),trim($body['phone']??''),trim($body['email']??''),trim($body['address']??''),trim($body['gst_no']??''),trim($body['device_types']??''),trim($body['notes']??''),$body['company']??'BGPT',$id]);}
    else{$pdo->prepare("INSERT INTO suppliers(company,name,contact_person,phone,email,address,gst_no,device_types,notes)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$body['company']??'BGPT',trim($body['name']),trim($body['contact_person']??''),trim($body['phone']??''),trim($body['email']??''),trim($body['address']??''),trim($body['gst_no']??''),trim($body['device_types']??''),trim($body['notes']??'')]);}
    echo json_encode(['success'=>true,'id'=>$id?:$pdo->lastInsertId()]);break;

case 'delete_supplier':
    $pdo->prepare("UPDATE suppliers SET is_active=0 WHERE id=?")->execute([intval($body['id']??0)]);echo json_encode(['success'=>true]);break;

case 'get_purchase_orders':
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',po_number VARCHAR(30) UNIQUE NOT NULL,supplier_id INT,order_date DATE,expected_date DATE NULL,status VARCHAR(30) DEFAULT 'Draft',total_amount DECIMAL(10,2) DEFAULT 0,paid_amount DECIMAL(10,2) DEFAULT 0,payment_mode VARCHAR(50),payment_ref VARCHAR(100),notes TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items(id INT AUTO_INCREMENT PRIMARY KEY,po_id INT,device_model VARCHAR(100),quantity INT DEFAULT 1,received_qty INT DEFAULT 0,unit_cost DECIMAL(10,2),total_cost DECIMAL(10,2),notes TEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    $co=$_GET['company']??'BGPT';$s=$pdo->prepare("SELECT p.*,s.name as supplier_name FROM purchase_orders p LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE p.company=? ORDER BY p.order_date DESC");$s->execute([$co]);$orders=$s->fetchAll();
    foreach($orders as &$o){$i=$pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id=?");$i->execute([$o['id']]);$o['items']=$i->fetchAll();}
    echo json_encode(['orders'=>$orders]);break;

case 'save_purchase_order':
    $id=intval($body['id']??0);$items=$body['items']??[];$total=array_sum(array_column($items,'total_cost'));$co=$body['company']??'BGPT';
    if($id){$pdo->prepare("UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_date=?,status=?,total_amount=?,paid_amount=?,payment_mode=?,payment_ref=?,notes=? WHERE id=?")->execute([intval($body['supplier_id']),$body['order_date'],$body['expected_date']?:null,$body['status']??'Draft',$total,floatval($body['paid_amount']??0),$body['payment_mode']??'',$body['payment_ref']??'',$body['notes']??'',$id]);$pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);}
    else{$yr=date('Y');$cnt=$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE YEAR(created_at)=$yr")->fetchColumn();$pn='PO-'.$yr.'-'.str_pad($cnt+1,4,'0',STR_PAD_LEFT);$pdo->prepare("INSERT INTO purchase_orders(company,po_number,supplier_id,order_date,expected_date,status,total_amount,paid_amount,payment_mode,payment_ref,notes,created_by)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$co,$pn,intval($body['supplier_id']),$body['order_date'],$body['expected_date']?:null,$body['status']??'Draft',$total,floatval($body['paid_amount']??0),$body['payment_mode']??'',$body['payment_ref']??'',$body['notes']??'',$cu['name']]);$id=$pdo->lastInsertId();}
    foreach($items as $item){$pdo->prepare("INSERT INTO purchase_order_items(po_id,device_model,quantity,received_qty,unit_cost,total_cost,notes)VALUES(?,?,?,?,?,?,?)")->execute([$id,$item['device_model'],intval($item['quantity']),intval($item['received_qty']??0),floatval($item['unit_cost']),floatval($item['total_cost']),$item['notes']??'']);}
    echo json_encode(['success'=>true,'id'=>$id]);break;

case 'delete_purchase_order':
    $id=intval($body['id']??0);$pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);$pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);echo json_encode(['success'=>true]);break;

case 'get_expenses':
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS expenses(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',date DATE,category VARCHAR(50),description TEXT,amount DECIMAL(10,2),payment_mode VARCHAR(50),paid_to VARCHAR(100),reference VARCHAR(100),receipt_note TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
    $co=$_GET['company']??'BGPT';$w=['company=?'];$pa=[$co];
    if(!empty($_GET['from'])){$w[]='date>=?';$pa[]=$_GET['from'];}if(!empty($_GET['to'])){$w[]='date<=?';$pa[]=$_GET['to'];}if(!empty($_GET['category'])){$w[]='category=?';$pa[]=$_GET['category'];}
    $s=$pdo->prepare("SELECT * FROM expenses WHERE ".implode(' AND ',$w)." ORDER BY date DESC");$s->execute($pa);$rows=$s->fetchAll();
    echo json_encode(['expenses'=>$rows,'total'=>array_sum(array_column($rows,'amount'))]);break;

case 'save_expense':
    $id=intval($body['id']??0);
    if($id){$sets=[];$vals=[];foreach(['company','date','category','description','amount','payment_mode','paid_to','reference','receipt_note']as $f){if(isset($body[$f])){$sets[]="$f=?";$vals[]=$body[$f];}}$vals[]=$id;$pdo->prepare("UPDATE expenses SET ".implode(',',$sets)." WHERE id=?")->execute($vals);}
    else{$pdo->prepare("INSERT INTO expenses(company,date,category,description,amount,payment_mode,paid_to,reference,receipt_note,created_by)VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$body['company']??'BGPT',$body['date'],$body['category'],$body['description'],floatval($body['amount']),$body['payment_mode']??'',$body['paid_to']??'',$body['reference']??'',$body['receipt_note']??'',$cu['name']]);$id=$pdo->lastInsertId();}
    echo json_encode(['success'=>true,'id'=>$id]);break;

case 'delete_expense':
    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([intval($body['id']??0)]);echo json_encode(['success'=>true]);break;

case 'get_accounts_summary':
    $co=$_GET['company']??'BGPT';$from=$_GET['from']??date('Y-01-01');$to=$_GET['to']??date('Y-m-d');
    try{$q1=$pdo->prepare("SELECT COALESCE(SUM(total_price),0)ts,COALESCE(SUM(payment_received),0)tr,COALESCE(SUM(pending_payment),0)tp,COALESCE(SUM(CASE WHEN type='sales' THEN total_price ELSE 0 END),0)si,COALESCE(SUM(CASE WHEN type='license' THEN total_price ELSE 0 END),0)li,COALESCE(SUM(CASE WHEN type='sales' THEN qty ELSE 0 END),0)ds,COUNT(*)tc FROM balance_sheet_entries WHERE profile=? AND date BETWEEN ? AND ?");$q1->execute([$co,$from,$to]);$inc=$q1->fetch();}catch(Exception $e){$inc=['ts'=>0,'tr'=>0,'tp'=>0,'si'=>0,'li'=>0,'ds'=>0,'tc'=>0];}
    $q2=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0)tp,COALESCE(SUM(paid_amount),0)pp,COUNT(*)pc FROM purchase_orders WHERE company=? AND order_date BETWEEN ? AND ? AND status!='Cancelled'");$q2->execute([$co,$from,$to]);$pur=$q2->fetch();
    try{$q3=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company=? AND date BETWEEN ? AND ?");$q3->execute([$co,$from,$to]);$tex=floatval($q3->fetchColumn());}catch(Exception $e){$tex=0;}
    try{$q4=$pdo->prepare("SELECT category,COALESCE(SUM(amount),0)ct FROM expenses WHERE company=? AND date BETWEEN ? AND ? GROUP BY category ORDER BY ct DESC");$q4->execute([$co,$from,$to]);$cats=$q4->fetchAll();}catch(Exception $e){$cats=[];}
    try{$q5=$pdo->prepare("SELECT DATE_FORMAT(date,'%Y-%m')month,COALESCE(SUM(total_price),0)income,COALESCE(SUM(payment_received),0)received FROM balance_sheet_entries WHERE profile=? AND date BETWEEN ? AND ? GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month");$q5->execute([$co,$from,$to]);$monthly=$q5->fetchAll();}catch(Exception $e){$monthly=[];}
    $gp=floatval($inc['ts']??0)-floatval($pur['tp']??0);
    echo json_encode(['income'=>['total_sales'=>$inc['ts']??0,'total_received'=>$inc['tr']??0,'total_pending'=>$inc['tp']??0,'sales_income'=>$inc['si']??0,'license_income'=>$inc['li']??0,'devices_sold'=>$inc['ds']??0,'total_entries'=>$inc['tc']??0],'purchases'=>['total_po'=>$pur['tp']??0,'po_count'=>$pur['pc']??0],'expenses_by_category'=>$cats,'total_expenses'=>$tex,'gross_profit'=>$gp,'net_profit'=>$gp-$tex,'monthly'=>$monthly]);break;

// ════════════════════════════════════════════════════
// INVOICING API — Items, Parties, Invoices, Settings
// ════════════════════════════════════════════════════

// ── INV ITEMS ────────────────────────────────────────
case 'inv_get_items':
    $stmt = $pdo->prepare("SELECT * FROM inv_items ORDER BY name ASC");
    $stmt->execute();
    echo json_encode(['success'=>true,'items'=>$stmt->fetchAll()]);
    break;

case 'inv_save_item':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id'] ?? ('ITEM-'.time().'-'.rand(100,999));
    $existing = $pdo->prepare("SELECT id FROM inv_items WHERE id=?"); $existing->execute([$id]);
    $data = [
        'id'=>$id, 'name'=>$body['name']??'', 'hsn'=>$body['hsn']??'',
        'code'=>$body['code']??'', 'unit'=>$body['unit']??'PCS',
        'category'=>$body['category']??'', 'description'=>$body['description']??'',
        'mrp'=>floatval($body['mrp']??0), 'sale_price'=>floatval($body['sale_price']??0),
        'purchase_price'=>floatval($body['purchase_price']??0), 'gst_rate'=>floatval($body['gst_rate']??18),
        'opening_stock'=>intval($body['opening_stock']??0), 'low_stock_alert'=>intval($body['low_stock_alert']??5),
        'location'=>$body['location']??'', 'is_service'=>intval($body['is_service']??0),
        'created_by'=>$userId
    ];
    if($existing->fetch()){
        $pdo->prepare("UPDATE inv_items SET name=?,hsn=?,code=?,unit=?,category=?,description=?,mrp=?,sale_price=?,purchase_price=?,gst_rate=?,opening_stock=?,low_stock_alert=?,location=?,is_service=? WHERE id=?")
            ->execute(array_values(array_slice(array_values($data),1,14))+[$id]);
    } else {
        $pdo->prepare("INSERT INTO inv_items (id,name,hsn,code,unit,category,description,mrp,sale_price,purchase_price,gst_rate,opening_stock,low_stock_alert,location,is_service,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$data['name'],$data['hsn'],$data['code'],$data['unit'],$data['category'],$data['description'],$data['mrp'],$data['sale_price'],$data['purchase_price'],$data['gst_rate'],$data['opening_stock'],$data['low_stock_alert'],$data['location'],$data['is_service'],$userId]);
    }
    echo json_encode(['success'=>true,'id'=>$id]);
    break;

case 'inv_delete_item':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id']??'';
    $pdo->prepare("DELETE FROM inv_items WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    break;

// ── INV PARTIES ──────────────────────────────────────
case 'inv_get_parties':
    $stmt = $pdo->prepare("SELECT * FROM inv_parties ORDER BY name ASC");
    $stmt->execute();
    echo json_encode(['success'=>true,'parties'=>$stmt->fetchAll()]);
    break;

case 'inv_save_party':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id'] ?? ('PARTY-'.time().'-'.rand(100,999));
    $existing = $pdo->prepare("SELECT id FROM inv_parties WHERE id=?"); $existing->execute([$id]);
    if($existing->fetch()){
        $pdo->prepare("UPDATE inv_parties SET name=?,phone=?,email=?,gstin=?,gst_type=?,billing_address=?,state=?,opening_balance=?,balance_type=? WHERE id=?")
            ->execute([$body['name']??'',$body['phone']??'',$body['email']??'',$body['gstin']??'',$body['gst_type']??'Unregistered/Consumer',$body['billing_address']??'',$body['state']??'',floatval($body['opening_balance']??0),$body['balance_type']??'receivable',$id]);
    } else {
        $pdo->prepare("INSERT INTO inv_parties (id,name,phone,email,gstin,gst_type,billing_address,state,opening_balance,balance_type,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$body['name']??'',$body['phone']??'',$body['email']??'',$body['gstin']??'',$body['gst_type']??'Unregistered/Consumer',$body['billing_address']??'',$body['state']??'',floatval($body['opening_balance']??0),$body['balance_type']??'receivable',$userId]);
    }
    echo json_encode(['success'=>true,'id'=>$id]);
    break;

case 'inv_delete_party':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $pdo->prepare("DELETE FROM inv_parties WHERE id=?")->execute([$body['id']??'']);
    echo json_encode(['success'=>true]);
    break;

// ── INV INVOICES ─────────────────────────────────────
case 'inv_get_invoices':
    // Ensure new columns exist (safe migration for existing installs)
    $migrations_inv = [
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS gstin VARCHAR(20) DEFAULT '' AFTER po_no",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS gst_type VARCHAR(50) DEFAULT 'Unregistered/Consumer' AFTER gstin",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS billing_address TEXT AFTER state",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS gst_split VARCHAR(20) DEFAULT 'GST' AFTER cash_sale",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS cgst DECIMAL(12,2) DEFAULT 0 AFTER gst_split",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS sgst DECIMAL(12,2) DEFAULT 0 AFTER cgst",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS igst DECIMAL(12,2) DEFAULT 0 AFTER sgst",
        "ALTER TABLE inv_invoices ADD COLUMN IF NOT EXISTS task_id_ref INT DEFAULT NULL AFTER notes",
    ];
    foreach($migrations_inv as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }

    $type = $_GET['type'] ?? 'sale';
    $stmt = $pdo->prepare("SELECT * FROM inv_invoices WHERE inv_type=? AND status!='cancelled' ORDER BY date DESC, created_at DESC");
    $stmt->execute([$type]);
    $rows = $stmt->fetchAll();
    foreach($rows as &$r){ $r['items'] = json_decode($r['items_json']??'[]',true); }
    echo json_encode(['success'=>true,'invoices'=>$rows]);
    break;

case 'inv_save_invoice':
    if(!in_array($userRole,['admin','assigner'])){ http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id = $body['id'] ?? ('INV-'.time().'-'.rand(100,999));
    $existing = $pdo->prepare("SELECT id FROM inv_invoices WHERE id=?"); $existing->execute([$id]);
    $itemsJson = json_encode($body['items']??[]);

    // Safe helper
    $g = function($k,$d='') use($body){ return $body[$k]??$d; };

    $data = [
        'inv_no'          => $g('inv_no'),
        'inv_type'        => $g('inv_type','sale'),
        'date'            => $g('date',date('Y-m-d')),
        'due_date'        => $g('due_date')||null,
        'party_id'        => $g('party_id')||null,
        'customer'        => $g('customer'),
        'billing_name'    => $g('billing_name'),
        'po_no'           => $g('po_no'),
        'gstin'           => $g('gstin'),
        'gst_type'        => $g('gst_type','Unregistered/Consumer'),
        'state'           => $g('state'),
        'billing_address' => $g('billing_address'),
        'pay_mode'        => $g('pay_mode'),
        'cash_sale'       => intval($g('cash_sale',0)),
        'gst_split'       => $g('gst_split','GST'),
        'cgst'            => floatval($g('cgst',0)),
        'sgst'            => floatval($g('sgst',0)),
        'igst'            => floatval($g('igst',0)),
        'items_json'      => $itemsJson,
        'sub_total'       => floatval($g('sub_total',0)),
        'discount_total'  => floatval($g('discount_total',0)),
        'gst_total'       => floatval($g('gst_total',0)),
        'grand_total'     => floatval($g('grand_total',0)),
        'amount_received' => floatval($g('amount_received',0)),
        'terms'           => $g('terms'),
        'notes'           => $g('notes'),
        'task_id_ref'     => $g('task_id_ref')||null,
    ];

    if($existing->fetch()){
        $pdo->prepare("UPDATE inv_invoices SET inv_no=?,inv_type=?,date=?,due_date=?,party_id=?,customer=?,billing_name=?,po_no=?,gstin=?,gst_type=?,state=?,billing_address=?,pay_mode=?,cash_sale=?,gst_split=?,cgst=?,sgst=?,igst=?,items_json=?,sub_total=?,discount_total=?,gst_total=?,grand_total=?,amount_received=?,terms=?,notes=?,task_id_ref=? WHERE id=?")
            ->execute(array_merge(array_values($data),[$id]));
    } else {
        $cols = implode(',',array_keys($data));
        $placeholders = implode(',',array_fill(0,count($data),'?'));
        $pdo->prepare("INSERT INTO inv_invoices (id,$cols,created_by) VALUES (?,{$placeholders},?)")
            ->execute(array_merge([$id],array_values($data),[$userId]));
    }
    echo json_encode(['success'=>true,'id'=>$id]);
    break;

case 'inv_delete_invoice':
    if($userRole!=='admin'){ http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    // Soft-delete only — never hard delete
    $pdo->prepare("UPDATE inv_invoices SET status='cancelled' WHERE id=?")->execute([$body['id']??'']);
    echo json_encode(['success'=>true]);
    break;

case 'inv_get_counter':
    $stmt = $pdo->prepare("SELECT setting_value FROM inv_settings WHERE setting_key='inv_counter'");
    $stmt->execute();
    $row = $stmt->fetch();
    echo json_encode(['success'=>true,'counter'=>intval($row['setting_value']??116)]);
    break;

case 'inv_increment_counter':
    $pdo->exec("INSERT INTO inv_settings (setting_key,setting_value) VALUES ('inv_counter','117') ON DUPLICATE KEY UPDATE setting_value=CAST(setting_value AS UNSIGNED)+1");
    $stmt = $pdo->prepare("SELECT setting_value FROM inv_settings WHERE setting_key='inv_counter'"); $stmt->execute();
    echo json_encode(['success'=>true,'counter'=>intval($stmt->fetch()['setting_value']??117)]);
    break;

case 'inv_save_setting':
    $pdo->prepare("INSERT INTO inv_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
        ->execute([$body['key']??'',$body['value']??'',$body['value']??'']);
    echo json_encode(['success'=>true]);
    break;

case 'inv_get_settings':
    $stmt=$pdo->query("SELECT setting_key,setting_value FROM inv_settings"); $rows=$stmt->fetchAll();
    $out=[];foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value'];
    echo json_encode(['success'=>true,'settings'=>$out]);
    break;


// ============================================================
// SEND CONSENT REQUEST — technician clicks Attend
// Generates consent_token, sends email to customer
// ============================================================
case 'send_consent':
    $id = intval($body['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }

    // Ensure columns exist
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN consent_token VARCHAR(64) DEFAULT NULL")->execute(); } catch(Exception $e){}
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_at DATETIME DEFAULT NULL")->execute(); } catch(Exception $e){}
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_name VARCHAR(200) DEFAULT NULL")->execute(); } catch(Exception $e){}
    try { $pdo->prepare("ALTER TABLE tasks ADD COLUMN customer_consent_mobile VARCHAR(20) DEFAULT NULL")->execute(); } catch(Exception $e){}

    $taskStmt = $pdo->prepare("SELECT t.*, u.name as tech_name, u.email as tech_email FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
    $taskStmt->execute([$id]);
    $taskRow  = $taskStmt->fetch();
    if(!$taskRow){ echo json_encode(['error'=>'Task not found']); break; }

    // Generate fresh consent token
    $cToken = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE tasks SET consent_token=?, customer_consent_at=NULL WHERE id=?")->execute([$cToken, $id]);
    $taskRow['consent_token'] = $cToken;

    // Send email to customer
    $sent = false;
    try {
        require_once __DIR__.'/mailer.php';
        if(!empty($taskRow['email'])){
            sendConsentRequest($taskRow, $taskRow['tech_name'] ?? 'BharatGPS Team');
            $sent = true;
        }
    } catch(Exception $e){ error_log('Consent email: '.$e->getMessage()); }

    // Log in activity
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'system')")
        ->execute([$id, $userId, "📩 Consent request sent to customer" . ($sent ? " via email" : " (no email on file)")]);

    echo json_encode(['success'=>true, 'email_sent'=>$sent, 'has_email'=>!empty($taskRow['email'])]);
    break;

// ============================================================
// CHECK CONSENT — poll to see if customer has confirmed
// ============================================================
case 'check_consent':
    $id = intval($body['id'] ?? $_GET['id'] ?? 0);
    if(!$id){ echo json_encode(['error'=>'Task ID required']); break; }
    $cs = $pdo->prepare("SELECT customer_consent_at, customer_consent_name, customer_consent_mobile FROM tasks WHERE id=?");
    $cs->execute([$id]);
    $crow = $cs->fetch();
    if($crow && !empty($crow['customer_consent_at'])){
        echo json_encode([
            'consented'   => true,
            'consented_at'=> $crow['customer_consent_at'],
            'name'        => $crow['customer_consent_name'],
            'mobile'      => $crow['customer_consent_mobile'],
        ]);
    } else {
        echo json_encode(['consented'=>false]);
    }
    break;

// ---- MARK TASK VIEWED (clears unseen badge) ----

// ---- ADMIN WIPE ----
case 'admin_wipe':
    if($userRole !== 'admin'){ echo json_encode(['error'=>'Admin only']); break; }
    if(($body['confirm']??'') !== 'DELETE'){ echo json_encode(['error'=>'Confirmation required']); break; }
    $type = $body['type'] ?? '';
    try {
        if($type === 'tasks'){
            // Wipe in order (FK constraints)
            $pdo->exec("DELETE FROM task_device_installs");
            $pdo->exec("DELETE FROM task_activities");
            // consent_token etc stored on tasks table
            try { $pdo->exec("DELETE FROM consent_logs"); } catch(Exception $e){}
            $pdo->exec("DELETE FROM tasks");
            echo json_encode(['success'=>true,'message'=>'All tasks, activities and device installs deleted.']);
        } elseif($type === 'reset_ids'){
            // Reset auto increment — only if tasks table is empty
            $cnt = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
            if($cnt > 0){
                echo json_encode(['error'=>'Cannot reset IDs while tasks exist. Wipe tasks first.']);
            } else {
                $pdo->exec("ALTER TABLE tasks AUTO_INCREMENT = 1");
                echo json_encode(['success'=>true,'message'=>'Task counter reset. Next task will be ID-'.date('Y').'-0001.']);
            }
        } else {
            echo json_encode(['error'=>'Unknown wipe type']);
        }
    } catch(Exception $e){
        echo json_encode(['error'=>'DB error: '.$e->getMessage()]);
    }
    break;

// ---- ADMIN DB STATS ----
case 'admin_db_stats':
    if($userRole !== 'admin'){ echo json_encode(['error'=>'Admin only']); break; }
    $stats = [
        'tasks'          => $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
        'activities'     => $pdo->query("SELECT COUNT(*) FROM task_activities")->fetchColumn(),
        'device_installs'=> $pdo->query("SELECT COUNT(*) FROM task_device_installs")->fetchColumn(),
        'consents'       => $pdo->query("SELECT COUNT(*) FROM tasks WHERE customer_consent_at IS NOT NULL")->fetchColumn(),
        'users'          => $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
    ];
    echo json_encode(['stats'=>$stats]);
    break;

case 'mark_viewed':
    $id = intval($body['id'] ?? $_GET['id'] ?? 0);
    if($id){
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN admin_viewed_at DATETIME DEFAULT NULL"); } catch(Exception $e){}
        $pdo->prepare("UPDATE tasks SET admin_viewed_at=NOW() WHERE id=?")->execute([$id]);
    }
    echo json_encode(['success'=>true]);
    break;


// ---- SIGNAL CHECK (real-time polling) ----
case 'get_signal':
    // Returns the timestamp of the most recently updated task
    // Client compares with its last known value to detect changes
    try {
        $sig = $pdo->query("SELECT MAX(updated_at) as sig FROM tasks")->fetchColumn();
        echo json_encode(['signal' => $sig ?: '0']);
    } catch(Exception $e){
        echo json_encode(['signal' => '0']);
    }
    break;

// ---- VERIFY TOKEN ----
case 'verify_token':
    // Validate the auth token and return user info
    $tok = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if(!$tok){ echo json_encode(['valid'=>false,'error'=>'No token']); break; }
    $vs = $pdo->prepare("SELECT id,name,email,role,phone FROM users WHERE auth_token=? AND is_active=1");
    $vs->execute([$tok]);
    $vu = $vs->fetch();
    if($vu){
        $pdo->prepare("UPDATE users SET last_active=NOW() WHERE id=?")->execute([$vu['id']]);
        echo json_encode(['valid'=>true,'user'=>$vu]);
    } else {
        echo json_encode(['valid'=>false,'error'=>'Invalid token']);
    }
    break;

case 'logout':
    $tok = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if($tok) $pdo->prepare("UPDATE users SET auth_token=NULL WHERE auth_token=?")->execute([$tok]);
    echo json_encode(['success'=>true]);
    break;

default:
    http_response_code(404);
    echo json_encode(['error'=>'Unknown action: '.$action]);
    break;
}
