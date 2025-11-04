<?php
// careers.php — NeinMaid Careers (redirect to worker registration)
session_start();
$isAuth = isset($_SESSION['user_id']);
$name   = $isAuth ? ($_SESSION['name'] ?? "Guest") : "Guest";
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Careers – Join NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="description" content="Join NeinMaid as a cleaner in Penang. Flexible hours, weekly payouts, fair jobs. Apply online in minutes." />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <style>
    /* ===== Global theme (matches user_dashboard) ===== */
    :root{
      --bg:#f8fafc; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
      --brand:#b91c1c; --brand-2:#ef4444; --ink-2:#111827;
      --radius:14px;
      --shadow:0 10px 30px rgba(2,6,23,.08);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
    a{color:inherit;text-decoration:none}
    img{display:block;max-width:100%}

    /* ===== Navbar ===== */
    .header{
      position:sticky; top:0; z-index:1000;
      background:rgba(255,255,255,.9);
      backdrop-filter:saturate(150%) blur(8px);
      border-bottom:1px solid var(--line);
      transition: box-shadow .2s ease, border-color .2s ease, background .2s ease;
    }
    .header.scrolled{ background:#fff; box-shadow:var(--shadow); border-color:#e2e8f0; }
    .nav-wrap{
      max-width:1200px;margin:0 auto;padding:10px 16px;
      display:grid; grid-template-columns:auto 1fr auto;
      align-items:center; gap:10px;
    }
    @media (min-width:901px){
      .nav-wrap{ column-gap:28px; }
      .brand{ padding-right:14px; }
      .nav-center{ padding-left:16px; border-left:1px solid var(--line); }
    }
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:30px;height:30px}
    .brand-name{font-weight:900;letter-spacing:.4px}
    .nav-center{display:flex;align-items:center;justify-content:center;gap:10px}
    .nav-link{padding:8px 12px;border-radius:10px;border:1px solid transparent}
    .nav-link:hover{background:#fff;border-color:var(--line)}
    .nav-search{
      margin-left:10px; display:flex; align-items:center; gap:6px;
      background:#fff;border:1px solid var(--line); border-radius:999px; padding:6px 10px; min-width:220px;
      box-shadow:0 2px 8px rgba(15,23,42,.03);
    }
    .nav-search input{border:none;outline:none;background:transparent;width:100%;font-size:14px;color:#111}
    .nav-search button{background:none;border:0;cursor:pointer;font-size:16px}
    .nav-right{display:flex;justify-content:flex-end;align-items:center;gap:8px}
    .btn,.nav-btn{border-radius:10px;padding:9px 12px;border:1px solid var(--line);background:#fff;cursor:pointer}
    .btn-primary{background:var(--brand);color:#fff;border-color:var(--brand)}
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

    /* ===== Page layout ===== */
    .wrap{max-width:1100px;margin:18px auto;padding:0 12px}
    .hero{
      display:grid;grid-template-columns:1.1fr .9fr;gap:18px;align-items:center;
      background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px
    }
    @media (max-width:920px){ .hero{grid-template-columns:1fr} }
    .eyebrow{color:var(--brand);font-weight:900;letter-spacing:.4px}
    h1{margin:.2em 0}
    .muted{color:var(--muted);font-size:14px}
    .apply-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:14px}
    .card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:14px}
    .card h3{margin:0 0 6px}
    .pill{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:6px 10px;margin:4px 6px 0 0;background:#f8fafc;font-size:13px}
    .section-title{margin:18px 0 10px;font-size:20px}
    .jobs .job{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px;display:grid;gap:6px}
    .faq details{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px}
    .faq details+details{margin-top:8px}
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
        <a class="nav-link" href="user_dashboard.php#services">Services</a>
        <a class="nav-link" href="user_dashboard.php#pricing">Pricing</a>
        <a class="nav-link" href="booking_success.php">Booking</a>
        <a class="nav-link active" href="careers.php">Careers</a>
        <a class="nav-link" href="contact_chat.php">Messages</a>
        <a class="nav-link" href="user_profile.php">Profile</a>
        <div class="nav-search" role="search">
          <button class="search-btn" aria-label="Search" onclick="dashSearch()">🔍</button>
          <input type="text" id="navSearch" placeholder="Search services..." onkeydown="if(event.key==='Enter') dashSearch()"/>
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
        <div class="mobile-search">
          🔍
          <input type="text" id="mNavSearch" placeholder="Search services..."
                 onkeydown="if(event.key==='Enter') dashSearch(true)"
                 style="border:0;outline:0;width:100%;background:transparent">
        </div>
        <div class="mobile-links">
          <a class="nav-btn" href="user_dashboard.php#services" onclick="toggleMenu()">Services</a>
          <a class="nav-btn" href="user_dashboard.php#pricing" onclick="toggleMenu()">Pricing</a>
          <a class="nav-btn" href="booking_success.php" onclick="toggleMenu()">Booking</a>
          <a class="nav-btn" href="careers.php" onclick="toggleMenu()">Careers</a>
          <a class="nav-btn" href="contact_chat.php" onclick="toggleMenu()">Messages</a>
          <a class="nav-btn" href="user_profile.php" onclick="toggleMenu()">Profile</a>
        </div>
        <div class="mobile-actions">
          <button class="btn btn-primary" onclick="location.href='book.php'">Book Now</button>
          <?php if ($isAuth): ?>
            <a class="btn" href="logout.php">Log out</a>
          <?php else: ?>
            <a class="btn" href="login.php">Log in</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- ===== Content ===== -->
  <main class="wrap">
    <!-- Hero -->
    <section class="hero" aria-labelledby="heroTitle">
      <div>
        <div class="eyebrow">CAREERS @ NEINMAID</div>
        <h1 id="heroTitle">Join our cleaner team in Penang</h1>
        <p class="muted">Flexible hours, weekly payouts, and fair jobs near you. We welcome experienced cleaners and newcomers—we’ll train you on our standards.</p>
        <div class="apply-actions">
          <button class="btn btn-primary" onclick="location.href='create_worker_account.php'">Register Now</button>
          <a class="btn" href="#openings">See openings</a>
        </div>
      </div>
      <div>
        <div class="card">
          <h3>Why work with us?</h3>
          <ul class="muted" style="line-height:1.9;margin:8px 0 0 18px">
            <li>Transparent pay with weekly payouts</li>
            <li>Choose when you want to work</li>
            <li>We assign jobs near your service areas</li>
            <li>Simple mobile scheduling & support</li>
          </ul>
          <div style="margin-top:8px">
            <span class="pill">Weekly payout</span>
            <span class="pill">Flexible hours</span>
            <span class="pill">Supportive team</span>
            <span class="pill">Tools stipend*</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Highlights -->
    <section style="margin-top:16px">
      <div class="grid-3">
        <div class="card">
          <h3>Pay & Incentives</h3>
          <div class="muted">Competitive hourly rates, travel fee top-ups, and performance bonuses.</div>
        </div>
        <div class="card">
          <h3>Training & Standards</h3>
          <div class="muted">Clear checklists, quick training for deep clean & move-in/out jobs.</div>
        </div>
        <div class="card">
          <h3>Safety First</h3>
          <div class="muted">We prioritize safety, provide guidance on PPE and safe practices.</div>
        </div>
      </div>
    </section>

    <!-- Openings -->
    <section id="openings" style="margin-top:18px">
      <h2 class="section-title">Current openings</h2>
      <div class="jobs grid-3">
        <div class="job">
          <strong>Standard House Cleaner (Part-time)</strong>
          <div class="muted">Penang island & mainland • Flexible schedule</div>
          <div class="muted">Responsibilities: routine cleaning, kitchens, bathrooms, floors, dusting.</div>
        </div>
        <div class="job">
          <strong>Deep Clean Specialist</strong>
          <div class="muted">Penang island • Prefer prior experience</div>
          <div class="muted">Responsibilities: deep cleaning, post-reno, move in/out; attention to detail.</div>
        </div>
        <div class="job">
          <strong>Office Cleaning Crew</strong>
          <div class="muted">Weekday evenings • Team shifts</div>
          <div class="muted">Responsibilities: office areas, shared spaces, safe chemical use.</div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="faq" style="margin-top:18px">
      <h2 class="section-title">FAQs</h2>
      <details>
        <summary><strong>Do I need experience?</strong></summary>
        <div class="muted" style="margin-top:6px">Experience helps, but not required. We provide basic training.</div>
      </details>
      <details>
        <summary><strong>What are the requirements?</strong></summary>
        <div class="muted" style="margin-top:6px">You must be 18+ and legally allowed to work in Malaysia. A smartphone, punctuality, and good attitude are important.</div>
      </details>
      <details>
        <summary><strong>How do payouts work?</strong></summary>
        <div class="muted" style="margin-top:6px">Bank transfers weekly. Make sure your bank details are correct in your profile.</div>
      </details>
    </section>
  </main>

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
  </script>
</body>
</html>
