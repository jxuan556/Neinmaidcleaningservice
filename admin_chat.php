<?php
// admin_chat.php — Minimal admin support console (fixed send; no fancy UI)
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Support Console – Admin (Minimal)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    /* Minimal, readable only */
    body { font-family: system-ui, Arial, sans-serif; margin: 12px; }
    .layout { display: grid; grid-template-columns: 300px 1fr; gap: 10px; }
    .box { border: 1px solid #ccc; padding: 8px; }
    .threads { height: 70vh; overflow: auto; }
    .thread { padding: 6px; border-bottom: 1px solid #eee; cursor: pointer; }
    .thread.active { background: #f3f4f6; }
    .messages { height: 60vh; overflow: auto; }
    .msg { margin: 8px 0; }
    .meta { font-size: 12px; color: #666; }
    .row { display: flex; gap: 6px; align-items: center; margin-bottom: 8px; }
    .input { width: 100%; }
    .tag { font-size: 12px; padding: 2px 6px; border: 1px solid #ccc; border-radius: 10px; }
    .error { color: #b91c1c; margin-bottom: 8px; }
  </style>
</head>
<body>
  <h2>Support Console – Admin</h2>

  <div id="err" class="error" style="display:none"></div>

  <div class="row">
    <input id="q" class="input" placeholder="Search name/email…">
    <select id="status">
      <option value="open">Open</option>
      <option value="closed">Closed</option>
    </select>
    <button id="btnSearch" onclick="loadThreads()">Search</button>
    <a href="admin_dashboard.php">Dashboard</a>
  </div>

  <div class="layout">
    <div class="box threads" id="threads" aria-label="Conversation threads"></div>

    <div class="box">
      <div class="row" style="justify-content:space-between">
        <div id="who">Select a thread</div>
        <div>
          <span id="state" class="tag">—</span>
          <button onclick="setStatus('open')">Open</button>
          <button onclick="setStatus('closed')">Close</button>
        </div>
      </div>

      <div id="messages" class="messages" aria-live="polite"></div>

      <div class="row">
        <input id="msgInput" class="input" placeholder="Type a reply…">
        <button id="btnSend" onclick="send()">Send</button>
      </div>
    </div>
  </div>

<script>
/* ===== State ===== */
let THREAD_ID = 0;
let LAST_ID = 0;
let POLL = null;
const ADMIN_ACTIONS = { list: 'admin_list', send: 'admin_send', setStatus: 'admin_set_status' };

/* ===== Utils ===== */
function showErr(msg){
  const el = document.getElementById('err');
  el.textContent = msg || 'Something went wrong.';
  el.style.display = 'block';
  setTimeout(()=>{ el.style.display='none'; }, 3500);
}
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
function setActive(id){
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
    const status = document.getElementById('status').value;
    const url = 'chat_api.php?action='+encodeURIComponent(ADMIN_ACTIONS.list)+'&status='+encodeURIComponent(status)+'&q='+encodeURIComponent(q);
    const j = await apiGET(url);
    const box = document.getElementById('threads');
    box.innerHTML='';
    if(!j.ok){ box.textContent=j.msg||'Error'; return; }
    (j.threads||[]).forEach(t=>{
      const el = document.createElement('div');
      el.className='thread';
      el.dataset.id = t.id;
      el.innerHTML = `
        <div><strong>${escapeHtml(t.name||'Unknown')}</strong> <span class="tag">${escapeHtml(t.status||'')}</span></div>
        <div>${escapeHtml(t.email||'')}</div>
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
  document.getElementById('who').textContent = `${t.name||'Unknown'} ${t.email?('· '+t.email):''}`;
  document.getElementById('state').textContent = t.status || '';
  document.getElementById('messages').innerHTML='';
  setActive(THREAD_ID);
  poll(true);
}

async function poll(immediate=false){
  if(!THREAD_ID) return;
  try{
    const url = 'chat_api.php?action=fetch&thread_id='+encodeURIComponent(THREAD_ID)+'&since_id='+encodeURIComponent(LAST_ID);
    const j = await apiGET(url);
    if(j.ok) appendMessages(j.messages||[]);
  }catch(e){ /* silent retry */ }
  clearTimeout(POLL);
  POLL = setTimeout(poll, immediate ? 1200 : 1800);
}

function appendMessages(list){
  if(!list || !list.length) return;
  const box = document.getElementById('messages');
  for (const m of list){
    LAST_ID = Math.max(LAST_ID, Number(m.id||0));
    const div = document.createElement('div');
    div.className = 'msg';
    const who = m.sender === 'admin' ? 'You' : 'User';
    const ts = m.created_at ? new Date(String(m.created_at).replace(' ','T')).toLocaleString() : '';
    div.innerHTML = `<div><strong>${who}:</strong> ${escapeHtml(m.body||'')}</div><div class="meta">${ts}</div>`;
    box.appendChild(div);
  }
  box.scrollTop = box.scrollHeight;
}

/* ===== Send (fixed) ===== */
async function send(){
  if(!THREAD_ID) return;
  const input = document.getElementById('msgInput');
  const txt = input.value.trim();
  if(!txt) return;

  document.getElementById('btnSend').disabled = true;

  try{
    // Prefer admin_send, fallback to legacy 'send'
    let j = await trySendWithAction(ADMIN_ACTIONS.send, THREAD_ID, txt);
    if (!j.ok) j = await trySendWithAction('send', THREAD_ID, txt);
    if (!j.ok) throw new Error(j.msg || 'Send failed');

    input.value='';
    await poll(true); // fetch the canonical message from server
  }catch(e){
    showErr(e.message || 'Failed to send message.');
  }finally{
    document.getElementById('btnSend').disabled = false;
    input.focus();
  }
}

async function trySendWithAction(action, threadId, body){
  const fd = new FormData();
  fd.append('action', action);
  fd.append('thread_id', threadId);
  fd.append('body', body);
  try { return await apiPOST(fd); }
  catch(e){ return { ok:false, msg:String(e) }; }
}

/* ===== Status (fixed) ===== */
async function setStatus(s){
  if(!THREAD_ID) return;
  try{
    // Prefer admin_set_status, fallback to legacy set_status
    let fd = new FormData();
    fd.append('action', ADMIN_ACTIONS.setStatus);
    fd.append('thread_id', THREAD_ID);
    fd.append('status', s);
    let j = await apiPOST(fd);
    if(!j.ok){
      fd = new FormData();
      fd.append('action', 'set_status');
      fd.append('thread_id', THREAD_ID);
      fd.append('status', s);
      j = await apiPOST(fd);
    }
    if(!j.ok) throw new Error(j.msg || 'Update failed');

    document.getElementById('state').textContent = s;
    await loadThreads();
  }catch(e){
    showErr(e.message || 'Failed to update status.');
  }
}

/* ===== Enter to send ===== */
document.getElementById('msgInput').addEventListener('keydown', (ev)=>{
  if (ev.key === 'Enter' && !ev.shiftKey) {
    ev.preventDefault();
    document.getElementById('btnSend').click();
  }
});

/* ===== Init ===== */
loadThreads();
</script>
</body>
</html>


