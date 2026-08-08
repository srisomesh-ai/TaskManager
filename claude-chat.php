<?php
/* ============================================================
   PRIVATE CLAUDE CHAT — BharatGPS internal tool
   Single file. Backend proxy + chat UI.
   SETUP:
   1) Paste your Anthropic API key below (from console.anthropic.com)
   2) Change the password
   3) Open this file in browser and login
   ============================================================ */

define('ANTHROPIC_API_KEY', 'sk-ant-PASTE_YOUR_KEY_HERE');
define('CHAT_PASSWORD', 'Somu@2026');   // change this
define('DEFAULT_MODEL', 'claude-sonnet-4-6');
define('MAX_TOKENS', 8000);

/* ---------------- BACKEND ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);

    if (!$req || !isset($req['password']) || !hash_equals(CHAT_PASSWORD, (string)$req['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Wrong password']);
        exit;
    }

    // login check only
    if (isset($req['action']) && $req['action'] === 'login') {
        echo json_encode(['ok' => true]);
        exit;
    }

    if (ANTHROPIC_API_KEY === 'sk-ant-PASTE_YOUR_KEY_HERE') {
        http_response_code(500);
        echo json_encode(['error' => 'API key not set. Edit claude-chat.php and paste your Anthropic API key.']);
        exit;
    }

    $messages = $req['messages'] ?? [];
    if (!is_array($messages) || count($messages) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No messages']);
        exit;
    }

    // keep only role/content, cap history to last 40 turns to control cost
    $clean = [];
    foreach (array_slice($messages, -40) as $m) {
        if (isset($m['role'], $m['content']) && in_array($m['role'], ['user','assistant'], true)) {
            $clean[] = ['role' => $m['role'], 'content' => (string)$m['content']];
        }
    }

    $model = in_array($req['model'] ?? '', ['claude-sonnet-4-6','claude-opus-4-8','claude-haiku-4-5-20251001'], true)
        ? $req['model'] : DEFAULT_MODEL;

    $payload = json_encode([
        'model' => $model,
        'max_tokens' => MAX_TOKENS,
        'system' => 'You are Claude, helping Someswara (owner of BharatGPS, Visakhapatnam) with software development. His stack: PHP backends, MySQL, single-file HTML frontends, Hostinger shared hosting, GitHub. Give complete, copy-paste-ready code. Be direct and practical.',
        'messages' => $clean,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Connection failed: ' . $err]);
        exit;
    }

    $data = json_decode($resp, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('API error HTTP ' . $code);
        http_response_code(502);
        echo json_encode(['error' => $msg]);
        exit;
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    echo json_encode([
        'text' => $text,
        'usage' => $data['usage'] ?? null,
        'model' => $data['model'] ?? $model,
    ]);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Somu · Claude</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.0/marked.min.js"></script>
<style>
:root{
  --bg:#0d1117; --panel:#161b22; --panel2:#1c2330; --line:#2a3546;
  --text:#e6edf3; --dim:#8b98a9; --accent:#2dd4bf; --accent-dk:#0f766e;
  --user:#1e293b; --code-bg:#0a0e14;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{background:var(--bg);color:var(--text);font-family:'Sora',sans-serif;font-size:15px;display:flex;flex-direction:column}
/* login */
#login{position:fixed;inset:0;background:var(--bg);display:flex;align-items:center;justify-content:center;z-index:50}
.login-card{width:min(340px,90vw);background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:28px}
.login-card h1{font-size:18px;font-weight:700;margin-bottom:4px}
.login-card p{color:var(--dim);font-size:13px;margin-bottom:18px}
.login-card input{width:100%;padding:12px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--text);font-family:inherit;font-size:15px}
.login-card button{width:100%;margin-top:12px;padding:12px;border:none;border-radius:8px;background:var(--accent);color:#04211d;font-weight:700;font-size:15px;cursor:pointer;font-family:inherit}
.login-err{color:#f87171;font-size:13px;margin-top:10px;display:none}
/* header */
header{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--panel)}
.dot{width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 8px var(--accent)}
header h2{font-size:15px;font-weight:600;flex:1}
header select{background:var(--bg);color:var(--dim);border:1px solid var(--line);border-radius:7px;padding:6px 8px;font-family:'IBM Plex Mono',monospace;font-size:12px}
header button{background:none;border:1px solid var(--line);color:var(--dim);border-radius:7px;padding:6px 10px;font-size:12px;cursor:pointer;font-family:inherit}
/* chat */
#chat{flex:1;overflow-y:auto;padding:18px 14px 10px;-webkit-overflow-scrolling:touch}
.msg{max-width:820px;margin:0 auto 16px;display:flex;gap:10px}
.msg .who{flex-shrink:0;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.msg.user .who{background:var(--user);color:var(--dim)}
.msg.ai .who{background:var(--accent-dk);color:#ccfbf1}
.msg .body{flex:1;min-width:0;line-height:1.65;overflow-wrap:break-word}
.msg.user .body{color:#cbd5e1;white-space:pre-wrap}
.body p{margin:0 0 10px}
.body p:last-child{margin-bottom:0}
.body h1,.body h2,.body h3{margin:14px 0 8px;font-size:15px;color:var(--accent)}
.body ul,.body ol{margin:0 0 10px 20px}
.body li{margin-bottom:4px}
.body code{font-family:'IBM Plex Mono',monospace;font-size:13px;background:var(--panel2);padding:2px 5px;border-radius:4px}
.body pre{position:relative;background:var(--code-bg);border:1px solid var(--line);border-radius:10px;padding:14px;margin:10px 0;overflow-x:auto}
.body pre code{background:none;padding:0;font-size:12.5px;line-height:1.55}
.copybtn{position:absolute;top:8px;right:8px;background:var(--panel2);border:1px solid var(--line);color:var(--dim);border-radius:6px;font-size:11px;padding:4px 9px;cursor:pointer;font-family:'Sora',sans-serif}
.copybtn:active{color:var(--accent)}
.body table{border-collapse:collapse;margin:10px 0;font-size:13px}
.body th,.body td{border:1px solid var(--line);padding:6px 10px}
.typing{color:var(--dim);font-size:13px;font-family:'IBM Plex Mono',monospace}
.typing::after{content:'▋';animation:blink 1s infinite;color:var(--accent)}
@keyframes blink{50%{opacity:0}}
.errline{color:#f87171;font-size:13px}
/* composer */
#composer{border-top:1px solid var(--line);background:var(--panel);padding:10px 12px calc(10px + env(safe-area-inset-bottom))}
.crow{max-width:820px;margin:0 auto;display:flex;gap:8px;align-items:flex-end}
#inp{flex:1;resize:none;border:1px solid var(--line);background:var(--bg);color:var(--text);border-radius:12px;padding:12px 14px;font-family:inherit;font-size:15px;line-height:1.5;max-height:180px;outline:none}
#inp:focus{border-color:var(--accent-dk)}
#send{flex-shrink:0;width:44px;height:44px;border:none;border-radius:12px;background:var(--accent);color:#04211d;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}
#send:disabled{opacity:.4}
@media(max-width:600px){.msg .who{width:26px;height:26px;font-size:11px}}
</style>
</head>
<body>

<div id="login">
  <div class="login-card">
    <h1>Somu · Claude</h1>
    <p>Private workspace. Enter password.</p>
    <input type="password" id="pw" placeholder="Password" autocomplete="current-password">
    <button onclick="doLogin()">Enter</button>
    <div class="login-err" id="loginErr">Wrong password</div>
  </div>
</div>

<header style="display:none" id="hdr">
  <div class="dot"></div>
  <h2>Somu · Claude</h2>
  <select id="model">
    <option value="claude-sonnet-4-6" selected>Sonnet</option>
    <option value="claude-opus-4-8">Opus</option>
    <option value="claude-haiku-4-5-20251001">Haiku</option>
  </select>
  <button onclick="newChat()">New chat</button>
</header>

<div id="chat" style="display:none"></div>

<div id="composer" style="display:none">
  <div class="crow">
    <textarea id="inp" rows="1" placeholder="Ask anything… (Shift+Enter = new line)"></textarea>
    <button id="send" onclick="sendMsg()">➤</button>
  </div>
</div>

<script>
let history = [];
let pw = sessionStorage.getItem('sc_pw') || '';

marked.setOptions({breaks:true});

function show(id,on){document.getElementById(id).style.display = on ? '' : 'none';}

async function api(body){
  const r = await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const d = await r.json().catch(()=>({error:'Bad response'}));
  if(!r.ok) throw new Error(d.error || ('HTTP '+r.status));
  return d;
}

async function doLogin(){
  const v = document.getElementById('pw').value;
  try{
    await api({action:'login',password:v});
    pw = v; sessionStorage.setItem('sc_pw',v);
    enterApp();
  }catch(e){
    document.getElementById('loginErr').style.display='block';
  }
}
document.getElementById('pw').addEventListener('keydown',e=>{if(e.key==='Enter')doLogin();});

function enterApp(){
  show('login',false); show('hdr',true); show('chat',true); show('composer',true);
  history = JSON.parse(localStorage.getItem('sc_hist')||'[]');
  renderAll();
  document.getElementById('inp').focus();
}

function newChat(){
  if(history.length && !confirm('Clear this conversation?')) return;
  history = []; localStorage.removeItem('sc_hist'); renderAll();
}

function renderMd(t){
  const div = document.createElement('div');
  div.innerHTML = marked.parse(t);
  div.querySelectorAll('pre').forEach(pre=>{
    const b = document.createElement('button');
    b.className='copybtn'; b.textContent='Copy';
    b.onclick=()=>{navigator.clipboard.writeText(pre.querySelector('code')?.innerText||pre.innerText);b.textContent='Copied!';setTimeout(()=>b.textContent='Copy',1200);};
    pre.appendChild(b);
  });
  return div;
}

function addMsg(role,content,cls){
  const chat = document.getElementById('chat');
  const m = document.createElement('div');
  m.className = 'msg ' + (role==='user'?'user':'ai');
  const who = document.createElement('div');
  who.className='who'; who.textContent = role==='user' ? 'S' : 'C';
  const body = document.createElement('div');
  body.className='body'+(cls?' '+cls:'');
  if(role==='user'){ body.textContent = content; }
  else { body.appendChild(renderMd(content)); }
  m.appendChild(who); m.appendChild(body);
  chat.appendChild(m);
  chat.scrollTop = chat.scrollHeight;
  return body;
}

function renderAll(){
  document.getElementById('chat').innerHTML='';
  history.forEach(m=>addMsg(m.role,m.content));
}

const inp = document.getElementById('inp');
inp.addEventListener('input',()=>{inp.style.height='auto';inp.style.height=Math.min(inp.scrollHeight,180)+'px';});
inp.addEventListener('keydown',e=>{
  if(e.key==='Enter' && !e.shiftKey && window.innerWidth>700){e.preventDefault();sendMsg();}
});

async function sendMsg(){
  const text = inp.value.trim();
  if(!text) return;
  inp.value=''; inp.style.height='auto';
  history.push({role:'user',content:text});
  addMsg('user',text);
  const btn = document.getElementById('send'); btn.disabled = true;

  const chat = document.getElementById('chat');
  const t = document.createElement('div');
  t.className='msg ai';
  t.innerHTML='<div class="who">C</div><div class="body"><span class="typing">thinking</span></div>';
  chat.appendChild(t); chat.scrollTop = chat.scrollHeight;

  try{
    const d = await api({password:pw, messages:history, model:document.getElementById('model').value});
    t.remove();
    history.push({role:'assistant',content:d.text});
    localStorage.setItem('sc_hist',JSON.stringify(history));
    addMsg('assistant',d.text);
  }catch(e){
    t.remove();
    history.pop(); // remove failed user turn from history so retry is clean
    addMsg('assistant','⚠ '+e.message,'errline');
  }finally{
    btn.disabled = false; inp.focus();
  }
}

// auto-login if session password stored
if(pw){ api({action:'login',password:pw}).then(enterApp).catch(()=>{sessionStorage.removeItem('sc_pw');}); }
</script>
</body>
</html>
