<?php
// user_dashboard.php — NeinMaid
session_start();

// Optional login: show "Guest" if not logged in
$isAuth = isset($_SESSION['user_id']);
$name   = $isAuth ? ($_SESSION['name'] ?? "Guest") : "Guest";

/* ---------- DB ---------- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* ---------- Small helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Announcements (optional helper) ---------- */
$ann = [];
try {
  if (file_exists(__DIR__.'/announcements_store.php')) {
    require_once __DIR__.'/announcements_store.php';
    if (function_exists('ann_list')) $ann = ann_list(5); // latest 5 banners
  }
} catch (Throwable $e) { /* no-op */ }

/* ---------- Dynamic Services ---------- */
$services = [];
try {
  $sql = "SELECT svc_key, title, blurb, image_url, booking_map
          FROM services
          WHERE is_active=1
          ORDER BY created_at DESC";
  if ($res = $conn->query($sql)) {
    while($row = $res->fetch_assoc()) $services[] = $row;
    $res->close();
  }
} catch (Throwable $e) { /* no-op */ }

/* ---------- Initial Promos snapshot from promo_codes (first paint only) ---------- */
$promos = [];
try{
  $psql = "SELECT id, code, percent_off, min_spend, max_discount,
                  usage_limit, used_count, starts_at, ends_at, active,
                  COALESCE(updated_at, created_at) AS vtime
           FROM promo_codes
           WHERE active=1
             AND (starts_at IS NULL OR starts_at <= NOW())
             AND (ends_at   IS NULL OR ends_at   >= NOW())
           ORDER BY vtime DESC, created_at DESC
           LIMIT 12";
  if ($pr=$conn->query($psql)) {
    while($r=$pr->fetch_assoc()){
      $promos[] = [
        'id'             => (int)$r['id'],
        'code'           => $r['code'],
        'title'          => $r['code'],
        'message'        => '',
        'discount_type'  => 'percent',
        'discount_value' => round(((float)$r['percent_off'])*100, 2),
      ];
    }
    $pr->close();
  }
}catch(Throwable $e){ /* ignore */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>NeinMaid – Penang Maid & Cleaning Services</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="dashboard.css"><!-- keep your theme if you have one -->
  <style>
    /* ===== Base ===== */
    :root{
      --bg:#f8fafc; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
      --brand:#b91c1c; --brand-2:#ef4444; --ink-2:#111827; --ring:rgba(185,28,28,.25);
      --shadow:0 10px 30px rgba(2,6,23,.08);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
    a{color:inherit;text-decoration:none}
    img{display:block;max-width:100%}

    /* ===== New Navbar ===== */
    .header{
      position:sticky; top:0; z-index:1000;
      background:rgba(255,255,255,.9);
      backdrop-filter:saturate(150%) blur(8px);
      border-bottom:1px solid var(--line);
      transition: box-shadow .2s ease, border-color .2s ease, background .2s ease;
    }
    .header.scrolled{
      background:#fff;
      box-shadow: var(--shadow);
      border-color:#e2e8f0;
    }
.nav-wrap{
  max-width:1200px;margin:0 auto;padding:10px 16px;
  display:grid;
  grid-template-columns:auto 1fr auto; /* was 1fr 2fr 1fr */
  align-items:center;
  gap:10px; /* we’ll add spacing below */
}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:30px;height:30px}
    .brand-name{font-weight:900;letter-spacing:.4px}

    /* Center nav links */
    .nav-center{display:flex;align-items:center;justify-content:center;gap:10px}
    .nav-link{padding:8px 12px;border-radius:10px;border:1px solid transparent}
    .nav-link:hover{background:#fff;border-color:var(--line)}
    .nav-search{
      margin-left:10px; display:flex; align-items:center; gap:6px;
      background:#fff;border:1px solid var(--line); border-radius:999px; padding:6px 10px; min-width:220px;
      box-shadow:0 2px 8px rgba(15,23,42,.03);
    }
    .nav-search input{
      border:none;outline:none;background:transparent;width:100%;font-size:14px;color:#111;
    }
    .nav-search button{background:none;border:0;cursor:pointer;font-size:16px}
@media (min-width: 901px){
  .nav-wrap{ column-gap: 28px; }              /* more space between columns */
  .brand{ padding-right: 14px; }              /* pad brand side */
  .nav-center{
    padding-left: 16px;                        /* move links off the logo */
    border-left: 1px solid var(--line);        /* subtle divider */
  }
} 
    /* Right actions */
    .nav-right{display:flex;justify-content:flex-end;align-items:center;gap:8px}
    .btn,.nav-btn{
      border-radius:10px;padding:9px 12px;border:1px solid var(--line);background:#fff;cursor:pointer
    }
    .btn-primary{background:var(--brand);color:#fff;border-color:var(--brand)}
    .hi{font-size:13px;color:#6b7280;margin:0 6px}

    /* Mobile */
    .hamburger{display:none;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 10px;cursor:pointer}
    .mobile-panel{
      display:none; border-top:1px solid var(--line); background:#fff;
    }
    .mobile-panel.open{display:block; animation:drop .18s ease}
    @keyframes drop{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
    .mobile-inner{padding:10px 16px; display:grid; gap:10px}
    .mobile-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .mobile-actions{display:flex;gap:8px;flex-wrap:wrap}
    .mobile-search{display:flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:12px;padding:8px 10px}

    /* ===== Page Sections kept from your original ===== */
    .announcement{max-width:1100px;margin:8px auto;background:#fff;border:1px solid #fee2e2;border-left:4px solid #ef4444;padding:10px;border-radius:10px;color:#7f1d1d}
    .hero{background:#fff}
    .hero-inner{max-width:1100px;margin:12px auto;display:grid;grid-template-columns:1.2fr .8fr;gap:18px;align-items:center;padding:12px}
    .eyebrow{color:#ef4444;font-weight:800;letter-spacing:.4px}
    h1{margin:.2em 0}
    .accent{color:#b91c1c}
    .cta-row{display:flex;gap:10px;margin-top:8px}
    .hero-visual img{width:100%;border-radius:12px;border:1px solid var(--line)}
    .section{padding:14px 0}
    .container{max-width:1100px;margin:0 auto;padding:0 12px}
    .chips{display:flex;gap:8px;flex-wrap:wrap}
    .chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 12px;cursor:pointer;font-weight:700}
    .grid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;padding:0 12px}
    .card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
    .card img{width:100%;height:150px;object-fit:cover}
    .card-body{padding:12px}
    .mini{color:#64748b;font-size:13px}
    .actions{display:flex;justify-content:flex-end;margin-top:8px}
    .btn-sm{border-radius:8px;padding:6px 10px;border:1px solid var(--line);background:#fff;cursor:pointer}
    .btn-sm.primary{background:var(--brand);border-color:var(--brand);color:#fff}
    .steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
    .step{background:#fff;border:1px dashed #f3c0c0;border-radius:12px;padding:10px}
    .band{background:#fff}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    .pills{display:flex;gap:8px;flex-wrap:wrap}
    .pill{background:#f1f5f9;border:1px solid var(--line);border-radius:999px;padding:6px 10px;font-size:13px}
    .pricing{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
    .price{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px}
    .amt{font-size:22px;font-weight:900;margin-top:6px}
    .footer{background:#0f172a;color:#e5e7eb;margin-top:20px}
    .footgrid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:16px 12px}
    .copyright{max-width:1100px;margin:0 auto;color:#94a3b8;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #1f2937}

    /* Promo toast */
    #promoToast{position:fixed;right:16px;bottom:16px;z-index:9999;display:none}
    .toast{width:min(360px,92vw);background:#111827;color:#fff;border-radius:14px;box-shadow:0 14px 38px rgba(0,0,0,.35);padding:12px;border:1px solid #374151}
    .toast h4{margin:0 0 6px 0}
    .toast .sub{color:#cbd5e1;font-size:13px}
    .toast .code{margin-top:6px;background:#0b1220;border:1px dashed #334155;border-radius:8px;padding:6px 8px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
    .toast .row{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
    .toast .btn{background:#fff;border:0;color:#111827}
    .toast .btn-primary{background:var(--brand);color:#fff}

    /* Careers teaser (unchanged) */
    .career-wrap{background:#fff;border:1px solid var(--line);border-radius:14px;display:grid;grid-template-columns:1.2fr .8fr;gap:18px;align-items:center;padding:16px}
    .career-left{padding:8px}
    .career-right img{width:100%;height:auto;border-radius:12px;border:1px solid var(--line)}
    .badge{display:inline-block;background:#fef3f2;color:var(--brand);border:1px solid #fee2e2;border-radius:999px;padding:6px 10px;font-weight:800;font-size:12px;margin-bottom:8px}
    .career-title{margin:6px 0 4px;font-size:26px}
    .career-sub{color:#6b7280;margin:0 0 10px}
    .kpis{display:flex;gap:12px;flex-wrap:wrap;margin:10px 0}
    .kpi{background:#fff;border:1px dashed #f3c0c0;border-radius:12px;padding:10px 12px;min-width:120px;text-align:center}
    .kpi-num{font-weight:900}
    .kpi-label{color:#6b7280;font-size:12px}
    .career-benefits{margin:8px 0 0 18px;color:#374151;line-height:1.8}
    .career-ctas{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
    .quote{margin-top:14px;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:10px;color:#374151;font-style:italic}
    .quote-name{display:block;margin-top:6px;color:#6b7280;font-style:normal}

    @media (max-width: 1100px){
      .nav-wrap{grid-template-columns:1fr auto 1fr}
      .nav-center{gap:6px}
      .nav-search{min-width:180px}
    }
    @media (max-width: 900px){
      .nav-wrap{grid-template-columns:1fr auto 1fr}
      .nav-center{display:none}
      .hamburger{display:inline-flex}
      .hero-inner{grid-template-columns:1fr}
      .career-wrap{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <!-- ===== New Navbar Layout ===== -->
  <header id="siteHeader" class="header">
    <div class="nav-wrap" aria-label="Primary">
      <!-- Left: brand -->
      <div class="brand">
        <img src="maid.png" alt="NeinMaid logo">
        <div class="brand-name">NeinMaid</div>
      </div>

      <!-- Center: links + search (hidden on small screens) -->
      <div class="nav-center">
        <!-- <a class="nav-link" href="#services">Services</a>
        <a class="nav-link" href="#pricing">Pricing</a> -->
        <a class="nav-link" href="booking_success.php">Booking</a>
        <a class="nav-link" href="careers.php">Careers</a>
        <a class="nav-link" href="contact_chat.php">Messages</a>
        <a class="nav-link" href="user_profile.php">Profile</a>
        <div class="nav-search" role="search">
          <button class="search-btn" aria-label="Search">🔍</button>
          <input type="text" id="navSearch" placeholder="Search services..."
                 oninput="filterServices(this.value)" />
        </div>
      </div>

      <!-- Right: actions -->
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

    <!-- Mobile slide-down panel -->
    <div id="mobilePanel" class="mobile-panel" aria-label="Mobile menu">
      <div class="mobile-inner">
        <div class="mobile-search">
          🔍
          <input type="text" placeholder="Search services..."
                 oninput="filterServices(this.value)" style="border:0;outline:0;width:100%;background:transparent">
        </div>
        <div class="mobile-links">
          <a class="nav-btn" href="#services" onclick="toggleMenu()">Services</a>
          <a class="nav-btn" href="#pricing" onclick="toggleMenu()">Pricing</a>
          <a class="nav-btn" href="booking_success.php">Booking History</a>
          <a class="nav-btn" href="careers.php">Careers</a>
          <a class="nav-btn" href="contact_chat.php">Messages</a>
          <a class="nav-btn" href="user_profile.php">Profile</a>
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

  <!-- Announcements banner (if any) -->
  <?php if (!empty($ann)): foreach ($ann as $a): ?>
    <div class="announcement">
      <strong><?= h($a['title'] ?? '') ?>:</strong>
      <?= h($a['body'] ?? '') ?>
    </div>
  <?php endforeach; endif; ?>

  <section class="hero">
    <div class="hero-inner">
      <div>
        <div class="eyebrow">PENANG’S TRUSTED CLEANERS</div>
        <h1>Hi, <?= h($name); ?> 👋<br>Professional cleaning for <span class="accent">home & office</span></h1>
        <p>Vetted cleaners, flexible scheduling, and transparent pricing. We currently serve <strong>Penang only</strong> — Georgetown, Jelutong, Tanjung Tokong, Bayan Lepas and nearby areas.</p>
        <div class="cta-row">
          <button class="btn btn-primary" onclick="location.href='book.php'">Book Now</button>
          <button class="btn" onclick="document.getElementById('services').scrollIntoView({behavior:'smooth'})">View Services</button>
        </div>
      </div>
      <div class="hero-visual"><img src="maid-4.jpg" alt="Professional cleaning"></div>
    </div>
  </section>

  <!-- Quick services chips -->
  <section class="section" id="services">
    <div class="container">
      <div class="chips" id="chipRow" aria-label="Quick services">
        <div class="chip" onclick="goBook('Standard House Cleaning')">🏠 <strong>Standard House Cleaning</strong></div>
        <div class="chip" onclick="goBook('Office & Commercial Cleaning')">🏢 <strong>Office & Commercial</strong></div>
        <div class="chip" onclick="goBook('Spring / Deep Cleaning')">🧽 <strong>Spring / Deep Cleaning</strong></div>
        <div class="chip" onclick="goBook('Move In/Out Cleaning')">🚚 <strong>Move In/Out Cleaning</strong></div>
        <div class="chip" onclick="goBook('Custom Cleaning Plans')">🧹 <strong>Custom Cleaning Plans</strong></div>
      </div>
    </div>
  </section>

  <!-- Dynamic service cards -->
  <div class="grid" id="serviceCards">
    <?php if($services): foreach($services as $row): ?>
      <div class="card" data-key="<?= h($row['svc_key']) ?>" data-name="<?= h($row['title']) ?>">
        <img src="<?= h($row['image_url'] ?: 'maid-4.jpg') ?>" alt="<?= h($row['title']) ?>">
        <div class="card-body">
          <h4><?= h($row['title']) ?></h4>
          <div class="mini"><?= h($row['blurb']) ?></div>
          <div class="actions">
            <button class="btn-sm primary" onclick="goBook('<?= h($row['booking_map'] ?: $row['title']) ?>')">Book now →</button>
          </div>
        </div>
      </div>
    <?php endforeach; else: ?>
      <div class="mini" style="grid-column:1/-1;color:#64748b">No services yet. Please ask admin to add some.</div>
    <?php endif; ?>
  </div>

  <section class="section" id="how">
    <div class="container">
      <h2 style="margin:0 0 16px">How it works</h2>
      <div class="steps">
        <div class="step">🧾 <strong>1) Pick a service</strong><div class="mini">Choose standard, office, deep, move in/out, or a custom plan.</div></div>
        <div class="step">📍 <strong>2) Choose area & time</strong><div class="mini">We serve Penang only; select a date and a convenient slot.</div></div>
        <div class="step">🧹 <strong>3) We clean</strong><div class="mini">Trained, background-checked cleaners arrive fully equipped.</div></div>
        <div class="step">✅ <strong>4) Review</strong><div class="mini">Check our work and sign off — easy.</div></div>
      </div>
    </div>
  </section>

  <section class="section band">
    <div class="container">
      <div class="cols">
        <div>
          <h2 style="margin:0 0 10px">Areas we serve</h2>
          <p class="mini">Penang only — travel fee varies by area.</p>
          <div class="pills">
            <span class="pill">Georgetown</span><span class="pill">Jelutong</span><span class="pill">Tanjung Tokong</span>
            <span class="pill">Bayan Lepas</span><span class="pill">Air Itam</span><span class="pill">Gelugor</span>
            <span class="pill">Balik Pulau</span><span class="pill">Butterworth</span><span class="pill">Perai</span>
            <span class="pill">Bukit Mertajam</span><span class="pill">Nibong Tebal</span><span class="pill">Seberang Jaya</span>
          </div>
        </div>
        <div>
          <h2 style="margin:0 0 10px">Why choose NeinMaid?</h2>
          <ul class="mini" style="line-height:1.9">
            <li>Background-checked, trained cleaners</li>
            <li>Clear, transparent pricing with travel fees shown upfront</li>
            <li>Flexible scheduling and easy rescheduling</li>
            <li>Consistent quality with simple guarantees</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- Careers teaser -->
  <section class="section" id="careers">
    <div class="container">
      <div class="career-wrap">
        <div class="career-left">
          <div class="badge">We’re hiring cleaners in Penang</div>
          <h2 class="career-title">Earn flexibly with NeinMaid</h2>
          <p class="career-sub">
            Choose your schedule, get fair pay every week, and work with respectful, repeat customers.
            No marketing, no chasing payments — just show up and do great work.
          </p>

          <div class="kpis">
            <div class="kpi">
              <div class="kpi-num">RM20-40</div>
              <div class="kpi-label">per hour</div>
            </div>
            <div class="kpi">
              <div class="kpi-num">+ Travel</div>
              <div class="kpi-label">fee paid</div>
            </div>
            <div class="kpi">
              <div class="kpi-num">Weekly</div>
              <div class="kpi-label">payouts</div>
            </div>
          </div>

          <ul class="career-benefits">
            <li>Pick the areas and days you want</li>
            <li>Jobs sent to your phone — accept or pass</li>
            <li>Safety-first: verified customers & support</li>
            <li>Bonuses for 5★ reviews and reliability</li>
          </ul>

          <div class="career-ctas">
            <a class="btn btn-primary" href="careers.php">Learn more</a>
            <a class="btn" href="create_worker_account.php">Apply as Cleaner</a>
          </div>

          <div class="quote">
            “I can plan around my kids’ school time and still hit my weekly target. Payouts are on time.”
            <span class="quote-name">— Siti, NeinMaid Pro</span>
          </div>
        </div>

        <div class="career-right">
          <img src="career.jpg" alt="Work with NeinMaid">
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="pricing">
    <div class="container">
      <h2 style="margin:0 0 16px">Simple pricing (from)</h2>
      <div class="pricing">
        <div class="price"><h4>Home</h4><div class="mini">Standard House Cleaning</div><div class="amt">RM 40 / hr</div></div>
        <div class="price"><h4>Office</h4><div class="mini">Office & Commercial Cleaning</div><div class="amt">RM 45 / hr</div></div>
        <div class="price"><h4>Deep / Move</h4><div class="mini">Spring/Deep & Move In/Out</div><div class="amt">RM 50–55 / hr</div></div>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="footgrid">
      <div><div style="font-weight:800;font-size:18px;margin-bottom:8px">NeinMaid</div><div style="color:#d1d5db">Professional maid & cleaning services in Penang.</div><div style="margin-top:10px;color:#d1d5db">Currently serving Penang only.</div></div>
      <div><div style="font-weight:800;margin-bottom:8px">Company</div><div><a href="about.php">About</a></div><div><a href="careers.php">Careers</a></div><div><a href="contact_chat.php">Contact</a></div></div>
      <div><div style="font-weight:800;margin-bottom:8px">Services</div><div><a href="#services">House Cleaning</a></div><div><a href="#services">Deep Cleaning</a></div><div><a href="#services">Office Cleaning</a></div><div><a href="#services">Move In/Out</a></div></div>
      <div><div style="font-weight:800;margin-bottom:8px">Help</div><div><a href="contact_chat.php">Support</a></div><div><a href="faq.php">FAQs</a></div></div>
    </div>
    <div class="copyright"><div>© <?= date('Y') ?> NeinMaid • All rights reserved</div><div>Made with ❤️ in Penang</div></div>
  </footer>

  <!-- Promo toast (auto show / live updates) -->
  <div id="promoToast">
    <div class="toast">
      <h4 id="promoTitle">🎁 Limited-time Offer</h4>
      <div class="sub" id="promoMsg">Use the promo below on your next booking.</div>
      <div class="code"><span id="promoCode">CODE123</span> • <span id="promoValue">RM10 OFF</span></div>
      <div class="row">
        <button class="btn" onclick="dismissToast()">Dismiss</button>
        <button class="btn btn-primary" onclick="applyPromo()">Apply</button>
      </div>
    </div>
  </div>

  <script>
    /* ===== Navbar behavior ===== */
    const header = document.getElementById('siteHeader');
    const mobilePanel = document.getElementById('mobilePanel');
    function toggleMenu(){ mobilePanel.classList.toggle('open'); }
    window.addEventListener('scroll', () => {
      if (window.scrollY > 4) header.classList.add('scrolled');
      else header.classList.remove('scrolled');
    });
    // Close mobile menu when resizing to desktop
    window.addEventListener('resize', () => {
      if (window.innerWidth > 900) mobilePanel.classList.remove('open');
    });

    /* ---------- UI helpers (kept) ---------- */
    function goBook(service){
      window.location.href='book.php'+(service?('?service='+encodeURIComponent(service)):'' );
    }
    function filterServices(q){
      const query=(q||'').toLowerCase().trim();
      const cards=document.querySelectorAll('#serviceCards .card');
      cards.forEach(card=>{
        const name=(card.dataset.name||'').toLowerCase();
        const show=!query || name.includes(query);
        card.style.display=show?'':'none';
      });
      if(query) document.getElementById('services').scrollIntoView({behavior:'smooth'});
    }

    /* ================= Real-time promos from promo_codes (kept) ================= */
    let promosData = <?php echo json_encode($promos, JSON_UNESCAPED_UNICODE); ?> || [];
    let promosVersion = '';
    let promoIdx = 0;

    function valueText(p){
      const t = (p.discount_type||'percent').toLowerCase();
      const v = Number(p.discount_value||0);
      if (t==='percent') return v.toFixed(0)+'% OFF';
      return 'RM'+v.toFixed(2)+' OFF';
    }
    function renderToast(idx){
      if(!promosData || !promosData.length) return;
      const p = promosData[idx]; if(!p) return;
      document.getElementById('promoTitle').textContent = '🎁 ' + (p.title || p.code || 'Special Offer');
      document.getElementById('promoMsg').textContent   = (p.message || 'Use this promo code on your next booking.');
      document.getElementById('promoCode').textContent  = p.code || '';
      document.getElementById('promoValue').textContent = valueText(p);
      document.getElementById('promoToast').style.display = 'block';
    }
    function hideToast(){ document.getElementById('promoToast').style.display='none'; }
    function applyPromo(){
      const code = document.getElementById('promoCode').textContent.trim();
      if(code) window.location = 'book.php?promo=' + encodeURIComponent(code);
    }
    function dismissToast(){
      hideToast();
      localStorage.setItem('promo_dismiss_until', (Date.now()+3600*1000).toString());
      promoIdx++;
      if (promoIdx < promosData.length) setTimeout(()=>renderToast(promoIdx), 300);
    }
    async function fetchPromos(showIfChanged=true){
      try{
        const r = await fetch('promotions_feed.php', {cache:'no-store'});
        if(!r.ok) return;
        const j = await r.json();
        if(!j.ok) return;
        const newVersion = j.version || '';
        if (newVersion !== promosVersion) {
          promosVersion = newVersion;
          promosData = j.promos || [];
          promoIdx = 0;
          const until = +(localStorage.getItem('promo_dismiss_until')||0);
          const recentlyDismissed = Date.now() < until;
          if (promosData.length) {
            if (showIfChanged && !recentlyDismissed) renderToast(0);
          } else hideToast();
        }
      }catch(e){ /* ignore */ }
    }
    (function initPromos(){
      const until = +(localStorage.getItem('promo_dismiss_until')||0);
      if (promosData.length && Date.now() >= until) renderToast(0);
      fetchPromos(false);
      setInterval(fetchPromos, 8000);
    })();
  </script>
</body>
</html>
