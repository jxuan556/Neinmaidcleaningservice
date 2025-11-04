<?php
// faq.php — NeinMaid FAQ Page
session_start();
$isAuth = isset($_SESSION['user_id']);
$name   = $isAuth ? ($_SESSION['name'] ?? "Guest") : "Guest";
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>FAQs – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <style>
    :root{
      --ink:#0f172a; --muted:#6b7280; --line:#e5e7eb; --brand:#b91c1c; --bg:#f8fafc; --card:#fff;
      --radius:14px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg)}
    a{text-decoration:none;color:var(--brand)}
    .header{background:#fff;border-bottom:1px solid var(--line)}
    .nav{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:10px;justify-content:space-between;padding:10px 12px}
    .brand{display:flex;align-items:center;gap:8px}
    .brand img{width:28px;height:28px}
    .brand-name{font-weight:900;letter-spacing:.5px}
    .navlinks{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .nav-btn,.btn{border-radius:10px;padding:8px 12px;border:1px solid var(--line);background:#fff;cursor:pointer}
    .btn-primary{background:var(--brand);border-color:var(--brand);color:#fff}
    .wrap{max-width:1000px;margin:20px auto;padding:0 12px}
    h1{margin:0 0 8px}
    .muted{color:var(--muted)}
    .faq-list{display:grid;gap:10px;margin-top:18px}
    details{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:12px;transition:all .3s}
    summary{font-weight:700;cursor:pointer;outline:none}
    details[open]{border-color:var(--brand);box-shadow:0 2px 6px rgba(0,0,0,.06)}
    details[open] summary{color:var(--brand)}
    details div{margin-top:8px;color:var(--muted);line-height:1.7;font-size:15px}
    .help{margin-top:28px;background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:16px;text-align:center}
    .help h3{margin:0 0 6px}
    .btn-group{display:flex;justify-content:center;gap:8px;margin-top:10px;flex-wrap:wrap}
  </style>
</head>
<body>
  <!-- Header -->
  <header class="header">
    <nav class="nav">
      <div class="brand">
        <img src="maid.png" alt="NeinMaid logo">
        <div class="brand-name">NeinMaid</div>
      </div>
      <div class="navlinks">
        <a class="nav-btn" href="user_dashboard.php">Home</a>
        <a class="nav-btn" href="book.php">Book</a>
        <a class="nav-btn" href="booking_success.php">Bookings</a>
        <a class="nav-btn" href="careers.php">Careers</a>
        <a class="nav-btn" href="contact_chat.php">Messages</a>
        <a class="nav-btn" href="user_profile.php">Profile</a>
        <?php if ($isAuth): ?>
          <span style="font-size:13px;color:#6b7280;">Hi, <?= h($name) ?></span>
          <a class="btn" href="logout.php">Log out</a>
        <?php else: ?>
          <a class="btn" href="login.php">Log in</a>
        <?php endif; ?>
      </div>
    </nav>
  </header>

  <!-- Main content -->
  <main class="wrap">
    <h1>Frequently Asked Questions</h1>
    <p class="muted">Find answers to the most common questions about our booking, services, and payments.</p>

    <div class="faq-list">
      <details>
        <summary>🧹 What cleaning services do you offer?</summary>
        <div>We provide standard house cleaning, deep cleaning, office cleaning, and move-in/out cleaning. Custom cleaning plans are also available on request.</div>
      </details>

      <details>
        <summary>📅 How do I book a cleaning session?</summary>
        <div>Click “Book” on the navigation bar or visit <a href="book.php">book.php</a>. Fill in your area, date, and time slot, and we’ll confirm your cleaner shortly.</div>
      </details>

      <details>
        <summary>⏰ Can I reschedule or cancel my booking?</summary>
        <div>Yes. Go to <a href="booking_success.php">My Bookings</a> and select the booking you want to reschedule or cancel. Please give at least 24 hours’ notice.</div>
      </details>

      <details>
        <summary>💳 What payment methods do you accept?</summary>
        <div>We support secure online payments via Stripe (credit/debit cards). Cash is accepted only for specific areas — please check your confirmation email.</div>
      </details>

      <details>
        <summary>👩‍🔧 Are your cleaners trained and verified?</summary>
        <div>Yes. All cleaners go through background checks and training to ensure quality and safety before joining NeinMaid.</div>
      </details>

      <details>
        <summary>🏠 Which areas do you cover?</summary>
        <div>We currently operate in Penang: Georgetown, Jelutong, Tanjung Tokong, Bayan Lepas, Air Itam, Gelugor, and selected mainland locations.</div>
      </details>

      <details>
        <summary>💰 How do promotions or promo codes work?</summary>
        <div>Active promotions appear automatically on your dashboard when you log in. You can also apply them manually on the booking page.</div>
      </details>

      <details>
        <summary>💼 I want to work as a cleaner. How do I apply?</summary>
        <div>Visit our <a href="careers.php">Careers page</a> to learn more and fill in the worker application form directly online.</div>
      </details>
    </div>

    <!-- Help / contact section -->
    <div class="help">
      <h3>Still need help?</h3>
      <p class="muted">You can message our support team directly or check your chat inbox for updates.</p>
      <div class="btn-group">
        <a class="btn btn-primary" href="contact_chat.php">💬 Chat with Support</a>
        <a class="btn" href="mailto:info@neinmaid.com">📧 Email us</a>
      </div>
    </div>
  </main>
</body>
</html>
