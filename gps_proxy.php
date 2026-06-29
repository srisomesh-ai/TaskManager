<?php
// ============================================================
// BharatGPS TaskManager — GPS Server Proxy
// Handles IMEI search and device update across all 4 servers
// Protected by X-Auth-Token (same as api/index.php)
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

require_once __DIR__ . '/api/db.php';

// Auth check
$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_GET['_tok'] ?? '';
$pdo   = getDB();
$user  = null;
if($token){
    $s = $pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");
    $s->execute([$token]); $user = $s->fetch();
}
if(!$user){ echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

// GPS Server credentials
$servers = [
    1 => ['id'=>1,'name'=>'Server 1 — bharatgps.com',    'base'=>'https://bharatgps.com/api',    'hash'=>'$2y$10$uzZ7lm.VASP20YWWb/NYVeopHdhaxZdOc213OktkPhUhImnySiir.'],
    2 => ['id'=>2,'name'=>'Server 2 — bharatgps.in',     'base'=>'https://bharatgps.in/api',     'hash'=>'$2y$10$OjQHmpMaK9V8X2.hX5lUcOs7Bzou3.Raa42wovvnN9i8m4ZebR71u'],
    3 => ['id'=>3,'name'=>'Server 3 — bharatgps.school', 'base'=>'https://bharatgps.school/api', 'hash'=>'$2y$10$oPTMk8NIUXu3Y10e4Fu80ulKKpwvT73l0Cu7L8lP9VPcogI40qlHi'],
    4 => ['id'=>4,'name'=>'Server 4 — bharatgps.org',    'base'=>'https://bharatgps.org/api',    'hash'=>'$2y$10$NtK3BHUxZbkU8WzdDBZz6.TUjNpx/064N6GASFmaZSMNQBI2DtqXG'],
];

function do_post($url, $fields){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query($fields));
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT,      'Mozilla/5.0');
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return ['json'=>json_decode($body,true), 'raw'=>$body, 'error'=>$error];
}

$action = $_GET['action'] ?? '';

// ── FIND — search all 4 servers by IMEI ──────────────────────────────────
if($action === 'find'){
    $keyword = trim($_GET['keyword'] ?? '');
    if(!$keyword){ echo json_encode(['success'=>false,'error'=>'Enter an IMEI number']); exit; }
    $q = strtolower($keyword);

    $multi = curl_multi_init();
    $handles = [];
    foreach($servers as $sid => $srv){
        $url = $srv['base'].'/get_devices?lang=en&user_api_hash='.rawurlencode($srv['hash']);
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT,      'Mozilla/5.0');
        curl_multi_add_handle($multi, $ch);
        $handles[$sid] = $ch;
    }
    $running = null;
    do { curl_multi_exec($multi, $running); curl_multi_select($multi); } while($running > 0);

    $found = [];
    foreach($handles as $sid => $ch){
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        if(!$body) continue;
        $data = json_decode($body, true);
        if(!is_array($data)) continue;
        foreach($data as $group){
            if(!isset($group['items'])) continue;
            foreach($group['items'] as $device){
                $dd   = $device['device_data'] ?? [];
                $imei = strtolower($dd['imei'] ?? '');
                if($imei && strpos($imei, $q) !== false){
                    $device['_server_id']   = $sid;
                    $device['_server_name'] = $servers[$sid]['name'];
                    $device['_group']       = $group['name'] ?? '';
                    $found[] = $device;
                }
            }
        }
    }
    curl_multi_close($multi);
    echo json_encode(['success'=>true, 'devices'=>$found, 'count'=>count($found)]);
    exit;
}

