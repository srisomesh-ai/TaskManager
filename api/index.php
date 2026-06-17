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

// Auth
$skipAuth = ['login','ping'];
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
    touchUserActive($pdo, $userId);
    echo json_encode(['user'=>['id'=>$cu['id'],'name'=>$cu['name'],'role'=>$cu['role'],'email'=>$cu['email']]]);
    break;

// ---- GET SYNC ----
case 'get_sync':
    touchUserActive($pdo, $userId);
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
    if ($userRole === 'technician') {
        $s = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(task_status='Open') open,
            SUM(task_status='In Progress') in_progress,
            SUM(task_status='Task Pending') task_pending,
            SUM(task_status='Awaiting Approval') awaiting_approval,
            SUM(task_status='Closed') closed,
            SUM(task_status='Cancelled') cancelled,
            SUM(task_status='Demo Sent') demo_sent,
            SUM(device_qty) devices_installed
            FROM tasks WHERE assigned_to=?");
        $s->execute([$userId]);
        $r = $s->fetch();
    } else {
        $r = $pdo->query("SELECT
            COUNT(*) total,
            SUM(task_status='Open') open,
            SUM(task_status='In Progress') in_progress,
            SUM(task_status='Task Pending') task_pending,
            SUM(task_status='Awaiting Approval') awaiting_approval,
            SUM(task_status='Closed') closed,
            SUM(task_status='Cancelled') cancelled,
            SUM(task_status='Demo Sent') demo_sent,
            SUM(device_qty) devices_installed
            FROM tasks")->fetch();
    }
    echo json_encode(['stats'=>$r]);
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
        if (!empty($body['password'])) { $sets[]='password=?'; $vals[]=password_hash($body['password'],PASSWORD_DEFAULT); }
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
    if (!empty($_GET['search']))    {
        $q='%'.$_GET['search'].'%';
        $where[]="(t.customer_name LIKE ? OR t.contact_number LIKE ? OR t.task_id LIKE ? OR t.location LIKE ?)";
        $params[]=$q; $params[]=$q; $params[]=$q; $params[]=$q;
    }

    $limit = min(intval($_GET['limit'] ?? 500), 1000);
    $sql = "SELECT t.*,u.name as technician_name,u.phone as tech_phone,c.name as creator_name
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to=u.id
            LEFT JOIN users c ON t.created_by=c.id"
         . ($where ? " WHERE ".implode(" AND ",$where) : "")
         . " ORDER BY t.created_at DESC LIMIT $limit";
    $s = $pdo->prepare($sql); $s->execute($params);
    echo json_encode(['tasks'=>$s->fetchAll()]);
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
    $pdo->prepare("INSERT INTO tasks (task_id,customer_name,contact_number,email,location,lead_type,device_qty,price_to_collect,payment_mode,assigned_to,task_status,is_outstation,customer_requested_delay,is_urgent,general_notes,reminder_date,device_details,created_by,payment_reminder_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,'Open',?,?,?,?,?,?,?,?)")
        ->execute([
            $taskId,
            trim($body['customer_name']??''), trim($body['contact_number']??''),
            trim($body['email']??''), trim($body['location']??''),
            $body['lead_type']??'New Lead', intval($body['device_qty']??1),
            floatval($body['price_to_collect']??0), $body['payment_mode']??'',
            $at, intval($body['is_outstation']??0), intval($body['customer_requested_delay']??0),
            intval($body['is_urgent']??0), trim($body['general_notes']??''),
            $rd, trim($body['device_details']??''), $userId, $prd
        ]);
    $newId = $pdo->lastInsertId();
    if (!empty($body['remark'])) $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")->execute([$newId,$userId,$body['remark']]);
    if ($at) {
        $tn=$pdo->prepare("SELECT name FROM users WHERE id=?"); $tn->execute([$at]);
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'assignment')")->execute([$newId,$userId,"Task assigned to ".$tn->fetchColumn()]);
    }
    echo json_encode(['success'=>true,'task_id'=>$taskId,'id'=>$newId]);
    logSync($pdo,'task_created',$newId,$userId);
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
               'gps_serial_no','name_on_server','server_name','invoice_no','payment_received_on','payment_transaction_details','gst_amount','pending_reason','discount_reason','discount_incharge','profile'];
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
    if (!empty($body['remark'])) $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'remark')")->execute([$id,$userId,$body['remark']]);
    if (isset($body['task_status'])&&$body['task_status']!==$existing['task_status'])
        $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,"Status: {$existing['task_status']} → {$body['task_status']}"]);
    echo json_encode(['success'=>true]);
    logSync($pdo,'task_updated',$id,$userId);
    touchUserActive($pdo,$userId);
    // Auto star rating on close
    if (isset($body['task_status'])&&$body['task_status']==='Closed'&&$existing['task_status']!=='Closed') {
        $h=(time()-strtotime($existing['created_at']))/3600;
        $stars=$h<=12?5:($h<=24?4:($h<=48?3:($h<=72?2:1)));
        $pdo->prepare("UPDATE tasks SET star_rating=? WHERE id=? AND (star_rating IS NULL OR star_rating=0)")->execute([$stars,$id]);
        try {
            require_once __DIR__.'/mailer.php';
            $cr=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $cr->execute([$id]); $cd=$cr->fetch();
            if ($cd) sendTaskClosedCustomer($cd);
        } catch(Exception $e) {}
    }
    // Email on technician assignment
    if (isset($body['assigned_to'])&&$body['assigned_to']!=$existing['assigned_to']&&!empty($body['assigned_to'])) {
        try {
            require_once __DIR__.'/mailer.php';
            $rr=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $rr->execute([$id]); $rd=$rr->fetch();
            $nr=$pdo->prepare("SELECT name,email,phone FROM users WHERE id=?"); $nr->execute([intval($body['assigned_to'])]); $nt=$nr->fetch();
            if ($rd && $nt && $nt['email']) sendTaskCreatedTech($rd,$nt['email'],$nt['name']);
            if ($rd) sendTaskCreatedCustomer($rd,$nt['name']??'',$nt['phone']??'');
        } catch(Exception $e) {}
    }
    break;

