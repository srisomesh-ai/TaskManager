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
// ── GET ICONS — fetch device icon list from a server ─────────────────────
if($action === 'get_icons'){
    $server_id = intval($_GET['server_id'] ?? 0);
    if(!$server_id || !isset($servers[$server_id])){ echo json_encode(['success'=>false,'error'=>'Invalid server']); exit; }
    $srv = $servers[$server_id];
    $base = str_replace('/api','',$srv['base']); // e.g. https://bharatgps.in
    // GPSWOX: /add_device_data returns { device_icons:[...], sensor_groups:[...] }
    // (this is the correct device-icons source, same as bharatgps.net adding page)
    $r = do_get($srv['base'].'/add_device_data?lang=en&user_api_hash='.rawurlencode($srv['hash']));
    $icons = [];
    $list = [];
    if(is_array($r['json'])){
        if(isset($r['json']['device_icons']) && is_array($r['json']['device_icons'])) $list = $r['json']['device_icons'];
        elseif(isset($r['json']['icons']) && is_array($r['json']['icons']))            $list = $r['json']['icons'];
        elseif(isset($r['json']['items']) && is_array($r['json']['items']))            $list = $r['json']['items'];
    }
    foreach($list as $ic){
        if(!is_array($ic)) continue;
        $id = $ic['id'] ?? null;
        if($id === null) continue;
        $p = $ic['path'] ?? '';
        $img = ($p && strpos($p,'http')===0) ? $p : ($base.'/'.ltrim($p,'/'));
        $icons[] = [
            'id'   => intval($id),
            'img'  => $img,
            'path' => $p,
            'type' => $ic['type'] ?? '',
            'w'    => intval($ic['width']  ?? 32),
            'h'    => intval($ic['height'] ?? 37),
        ];
    }
    $out = ['success'=>true,'icons'=>$icons];
    if(empty($icons)){ $out['debug_raw'] = substr($r['raw'] ?? '', 0, 400); }
    echo json_encode($out);
    exit;
}

if($action === 'find'){
    $keyword = trim($_GET['keyword'] ?? '');
    if(!$keyword){ echo json_encode(['success'=>false,'error'=>'Enter an IMEI number']); exit; }
    $q = strtolower($keyword);

    // Optional: restrict to a single server by host (e.g. server=bharatgps.in)
    $onlyHost = strtolower(trim($_GET['server'] ?? ''));
    $searchServers = $servers;
    if($onlyHost !== ''){
        $filtered = [];
        foreach($servers as $sid => $srv){
            if(strpos(strtolower($srv['base']), $onlyHost) !== false){ $filtered[$sid] = $srv; }
        }
        if(!empty($filtered)) $searchServers = $filtered;
    }

    $multi = curl_multi_init();
    $handles = [];
    foreach($searchServers as $sid => $srv){
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

// ── FIND BY PLATE — search all servers by plate/vehicle number, return status ──
if($action === 'find_by_plate'){
    $keyword = strtolower(trim($_GET['plate'] ?? $_GET['keyword'] ?? ''));
    if($keyword === ''){ echo json_encode(['success'=>false,'error'=>'Enter a vehicle number']); exit; }
    // normalize: strip spaces/hyphens for loose match
    $normKw = preg_replace('/[^a-z0-9]/', '', $keyword);

    $multi = curl_multi_init();
    $handles = [];
    foreach($servers as $sid => $srv){
        if($sid == 4) continue; // server 4 closed
        $url = $srv['base'].'/get_devices?lang=en&user_api_hash='.rawurlencode($srv['hash']);
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_multi_add_handle($multi, $ch);
        $handles[$sid] = $ch;
    }
    $running = null;
    do { curl_multi_exec($multi, $running); curl_multi_select($multi); } while($running > 0);

    $matches = [];
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
                $dd = $device['device_data'] ?? [];
                $plate = strtolower($dd['plate_number'] ?? '');
                $name  = strtolower($device['name'] ?? $dd['name'] ?? '');
                $normPlate = preg_replace('/[^a-z0-9]/', '', $plate);
                $normName  = preg_replace('/[^a-z0-9]/', '', $name);
                // match if keyword appears in plate or device name (normalized)
                if(($normPlate && strpos($normPlate, $normKw) !== false) ||
                   ($normName && strpos($normName, $normKw) !== false)){
                    // online status: GPSWOX uses device['online'] = online|ack|offline
                    $onlineRaw = strtolower($device['online'] ?? '');
                    $isOnline = in_array($onlineRaw, ['online','ack']);
                    // last update time
                    $lastTime = $device['time'] ?? ($dd['time'] ?? ($device['timestamp'] ?? ''));
                    $matches[] = [
                        'server_id'     => $sid,
                        'server_name'   => $servers[$sid]['name'],
                        'device_id'     => $device['id'] ?? ($dd['id'] ?? null),
                        'imei'          => $dd['imei'] ?? '',
                        'plate_number'  => $dd['plate_number'] ?? '',
                        'device_name'   => $device['name'] ?? ($dd['name'] ?? ''),
                        'online'        => $isOnline ? 'online' : 'offline',
                        'online_raw'    => $onlineRaw,
                        'last_time'     => $lastTime,
                    ];
                }
            }
        }
    }
    curl_multi_close($multi);
    echo json_encode(['success'=>true, 'matches'=>$matches, 'count'=>count($matches)]);
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
    if(!empty($_POST['icon_id']))      $fields['icon_id']      = intval($_POST['icon_id']);
    if(!empty($_POST['icon_colors']))  $fields['icon_colors']  = $_POST['icon_colors'];

    $result = do_post($srv['base'].'/edit_device', $fields);
    $json   = $result['json'];

    if($result['error']){ echo json_encode(['success'=>false,'error'=>'Connection error: '.$result['error']]); exit; }

    exit;
}

