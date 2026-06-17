<?php
session_start();
$token  = urldecode($_GET['token'] ?? '');
$taskId = intval($_GET['id'] ?? 0);
if(!$token || !$taskId){ header('Location: index.html'); exit; }

// Direct DB query — bypass JS/API entirely for initial load
require_once 'api/db.php';
$pdo = getDB();

// Validate token
$uq = $pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");
$uq->execute([$token]);
$user = $uq->fetch();
if(!$user){ header('Location: index.html'); exit; }

// Get task
$tq = $pdo->prepare("SELECT t.*, u.name as technician_name, u.phone as tech_phone, c.name as creator_name FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN users c ON t.created_by=c.id WHERE t.id=?");
$tq->execute([$taskId]);
$task = $tq->fetch();
if(!$task){ header('Location: index.html?err=notfound'); exit; }

// Get activities
$aq = $pdo->prepare("SELECT a.*, u.name as user_name FROM task_activities a LEFT JOIN users u ON a.user_id=u.id WHERE a.task_id=? ORDER BY a.created_at ASC");
$aq->execute([$taskId]);
$task['activities'] = $aq->fetchAll();

// Get payments
$pq = $pdo->prepare("SELECT * FROM payments WHERE task_id=?");
$pq->execute([$taskId]);
$task['payments'] = $pq->fetchAll();

