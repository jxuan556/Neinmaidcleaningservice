<?php
// admin_chat.php — Admin support console (sidebar identical to admin_bookings, chat-style bubbles)
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { header('Location: login.php'); exit(); }
date_default_timezone_set('Asia/Kuala_Lumpur');

$adminName = $_SESSION['name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin · Support Chats – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css">
  <style>
    /* ====== layout copied to match admin_bookings exactly ====== */
    body{background:#f6f7fb;color:#0f172a;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0}
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px 12px}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand img{width:28px;height:28px}
    .nav{display:flex;flex-direction:column;margin-top:12px}
    .nav a{padding:10px 12px;border-radius:10px;color:#111827;text-decoration:none;margin:2px 0}
    .nav a.active, .nav a:hover{background:#eef2ff}
    .main{padding:18px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .row{display:flex;gap:10px;align-items:center}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:12px}
    .toolbar .title{font-weight:800;font-size:20px}
    .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
    .btn:hover{background:#f8fafc}
    .muted{color:#64748b}
    .small{font-size:12px}

    /* ====== thread list & inputs ====== */
    .tag{display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px;background:#f8fafc}
    .tag.role{background:#eef2ff;border-color:#c7d2fe;color:#3730a3}
    .tag.role.user{background:#e5e7eb;border-color:#d1d5db;color:#374151}
    .tag.role.worker{background:#dbeafe;border-color:#bfdbfe;color:#1e40af}

    .admin-grid{display:grid;grid-template-columns:360px 1fr;gap:12px}
    @media(max-width:980px){.admin-grid{grid-template-columns:1fr}}

    .input, select{width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;font:inherit;background:#fff}
    .threads{border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden}
    .threads-head{display:flex;gap:8px;padding:10px;border-bottom:1px solid #e5e7eb;background:#f8fafc}
    .threads-list{max-height:62vh;overflow:auto}
    .thread{padding:10px;border-bottom:1px solid #eef2f7;cursor:pointer}
    .thread:hover{background:#f8fafc}
    .thread.active{background:#eef2ff}
    .thread .name{font-weight:700}
    .thread .meta{font-size:12px;color:#6b7280;margin-top:2px}

    /* ====== chat bubbles ====== */
    .chat-box{background:#fff;border:1px solid #e5e7eb;border-radius:14px;min-height:480px;display:flex;flex-direction:column}
    .messages{padding:12px;overflow:auto;max-height:62vh}
    .msg{display:flex;margin:8px 0}
    .msg .wrap{max-width:74%}
    .bubble{padding:10px 12px;border-radius:16px;border:1px solid #e5e7eb;word-wrap:break-word;white-space:pre-wrap}
    .meta-line{font-size:11px;color:#6b7280;margin-top:4px}

    .them{justify-content:flex-start}
    .them .bubble{background:#f8fafc}
    .me{justify-content:flex-end}
    .me .wrap{display:flex;flex-direction:column;align-items:flex-end}
    .me .bubble{background:#eef2ff;border-color:#c7d2fe}

    .sendbar{display:flex;gap:8px;border-top:1px solid #e5e7eb;padding:10px}
    .err{color:#ef4444;margin-top:10px;display:none}
  </style>
</head>
<body>

<div class="layout">
  <!-- ===== Sidebar (exactly like admin_bookings) ===== -->
  <aside class="sidebar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      <div>NeinMaid</div>
    </div>

    <div class="nav">
      <a href="admin_dashboard.php">Dashboard</a>
      <a href="admin_bookings.php">Bookings</a>
      <a href="admin_employees.php">Workers</a>
      <a href="admin_services.php">Services</a>
      <a href="admin_finance.php">Finance</a>
      <a href="admin_promos.php">Promotions</a>
      <a href="admin_worker_changes.php">Worker Changes</a>
      <a class="active" href="admin_chat.php">Support Chats</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <!-- ===== Main ===== -->
  <main class="main">
    <div class="toolbar">
      <div class="title">Support Chats</div>
      <div class="row muted small">Signed in as <strong><?= htmlspecialchars($adminName,ENT_QUOTES,'UTF-8') ?></strong></div>
    </div>

    <div class="card">
      <div class="admin-grid">
        <!-- LEFT: Threads -->
        <section>
          <div class="threads">
            <div class="threads-head">
              <input id="q" class="input" placeholder="Search name/email…">
              <select id="status" style="max-width:130px">
                <option value="open">Open</option>
                <option value="closed">Closed</option>
              </select>
              <button id="btnSearch" class="btn" onclick="loadThreads()">Search</button>
            </div>
            <div id="threads" class="threads-list" aria-label="Conversation threads"></div>
          </div>
        </section>

        <!-- RIGHT: Messages (chat bubbles) -->
        <section>
          <div class="chat-box">
            <div id="messages" class="messages" aria-live="polite"></div>
            <div class="sendbar">
              <input id="msgInput" class="input" placeholder="Type a reply…">
              <button id="btnSend" class="btn" onclick="send()">Send</button>
            </div>
          </div>
          <div id="err" class="err"></div>
        </section>
      </div>
    </div>
  </main>
</div>

<script>
/* ===== State ===== */
let THREAD_ID = 0;
let LAST_ID = 0;
let POLL = null;
let theStatus = 'open'; // filter only

const ADMIN_ACTIONS = {
  list: 'admin_list',
  send: 'admin_send'
};

/* ===== Utils ===== */
function showErr(msg){
  const el = document.getElementById('err');
  el.textContent = msg || 'Something went wrong.';
  el.style.display = 'block';
  setTimeout(()=>{ el.style.display='none'; }, 3500);
}
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
function setActiveThread(id){
  const box = document.getElementById('threads');
  [...box.children].forEach(el => el.classList.toggle('active', Number(el.dataset.id) === Number(id)));
}

/* Always include cookies for PHP session */
async function apiGET(url){
  const r = await fetch(url, { credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'} });
  if (!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}
async function apiPOST(fd){
  const r = await fetch('chat_api.php', { method:'POST', body: fd, credentials:'same-origin' });
  if (!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}

/* ===== Threads ===== */
async function loadThreads(){
  try{
    const q = document.getElementById('q').value.trim();
    theStatus = document.getElementById('status').value;
    const url = 'chat_api.php?action='+encodeURIComponent(ADMIN_ACTIONS.list)+'&status='+
      encodeURIComponent(theStatus)+'&q='+encodeURIComponent(q);
    const j = await apiGET(url);

    const box = document.getElementById('threads');
    box.innerHTML='';

    if(!j.ok){ box.textContent=j.msg||'Error'; return; }

    (j.threads||[]).forEach(t=>{
      const party = (t.party || '').toLowerCase(); // 'worker' | 'user' | ''
      const el = document.createElement('div');
      el.className='thread';
      el.dataset.id = t.id;

      el.innerHTML = `
        <div class="row" style="justify-content:space-between">
          <div class="name">${escapeHtml(t.name||'Unknown')}</div>
          <div class="row" style="gap:6px">
            ${party ? `<span class="tag role ${escapeHtml(party)}">${escapeHtml(party.toUpperCase())}</span>` : ''}
          </div>
        </div>
        <div class="meta">${escapeHtml(t.email||'')}</div>
        <div class="meta">${t.last_at ? new Date(String(t.last_at).replace(' ','T')).toLocaleString() : ''}</div>`;

      el.onclick = ()=>openThread(t);
      if (Number(t.id) === Number(THREAD_ID)) el.classList.add('active');
      box.appendChild(el);
    });
  }catch(e){
    showErr('Failed to load threads.');
  }
}

/* ===== Open & Poll ===== */
async function openThread(t){
  THREAD_ID = t.id; LAST_ID=0;
  document.getElementById('messages').innerHTML='';
  setActiveThread(THREAD_ID);
  poll(true);
}

async function poll(immediate=false){
  if(!THREAD_ID) return;
  try{
    const url = 'chat_api.php?action=fetch&thread_id='+encodeURIComponent(THREAD_ID)+'&since_id='+encodeURIComponent(LAST_ID);
    const j = await apiGET(url);
    if(j.ok) appendMessages(j.messages||[]);
  }catch(e){ /* silent */ }
  clearTimeout(POLL);
  POLL = setTimeout(poll, immediate ? 1200 : 1800);
}

/* ===== Render messages as chat bubbles ===== */
function appendMessages(list){
  if(!list || !list.length) return;
  const box = document.getElementById('messages');

  for (const m of list){
    LAST_ID = Math.max(LAST_ID, Number(m.id||0));

    // Determine side: admin -> right, others -> left
    const mine = (m.sender === 'admin');

    const row = document.createElement('div');
    row.className = 'msg ' + (mine ? 'me' : 'them');

    const wrap = document.createElement('div');
    wrap.className = 'wrap';

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = m.body || '';

    const meta = document.createElement('div');
    meta.className = 'meta-line';
    meta.textContent = m.created_at ? new Date(String(m.created_at).replace(' ','T')).toLocaleString() : '';

    wrap.appendChild(bubble);
    wrap.appendChild(meta);
    row.appendChild(wrap);
    box.appendChild(row);
  }

  // stick to bottom like real chats
  box.scrollTop = box.scrollHeight;
}

/* ===== Send (with admin_send fallback to legacy send) ===== */
async function send(){
  if(!THREAD_ID) return;
  const input = document.getElementById('msgInput');
  const txt = input.value.trim();
  if(!txt) return;

  // optimistic echo (feel snappier)
  echoMyMessage(txt);

  try{
    let j = await trySendWithAction(ADMIN_ACTIONS.send, THREAD_ID, txt);
    if (!j.ok) j = await trySendWithAction('send', THREAD_ID, txt);
    if (!j.ok) throw new Error(j.msg || 'Send failed');

    input.value='';
    await poll(true); // fetch canonical message (id/timestamp) from server
  }catch(e){
    showErr(e.message || 'Failed to send message.');
  }finally{
    input.value='';
    input.focus();
  }
}

function echoMyMessage(text){
  const box = document.getElementById('messages');
  const row = document.createElement('div');
  row.className = 'msg me';

  const wrap = document.createElement('div');
  wrap.className = 'wrap';

  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  bubble.textContent = text;

  const meta = document.createElement('div');
  meta.className = 'meta-line';
  meta.textContent = new Date().toLocaleString();

  wrap.appendChild(bubble);
  wrap.appendChild(meta);
  row.appendChild(wrap);
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}

async function trySendWithAction(action, threadId, body){
  const fd = new FormData();
  fd.append('action', action);
  fd.append('thread_id', threadId);
  fd.append('body', body);
  try { 
    const r = await apiPOST(fd); 
    return r;
  } catch(e){ 
    return { ok:false, msg:String(e) }; 
  }
}

/* ===== Enter to send ===== */
document.getElementById('msgInput').addEventListener('keydown', (ev)=>{
  if (ev.key === 'Enter' && !ev.shiftKey) {
    ev.preventDefault();
    send();
  }
});

/* ===== Init ===== */
loadThreads();
</script>
</body>
</html>
