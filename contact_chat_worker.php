<?php
// contact_chat_worker.php — booking-scoped chat (USER THEME)
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$role       = $_SESSION['role'] ?? 'user';
$isWorker   = ($role === 'worker');
$uid        = (int)$_SESSION['user_id'];
$username   = $_SESSION['name'] ?? 'You';

$workerId   = isset($_GET['worker_id'])  ? (int)$_GET['worker_id']  : 0;
$bookingId  = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      /* User theme tokens */
      --bg:#f6f7fb;
      --panel:#ffffff;
      --ink:#0f172a;
      --muted:#64748b;
      --line:#e5e7eb;
      --brand:#0ea5e9;
      --brand-600:#0284c7;
      --success:#10b981;
      --radius:14px;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0; background:var(--bg); color:var(--ink);
      font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;
    }

    /* Top brand bar (user pages style) */
    .topbar{
      background:#0f172a; color:#fff; padding:12px 16px;
      display:flex; align-items:center; justify-content:space-between;
    }
    .brand{display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.2px}
    .brand img{width:28px; height:28px; border-radius:6px; background:#fff; padding:2px}
    .links{display:flex; gap:12px; flex-wrap:wrap}
    .links a{color:#a7f3d0; text-decoration:none; font-weight:600}
    .links a:hover{text-decoration:underline}

    /* Main container */
    .wrap{max-width:960px; margin:18px auto; padding:0 12px}
    .card{background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); padding:14px}
    .row{display:flex; align-items:center; gap:10px; flex-wrap:wrap}
    .row-sb{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}

    /* Pills & buttons */
    .pill{display:inline-block; padding:6px 10px; border:1px solid var(--line); border-radius:999px; background:#f8fafc; color:#334155; font-size:12px; font-weight:600}
    .btn{appearance:none; border:1px solid var(--line); background:#fff; color:var(--ink); border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center}
    .btn:hover{background:#f8fafc}
    .btn.brand{background:var(--brand); border-color:var(--brand); color:#fff}
    .btn.brand:hover{background:var(--brand-600)}
    .btn.link{border:none; background:transparent; padding:0; color:var(--brand); font-weight:700; text-decoration:none}
    .btn.link:hover{text-decoration:underline}

    /* Chat area */
    .chat{margin-top:10px}
    .box{background:#fff; border:1px solid var(--line); border-radius:var(--radius); min-height:480px; display:flex; flex-direction:column}
    .messages{padding:14px; overflow:auto; max-height:62vh}
    .msg{margin:10px 0; display:flex}
    .msg .bubble{padding:10px 12px; border-radius:12px; max-width:74%; word-wrap:break-word; white-space:pre-wrap; border:1px solid var(--line)}
    .me{justify-content:flex-end}
    .me .bubble{background:#eff6ff; border-color:#dbeafe}      /* light blue for me (user theme) */
    .them .bubble{background:#f8fafc}
    .meta{font-size:11px; color:#6b7280; margin-top:4px}
    .sendbar{display:flex; gap:8px; border-top:1px solid var(--line); padding:10px}
    .input{flex:1; height:42px; border:1px solid var(--line); border-radius:10px; padding:0 12px; font-size:14px}

    /* Responsive */
    @media (max-width:640px){
      .messages{max-height:56vh}
    }
  </style>
</head>
<body>

  <!-- User theme top bar -->
  <div class="topbar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      <span>NeinMaid</span>
    </div>
  </div>


  <main class="wrap">
    <div class="card">
      <div class="row-sb">
        <h2 style="margin:0">
          <?= $isWorker ? 'Chat with your customer' : 'Chat with your cleaner' ?>
        </h2>
        <div class="row">
          <span id="threadStatus" class="pill">Connecting…</span>
          <?php if ($bookingRef): ?>
            <span class="pill">Booking: <?= htmlspecialchars($bookingRef, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="chat">
        <div class="box">
          <div id="messages" class="messages" aria-live="polite"></div>
          <div class="sendbar">
            <input id="body" class="input" placeholder="Type your message…" maxlength="1000" />
            <button id="sendBtn" class="btn brand">Send</button>
          </div>
        </div>
      </div>

      <div class="row" style="margin-top:10px">
        <?php if ($isWorker): ?>
          <a class="btn link" href="worker_dashboard.php">← Back to Worker Dashboard</a>
        <?php else: ?>
          <a class="btn link" href="user_dashboard.php">← Back to Dashboard</a>
          <a class="btn link" href="booking_success.php">My bookings</a>
        <?php endif; ?>
      </div>
    </div>
  </main>

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
  let j=null; try{ j = await r.json(); }catch{}
  if(!j || !j.ok){
    document.getElementById('threadStatus').textContent = 'Error';
    alert((j && j.msg) || 'Unable to start chat.');
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
    const mine = m.sender === 'me'; // API returns 'me'/'them'
    const row = document.createElement('div');
    row.className = 'msg ' + (mine?'me':'them');

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = m.body;

    const meta = document.createElement('div');
    meta.className = 'meta';
    const when = (m.created_at || '').replace(' ', 'T');
    meta.textContent = when ? new Date(when).toLocaleString() : '';

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
  let j=null; try{ j = await r.json(); }catch{}
  if(!j || !j.ok){ alert((j && j.msg) || 'Failed to send'); return; }
  await poll();
}

document.getElementById('sendBtn').addEventListener('click', send);
document.getElementById('body').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); send(); } });

ensureThread();
</script>
</body>
</html>