// ---- DELETE TASK ----
case 'delete_task':
    if ($userRole!=='admin') { http_response_code(403); echo json_encode(['error'=>'Admins only']); break; }
    $id=intval($body['id']??$_GET['id']??0);
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
    logSync($pdo,'task_deleted',$id,$userId);
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
    logSync($pdo,'task_transferred',$id,$userId);
    break;

// ---- APPROVE TASK ----
case 'approve_task':
    if (!in_array($userRole,['admin','assigner'])) { http_response_code(403); echo json_encode(['error'=>'Not authorized']); break; }
    $id=intval($body['id']??0);
    $pdo->prepare("UPDATE tasks SET task_status='Closed',closed_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->prepare("INSERT INTO task_activities (task_id,user_id,remark,activity_type) VALUES (?,?,?,'status_change')")->execute([$id,$userId,'Task approved and closed by manager']);
    $h=$pdo->prepare("SELECT t.*,u.name as tech_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?"); $h->execute([$id]); $t=$h->fetch();
    if ($t) {
        $hrs=(time()-strtotime($t['created_at']))/3600;
        $stars=$hrs<=12?5:($hrs<=24?4:($hrs<=48?3:($hrs<=72?2:1)));
        $pdo->prepare("UPDATE tasks SET star_rating=? WHERE id=? AND (star_rating IS NULL OR star_rating=0)")->execute([$stars,$id]);
        // Auto-create balance sheet entry if not exists
        if (!$t['bs_entry_id']) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS balance_sheet_entries (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) DEFAULT 'sales', profile VARCHAR(10) DEFAULT 'BGPT', task_id VARCHAR(20) NULL, task_db_id INT NULL, date DATE NOT NULL, invoice_no VARCHAR(50), gps_serial_no VARCHAR(100), customer_type VARCHAR(50), name_on_server TEXT, server_name VARCHAR(50), device_model VARCHAR(100), service_type VARCHAR(100), license_plan VARCHAR(100), qty DECIMAL(10,2) DEFAULT 1, unit_price DECIMAL(10,2) DEFAULT 0, gst DECIMAL(10,2) DEFAULT 0, total_price DECIMAL(10,2) DEFAULT 0, payment_status VARCHAR(50), payment_received DECIMAL(10,2) DEFAULT 0, pending_payment DECIMAL(10,2) DEFAULT 0, payment_mode VARCHAR(50), payment_received_on DATE NULL, payment_transaction_details TEXT, pending_reason VARCHAR(100), discount_given DECIMAL(10,2) DEFAULT 0, discount_reason TEXT, discount_incharge VARCHAR(100), payment_reminder_date DATE NULL, technician_name VARCHAR(100), location VARCHAR(200), remarks TEXT, created_by_code VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $qty  = floatval($t['device_qty']??1);
                $total= floatval($t['price_to_collect']??0);
                $unit = $qty>0?$total/$qty:$total;
                $recv = floatval($t['amount_collected']??0);
                $pend = max(0,$total-$recv);
                $pdo->prepare("INSERT INTO balance_sheet_entries (type,profile,task_id,task_db_id,date,gps_serial_no,customer_type,name_on_server,server_name,device_model,qty,unit_price,gst,total_price,payment_status,payment_received,pending_payment,payment_mode,technician_name,location,remarks,created_by_code) VALUES ('sales','BGPT',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$t['task_id'],$id,date('Y-m-d'),$t['gps_serial_no']??null,$t['lead_type']??null,$t['name_on_server']??null,$t['server_name']??null,$t['device_details']??null,$qty,$unit,floatval($t['gst_amount']??0),$total,$t['payment_status']??'Pending',$recv,$pend,$t['payment_mode']??null,$t['tech_name']??null,$t['location']??null,$t['general_notes']??null,$cu['name']]);
                $bsId=$pdo->lastInsertId();
                $pdo->prepare("UPDATE tasks SET bs_entry_id=? WHERE id=?")->execute([$bsId,$id]);
            } catch(Exception $e) { error_log('BS entry error: '.$e->getMessage()); }
        }
    }
    echo json_encode(['success'=>true]);
    logSync($pdo,'task_closed',$id,$userId);
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
    if ($userRole === 'technician') {
        $s=$pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.assigned_to=? AND t.task_status IN ('Open','In Progress','Task Pending') AND t.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY t.created_at ASC");
        $s->execute([$userId]);
    } else {
        $s=$pdo->query("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.task_status IN ('Open','In Progress','Task Pending') AND t.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY t.created_at ASC");
    }
    echo json_encode(['tasks'=>$s->fetchAll()]);
    break;

// ---- GET APPROVALS ----
case 'get_approvals':
    if ($userRole === 'technician') {
        $s=$pdo->prepare("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.assigned_to=? AND t.task_status='Awaiting Approval' ORDER BY t.updated_at DESC");
        $s->execute([$userId]);
    } else {
        $s=$pdo->query("SELECT t.*,u.name as technician_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.task_status='Awaiting Approval' ORDER BY t.updated_at DESC");
    }
    echo json_encode(['tasks'=>$s->fetchAll()]);
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

default:
    http_response_code(404);
    echo json_encode(['error'=>'Unknown action: '.$action]);
    break;
}
