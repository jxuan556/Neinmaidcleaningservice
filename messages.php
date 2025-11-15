<?php
// messages.php — user-facing chat with worker-style chatbox (uses chat_api.php)
session_start();
$isAuth = !empty($_SESSION['user_id']);
$name   = $isAuth ? ($_SESSION['name'] ?? 'You') : 'Guest';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Messages – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">

  <style>
    /* ===== User Shell (navbar + footer identical to careers/user_dashboard) ===== */
    :root{
      --bg:#f8fafc; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
      --brand:#b91c1c; --brand-2:#ef4444; --ink-2:#111827;
      --radius:14px; --shadow:0 10px 30px rgba(2,6,23,.08);
      /* Chat (worker style) */
      --me:#10b981;           /* mint green bubble */
      --me-ink:#ffffff;
      --them:#eef2f7;         /* light neutral bubble */
      --them-ink:#0f172a;
      --send:#10b981;         /* Send button = mint, Book button stays brand */
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--ink)}
    a{color:inherit;text-decoration:none}

    /* ===== Navbar (same as careers.php) ===== */
    .header{position:sticky;top:0;z-index:1000;background:rgba(255,255,255,.9);
      backdrop-filter:saturate(150%) blur(8px);border-bottom:1px solid var(--line);
      transition: box-shadow .2s ease, border-color .2s ease, background .2s ease;}
    .header.scrolled{ background:#fff; box-shadow:var(--shadow); border-color:#e2e8f0; }
    .nav-wrap{max-width:1200px;margin:0 auto;padding:10px 16px;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px}
    @media (min-width:901px){ .nav-wrap{column-gap:28px}.brand{padding-right:14px}.nav-center{padding-left:16px;border-left:1px solid var(--line)} }
    .brand{display:flex;align-items:center;gap:10px}.brand img{width:30px;height:30px}.brand-name{font-weight:900;letter-spacing:.4px}
    .nav-center{display:flex;align-items:center;justify-content:center;gap:10px}
    .nav-link{padding:8px 12px;border-radius:10px;border:1px solid transparent}
    .nav-link:hover{background:#fff;border-color:var(--line)}
    .nav-search{margin-left:10px;display:flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 10px;min-width:220px;box-shadow:0 2px 8px rgba(15,23,42,.03)}
    .nav-search input{border:none;outline:none;background:transparent;width:100%;font-size:14px;color:#111}
    .nav-search button{background:none;border:0;cursor:pointer;font-size:16px}
    .nav-right{display:flex;justify-content:flex-end;align-items:center;gap:8px}
    .btn,.nav-btn{border-radius:10px;padding:9px 12px;border:1px solid var(--line);background:#fff;cursor:pointer}
    .btn-primary{background:var(--brand);color:#fff;border-color:var(--brand)} /* Book button color preserved */
    .hi{font-size:13px;color:#6b7280;margin:0 6px}
    .hamburger{display:none;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 10px;cursor:pointer}
    .mobile-panel{display:none;border-top:1px solid var(--line);background:#fff}
    .mobile-panel.open{display:block;animation:drop .18s ease}
    @keyframes drop{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
    .mobile-inner{padding:10px 16px;display:grid;gap:10px}
    .mobile-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .mobile-actions{display:flex;gap:8px;flex-wrap:wrap}
    .mobile-search{display:flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:12px;padding:8px 10px}
    @media (max-width:900px){ .nav-center{display:none} .hamburger{display:inline-flex} }

    /* ===== Page ===== */
    .wrap{max-width:1100px;margin:18px auto;padding:0 12px}

    /* ===== Chat card (worker-style) ===== */
    .chat-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 8px 24px rgba(2,6,23,.05);display:grid;grid-template-rows:auto 1fr auto;min-height:62vh}
    .chat-head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}
    .chat-title{font-weight:800}
    .status{font-size:12px;color:var(--muted)}
    .chat-body{padding:14px 16px;overflow:auto;background:linear-gradient(180deg,#fff 0%,#fafcff 100%)}
    .empty{color:var(--muted);text-align:center;margin-top:24px}

    .row{display:flex;flex-direction:column;gap:4px;margin:8px 0}
    .bubble{max-width:72%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}
    .me{margin-left:auto; background:var(--me); color:var(--me-ink); border-bottom-right-radius:6px}
    .them{margin-right:auto;background:var(--them); color:var(--them-ink); border-bottom-left-radius:6px}
    .meta{font-size:11px;color:#94a3b8}
    .chat-foot{padding:12px;border-top:1px solid var(--line);display:grid;grid-template-columns:1fr auto;gap:10px}
    textarea{resize:none;height:58px;padding:10px;border:1px solid var(--line);border-radius:12px;outline:none;background:#fff}
    button{border:1px solid var(--line);background:#fff;border-radius:12px;padding:10px 16px;cursor:pointer}
    .primary{background:var(--send);color:#fff;border-color:var(--send)}
    .note{color:#b91c1c;background:#fef2f2;border:1px solid #fee2e2;border-radius:10px;padding:10px;margin:12px 0}

    /* ===== Footer (unchanged) ===== */
    .footer{background:#0f172a;color:#e5e7eb;margin-top:20px}
    .footgrid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:16px 12px}
    .copyright{max-width:1100px;margin:0 auto;color:#94a3b8;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #1f2937}
  </style>
</head>
<body>

  <!-- ===== Navbar ===== -->
  <header id="siteHeader" class="header">
    <div class="nav-wrap" aria-label="Primary">
      <div class="brand" role="img" aria-label="NeinMaid">
        <img src="maid.png" alt="NeinMaid logo">
        <div class="brand-name">NeinMaid</div>
      </div>

      <div class="nav-center">
        <a class="nav-link" href="user_dashboard.php">Home</a>
        <a class="nav-link" href="booking_success.php">Booking</a>
        <a class="nav-link" href="careers.php">Careers</a>
        <a class="nav-link" href="user_profile.php">Profile</a>
        <div class="nav-search" role="search">
          <button aria-label="Search" onclick="dashSearch()">🔍</button>
          <input type="text" id="navSearch" placeholder="Search services..." onkeydown="if(event.key==='Enter') dashSearch()" />
        </div>
      </div>

      <div class="nav-right">
        <button class="btn btn-primary" onclick="location.href='book.php'">Book</button>
        <?php if ($isAuth): ?>
          <span class="hi">Hi, <?= h($name) ?></span>
          <a class="btn" href="logout.php">Log out</a>
        <?php else: ?>
          <a class="btn" href="login.php">Log in</a>
        <?php endif; ?>
        <button class="hamburger" aria-label="Toggle menu" onclick="toggleMenu()">☰</button>
      </div>
    </div>

    <div id="mobilePanel" class="mobile-panel" aria-label="Mobile menu">
      <div class="mobile-inner">
        <div class="mobile-search">🔍
          <input type="text" id="mNavSearch" placeholder="Search services..." onkeydown="if(event.key==='Enter') dashSearch(true)" style="border:0;outline:0;width:100%;background:transparent">
        </div>
        <div class="mobile-links">
          <a class="nav-btn" href="user_dashboard.php#services" onclick="toggleMenu()">Services</a>
          <a class="nav-btn" href="user_dashboard.php#pricing" onclick="toggleMenu()">Pricing</a>
          <a class="nav-btn" href="booking_success.php" onclick="toggleMenu()">Booking</a>
          <a class="nav-btn" href="careers.php" onclick="toggleMenu()">Careers</a>
          <a class="nav-btn" href="messages.php" onclick="toggleMenu()">Messages</a>
          <a class="nav-btn" href="user_profile.php" onclick="toggleMenu()">Profile</a>
        </div>
        <div class="mobile-actions">
          <button class="btn btn-primary" onclick="location.href='book.php'">Book Now</button>
          <?php if ($isAuth): ?><a class="btn" href="logout.php">Log out</a><?php else: ?><a class="btn" href="login.php">Log in</a><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- ===== Content ===== -->
  <main class="wrap">
    <h2 style="margin:10px 0 12px">Support Messages</h2>

    <?php if (!$isAuth): ?>
      <div class="note">You must <a href="login.php"><strong>log in</strong></a> to message support.</div>
    <?php endif; ?>

    <section class="chat-card" aria-live="polite">
      <div class="chat-head">
        <div class="chat-title">NeinMaid Support</div>
        <div id="status" class="status">Connecting…</div>
      </div>

      <div id="messages" class="chat-body">
        <div class="empty" id="emptyHint">No messages yet. Say hi! 👋</div>
      </div>

      <div class="chat-foot">
        <textarea id="input" placeholder="Type your message..." <?php echo $isAuth?'':'disabled'; ?>></textarea>
        <button id="sendBtn" class="primary" <?php echo $isAuth?'':'disabled'; ?>>Send</button>
      </div>
    </section>
  </main>

  <!-- ===== Footer (unchanged) ===== -->
  <footer class="footer">
    <div class="footgrid">
      <div>
        <div style="font-weight:800;font-size:18px;margin-bottom:8px">NeinMaid</div>
        <div style="color:#d1d5db">Professional maid & cleaning services in Penang.</div>
        <div style="margin-top:10px;color:#d1d5db">Currently serving Penang only.</div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:8px">Company</div>
        <div><a href="about.php">About</a></div>
        <div><a href="careers.php">Careers</a></div>
        <div><a href="contact_chat.php">Contact</a></div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:8px">Services</div>
        <div><a href="user_dashboard.php#services">House Cleaning</a></div>
        <div><a href="user_dashboard.php#services">Deep Cleaning</a></div>
        <div><a href="user_dashboard.php#services">Office Cleaning</a></div>
        <div><a href="user_dashboard.php#services">Move In/Out</a></div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:8px">Help</div>
        <div><a href="messages.php">Support</a></div>
        <div><a href="faq.php">FAQs</a></div>
      </div>
    </div>
    <div class="copyright">
      <div>© <?= date('Y') ?> NeinMaid • All rights reserved</div>
      <div>Made with ❤️ in Penang</div>
    </div>
  </footer>

  <script>
    /* Navbar behavior + dashboard search redirect */
    const header = document.getElementById('siteHeader');
    const mobilePanel = document.getElementById('mobilePanel');
    function toggleMenu(){ mobilePanel.classList.toggle('open'); }
    window.addEventListener('scroll', () => {
      if (window.scrollY > 4) header.classList.add('scrolled');
      else header.classList.remove('scrolled');
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 900) mobilePanel.classList.remove('open');
    });
    function dashSearch(isMobile=false){
      const q = (isMobile ? document.getElementById('mNavSearch') : document.getElementById('navSearch')).value.trim();
      const url = 'user_dashboard.php' + (q ? ('?q='+encodeURIComponent(q)) : '') + '#services';
      window.location.href = url;
    }

    /* ===== Chat logic (same API as before) ===== */
    const isAuth = <?php echo $isAuth ? 'true' : 'false'; ?>;
    const messagesEl = document.getElementById('messages');
    const emptyHint  = document.getElementById('emptyHint');
    const statusEl   = document.getElementById('status');
    const inputEl    = document.getElementById('input');
    const sendBtn    = document.getElementById('sendBtn');

    let threadId = 0;
    let sinceId  = 0;
    let pollTimer = null;

    function el(tag, cls, text){
      const e = document.createElement(tag);
      if (cls) e.className = cls;
      if (text != null) e.textContent = text;
      return e;
    }
    function addMessage(m){
      if (emptyHint) emptyHint.style.display = 'none';
      const mine = (m.sender === 'user');
      const row  = el('div','row');
      const bubble = el('div','bubble ' + (mine ? 'me' : 'them'));
      bubble.textContent = m.body || '';
      const meta = el('div','meta', (mine ? 'You' : 'Support') + ' • #' + m.id);
      row.appendChild(bubble); row.appendChild(meta);
      messagesEl.appendChild(row);
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    async function api(url, opts){
      const r = await fetch(url, {credentials:'same-origin', ...opts});
      try{ return await r.json(); }catch{ return {ok:false}; }
    }

    async function ensureThread(){
      statusEl.textContent = 'Connecting…';
      const j = await api('chat_api.php?action=ensure_thread');
      if (!j.ok) { statusEl.textContent = 'Failed to start'; return; }
      threadId = j.thread_id;
      statusEl.textContent = 'Connected';
    }

    async function fetchMessages(){
      if (!threadId) return;
      const u = new URL('chat_api.php', location.href);
      u.searchParams.set('action','fetch');
      u.searchParams.set('thread_id', threadId);
      if (sinceId) u.searchParams.set('since_id', sinceId);
      const j = await api(u.toString());
      if (!j.ok) return;
      const list = j.messages || [];
      if (list.length){
        list.forEach(m => { addMessage(m); sinceId = Math.max(sinceId, m.id); });
      }
    }

    async function sendMessage(){
      const body = (inputEl.value || '').trim();
      if (!body || !threadId) return;
      sendBtn.disabled = true;
      try{
        const form = new FormData();
        form.set('action','send');
        form.set('thread_id', threadId);
        form.set('body', body);
        const j = await api('chat_api.php', {method:'POST', body: form});
        if (j.ok){
          inputEl.value = '';
          fetchMessages();
        }
      } finally {
        sendBtn.disabled = false;
        inputEl.focus();
      }
    }

    async function start(){
      if (!isAuth) { statusEl.textContent = 'Please log in'; return; }
      try{
        await ensureThread();
        await fetchMessages();
        pollTimer = setInterval(fetchMessages, 3000);
      } catch(e){
        statusEl.textContent = 'Connection error';
      }
    }

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', (e)=>{ if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); sendMessage(); }});
    start();
  </script>
</body>
</html>