// ── ADD DEVICE — push a new device to a server (used by Re-Adding approval) ──
if($action === 'add_device'){
    $server_id = intval($_POST['server_id'] ?? 0);
    if(!$server_id){ echo json_encode(['success'=>false,'error'=>'Missing server_id']); exit; }
    if(!isset($servers[$server_id])){ echo json_encode(['success'=>false,'error'=>'Invalid server']); exit; }
    $srv = $servers[$server_id];

    $name = trim($_POST['name'] ?? '');
    $imei = trim($_POST['imei'] ?? '');
    if(!$name || !$imei){ echo json_encode(['success'=>false,'error'=>'Name and IMEI required']); exit; }

    $fields = [
        'user_api_hash'       => $srv['hash'],
        'lang'                => 'en',
        'name'                => $name,
        'imei'                => $imei,
        'vin'                 => trim($_POST['vin'] ?? ''),
        'sim_number'          => trim($_POST['sim'] ?? ''),
        'device_model'        => trim($_POST['model'] ?? ''),
        'fuel_measurement_id' => 1,
        'tail_length'         => 10,
        'min_moving_speed'    => 3,
    ];

    $result = do_post($srv['base'].'/add_device', $fields);
    $json   = $result['json'];
    if($result['error']){ echo json_encode(['success'=>false,'error'=>'Connection error: '.$result['error']]); exit; }

    $status = $json['status'] ?? $json['success'] ?? null;
    if($status == 1 || $status === true){
        $device_id = $json['id'] ?? ($json['device_id'] ?? ($json['data']['id'] ?? null));
        echo json_encode(['success'=>true,'device_id'=>$device_id,'server'=>$srv['name']]);
    } else {
        echo json_encode(['success'=>false,'error'=>parse_gps_err($json,$result['raw'])]);
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

    // Auto-fetch manager_id from support@bharatgps.com if not provided
    if(!$manager_id){
        $MANAGER_EMAIL = 'support@bharatgps.com';
        $search_r = do_get($srv['base'].'/admin/clients?search_phrase='.rawurlencode($MANAGER_EMAIL).'&limit=5&lang=en&user_api_hash='.rawurlencode($srv['hash']));
        $clients = [];
        if(is_array($search_r['json'])){
            if(isset($search_r['json']['data']) && is_array($search_r['json']['data'])) $clients = $search_r['json']['data'];
            elseif(isset($search_r['json'][0])) $clients = array_values($search_r['json']);
        }
        foreach($clients as $c){
            if(strcasecmp(trim($c['email']??''), $MANAGER_EMAIL) === 0){
                $manager_id = intval($c['id'] ?? 0);
                break;
            }
        }
    }

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
