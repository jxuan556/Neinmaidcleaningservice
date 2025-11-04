<?php
// contact_chat_worker.php — booking-scoped chat for user & worker
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$role     = $_SESSION['role'] ?? 'user';
$isWorker = ($role === 'worker');
$uid      = (int)$_SESSION['user_id'];
$username = $_SESSION['name'] ?? ($isWorker ? 'Worker' : 'You');

$workerId   = isset($_GET['worker_id'])  ? (int)$_GET['worker_id']  : 0;
$bookingId  = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;   // safer than ref for DB join
$bookingRef = isset($_GET['booking_ref'])? trim($_GET['booking_ref']) : '';

if ($workerId <= 0 || $bookingId <= 0) {
  http_response_code(400);
  echo "Missing worker_id or booking_id."; exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $isWorker ? 'Chat with your customer – NeinMaid' : 'Chat with your cleaner – NeinMaid' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="foundation.css">
  <style>
    body{background:#f7f7fb}
    .chat-wrap{max-width:820px;margin:18px auto;padding:0 12px}
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
  </style>
</head>
<body>
  <div class="chat-wrap">
    <div class="brand brand--left" style="margin-bottom:8px">
      <img class="brand-mark" src="maid.png" alt="NeinMaid">
      <span class="word">NEINMAID</span>
    </div>

    <div class="card" style="padding:14px">
      <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h2 class="title" style="margin:0">
          <?= $isWorker ? 'Chat with your customer' : 'Chat with your cleaner' ?>
        </h2>
        <div>
          <span class="tag" id="threadStatus">Connecting…</span>
          <?php if ($bookingRef): ?>
            <span class="tag">Booking: <?= htmlspecialchars($bookingRef, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="chat-box" style="margin-top:10px">
        <div id="messages" class="messages" aria-live="polite"></div>
        <div class="sendbar">
          <input id="body" class="input" placeholder="Type your message…" maxlength="1000" />
          <button id="sendBtn" class="btn-cta">Send</button>
        </div>
      </div>

      <div class="footer-links" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($isWorker): ?>
          <a href="worker_dashboard.php">Dashboard</a>
        <?php else: ?>
          <a href="user_dashboard.php">Dashboard</a>
          <a href="booking_success.php">My bookings</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

<script>
const USERNAME   = <?= json_encode($username) ?>;
const WORKER_ID  = <?= (int)$workerId ?>;
const BOOKING_ID = <?= (int)$bookingId ?>;

let THREAD_ID = 0;
let LAST_ID   = 0;
let POLL      = null;

async function ensureThread(){
  const qs = new URLSearchParams({ action:'ensure_thread', worker_id:WORKER_ID, booking_id:BOOKING_ID });
  const r = await fetch('chat_api_worker.php?'+qs.toString(), {cache:'no-store'});
  const j = await r.json();
  if(!j.ok){
    document.getElementById('threadStatus').textContent = 'Error';
    alert(j.msg||'Unable to start chat.');
    return;
  }
  THREAD_ID = j.thread_id;
  document.getElementById('threadStatus').textContent = 'Open';
  poll();
}

async function poll(){
  if(!THREAD_ID) return;
  try{
    const qs = new URLSearchParams({ action:'fetch', thread_id:String(THREAD_ID), since_id:String(LAST_ID) });
    const r = await fetch('chat_api_worker.php?'+qs.toString(), { cache:'no-store' });
    const j = await r.json();
    if(j.ok){ appendMessages(j.messages || []); }
  }catch(e){}
  clearTimeout(POLL);
  POLL = setTimeout(poll, 2500);
}

function appendMessages(list){
  const box = document.getElementById('messages');
  if(!list.length) return;
  for(const m of list){
    LAST_ID = Math.max(LAST_ID, Number(m.id||0));
    const mine = m.sender === 'me'; // API already maps to me/them
    const row = document.createElement('div');
    row.className = 'msg ' + (mine?'me':'them');

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = m.body;

    const meta = document.createElement('div');
    meta.className = 'meta';
    const when = (m.created_at || '').replace(' ', 'T');
    meta.textContent = new Date(when).toLocaleString();

    const wrap = document.createElement('div');
    wrap.appendChild(bubble); wrap.appendChild(meta);
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
  fd.append('thread_id', String(THREAD_ID));
  fd.append('body', txt);

  const r = await fetch('chat_api_worker.php', { method:'POST', body: fd });
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