// ── UPDATE — push device data to GPS server ───────────────────────────────
if($action === 'update'){
    $server_id = intval($_POST['server_id'] ?? 0);
    $device_id = intval($_POST['device_id'] ?? 0);
    if(!$server_id || !$device_id){ echo json_encode(['success'=>false,'error'=>'Missing server_id or device_id']); exit; }
    if(!isset($servers[$server_id]))  { echo json_encode(['success'=>false,'error'=>'Invalid server']); exit; }

    $srv = $servers[$server_id];
    $fields = [
        'user_api_hash'       => $srv['hash'],
        'id'                  => $device_id,
        'lang'                => 'en',
        'name'                => $_POST['name']                ?? '',
        'plate_number'        => $_POST['plate_number']        ?? '',
        'registration_number' => $_POST['registration_number'] ?? '',
        'object_owner'        => $_POST['object_owner']        ?? '',
        'installation_date'   => $_POST['installation_date']   ?? '',
        'expiration_date'     => $_POST['expiration_date']     ?? '',
        'additional_notes'    => $_POST['additional_notes']    ?? '',
        'comment'             => $_POST['comment']             ?? '',
    ];
    if(!empty($_POST['device_model'])) $fields['device_model'] = $_POST['device_model'];

    $result = do_post($srv['base'].'/edit_device', $fields);
    $json   = $result['json'];

    if($result['error']){ echo json_encode(['success'=>false,'error'=>'Connection error: '.$result['error']]); exit; }

    $status = $json['status'] ?? $json['success'] ?? null;
    if($status == 1 || $status === true){
        echo json_encode(['success'=>true,'message'=>'Device updated on '.$srv['name']]);
    } else {
        $err = '';
        if(isset($json['errors']) && is_array($json['errors'])) $err = implode(', ', array_values($json['errors']));
        echo json_encode(['success'=>false,'error'=>$err ?: ('Server returned: '.($result['raw']??'unknown'))]);
    }
    exit;
}

// ── Helper: GET request ───────────────────────────────────────────────────
function do_get($url){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT,      'Mozilla/5.0');
    $body = curl_exec($ch); curl_close($ch);
    return ['json'=>json_decode($body,true), 'raw'=>$body];
}

function parse_gps_err($json, $body){
    if(!is_array($json)) return substr($body,0,200);
    if(isset($json['errors'])){
        $e=$json['errors'];
        if(is_array($e)){$p=[];foreach($e as $k=>$v)$p[]=$k.':'.(is_array($v)?implode(',',$v):$v);return implode('|',$p);}
        return strval($e);
    }
    if(isset($json['message'])) return $json['message'];
    return substr($body,0,200);
}

// ── FIND USER — check if email exists on a GPS server ────────────────────
if($action === 'find_user'){
    $server_id = intval($_GET['server_id'] ?? 0);
    $email     = trim($_GET['email'] ?? '');
    if(!$email)     { echo json_encode(['success'=>false,'error'=>'Email required']); exit; }
    if(!$server_id) { echo json_encode(['success'=>false,'error'=>'server_id required']); exit; }
    if(!isset($servers[$server_id])) { echo json_encode(['success'=>false,'error'=>'Invalid server']); exit; }

    $srv = $servers[$server_id];

    // Search via admin/clients
    $r = do_get($srv['base'].'/admin/clients?search_phrase='.rawurlencode($email).'&limit=10&lang=en&user_api_hash='.rawurlencode($srv['hash']));
    $clients = [];
    if(is_array($r['json'])){
        if(isset($r['json']['data']) && is_array($r['json']['data'])) $clients = $r['json']['data'];
        elseif(isset($r['json'][0])) $clients = array_values($r['json']);
    }
    $found = array_filter($clients, fn($c) => strcasecmp(trim($c['email']??''), $email) === 0);
    if(!empty($found)){
        $u = array_values($found)[0];
        echo json_encode(['success'=>true,'found'=>true,'user'=>['id'=>$u['id'],'email'=>$u['email'],'name'=>$u['name']??$email]]);
    } else {
        echo json_encode(['success'=>true,'found'=>false]);
    }
    exit;
}

