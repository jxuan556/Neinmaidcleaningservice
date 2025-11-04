<?php
// contact_chat.php — worker support chat
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'worker') {
  header("Location: login.php");
  exit();
}

$logged = true;
$name   = $_SESSION['name'] ?? 'You';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Support Chat – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="foundation.css">
  <style>
    :root{
      --ink:#0f172a; --muted:#64748b; --bg:#f6f7fb; --card:#ffffff; --bd:#e5e7eb;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}

    /* Shared layout */
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .side{background:#0f172a;color:#cbd5e1;padding:20px;display:flex;flex-direction:column;gap:16px}
    .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:900}
    .avatar{width:40px;height:40px;border-radius:50%;background:#1f2937;display:grid;place-items:center;color:#fff;font-weight:800}
    .nav a{display:block;color:#cbd5e1;text-decoration:none;padding:10px;border-radius:10px}
    .nav a.active,.nav a:hover{background:rgba(255,255,255,.06);color:#fff}
    .main{padding:20px}
    @media(max-width:900px){.layout{grid-template-columns:1fr}}

    /* Chat styles */
    .chat-wrap{max-width:820px;margin:0 auto}
    .chat-box{background:#fff;border:1px solid #e5e7eb;border-radius:14px;min-height:420px;display:flex;flex-direction:column}
    .messages{padding:12px;overflow:auto;max-height:60vh}
    .msg{margin:8px 0;display:flex}
    .msg .bubble{padding:10px 12px;border-radius:12px;max-width:74%}
    .me{justify-content:flex-end}
    .me .bubble{background:#eef2ff;border:1px solid #c7d2fe}
    .them .bubble{background:#f8fafc;border:1px solid #e5e7eb}
    .meta{font-size:11px;color:#6b7280;margin-top:2px}
    .sendbar{display:flex;gap:8px;border-top:1px solid #e5e7eb;padding:10px}
    .input{flex:1}
    .tag{display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px}
    .row{display:flex;align-items:center;gap:8px}
    .btn-cta{background:#0ea5e9;color:#fff;border:none;border-radius:8px;padding:8px 16px;cursor:pointer;font-weight:600}
    .btn-cta:hover{background:#0284c7}
    .footer-links{display:flex;gap:12px;margin-top:10px}
    .footer-links a{text-decoration:none;color:#0ea5e9}
  </style>
</head>
<body>
<div class="layout">
  <!-- Sidebar -->
  <aside class="side">
    <div class="brand">
      <div class="avatar"><?= strtoupper(substr($name,0,1)) ?></div>
      NeinMaid Worker
    </div>
    <nav class="nav">
      <a href="worker_dashboard.php">🏠 Dashboard</a>
      <a href="worker_history.php">📜 Job History</a>
      <a href="worker_finance.php">💰 Finance</a>
      <a href="worker_profile.php">👤 Profile</a>
      <a class="active" href="contact_chat.php">💬 Chats</a>
    </nav>
    <div style="flex:1"></div>
    <a class="nav" href="logout.php" style="color:#fecaca;text-decoration:none">⏻ Logout</a>
  </aside>

  <!-- Main -->
  <main class="main">
    <div class="chat-wrap">
      <div class="card" style="padding:14px;background:#fff;border-radius:14px;border:1px solid #e5e7eb">
        <div class="row" style="justify-content:space-between">
          <h2 class="title" style="margin:0">Support Chat</h2>
          <div id="threadStatus" class="tag">Connecting…</div>
        </div>

        <div class="chat-box" style="margin-top:10px">
          <div id="messages" class="messages"></div>
          <div class="sendbar">
            <input id="body" class="input" placeholder="Type your message…" />
            <button id="sendBtn" class="btn-cta">Send</button>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
let THREAD_ID = 0;
let LAST_ID = 0;
let POLL = null;

async function ensureThread(){
  const r = await fetch('chat_api.php?action=ensure_thread');
  const j = await r.json();
  if(!j.ok){ alert(j.msg||'Unable to start chat'); return; }
  THREAD_ID = j.thread_id;
  document.getElementById('threadStatus').textContent = 'Open';
  poll();
}

async function poll(){
  if(!THREAD_ID) return;
  try{
    const r = await fetch('chat_api.php?action=fetch&thread_id='+THREAD_ID+'&since_id='+LAST_ID, { cache:'no-store' });
    const j = await r.json();
    if(j.ok){
      appendMessages(j.messages || []);
    }
  }catch(e){}
  clearTimeout(POLL);
  POLL = setTimeout(poll, 2500);
}

function appendMessages(list){
  const box = document.getElementById('messages');
  if(!list.length) return;
  for(const m of list){
    LAST_ID = Math.max(LAST_ID, m.id);
    const mine = m.sender === 'user';
    const row = document.createElement('div');
    row.className = 'msg ' + (mine?'me':'them');
    const b = document.createElement('div');
    b.className = 'bubble';
    b.textContent = m.body;
    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = new Date(m.created_at.replace(' ','T')).toLocaleString();
    const wrap=document.createElement('div');
    wrap.appendChild(b);
    wrap.appendChild(meta);
    row.appendChild(wrap);
    box.appendChild(row);
  }
  box.scrollTop = box.scrollHeight;
}

async function send(){
  const input = document.getElementById('body');
  const txt = input.value.trim();
  if(!txt || !THREAD_ID) return;
  input.value='';
  const fd = new FormData();
  fd.append('action','send');
  fd.append('thread_id', THREAD_ID);
  fd.append('body', txt);
  const r = await fetch('chat_api.php', { method:'POST', body: fd });
  const j = await r.json();
  if(!j.ok){ alert(j.msg||'Failed to send'); return; }
  await poll();
}

document.getElementById('sendBtn').addEventListener('click', send);
document.getElementById('body').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); send(); } });

ensureThread();
</script>
</body>
</html>
