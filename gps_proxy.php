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

echo json_encode(['success'=>false,'error'=>'Unknown action']);