// ── CREATE USER — create account on GPS server, linked to manager ─────────
if($action === 'create_user'){
    $server_id  = intval($_POST['server_id'] ?? 0);
    $email      = trim($_POST['email'] ?? '');
    $password   = trim($_POST['password'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $manager_id = intval($_POST['manager_id'] ?? 0);
    if(!$email)     { echo json_encode(['success'=>false,'error'=>'Email required']); exit; }
    if(!$password || strlen($password) < 6) { echo json_encode(['success'=>false,'error'=>'Password must be at least 6 characters']); exit; }
    if(!$server_id) { echo json_encode(['success'=>false,'error'=>'server_id required']); exit; }
    if(!isset($servers[$server_id])) { echo json_encode(['success'=>false,'error'=>'Invalid server']); exit; }

    $srv = $servers[$server_id];

    // Get valid map IDs from server
    $maps_r = do_get($srv['base'].'/edit_setup_data?lang=en&user_api_hash='.rawurlencode($srv['hash']));
    $valid_maps = [1,2,3,4]; // fallback
    if(is_array($maps_r['json'])){
        $am = $maps_r['json']['item']['available_maps'] ?? $maps_r['json']['available_maps'] ?? null;
        if(is_array($am)) $valid_maps = array_values(array_map('intval', $am));
    }

    $fields = [
        'email'                 => $email,
        'password'              => $password,
        'password_confirmation' => $password,
        'phone_number'          => $phone,
        'active'                => '1',
        'group_id'              => '2',
        'enable_devices_limit'  => '1',
        'devices_limit'         => '10',
        'password_generate'     => '0',
        'account_created'       => '1',
        'email_verification'    => '0',
    ];
    if($manager_id) $fields['manager_id'] = strval($manager_id);
    foreach($valid_maps as $i => $mid) $fields['available_maps['.$i.']'] = strval($mid);

    $result = do_post($srv['base'].'/admin/client?lang=en&user_api_hash='.rawurlencode($srv['hash']), $fields);
    $json   = $result['json'];
    if(($json['status'] ?? null) == 1){
        $uid = $json['item']['id'] ?? $json['id'] ?? null;
        echo json_encode(['success'=>true,'user_id'=>$uid,'message'=>'Account created on '.$srv['name']]);
    } else {
        echo json_encode(['success'=>false,'error'=>parse_gps_err($json,$result['raw'])]);
    }
    exit;
}

// ── ASSIGN DEVICE — link device to user account ───────────────────────────
if($action === 'assign_device'){
    $server_id = intval($_POST['server_id'] ?? 0);
    $device_id = intval($_POST['device_id'] ?? 0);
    $user_id   = intval($_POST['user_id']   ?? 0);
    if(!$server_id || !$device_id || !$user_id){
        echo json_encode(['success'=>false,'error'=>'server_id, device_id, user_id all required']); exit;
    }
    if(!isset($servers[$server_id])) { echo json_encode(['success'=>false,'error'=>'Invalid server']); exit; }

    $srv = $servers[$server_id];

    // POST /api/admin/device/{device_id}/user
    $result = do_post(
        $srv['base'].'/admin/device/'.$device_id.'/user?lang=en&user_api_hash='.rawurlencode($srv['hash']),
        ['user_id' => strval($user_id)]
    );
    $json = $result['json'];
    if(($json['status'] ?? null) == 1){
        echo json_encode(['success'=>true,'message'=>'Device assigned to user on '.$srv['name']]);
    } else {
        // Fallback: edit_device with user_id
        $r2 = do_post($srv['base'].'/edit_device?lang=en&user_api_hash='.rawurlencode($srv['hash']),
            ['id'=>strval($device_id),'user_id'=>strval($user_id)]);
        $j2 = $r2['json'];
        if(($j2['status'] ?? null) == 1){
            echo json_encode(['success'=>true,'message'=>'Device assigned via edit_device on '.$srv['name']]);
        } else {
            echo json_encode(['success'=>false,'error'=>parse_gps_err($j2,$r2['raw'])]);
        }
    }
    exit;
}


echo json_encode(['success'=>false,'error'=>'Unknown action']);
