<?php
/**
 * booking_success.php — NeinMaid
 * Lists a user's bookings grouped by status and lets them rate completed ones.
 */
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

date_default_timezone_set('Asia/Kuala_Lumpur');
$TZ = new DateTimeZone('Asia/Kuala_Lumpur');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost", "root", "", "maid_system");
$conn->set_charset('utf8mb4');

$isAuth = isset($_SESSION['user_id']);
$name   = $isAuth ? ($_SESSION['name'] ?? "Guest") : "Guest";

$userId    = (int)($_SESSION['user_id'] ?? 0);
$scrollRef = $_GET['ref'] ?? '';

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

/* ===== Fetch bookings for this user ===== */
$sql = "SELECT * FROM bookings WHERE user_id=? ORDER BY date DESC, id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$all) { header("Location: book.php"); exit(); }

/* ===== Fetch reviews (JOIN through bookings because booking_ratings has no user_id) ===== */
$reviewsByBooking = [];
$q = $conn->prepare("
  SELECT br.booking_id, br.stars AS rating, br.comment AS comments
  FROM booking_ratings br
  JOIN bookings b ON b.id = br.booking_id
  WHERE b.user_id = ?
");
$q->bind_param("i", $userId);
$q->execute();
$r = $q->get_result();
while ($x = $r->fetch_assoc()) $reviewsByBooking[(int)$x['booking_id']] = $x;
$q->close();

/* ===== Date helpers ===== */
function parse_booking_date(?string $ymd, DateTimeZone $tz): ?DateTimeImmutable {
  if (!$ymd) return null;
  $ymd = substr($ymd, 0, 10);
  if ($ymd === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return null;
  return DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $tz) ?: null;
}
function is_past_day(?DateTimeImmutable $job, DateTimeZone $tz): bool {
  if (!$job) return false;
  $today  = (new DateTimeImmutable('today', $tz))->setTime(0,0,0);
  $jobDay = $job->setTime(0,0,0);
  return $jobDay < $today;
}

/* ===== Buckets ===== */
function bucket_for(array $b, DateTimeZone $tz): string {
  $statusRaw = strtolower(trim($b['status'] ?? 'pending'));
  $jobDate   = parse_booking_date($b['date'] ?? null, $tz);

  $isCancelled = in_array($statusRaw, ['cancelled','canceled'], true);
  $isAssigned  = in_array($statusRaw, ['approved','assigned','confirmed'], true);
  $isCompleted = in_array($statusRaw, ['completed','done','finished'], true);
  $isPast      = is_past_day($jobDate, $tz);

  if ($isCancelled) return 'cancelled';
  if ($isCompleted || $isPast) return 'past';
  if ($isAssigned)  return 'assigned';
  return 'pending';
}
function tag_class(string $bucket): string {
  return [
    'pending'  => 'tag tag--pending',
    'assigned' => 'tag tag--approved',
    'cancelled'=> 'tag tag--cancelled',
    'past'     => 'tag tag--past'
  ][$bucket] ?? 'tag';
}
function bucket_title(string $b): string {
  return [
    'pending'  => 'Upcoming — Pending',
    'assigned' => 'Upcoming — Assigned',
    'cancelled'=> 'Cancelled',
    'past'     => 'Completed'
  ][$b] ?? ucfirst($b);
}

/* ===== Chat window rule ===== */
function chat_window_open(?DateTimeImmutable $job, DateTimeZone $tz): bool {
  if (!$job) return false;
  $start = $job->setTime(0,0,0);
  $now = new DateTimeImmutable('now', $tz);
  $openFrom = $start->sub(new DateInterval('P1D'));
  $closeAt  = $start->add(new DateInterval('P1D'));
  return ($now >= $openFrom) && ($now < $closeAt);
}

/* ===== Partition ===== */
$buckets = ['pending'=>[], 'assigned'=>[], 'cancelled'=>[], 'past'=>[]];
foreach ($all as $b) $buckets[bucket_for($b,$TZ)][] = $b;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Bookings – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* ===== Shared user theme (matches user_dashboard) ===== */
:root{
  --bg:#f8fafc; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
  --brand:#b91c1c; --brand-2:#ef4444; --ink-2:#111827; --shadow:0 10px 30px rgba(2,6,23,.08);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink)}
a{color:inherit;text-decoration:none}
img{display:block;max-width:100%}

/* Navbar (identical to user_dashboard) */
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
@media (min-width:901px){
  .nav-wrap{ column-gap:28px; }
  .brand{ padding-right:14px; }
  .nav-center{ padding-left:16px; border-left:1px solid var(--line); }
}
@media (max-width:900px){ .nav-center{display:none} .hamburger{display:inline-flex} }

/* Footer (identical to user_dashboard) */
.footer{background:#0f172a;color:#e5e7eb;margin-top:20px}
.footgrid{
  max-width:1100px;margin:0 auto;
  display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:16px;padding:16px 12px
}
.copyright{
  max-width:1100px;margin:0 auto;color:#94a3b8;
  display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;
  padding:10px 12px;border-top:1px solid #1f2937
}

/* ===== Page styles (bookings list) ===== */
.wrap{max-width:980px;margin:20px auto;padding:0 12px}
.bucket{margin-bottom:24px}
.bucket h2{font-size:20px;font-weight:800;margin:10px 0}
.card-bk{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;margin-bottom:10px}
.row{display:flex;justify-content:space-between;margin:6px 0}
.hr{border:0;border-top:1px dashed #e5e7eb;margin:10px 0}
.tag{padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb}
.tag--pending{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
.tag--approved{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}
.tag--cancelled{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.tag--past{background:#eef2ff;color:#3730a3;border-color:#c7d2fe}
.total{color:#b91c1c;font-weight:700}
.muted{color:#6b7280;font-size:12px}
.btn-ghost{background:#fff;color:#111;border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px;text-decoration:none;cursor:pointer}
.note{max-width:70%;white-space:normal}

/* Reschedule modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.42);display:none;align-items:center;justify-content:center;z-index:60}
.modal{width:min(520px,92vw);background:#fff;border:1px solid #ffe2e2;border-radius:14px;box-shadow:0 10px 28px rgba(221,111,111,.12);padding:18px}
.modal header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.modal h3{margin:0}
.modal .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px}
.modal-backdrop.show{display:flex}
.input{width:100%;border:1px solid #e5e7eb;border-radius:12px;padding:10px;font:inherit}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.notice{border-radius:12px;padding:10px 12px;margin:10px 0;font-weight:700}
.notice--ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.notice--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
</style>
</head>
<body>

<!-- ===== Navbar (exact copy from user_dashboard) ===== -->
<header id="siteHeader" class="header">
  <div class="nav-wrap" aria-label="Primary">
    <!-- Left: brand -->
    <div class="brand">
      <img src="maid.png" alt="NeinMaid logo">
      <div class="brand-name">NeinMaid</div>
    </div>

    <!-- Center: links + search -->
    <div class="nav-center">
      <!-- Keep these links identical to user_dashboard -->
      <a class="nav-link" href="user_dashboard.php">Home</a>
      <a class="nav-link" href="careers.php">Careers</a>
      <a class="nav-link" href="contact_chat.php">Messages</a>
      <a class="nav-link" href="user_profile.php">Profile</a>
      <div class="nav-search" role="search">
        <button class="search-btn" aria-label="Search" onclick="dashSearch()">
          🔍
        </button>
        <input type="text" id="navSearch" placeholder="Search services..."
               onkeydown="if(event.key==='Enter') dashSearch()"/>
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

  <!-- Mobile panel -->
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

<div class="wrap">
<?php foreach (['pending','assigned','cancelled','past'] as $bucket):
  $list = $buckets[$bucket]; if (!$list) continue; ?>
  <div class="bucket" id="sect-<?=h($bucket)?>">
    <h2><?=h(bucket_title($bucket))?> <span class="muted">(<?=count($list)?>)</span></h2>

    <?php foreach ($list as $b):
      $ref      = h($b['ref_code'] ?? '#'.$b['id']);
      $tag      = tag_class($bucket);
      $jobDate  = parse_booking_date($b['date'] ?? null, $TZ);
      $dateNice = $jobDate ? $jobDate->format('d M Y') : '—';
      $review   = $reviewsByBooking[(int)$b['id']] ?? null;
      $chatAllowed = ($bucket!=='past' && !empty($b['assigned_worker_id']) && chat_window_open($jobDate,$TZ));

      $cdRaw = (string)($b['custom_details'] ?? '');
      if ($cdRaw !== '') {
        $cdRaw = html_entity_decode($cdRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cdRaw = preg_replace('/(?:\r\n|\r|\n){2}\[Property\][\s\S]*$/u', '', $cdRaw);
      }
      $cdPretty = trim($cdRaw) !== '' ? nl2br(h($cdRaw)) : '';

      $rawDate = substr((string)($b['date'] ?? ''), 0, 10);
    ?>
    <div class="card-bk" id="bk-<?=$ref?>">
      <div class="row" style="align-items:center">
        <div><strong>Ref:</strong> <?=$ref?></div>
        <div class="<?=$tag?>"><?=ucfirst($bucket)?></div>
      </div>

      <div class="hr"></div>
      <div class="row"><span>Service</span><strong><?=h($b['service'])?></strong></div>
      <div class="row"><span>Area</span><strong><?=h($b['area'])?></strong></div>
      <div class="row"><span>Date</span><strong><?=$dateNice?></strong></div>
      <div class="row"><span>Time</span><strong><?=h($b['time_slot'])?></strong></div>

      <?php if ($cdPretty !== ''): ?>
        <div class="hr"></div>
        <div class="row"><span>Notes</span><div class="note"><?=$cdPretty?></div></div>
      <?php endif; ?>

      <div class="hr"></div>
      <div class="row"><span>Travel fee</span><strong><?=money_rm($b['travel_fee'] ?? 0)?></strong></div>
      <div class="row"><span>Estimated total</span><strong class="total"><?=money_rm($b['estimated_price'] ?? 0)?></strong></div>
      <?php if(!empty($b['final_paid_amount'])):?>
        <div class="row"><span>Paid amount</span><strong><?=money_rm($b['final_paid_amount'])?></strong></div>
      <?php endif;?>
      <?php if(!empty($b['payment_status'])):?>
        <div class="row muted"><span>Payment</span><span><?=h($b['payment_status'])?><?=!empty($b['payment_ref'])?' · '.h($b['payment_ref']):''?></span></div>
      <?php endif;?>

      <?php if($bucket==='assigned'):?>
        <div class="hr"></div>
        <div class="row"><span>Cleaner</span><strong><?=h($b['assigned_worker_name'] ?? '—')?></strong></div>
      <?php endif;?>

      <?php if($chatAllowed):?>
        <div class="hr"></div>
        <a class="btn" href="contact_chat_worker.php?worker_id=<?=(int)$b['assigned_worker_id']?>&booking_id=<?=(int)$b['id']?>&booking_ref=<?=urlencode($ref)?>">
          💬 Chat with cleaner
        </a>
      <?php endif;?>

      <?php if($bucket==='past'):?>
        <div class="hr"></div>
        <?php if($review):?>
          <div class="muted">You rated <?=$review['rating']?>★ — <?=nl2br(h($review['comments']))?></div>
        <?php else:?>
          <form method="post" action="review_submit.php">
            <input type="hidden" name="booking_id" value="<?=$b['id']?>">
            <input type="hidden" name="ref" value="<?=$ref?>">
            <label>Rating:</label><br>
            <?php for($i=5;$i>=1;$i--):?>
              <label style="margin-right:6px"><input type="radio" name="rating" value="<?=$i?>" required> <?=str_repeat('★',$i)?></label>
            <?php endfor;?>
            <textarea name="comments" rows="2" style="width:100%;margin-top:4px" placeholder="Comments..."></textarea>
            <button class="btn" style="margin-top:6px">Submit Review</button>
          </form>
        <?php endif;?>
      <?php endif;?>

      <div class="hr"></div>
      <div class="row" style="justify-content:flex-end;gap:8px">
        <?php if ($bucket !== 'past' && $bucket !== 'cancelled'): ?>
          <button
            class="btn"
            type="button"
            data-reschedule-btn
            data-booking-id="<?=(int)$b['id']?>"
            data-current-date="<?=h($rawDate)?>"
            data-current-time=""
          >Reschedule</button>
        <?php endif; ?>

        <a class="btn-ghost" href="book.php">Book again</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>

<!-- Footer (exact copy from user_dashboard) -->
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
      <div><a href="contact_chat.php">Support</a></div>
      <div><a href="faq.php">FAQs</a></div>
    </div>
  </div>
  <div class="copyright">
    <div>© <?= date('Y') ?> NeinMaid • All rights reserved</div>
    <div>Made with ❤️ in Penang</div>
  </div>
</footer>

<!-- Reschedule modal -->
<div id="reschedBack" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="reschedTitle">
    <header>
      <h3 id="reschedTitle">Reschedule Booking</h3>
      <button class="btn-ghost" id="reschedClose" aria-label="Close">✕</button>
    </header>

    <form id="reschedForm">
      <input type="hidden" name="booking_id" id="reschedBookingId">
      <div class="grid-2">
        <div>
          <label for="reschedDate"><strong>New date</strong></label>
          <input class="input" type="date" name="date" id="reschedDate" required>
        </div>
        <div>
          <label for="reschedTime"><strong>New time</strong></label>
          <input class="input" type="time" name="time" id="reschedTime" required step="1800">
        </div>
      </div>

      <div style="margin-top:10px;">
        <label for="reschedNote"><strong>Note (optional)</strong></label>
        <textarea class="input" name="note" id="reschedNote" rows="3" placeholder="e.g., Need to shift due to work timing"></textarea>
      </div>

      <div id="reschedMsg" class="notice notice--err" style="display:none;"></div>

      <div class="actions">
        <button type="button" class="btn-ghost" id="reschedCancel">Cancel</button>
        <button type="submit" class="btn">Confirm Reschedule</button>
      </div>
    </form>
  </div>
</div>

<?php if ($scrollRef): ?>
<script>
  const el = document.getElementById("bk=<?=h($scrollRef)?>");
  if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
</script>
<?php endif; ?>

<script>
/* ===== Navbar behavior + dashboard search redirect (same as user_dashboard) ===== */
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

/* ===== Reschedule modal + 30-minute enforcement ===== */
(function(){
  const back   = document.getElementById('reschedBack');
  const closeB = document.getElementById('reschedClose');
  const cancel = document.getElementById('reschedCancel');
  const form   = document.getElementById('reschedForm');
  const msg    = document.getElementById('reschedMsg');

  const idInp  = document.getElementById('reschedBookingId');
  const dateInp= document.getElementById('reschedDate');
  const timeInp= document.getElementById('reschedTime');
  const noteInp= document.getElementById('reschedNote');

  function openModal(payload){
    idInp.value   = payload.id;
    dateInp.value = payload.date || '';
    timeInp.value = payload.time || '';
    noteInp.value = '';
    msg.style.display = 'none';
    back.classList.add('show');
    back.setAttribute('aria-hidden', 'false');
  }
  function closeModal(){
    back.classList.remove('show');
    back.setAttribute('aria-hidden', 'true');
  }

  document.querySelectorAll('[data-reschedule-btn]').forEach(btn=>{
    btn.addEventListener('click',()=>{
      openModal({
        id:   btn.dataset.bookingId,
        date: btn.dataset.currentDate,
        time: btn.dataset.currentTime
      });
    });
  });

  [closeB, cancel].forEach(el=> el.addEventListener('click', closeModal));
  back.addEventListener('click', (e)=>{ if (e.target === back) closeModal(); });

  function snapToHalfHour(value){
    if(!value) return value;
    const [hStr, mStr='0'] = value.split(':');
    let h = Number(hStr), m = Number(mStr);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return value;
    const mm = m < 15 ? '00' : (m < 45 ? '30' : '00');
    const hh = (m >= 45 ? (h + 1) % 24 : h);
    return String(hh).padStart(2,'0') + ':' + mm;
  }
  timeInp.addEventListener('blur', ()=>{ timeInp.value = snapToHalfHour(timeInp.value); });

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    msg.style.display = 'none';

    const t = (timeInp.value || '').trim();
    const parts = t.split(':');
    const mm = parts[1] || '';
    if (!['00','30'].includes(mm)) {
      msg.textContent = 'Please choose a time on the half hour (e.g., 09:00 or 09:30).';
      msg.className = 'notice notice--err';
      msg.style.display = 'block';
      timeInp.focus();
      return;
    }

    const fd = new FormData(form);
    try {
      const res = await fetch('reschedule_booking.php', { method:'POST', body: fd, credentials:'same-origin' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        msg.textContent = data.error || 'Unable to reschedule.';
        msg.className = 'notice notice--err';
        msg.style.display = 'block';
        return;
      }
      msg.textContent = 'Rescheduled successfully!';
      msg.className = 'notice notice--ok';
      msg.style.display = 'block';
      setTimeout(()=>{ window.location.reload(); }, 600);
    } catch(err){
      msg.textContent = 'Network error. Please try again.';
      msg.className = 'notice notice--err';
      msg.style.display = 'block';
    }
  });
})();
</script>
</body>
</html>