// Encode for JS
$taskJson = json_encode($task);
$userJson = json_encode(['id'=>$user['id'],'name'=>$user['name'],'role'=>$user['role']]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Task – <?= htmlspecialchars($task['task_id']) ?> | Bharat GPS</title>
<style>
:root{
  --bg:#f0f2f5;--sur:#fff;--sur2:#f7f8fa;--sur3:#eef0f4;
  --bdr:#d0d5dd;--acc:#1a3a6b;--acc2:#2451a3;--accl:#e8eef8;
  --grn:#1a7a3a;--grnb:#e8f5ec;--red:#c0392b;--redb:#fdecea;
  --blu:#1a56a0;--blub:#e8f0fb;--saf:#e07b00;--safb:#fff3e0;
  --pur:#5b2d8e;--purb:#f0eaf8;--org:#d4680a;--org-b:#fff0e0;
  --tx:#1a1f2e;--tx2:#4a5568;--tx3:#8a9ab0;
  --r:10px;--rs:7px;--sh:0 2px 8px rgba(0,0,0,.08);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Calibri','Segoe UI',sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;padding-bottom:24px}
.tb{background:var(--acc);height:52px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.tb-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:6px 11px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none}
.tb-title{color:#fff;font-size:14px;font-weight:800}
.tb-sub{color:rgba(255,255,255,.6);font-size:10px}
.wf{background:var(--sur);border-bottom:2px solid var(--bdr);padding:10px 14px;overflow-x:auto;display:flex;align-items:center}
.wf-step{display:flex;align-items:center;flex-shrink:0}
.wf-dot{width:24px;height:24px;border-radius:50%;border:2px solid var(--bdr);background:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:var(--tx3)}
.wf-dot.dn{background:var(--grn);border-color:var(--grn);color:#fff}
.wf-dot.ac{background:var(--acc);border-color:var(--acc);color:#fff}
.wf-lbl{font-size:10px;font-weight:700;color:var(--tx3);margin-left:4px;white-space:nowrap}
.wf-lbl.dn{color:var(--grn)}.wf-lbl.ac{color:var(--acc)}
.wf-line{width:14px;height:2px;background:var(--bdr);margin:0 3px}
.wf-line.dn{background:var(--grn)}
.tabs{background:var(--sur);border-bottom:2px solid var(--bdr);display:flex;overflow-x:auto;position:sticky;top:52px;z-index:99}
.tab{padding:10px 14px;font-size:13px;font-weight:700;cursor:pointer;color:var(--tx3);border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap}
.tab.on{color:var(--acc);border-bottom-color:var(--acc)}
.tab-n{display:inline-block;background:var(--acc);color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:3px;vertical-align:middle}
.pnl{display:none;padding:12px;max-width:640px;margin:0 auto}
.pnl.on{display:block}
.card{background:var(--sur);border:1.5px solid var(--bdr);border-radius:var(--r);margin-bottom:11px;overflow:hidden;box-shadow:var(--sh)}
.ch{padding:11px 13px;display:flex;align-items:center;gap:9px;background:var(--sur2);border-bottom:1px solid var(--bdr)}
.ci{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.ch h3{font-size:13px;font-weight:800;color:var(--acc)}.ch p{font-size:11px;color:var(--tx3)}
.cb{padding:12px 13px}
.ir{display:flex;align-items:flex-start;padding:7px 0;border-bottom:1px solid var(--sur3)}
.ir:last-child{border-bottom:none}
.il{font-size:11px;font-weight:700;color:var(--tx3);text-transform:uppercase;width:100px;flex-shrink:0;padding-top:1px}
.iv{font-size:14px;font-weight:600;flex:1}
.iv.big{font-size:17px;font-weight:800}.iv.grn{color:var(--grn)}.iv.blu{color:var(--blu)}
.mgr-note{background:var(--safb);border-left:4px solid var(--saf);border-radius:0 var(--rs) var(--rs) 0;padding:11px 13px;font-size:13px;color:var(--org);font-weight:600;line-height:1.5}
.af{max-height:300px;overflow-y:auto}
.ai{display:flex;gap:9px;padding:9px 0;border-bottom:1px solid var(--sur3)}
.ai:last-child{border-bottom:none}
.av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0}
.avm{background:var(--acc)}.avt{background:var(--grn)}.avs{background:var(--tx3)}
.atb{display:inline-block;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-bottom:2px}
.atr{background:var(--purb);color:var(--pur)}.ati{background:var(--grnb);color:var(--grn)}
.atp{background:var(--safb);color:var(--saf)}.ats{background:var(--sur3);color:var(--tx3)}.ata{background:var(--blub);color:var(--blu)}
.f{margin-bottom:12px}.f:last-child{margin-bottom:0}
.f label{display:block;font-size:11px;font-weight:800;color:var(--tx2);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.f input,.f select,.f textarea{width:100%;padding:10px 12px;background:var(--sur2);border:1.5px solid var(--bdr);border-radius:var(--rs);color:var(--tx);font-family:inherit;font-size:14px;outline:none}
.f input:focus,.f select:focus,.f textarea:focus{border-color:var(--acc2);box-shadow:0 0 0 3px rgba(36,81,163,.1)}
.f textarea{resize:vertical;min-height:80px}
.ua{border:2px dashed var(--bdr);border-radius:var(--rs);padding:12px 8px;text-align:center;cursor:pointer;background:var(--sur2)}
.ua.ok{border-color:var(--grn);background:var(--grnb)}
.ua-icon{font-size:18px;margin-bottom:3px}.ua-lbl{font-size:10px;color:var(--tx3);font-weight:600}
.vb{background:var(--sur2);border:1.5px solid var(--bdr);border-radius:var(--rs);padding:11px;margin-bottom:10px}
.vbt{font-size:12px;font-weight:800;color:var(--acc2);margin-bottom:8px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.btn{padding:11px 16px;border-radius:var(--rs);font-family:inherit;font-weight:800;font-size:14px;cursor:pointer;border:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.btnw{width:100%}.btnp{background:var(--acc);color:#fff}.btns{background:var(--grn);color:#fff}
.btno{background:var(--sur2);color:var(--tx);border:1.5px solid var(--bdr)}
.btnl{background:transparent;color:var(--acc2);border:1.5px solid var(--acc2)}.btnsm{padding:7px 12px;font-size:12px}
.abtn{width:100%;padding:14px;border-radius:var(--r);font-family:inherit;font-weight:800;font-size:14px;cursor:pointer;border:none;display:flex;align-items:center;justify-content:center;gap:7px;margin-top:4px}
.abtn-i{background:linear-gradient(135deg,#1a3a6b,#2451a3);color:#fff;box-shadow:0 4px 14px rgba(26,58,107,.3)}
.abtn-p{background:linear-gradient(135deg,#1a7a3a,#22c55e);color:#fff;box-shadow:0 4px 14px rgba(26,122,58,.3)}
.abtn-a{background:linear-gradient(135deg,#5b2d8e,#8b5cf6);color:#fff;box-shadow:0 4px 14px rgba(91,45,142,.3)}
.pe{background:var(--grnb);border:1.5px solid rgba(26,122,58,.25);border-radius:var(--rs);padding:10px 12px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:flex-start}
.pea{font-size:16px;font-weight:800;color:var(--grn)}.pem{font-size:11px;color:var(--tx3)}
.badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700}
.b-o{background:var(--blub);color:var(--blu)}.b-p{background:var(--purb);color:var(--pur)}
.b-t{background:var(--safb);color:var(--saf)}.b-a{background:var(--grnb);color:var(--grn)}.b-c{background:var(--grnb);color:var(--grn)}
.sfbox{background:var(--redb);border:1.5px solid rgba(192,57,43,.3);border-radius:var(--rs);padding:11px 13px;margin-bottom:12px}
.lockbox{background:var(--grnb);border:2px solid var(--grn);border-radius:var(--r);padding:20px;text-align:center}
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1a1f2e;color:#fff;padding:10px 18px;border-radius:30px;font-size:13px;font-weight:700;z-index:9999;animation:ti .2s ease;white-space:nowrap;box-shadow:0 6px 20px rgba(0,0,0,.3)}
@keyframes ti{from{opacity:0;transform:translateX(-50%) translateY(8px)}}
</style>
</head>
<body>

<div class="tb">
  <div style="display:flex;align-items:center;gap:10px">
    <a class="tb-back" href="index.html">← Tasks</a>
    <div>
      <div class="tb-title"><?= htmlspecialchars($task['task_id']) ?></div>
      <div class="tb-sub"><?= htmlspecialchars($task['customer_name']) ?> · <?= htmlspecialchars($task['location']??'') ?></div>
    </div>
  </div>
  <span class="badge" id="tb-status"><?= htmlspecialchars($task['task_status']) ?></span>
</div>

<div class="wf" id="wf-bar">
  <div class="wf-step"><div class="wf-dot dn">✓</div><div class="wf-lbl dn">Assigned</div><div class="wf-line dn"></div></div>
  <div class="wf-step"><div class="wf-dot" id="w2">2</div><div class="wf-lbl" id="w2l">Updates</div><div class="wf-line" id="w2n"></div></div>
  <div class="wf-step"><div class="wf-dot" id="w3">3</div><div class="wf-lbl" id="w3l">Install</div><div class="wf-line" id="w3n"></div></div>
  <div class="wf-step"><div class="wf-dot" id="w4">4</div><div class="wf-lbl" id="w4l">Payment</div><div class="wf-line" id="w4n"></div></div>
  <div class="wf-step"><div class="wf-dot" id="w5">5</div><div class="wf-lbl" id="w5l">Approval</div></div>
</div>

<div class="tabs">
  <div class="tab on" id="tab-info"    onclick="show('info')">📋 Task Info</div>
  <div class="tab"    id="tab-updates" onclick="show('updates')">💬 Updates <span class="tab-n" id="act-cnt">0</span></div>
  <div class="tab"    id="tab-install" onclick="show('install')">🔧 Installation</div>
  <div class="tab"    id="tab-payment" onclick="show('payment')">💳 Payment</div>
</div>

<div class="pnl on" id="pnl-info"></div>
<div class="pnl"    id="pnl-updates"></div>
<div class="pnl"    id="pnl-install"></div>
<div class="pnl"    id="pnl-payment"></div>

<script>
// Data injected by PHP — no API call needed for initial load
var T   = <?= $taskJson ?>;
var ME  = <?= $userJson ?>;
var TOK = '<?= htmlspecialchars($token, ENT_QUOTES) ?>';
var TID = <?= (int)$taskId ?>;
var API = 'api/index.php'; // relative — same server

var VTYPES = ['Truck','Car','Auto','Bike','Bus','JCB','Tractor','Van','Tempo','Other'];
var JOBS = {
  'Basic/Normal'            :{rc:true, imei:true,  ex:[]},
  'Engine Status'           :{rc:true, imei:true,  ex:[]},
  'Engine Cut'              :{rc:true, imei:true,  ex:['relay']},
  'Micro GPS'               :{rc:true, imei:true,  ex:[]},
  'Magnet GPS'              :{rc:false,imei:true,  ex:[]},
  'MIC/SOS GPS'             :{rc:true, imei:true,  ex:[]},
  'VLTD'                    :{rc:true, imei:true,  ex:['cert']},
  'Troubleshoot/Offline'    :{rc:false,imei:false, ex:['issue','action']},
  'Vehicle to Vehicle Change':{rc:true,imei:true,  ex:['old_imei']},
  'Re-Adding'               :{rc:false,imei:true,  ex:['old_imei']},
  'Only Remove'             :{rc:false,imei:false, ex:['reason']},
  'Demonstration'           :{rc:false,imei:true,  ex:['demo_fb']},
};

var unlocked = false, instDone = false, isLocked = false;
var uploads = {};

function apiPost(action, body) {
  return fetch(API + '?action=' + action, {
    method: 'POST',
    headers: {'X-Auth-Token': TOK, 'Content-Type': 'application/json'},
    body: JSON.stringify(body)
  }).then(function(r){ return r.json(); });
}
function apiUpload(taskId, docType, file) {
  var fd = new FormData();
  fd.append('action','upload_document');
  fd.append('task_id', taskId);
  fd.append('doc_type', docType);
  fd.append('file', file);
  return fetch(API, {method:'POST', headers:{'X-Auth-Token':TOK}, body:fd}).then(function(r){ return r.json(); });
}
function apiFetch(action, params) {
  var url = API + '?action=' + action;
  if(params) url += '&' + new URLSearchParams(params);
  return fetch(url, {headers:{'X-Auth-Token':TOK}}).then(function(r){ return r.json(); });
}

function show(name) {
  document.querySelectorAll('.pnl').forEach(function(p){ p.classList.remove('on'); });
  document.querySelectorAll('.tab').forEach(function(t){ t.classList.remove('on'); });
  document.getElementById('pnl-'+name).classList.add('on');
  document.getElementById('tab-'+name).classList.add('on');
  window.scrollTo(0,60);
}
function toast(m) {
  var t=document.createElement('div'); t.className='toast'; t.textContent=m;
  document.body.appendChild(t); setTimeout(function(){ t.remove(); }, 2500);
}

function setState() {
  var acts = T.activities||[];
  var hasInstall = acts.some(function(a){
    return (a.remark||'').toLowerCase().indexOf('started installation')>=0
        || (a.remark||'').toLowerCase().indexOf('installation completed')>=0
        || (a.remark||'').toLowerCase().indexOf('attending')>=0;
  });
  unlocked = ['In Progress','Task Pending','Awaiting Approval','Closed'].indexOf(T.task_status)>=0 || hasInstall;
  instDone = ['Task Pending','Awaiting Approval','Closed'].indexOf(T.task_status)>=0;
  isLocked = ['Awaiting Approval','Closed','Cancelled'].indexOf(T.task_status)>=0;
}

function setWF(s) {
  var sm={'Open':2,'In Progress':2,'Task Pending':3,'Awaiting Approval':4,'Closed':5};
  var cur=sm[s]||2;
  for(var i=2;i<=5;i++){
    var d=document.getElementById('w'+i), l=document.getElementById('w'+i+'l'), n=document.getElementById('w'+i+'n');
    if(!d) continue;
    if(i<cur){d.className='wf-dot dn';d.textContent='✓';if(l)l.className='wf-lbl dn';}
    else if(i===cur){d.className='wf-dot ac';d.textContent=i;if(l)l.className='wf-lbl ac';}
    else{d.className='wf-dot';d.textContent=i;if(l)l.className='wf-lbl';}
    if(n) n.className=i<cur?'wf-line dn':'wf-line';
  }
}

function setStatusBadge(s) {
  var b=document.getElementById('tb-status');
  var m={'Open':'b-o','In Progress':'b-p','Task Pending':'b-t','Awaiting Approval':'b-a','Closed':'b-c'};
  b.className='badge '+(m[s]||'b-o'); b.textContent=s;
}

function reloadTask() {
  apiFetch('get_task',{id:TID}).then(function(res){
    if(res&&res.task){ T=res.task; setState(); setWF(T.task_status); setStatusBadge(T.task_status); renderAll(); }
  }).catch(function(){});
}

// ---- RENDER ALL ----
function renderAll() {
  var locked=['Awaiting Approval','Closed','Cancelled'].indexOf(T.task_status)>=0;
  isLocked=locked;
  renderInfo();
  renderUpdates();
  renderInstall();
  renderPayment();
}

// ---- INFO ----
function renderInfo() {
  var t=T;
  var h='<div style="background:var(--sur3);border-radius:var(--rs);padding:8px 12px;font-size:12px;color:var(--tx3);font-weight:700;margin-bottom:10px">🔒 Read only — set by your manager</div>';
  if(isLocked) h='<div style="background:var(--grnb);border:2px solid var(--grn);border-radius:var(--rs);padding:10px 13px;display:flex;align-items:center;gap:9px;margin-bottom:10px"><span style="font-size:18px">🔒</span><div><div style="font-size:13px;font-weight:800;color:var(--grn)">Task Submitted — View Only</div><div style="font-size:11px;color:var(--tx3)">Awaiting manager approval.</div></div></div>';

  h+='<div class="card"><div class="ch"><div class="ci" style="background:var(--accl)">👤</div><div><h3>Customer</h3><p>Call before visiting</p></div></div><div class="cb">'
    +'<div class="ir"><div class="il">Name</div><div class="iv">'+esc(t.customer_name)+'</div></div>'
    +'<div class="ir"><div class="il">Phone</div><div class="iv blu"><a href="tel:'+esc(t.contact_number)+'" style="color:var(--blu);text-decoration:none">📞 '+esc(t.contact_number)+'</a></div></div>'
    +(t.email?'<div class="ir"><div class="il">Email</div><div class="iv">'+esc(t.email)+'</div></div>':'')
    +'<div class="ir"><div class="il">Location</div><div class="iv">📍 '+esc(t.location||'–')+'</div></div>'
    +'</div></div>';

  h+='<div class="card"><div class="ch"><div class="ci" style="background:var(--blub)">📋</div><div><h3>Job Details</h3></div></div><div class="cb">'
    +'<div class="ir"><div class="il">Job Type</div><div class="iv"><span style="background:var(--accl);color:var(--acc2);padding:2px 9px;border-radius:4px;font-weight:700">'+esc(t.device_details||'Not set')+'</span></div></div>'
    +'<div class="ir"><div class="il">Devices</div><div class="iv big blu">'+esc(String(t.device_qty))+'</div></div>'
    +'<div class="ir"><div class="il">Price</div><div class="iv big grn">₹'+parseFloat(t.price_to_collect||0).toLocaleString('en-IN')+'</div></div>'
    +'<div class="ir"><div class="il">Pay Mode</div><div class="iv">'+esc(t.payment_mode||'Not set')+'</div></div>'
    +(t.is_outstation?'<div class="ir"><div class="il">Outstation</div><div class="iv" style="color:var(--saf);font-weight:700">✅ Yes</div></div>':'')
    +'</div></div>';

  if(t.general_notes) h+='<div class="card"><div class="ch"><div class="ci" style="background:var(--safb)">📣</div><div><h3>Manager Instructions</h3></div></div><div class="cb"><div class="mgr-note">'+esc(t.general_notes)+'</div></div></div>';

  if(!isLocked) h+='<button class="btn btnl btnw" onclick="show(\'updates\')" style="margin-top:4px">💬 Go to Updates →</button>';

  document.getElementById('pnl-info').innerHTML=h;
}

// ---- UPDATES ----
function renderUpdates() {
  var acts=T.activities||[];
  document.getElementById('act-cnt').textContent=acts.length;

  var aH=acts.length?acts.map(function(a){
    var tc=a.activity_type||'remark';
    var cls={remark:'atr',status_change:'ata',assignment:'ata',payment_update:'atp',document_upload:'ati',system:'ats'}[tc]||'atr';
    var isMine=ME&&a.user_id==ME.id;
    return '<div class="ai"><div class="av '+(isMine?'avt':'avm')+'">'+(a.user_name||'?')[0].toUpperCase()+'</div>'
      +'<div style="flex:1"><span class="atb '+cls+'">'+tc+'</span>'
      +'<div style="font-size:13px;font-weight:600">'+esc(a.user_name||'System')+' — '+esc(a.remark||'')+'</div>'
      +'<div style="font-size:10px;color:var(--tx3);margin-top:2px">'+esc(a.created_at||'')+'</div></div></div>';
  }).join(''):'<div style="padding:14px;color:var(--tx3);font-size:13px">No activity yet</div>';

  var h='<div class="card"><div class="ch"><div class="ci" style="background:var(--grnb)">📜</div><div><h3>Activity Log</h3><p>'+acts.length+' entries</p></div></div><div class="cb af">'+aH+'</div></div>';

  if(isLocked){
    h+='<div class="lockbox"><div style="font-size:28px;margin-bottom:8px">🔒</div><div style="font-size:15px;font-weight:800;color:var(--grn)">Task Submitted</div><div style="font-size:12px;color:var(--tx3);margin-top:6px">View only — no edits allowed.</div></div>';
    document.getElementById('pnl-updates').innerHTML=h; return;
  }

  // TWO ACTION BUTTONS
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">'
    +'<div style="background:var(--sur);border:2px solid var(--acc);border-radius:var(--r);padding:16px;text-align:center;cursor:pointer" onclick="toggleUpdateForm()">'
    +'<div style="font-size:28px;margin-bottom:6px">📞</div>'
    +'<div style="font-size:14px;font-weight:800;color:var(--acc)">Update</div>'
    +'<div style="font-size:11px;color:var(--tx3);margin-top:3px">Call log & remarks</div></div>'
    +'<div style="background:var(--acc);border-radius:var(--r);padding:16px;text-align:center;cursor:pointer" onclick="startAttend()">'
    +'<div style="font-size:28px;margin-bottom:6px">🔧</div>'
    +'<div style="font-size:14px;font-weight:800;color:#fff">Attend</div>'
    +'<div style="font-size:11px;color:rgba(255,255,255,.7);margin-top:3px">'+esc(T.device_details||'Go to task')+'</div></div>'
    +'</div>';

  // UPDATE FORM
  h+='<div id="update-form" style="display:none;background:var(--blub);border:1.5px solid rgba(26,86,160,.2);border-radius:var(--r);padding:14px;margin-bottom:12px">'
    +'<div style="font-size:13px;font-weight:800;color:var(--blu);margin-bottom:12px">📞 Call / Visit Update</div>'
    +'<div class="f"><label>What was discussed? *</label><textarea id="rem-discussed" rows="3" placeholder="e.g. Called customer — will visit at 3 PM..."></textarea></div>'
    +'<div class="f"><label>Customer Response</label><select id="rem-resp"><option value="">Select</option><option>Available — confirmed time</option><option>Not available — rescheduled</option><option>Not answering call</option><option>Asked to call back later</option><option>Vehicle not ready</option><option>Payment issue</option><option>Other</option></select></div>'
    +'<div class="f"><label>What did you tell the customer?</label><textarea id="rem-told" rows="2" placeholder="e.g. Will visit by 3 PM today..."></textarea></div>'
    +'<div class="g2" style="margin-bottom:12px">'
    +'<div class="f" style="margin:0"><label>Remind in</label><select id="rem-when"><option value="">No reminder</option><option value="1">1 hour</option><option value="2">2 hours</option><option value="4">4 hours</option><option value="24">Tomorrow</option><option value="48">In 2 days</option></select></div>'
    +'<div class="f" style="margin:0"><label>Next Step</label><select id="rem-next"><option value="">Not decided</option><option>Will visit today</option><option>Will visit tomorrow</option><option>Waiting for customer</option><option>Ready for installation</option></select></div></div>'
    +'<div style="display:flex;gap:8px">'
    +'<button class="btn btno btnsm" onclick="toggleUpdateForm()">Cancel</button>'
    +'<button class="btn btnp" style="flex:1" onclick="submitUpdate()">💬 Submit Update</button>'
    +'</div></div>';

  // TRANSFER
  h+='<button class="btn btno btnw" onclick="toggleTransfer()" style="border-color:var(--org);color:var(--org)">🔀 Transfer Task</button>'
    +'<div id="transfer-box" style="display:none;margin-top:10px;background:var(--org-b);border:1.5px solid rgba(212,104,10,.3);border-radius:var(--rs);padding:12px">'
    +'<div style="font-size:13px;font-weight:800;color:var(--org);margin-bottom:10px">🔀 Transfer Task</div>'
    +'<div class="f"><label>Transfer To *</label><select id="transfer-to"><option value="">Select technician</option></select></div>'
    +'<div class="f"><label>Reason</label><input type="text" id="transfer-note" placeholder="Why are you transferring?"></div>'
    +'<div style="display:flex;gap:8px;margin-top:4px">'
    +'<button class="btn btno btnsm" onclick="toggleTransfer()">Cancel</button>'
    +'<button class="btn btnsm" style="background:var(--org);color:#fff;flex:1" onclick="submitTransfer()">🔀 Confirm</button>'
    +'</div></div>';

  document.getElementById('pnl-updates').innerHTML=h;
}

function toggleUpdateForm(){
  var f=document.getElementById('update-form');
  if(f) f.style.display=f.style.display==='none'?'block':'none';
}
function toggleTransfer(){
  var b=document.getElementById('transfer-box');
  if(!b) return;
  if(b.style.display==='none'){
    b.style.display='block';
    apiFetch('get_users',{role:'technician'}).then(function(r){
      var sel=document.getElementById('transfer-to');
      if(!sel) return;
      var techs=(r.users||[]).filter(function(u){ return u.id!=ME.id; });
      sel.innerHTML='<option value="">Select technician</option>'+techs.map(function(u){ return '<option value="'+u.id+'">'+esc(u.name)+'</option>'; }).join('');
    }).catch(function(){});
  } else b.style.display='none';
}

function submitUpdate(){
  var discussed=document.getElementById('rem-discussed')?document.getElementById('rem-discussed').value.trim():'';
  if(!discussed){toast('❌ Please describe what was discussed');return;}
  var resp=document.getElementById('rem-resp')?document.getElementById('rem-resp').value:'';
  var told=document.getElementById('rem-told')?document.getElementById('rem-told').value.trim():'';
  var when=document.getElementById('rem-when')?document.getElementById('rem-when').value:'';
  var next=document.getElementById('rem-next')?document.getElementById('rem-next').value:'';
  var parts=['📞 '+discussed];
  if(resp) parts.push('Customer: '+resp);
  if(told) parts.push('Told: '+told);
  if(next) parts.push('Next: '+next);
  if(when) parts.push('Follow-up in '+when+'h');
  var body={id:TID,remark:parts.join(' | ')};
  if(when){var d=new Date();d.setHours(d.getHours()+parseInt(when));body.reminder_date=d.toISOString().split('T')[0];}
  apiPost('update_task',body).then(function(r){
    if(r.success){toast('✅ Update saved!');setTimeout(function(){window.location.href='index.html';},1500);}
    else toast('❌ '+(r.error||'Error'));
  }).catch(function(e){toast('❌ '+e.message);});
}

function startAttend(){
  apiPost('update_task',{id:TID,remark:'Technician attending — at customer location'}).then(function(r){
    if(r.success){unlocked=true;toast('🔧 Starting...');reloadTask().then?reloadTask().then(function(){show('install');}):setTimeout(function(){reloadTask();show('install');},500);}
  }).catch(function(e){toast('❌ '+e.message);});
}

function submitTransfer(){
  var toId=document.getElementById('transfer-to')?document.getElementById('transfer-to').value:'';
  var note=document.getElementById('transfer-note')?document.getElementById('transfer-note').value.trim():'';
  if(!toId){toast('❌ Select a technician');return;}
  apiPost('transfer_task',{task_id:TID,to_user_id:parseInt(toId),note:note}).then(function(r){
    if(r.success){toast('🔀 Transferred!');setTimeout(function(){window.location.href='index.html';},1500);}
    else toast('❌ '+(r.error||'Error'));
  }).catch(function(e){toast('❌ '+e.message);});
}

// ---- INSTALL ----
function renderInstall(){
  var cfg=JOBS[T.device_details]||{rc:true,imei:true,ex:[]};
  var qty=parseInt(T.device_qty)||1;

  if(isLocked){
    document.getElementById('pnl-install').innerHTML='<div class="lockbox" style="margin:12px"><div style="font-size:28px;margin-bottom:8px">🔒</div><div style="font-size:15px;font-weight:800;color:var(--grn)">Locked</div><div style="font-size:12px;color:var(--tx3);margin-top:4px">Task submitted — installation frozen.</div></div>';
    return;
  }
  if(!unlocked){
    document.getElementById('pnl-install').innerHTML='<div style="text-align:center;padding:40px 16px;color:var(--tx3)"><div style="font-size:40px;margin-bottom:12px">🔒</div><div style="font-size:15px;font-weight:800;color:var(--tx2);margin-bottom:8px">Not started yet</div><div style="font-size:13px;margin-bottom:18px">Go to Updates and click Attend when at customer location.</div><button class="btn btnl" onclick="show(\'updates\')">← Go to Updates</button></div>';
    return;
  }

  var dis=instDone?' disabled':'';
  var h='<div class="card"><div class="ch"><div class="ci" style="background:var(--blub)">🔧</div><div><h3>'+esc(T.device_details||'Installation')+'</h3><p>'+qty+' vehicle'+(qty>1?'s':'')+'</p></div></div><div class="cb">';

  for(var i=1;i<=qty;i++){
    h+='<div class="vb"><div class="vbt">🚗 Vehicle '+i+'</div><div class="g2">';
    h+='<div class="f" style="margin:0"><label>Vehicle Number</label><input id="vn'+i+'" type="text" placeholder="AP31AB1234" oninput="this.value=this.value.toUpperCase()"'+dis+'></div>';
    h+='<div class="f" style="margin:0"><label>Vehicle Type</label><select id="vt'+i+'"'+dis+'><option value="">Select</option>';
    VTYPES.forEach(function(vt){h+='<option>'+vt+'</option>';});
    h+='</select></div></div><div class="g2" style="margin-top:8px">';
    if(cfg.rc){h+='<div><label style="font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;display:block;margin-bottom:4px">RC Copy</label><div class="ua" id="rc'+i+'" onclick="pickFile(\'rc\','+i+')"><div class="ua-icon">📄</div><div class="ua-lbl">Upload RC</div></div><input type="file" id="rcf'+i+'" accept="image/*,.pdf" style="display:none" onchange="filePicked(\'rc\','+i+',this)"></div>';}
    h+='<div><label style="font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;display:block;margin-bottom:4px">Selfie</label><div class="ua" id="sl'+i+'" onclick="pickFile(\'selfie\','+i+')"><div class="ua-icon">🤳</div><div class="ua-lbl">Take Selfie</div></div><input type="file" id="slf'+i+'" accept="image/*" style="display:none" onchange="filePicked(\'selfie\','+i+',this)"></div>';
    h+='</div></div>';
  }

  if(cfg.imei) h+='<div class="f"><label>Device IMEI / GPS Serial No. *</label><input id="imei" type="text" placeholder="356938035643809"'+dis+'></div>';
  h+='<div class="f"><label>Name on Server (Vehicle Name) *</label><input id="name_on_server" type="text" placeholder="e.g. Ravi Kumar - AP31AB1234"'+dis+'></div>';
  h+='<div class="f"><label>GPS Server *</label><select id="server_name"'+dis+'><option value="">Select Server</option><option>Server 1</option><option>Server 2</option><option>Server 3</option><option>Server 4</option></select></div>';
  if(cfg.ex.indexOf('relay')>=0) h+='<div class="f"><label>Relay Installed?</label><select id="relay"'+dis+'><option value="">Select</option><option>Yes - Engine Cut Active</option><option>Yes - Bypassed</option><option>No</option></select></div>';
  if(cfg.ex.indexOf('old_imei')>=0) h+='<div class="f"><label>Old Device IMEI</label><input id="old_imei" type="text"'+dis+'></div>';
  if(cfg.ex.indexOf('reason')>=0) h+='<div class="f"><label>Reason for Removal</label><select id="reason"'+dis+'><option>Customer Request</option><option>Vehicle Sold</option><option>Upgrade</option><option>Damage</option><option>Other</option></select></div>';
  if(cfg.ex.indexOf('issue')>=0) h+='<div class="f"><label>Issue Found</label><select id="issue"'+dis+'><option value="">Select</option><option>Device Offline</option><option>SIM Issue</option><option>Power Wire Cut</option><option>Device Damaged</option><option>Antenna Issue</option><option>Software Reset</option><option>Other</option></select></div>';
  if(cfg.ex.indexOf('action')>=0) h+='<div class="f"><label>Action Taken</label><textarea id="action" rows="2"'+dis+'></textarea></div>';
  if(cfg.ex.indexOf('demo_fb')>=0) h+='<div class="f"><label>Customer Feedback</label><select id="demo_fb"'+dis+'><option>Interested</option><option>Wants to Think</option><option>Not Interested</option><option>Ready Now</option></select></div>';
  if(cfg.ex.indexOf('cert')>=0) h+='<div class="f"><label>VLTD Certificate No.</label><input id="cert" type="text"'+dis+'></div>';
  h+='<div class="f"><label>Work Remarks *</label><textarea id="inst_rem" rows="3" placeholder="Describe exactly what was done..."'+dis+'></textarea></div>';
  h+='</div></div>';

  if(!instDone) h+='<button class="abtn abtn-i" id="inst-btn" onclick="submitInstall()">✅ Submit Installation → Payment</button>';
  else h+='<div style="background:var(--grnb);border:1.5px solid var(--grn);border-radius:var(--r);padding:13px;display:flex;align-items:center;gap:10px"><span style="font-size:20px">✅</span><div style="flex:1"><div style="font-size:14px;font-weight:800;color:var(--grn)">Installation submitted</div></div><button class="btn btns btnsm" onclick="show(\'payment\')">Payment →</button></div>';

  document.getElementById('pnl-install').innerHTML=h;
}

function pickFile(type,idx){ if(instDone)return; document.getElementById((type==='rc'?'rcf':'slf')+idx).click(); }
function filePicked(type,idx,inp){
  if(!inp.files[0])return;
  uploads[type+'-'+idx]=inp.files[0];
  var el=document.getElementById((type==='rc'?'rc':'sl')+idx);
  el.className='ua ok'; el.innerHTML='<div class="ua-icon">✅</div><div class="ua-lbl" style="color:var(--grn)">'+inp.files[0].name.slice(0,18)+'</div>';
  toast('📎 Selected');
}

function submitInstall(){
  var rem=document.getElementById('inst_rem')?document.getElementById('inst_rem').value.trim():'';
  if(!rem){toast('❌ Add work remarks');return;}
  var qty=parseInt(T.device_qty)||1, vinfo=[];
  for(var i=1;i<=qty;i++){
    var vn=document.getElementById('vn'+i)?document.getElementById('vn'+i).value.trim():'';
    var vt=document.getElementById('vt'+i)?document.getElementById('vt'+i).value:'';
    vinfo.push('Vehicle '+i+': '+(vn||'not entered')+(vt?' ('+vt+')':''));
  }
  var imei=document.getElementById('imei')?document.getElementById('imei').value.trim():'';
  var nameOnServer=document.getElementById('name_on_server')?document.getElementById('name_on_server').value.trim():'';
  var serverName=document.getElementById('server_name')?document.getElementById('server_name').value:'';
  var fullRem='Installation completed. '+vinfo.join(', ')+'. '+rem+(imei?' IMEI:'+imei:'')+(nameOnServer?' Name:'+nameOnServer:'')+(serverName?' Server:'+serverName:'');
  var btn=document.getElementById('inst-btn');
  if(btn){btn.textContent='Submitting...';btn.disabled=true;}
  // Save BS fields to task first
  var bsUpdateBody={id:TID};
  if(imei) bsUpdateBody.gps_serial_no=imei;
  if(nameOnServer) bsUpdateBody.name_on_server=nameOnServer;
  if(serverName) bsUpdateBody.server_name=serverName;
  apiPost('update_task',bsUpdateBody).catch(function(){});
  apiPost('update_task',{id:TID,task_status:'Task Pending',remark:fullRem}).then(function(r){
    if(!r.success){toast('❌ '+(r.error||'Error'));if(btn){btn.textContent='✅ Submit Installation → Payment';btn.disabled=false;}return;}
    var keys=Object.keys(uploads), chain=Promise.resolve();
    keys.forEach(function(k){ var dtype=k.split('-')[0]; chain=chain.then(function(){ return apiUpload(TID,dtype,uploads[k]); }); });
    return chain;
  }).then(function(){
    uploads={}; instDone=true; toast('✅ Installation submitted!');
    reloadTask(); show('payment');
  }).catch(function(e){ toast('❌ '+e.message); if(btn){btn.textContent='✅ Submit Installation → Payment';btn.disabled=false;} });
}

// ---- PAYMENT ----
function renderPayment(){
  var exp=parseFloat(T.price_to_collect||0);
  var col=parseFloat(T.amount_collected||0);
  var bal=Math.max(0,exp-col);
  var pct=exp>0?Math.min(100,Math.round((col/exp)*100)):(col>0?100:0);
  var hasPayments=T.payments&&T.payments.length>0;
  var fullPaid=bal<=0&&col>0;

  var h='';
  if(isLocked) h='<div class="lockbox" style="margin-bottom:11px"><div style="font-size:28px;margin-bottom:8px">✅</div><div style="font-size:15px;font-weight:800;color:var(--grn)">Submitted for Approval</div><div style="font-size:12px;color:var(--tx3);margin-top:4px">Manager will review and close.</div></div>';

  h+='<div class="card"><div class="ch"><div class="ci" style="background:var(--grnb)">💰</div><div><h3>Payment Summary</h3></div></div><div class="cb">'
    +'<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:11px">'
    +'<div style="text-align:center;background:var(--sur2);border:1px solid var(--bdr);border-radius:8px;padding:9px"><div style="font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;margin-bottom:3px">Expected</div><div style="font-size:16px;font-weight:800;color:var(--blu)">₹'+exp.toLocaleString('en-IN')+'</div></div>'
    +'<div style="text-align:center;background:var(--sur2);border:1px solid var(--bdr);border-radius:8px;padding:9px"><div style="font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;margin-bottom:3px">Collected</div><div style="font-size:16px;font-weight:800;color:'+(col>=exp?'var(--grn)':col>0?'var(--saf)':'var(--tx3)')+'">₹'+col.toLocaleString('en-IN')+'</div></div>'
    +'<div style="text-align:center;background:var(--sur2);border:1px solid var(--bdr);border-radius:8px;padding:9px"><div style="font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;margin-bottom:3px">Balance</div><div style="font-size:16px;font-weight:800;color:'+(bal>0?'var(--red)':'var(--grn)')+'">₹'+bal.toLocaleString('en-IN')+'</div></div>'
    +'</div>'
    +'<div style="height:9px;background:var(--sur3);border-radius:9px;overflow:hidden"><div style="height:100%;width:'+pct+'%;background:'+(pct>=100?'linear-gradient(90deg,var(--grn),#22c55e)':pct>=50?'linear-gradient(90deg,var(--saf),#f59e0b)':'linear-gradient(90deg,var(--red),#f87171)')+';border-radius:9px;transition:width .5s"></div></div>'
    +'<div style="font-size:11px;color:var(--tx3);margin-top:4px;text-align:right">'+pct+'% collected</div>'
    +'</div></div>';

  if(hasPayments){
    h+='<div class="card"><div class="ch"><div class="ci" style="background:var(--safb)">🧾</div><div><h3>Recorded Payments</h3></div></div><div class="cb">';
    T.payments.forEach(function(p){ h+='<div class="pe"><div><div class="pea">₹'+parseFloat(p.amount).toLocaleString('en-IN')+'</div><div class="pem">'+esc(p.payment_mode)+(p.transaction_ref?' · '+esc(p.transaction_ref):'')+'</div></div><div class="pem">'+(p.created_at||'').substring(0,16)+'</div></div>'; });
    h+='</div></div>';
  }

  if(!isLocked&&!hasPayments){
    h+='<div class="card"><div class="ch"><div class="ci" style="background:var(--safb)">➕</div><div><h3>Record Payment</h3></div></div><div class="cb">'
      +'<div style="background:var(--accl);border:1px solid rgba(36,81,163,.2);border-radius:6px;padding:8px 11px;font-size:12px;color:var(--acc2);font-weight:700;margin-bottom:12px">💡 Expected ₹'+exp.toLocaleString('en-IN')+' · Remaining ₹'+bal.toLocaleString('en-IN')+'</div>'
      +'<div class="g2" style="margin-bottom:12px"><div class="f" style="margin:0"><label>Amount (₹) *</label><input type="number" id="pa" placeholder="" inputmode="numeric" oninput="chkSF('+exp+','+col+')"></div>'
      +'<div class="f" style="margin:0"><label>Mode *</label><select id="pm"><option value="">Select</option><option>Cash</option><option>UPI</option><option>Bank Transfer</option></select></div></div>'
      +'<div class="f"><label>Ref / UTR (optional)</label><input type="text" id="pr" placeholder="For UPI / bank transfer"></div>'
      +'<div id="sf-box" style="display:none"><div class="sfbox"><div style="font-size:13px;font-weight:800;color:var(--red);margin-bottom:10px">⚠️ Less than expected</div>'
      +'<div class="f" style="margin-bottom:9px"><label>Reason *</label><select id="sf-r"><option value="">Select</option><option>Customer will pay balance later</option><option>Partial — agreed on balance date</option><option>Price negotiated</option><option>Customer disputed</option><option>Other</option></select></div>'
      +'<div class="f" style="margin-bottom:9px"><label>Balance (₹)</label><input type="number" id="sf-b" readonly style="background:var(--sur3);color:var(--red);font-weight:700"></div>'
      +'<div class="f" style="margin:0"><label>When will balance be paid?</label><input type="text" id="sf-w" placeholder="e.g. Tomorrow, In 2 days..."></div>'
      +'</div></div>'
      +'<button class="btn btns btnw" onclick="addPay()">💰 Record This Payment</button>'
      +'</div></div>';
  } else if(fullPaid&&!isLocked){
    h+='<div style="background:var(--grnb);border:1.5px solid rgba(26,122,58,.3);border-radius:var(--r);padding:12px;display:flex;align-items:center;gap:9px;margin-bottom:11px"><span style="font-size:22px">✅</span><div><div style="font-size:14px;font-weight:800;color:var(--grn)">Full payment collected!</div></div></div>';
  }

  if(!isLocked){
    var sc=bal>0?'var(--saf)':col<=0?'var(--tx2)':'var(--grn)';
    var sm=bal>0?'⚠️ Balance pending — submit anyway?':col<=0?'🚀 Submit for approval?':'✅ Full payment — submit for approval';
    h+='<div style="background:var(--sur2);border:1.5px solid var(--bdr);border-radius:var(--r);padding:13px">'
      +'<div style="font-size:13px;font-weight:800;color:'+sc+';margin-bottom:5px">'+sm+'</div>'
      +'<div class="f"><label>Final Note for Manager</label><input type="text" id="fin-rem" placeholder="Any message..."></div>'
      +'<button class="abtn abtn-a" onclick="submitApproval()" style="margin-top:10px">🚀 Submit for Approval →</button>'
      +'</div>';
  }
  document.getElementById('pnl-payment').innerHTML=h;
}

function chkSF(exp,col){
  var entered=parseFloat(document.getElementById('pa')?document.getElementById('pa').value||0:0);
  var rem=Math.max(0,exp-col);
  var box=document.getElementById('sf-box'), binp=document.getElementById('sf-b');
  if(entered>0&&entered<rem){if(box)box.style.display='block';if(binp)binp.value=(rem-entered).toFixed(0);}
  else{if(box)box.style.display='none';if(binp)binp.value='';}
}
function addPay(){
  var amt=parseFloat(document.getElementById('pa')?document.getElementById('pa').value||0:0);
  var mode=document.getElementById('pm')?document.getElementById('pm').value:'';
  var ref=document.getElementById('pr')?document.getElementById('pr').value.trim():'';
  var exp=parseFloat(T.price_to_collect||0), col=parseFloat(T.amount_collected||0), rem=Math.max(0,exp-col);
  if(!amt||amt<=0){toast('❌ Enter valid amount');return;}
  if(!mode){toast('❌ Select payment mode');return;}
  var remark='Payment ₹'+amt.toLocaleString('en-IN')+' collected via '+mode+(ref?' (Ref:'+ref+')':'');
  if(amt<rem){
    var reason=document.getElementById('sf-r')?document.getElementById('sf-r').value:'';
    var when=document.getElementById('sf-w')?document.getElementById('sf-w').value.trim():'';
    if(!reason){toast('❌ Select reason for less payment');return;}
    remark+=' | SHORT ₹'+(rem-amt).toLocaleString('en-IN')+'. Reason: '+reason+(when?'. By: '+when:'');
  }
  apiPost('add_payment',{task_id:TID,amount:amt,payment_mode:mode,transaction_ref:ref}).then(function(r){
    if(!r.success){toast('❌ '+(r.error||'Error'));return;}
    return apiPost('update_task',{id:TID,remark:remark});
  }).then(function(){ toast('✅ ₹'+amt.toLocaleString('en-IN')+' recorded!'); reloadTask(); }).catch(function(e){toast('❌ '+e.message);});
}
function submitApproval(){
  var note=document.getElementById('fin-rem')?document.getElementById('fin-rem').value.trim():'';
  var col=parseFloat(T.amount_collected||0);
  var remark='Task submitted for approval. Collected: ₹'+col.toLocaleString('en-IN')+(note?'. '+note:'');
  apiPost('update_task',{id:TID,task_status:'Awaiting Approval',remark:remark}).then(function(r){
    if(r.success){ isLocked=true; T.task_status='Awaiting Approval'; toast('🚀 Submitted!'); setTimeout(function(){window.location.href='index.html';},1500); }
    else toast('❌ '+(r.error||'Error'));
  }).catch(function(e){toast('❌ '+e.message);});
}

function reloadTask(){
  return apiFetch('get_task',{id:TID}).then(function(res){
    if(res&&res.task){ T=res.task; setState(); setWF(T.task_status); setStatusBadge(T.task_status); renderAll(); }
  }).catch(function(){});
}
function setStatusBadge(s){
  var b=document.getElementById('tb-status');
  var m={'Open':'b-o','In Progress':'b-p','Task Pending':'b-t','Awaiting Approval':'b-a','Closed':'b-c'};
  b.className='badge '+(m[s]||'b-o'); b.textContent=s;
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ---- INIT — data already loaded by PHP, just render ----
setState();
setWF(T.task_status);
setStatusBadge(T.task_status);
renderAll();
</script>
</body>
</html>
