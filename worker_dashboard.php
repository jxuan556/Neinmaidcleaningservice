<?php
session_start();

/* ===== Guard: only workers ===== */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'worker') {
  header("Location: login.php"); exit();
}

/* ===== DB ===== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$today  = date('Y-m-d');
$weekTo = date('Y-m-d', strtotime('+7 days'));

/* ===== Column exists (MariaDB-safe) ===== */
function col_exists(mysqli $conn, $table, $col){
  $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
  $dbRow = $conn->query("SELECT DATABASE()")->fetch_row();
  $db = $conn->real_escape_string($dbRow[0] ?? '');
  $t  = $conn->real_escape_string($table);
  $c  = $conn->real_escape_string((string)$col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}'
          LIMIT 1";
  $rs = $conn->query($sql);
  return ($rs && $rs->num_rows > 0);
}

/* Haversine distance (meters) */
function haversine_m($lat1,$lon1,$lat2,$lon2){
  if ($lat1===null||$lon1===null||$lat2===null||$lon2===null) return null;
  $R = 6371000.0;
  $phi1 = deg2rad((float)$lat1); $phi2 = deg2rad((float)$lat2);
  $dphi = deg2rad((float)$lat2-(float)$lat1);
  $dlam = deg2rad((float)$lon2-(float)$lon1);
  $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlam/2)**2;
  $c = 2*atan2(sqrt($a), sqrt(1-$a));
  return $R*$c;
}

/* ===== Current user info ===== */
$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['name']  ?? 'Worker';
$userMail = $_SESSION['email'] ?? '';

/* ===== Auto-migrate (safe adds) ===== */
try{
  if (!col_exists($conn,'bookings','lat'))            $conn->query("ALTER TABLE bookings ADD COLUMN lat DECIMAL(10,7) NULL");
  if (!col_exists($conn,'bookings','lng'))            $conn->query("ALTER TABLE bookings ADD COLUMN lng DECIMAL(10,7) NULL");
  if (!col_exists($conn,'bookings','arrived_at'))     $conn->query("ALTER TABLE bookings ADD COLUMN arrived_at DATETIME NULL");
  if (!col_exists($conn,'bookings','started_at'))     $conn->query("ALTER TABLE bookings ADD COLUMN started_at DATETIME NULL");
  if (!col_exists($conn,'bookings','finished_at'))    $conn->query("ALTER TABLE bookings ADD COLUMN finished_at DATETIME NULL");
  if (!col_exists($conn,'bookings','completion_otp')) $conn->query("ALTER TABLE bookings ADD COLUMN completion_otp VARCHAR(8) NULL");
  if (!col_exists($conn,'bookings','estimated_price'))$conn->query("ALTER TABLE bookings ADD COLUMN estimated_price DECIMAL(10,2) NULL");
  if (!col_exists($conn,'bookings','time_slot'))      $conn->query("ALTER TABLE bookings ADD COLUMN time_slot VARCHAR(50) NULL");
} catch(Throwable $e){ /* ignore if perms limited */ }

/* ===== Resolve worker profile ===== */
$workerId = (int)($_SESSION['worker_id'] ?? 0);
$profile  = null; $candidates = [];

if ($workerId > 0) {
  $st = $conn->prepare("SELECT id,name,COALESCE(status,'Enabled') status, COALESCE(approval_status,'pending') approval_status, COALESCE(email,'') email FROM worker_profiles WHERE id=? LIMIT 1");
  $st->bind_param("i",$workerId); $st->execute();
  $profile = $st->get_result()->fetch_assoc(); $st->close();
}

if (!$profile) {
  $st = $conn->prepare("SELECT id,name,COALESCE(status,'Enabled') status, COALESCE(approval_status,'pending') approval_status, COALESCE(email,'') email FROM worker_profiles WHERE user_id=? LIMIT 1");
  $st->bind_param("i",$userId); $st->execute();
  $profile = $st->get_result()->fetch_assoc(); $st->close();
  if ($profile) { $workerId = (int)$profile['id']; $_SESSION['worker_id'] = $workerId; }
}

if (!$profile && $userMail) {
  $st = $conn->prepare("SELECT id,name,COALESCE(status,'Enabled') status, COALESCE(approval_status,'pending') approval_status, COALESCE(email,'') email FROM worker_profiles WHERE email=? ORDER BY id DESC");
  $st->bind_param("s",$userMail); $st->execute();
  $rs = $st->get_result();
  while($row = $rs->fetch_assoc()) $candidates[] = $row;
  $st->close();
  if (count($candidates) === 1) {
    $profile  = $candidates[0];
    $workerId = (int)$profile['id'];
    $_SESSION['worker_id'] = $workerId;
  }
}

/* ===== AJAX ===== */
if (isset($_GET['ajax']) && $_GET['ajax']==='1') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $action = $_POST['action'] ?? '';

    if ($action === 'link_profile') {
      $pid = (int)($_POST['profile_id'] ?? 0);
      if ($pid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid profile']); exit; }
      $st = $conn->prepare("SELECT user_id FROM worker_profiles WHERE id=? LIMIT 1");
      $st->bind_param("i",$pid); $st->execute();
      $cur = $st->get_result()->fetch_assoc(); $st->close();
      if (!$cur) { echo json_encode(['ok'=>false,'msg'=>'Profile not found']); exit; }
      $curUid = (int)($cur['user_id'] ?? 0);
      if ($curUid && $curUid !== $userId) { echo json_encode(['ok'=>false,'msg'=>'Profile already linked elsewhere.']); exit; }
      $st = $conn->prepare("UPDATE worker_profiles SET user_id=? WHERE id=? LIMIT 1");
      $st->bind_param("ii",$userId,$pid); $st->execute(); $st->close();
      $_SESSION['worker_id'] = $pid;
      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'set_availability') {
      $status = $_POST['status'] ?? 'Enabled';
      $allowed = ['Enabled','On Leave'];
      if (!in_array($status,$allowed,true)) $status='Enabled';
      if (!$workerId) { echo json_encode(['ok'=>false,'msg'=>'No linked worker profile']); exit; }
      $st = $conn->prepare("UPDATE worker_profiles SET status=? WHERE id=? LIMIT 1");
      $st->bind_param("si",$status,$workerId); $st->execute(); $st->close();
      echo json_encode(['ok'=>true,'status'=>$status]); exit;
    }

    if ($action === 'ack_job') {
      $bid = (int)($_POST['booking_id'] ?? 0);
      if (!$workerId || $bid<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
      $st = $conn->prepare("UPDATE bookings SET status='approved' WHERE id=? AND assigned_worker_id=? AND status='assigned' LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute(); $st->close();
      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'decline_job') {
      $bid = (int)($_POST['booking_id'] ?? 0);
      $reason = trim((string)$_POST['reason'] ?? '');
      if (!$workerId || $bid<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
      if ($reason==='') { echo json_encode(['ok'=>false,'msg'=>'Please provide a reason.']); exit; }
      if (mb_strlen($reason)>500) $reason = mb_substr($reason,0,500);

      $conn->begin_transaction();
      try{
        $conn->query("
          CREATE TABLE IF NOT EXISTS booking_rejections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            worker_id INT NOT NULL,
            reason TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (booking_id), INDEX (worker_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $st = $conn->prepare("INSERT INTO booking_rejections (booking_id, worker_id, reason) VALUES (?,?,?)");
        $st->bind_param("iis",$bid,$workerId,$reason); $st->execute(); $st->close();

        $st = $conn->prepare("UPDATE bookings SET assigned_worker_id=NULL, status='pending' WHERE id=? AND assigned_worker_id=? LIMIT 1");
        $st->bind_param("ii",$bid,$workerId); $st->execute(); $st->close();

        $conn->commit();
        echo json_encode(['ok'=>true]); exit;
      }catch(Throwable $e){
        $conn->rollback(); http_response_code(500);
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
      }
    }

    if ($action === 'on_the_way') {
      $bid = (int)($_POST['booking_id'] ?? 0);
      if (!$workerId || $bid<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
      $st = $conn->prepare("UPDATE bookings SET status='on_the_way' WHERE id=? AND assigned_worker_id=? AND status IN ('approved','assigned') LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute(); $st->close();
      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'arrived') {
      $bid = (int)($_POST['booking_id'] ?? 0);
      $wlat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
      $wlng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
      if (!$workerId || $bid<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }

      $st = $conn->prepare("SELECT lat,lng FROM bookings WHERE id=? AND assigned_worker_id=? LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute();
      $row = $st->get_result()->fetch_assoc(); $st->close();
      if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Booking not found or not assigned to you']); exit; }

      $blat = $row['lat']; $blng = $row['lng'];
      if ($blat !== null && $blng !== null && $wlat !== null && $wlng !== null) {
        $dist = haversine_m((float)$blat,(float)$blng,$wlat,$wlng);
        if ($dist !== null && $dist > 150) { echo json_encode(['ok'=>false,'msg'=>'You are too far from the job location ('.$dist.' m)']); exit; }
      }
      $st = $conn->prepare("UPDATE bookings SET status='arrived', arrived_at=NOW() WHERE id=? AND assigned_worker_id=? LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute(); $st->close();
      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'start_job') {
      $bid = (int)($_POST['booking_id'] ?? 0);
      if (!$workerId || $bid<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
      $st = $conn->prepare("UPDATE bookings SET status='in_progress', started_at=NOW() WHERE id=? AND assigned_worker_id=? AND status IN ('arrived','approved','on_the_way') LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute(); $st->close();
      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'finish_job') {
      $bid = (int)($_POST['booking_id'] ?? 0);
      $otp = isset($_POST['otp']) ? trim((string)$_POST['otp']) : '';
      if (!$workerId || $bid<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }

      $st = $conn->prepare("SELECT completion_otp FROM bookings WHERE id=? AND assigned_worker_id=? LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute();
      $bk = $st->get_result()->fetch_assoc(); $st->close();
      $needOtp = ($bk && $bk['completion_otp'] !== null && $bk['completion_otp'] !== '');
      if ($needOtp && $otp === '') { echo json_encode(['ok'=>false,'msg'=>'OTP required']); exit; }
      if ($needOtp && strcasecmp($otp,$bk['completion_otp']) !== 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid OTP']); exit; }

      $st = $conn->prepare("UPDATE bookings SET status='completed', finished_at=NOW() WHERE id=? AND assigned_worker_id=? AND status IN ('in_progress','arrived','approved','on_the_way') LIMIT 1");
      $st->bind_param("ii",$bid,$workerId); $st->execute(); $st->close();
      echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
  }
}

/* ===== KPIs & lists ===== */
$availability = 'Enabled';
$workerName   = $userName;
if ($profile) {
  $availability = $profile['status'] ?: 'Enabled';
  $workerName   = $profile['name']   ?: $userName;
  $workerId     = (int)$profile['id'];
  $_SESSION['worker_id'] = $workerId;
}

/* ---- include optional columns and custom_details in SELECTs ---- */
$hasAddress   = col_exists($conn,'bookings','address');
$hasCustomDet = col_exists($conn,'bookings','custom_details'); // NEW
$hasNotes     = col_exists($conn,'bookings','notes');

$commonCols = "b.id,b.ref_code,b.service,b.area"
            . ($hasAddress   ? ",b.address"        : "")
            . ($hasCustomDet ? ",b.custom_details" : "") // NEW
            . ",b.lat,b.lng,b.date,b.time_slot,b.status,b.estimated_price";

/* helper used to render a compact address-notes preview */
function tidy_details_preview(?string $raw): string {
  $t = (string)$raw;
  if ($t === '') return '';
  $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');     // decode &#13; etc.
  $t = preg_replace('/(?:\r\n|\r|\n){2}\[Property\][\s\S]*$/u', '', $t); // drop auto property block
  $t = trim($t);
  if ($t === '') return '';
  $t = preg_replace('/\s+/', ' ', $t); // one-liner
  if (mb_strlen($t,'UTF-8') > 140) $t = mb_substr($t, 0, 140, 'UTF-8').'…';
  return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

$kpi_today=$kpi_needs=$kpi_month=0; $kpi_earn=0.00;
$todayJobs=[]; $upcoming=[];

if ($workerId > 0) {
  // Today count
  $s = $conn->prepare("SELECT COUNT(*) c FROM bookings WHERE assigned_worker_id=? AND date=? AND status IN ('assigned','approved','on_the_way','arrived','in_progress')");
  $s->bind_param("is",$workerId,$today); $s->execute();
  $kpi_today = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

  // Needs my action
  $s = $conn->prepare("SELECT COUNT(*) c FROM bookings WHERE assigned_worker_id=? AND status='assigned' AND date>=?");
  $s->bind_param("is",$workerId,$today); $s->execute();
  $kpi_needs = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

  // Month counts
  $firstDay = date('Y-m-01'); $lastDay = date('Y-m-t');
  $s = $conn->prepare("SELECT COUNT(*) c FROM bookings WHERE assigned_worker_id=? AND status IN ('assigned','approved','on_the_way','arrived','in_progress','completed') AND date BETWEEN ? AND ?");
  $s->bind_param("iss",$workerId,$firstDay,$lastDay); $s->execute();
  $kpi_month = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

  // Earnings placeholder
  $s = $conn->prepare("SELECT COALESCE(SUM(estimated_price),0) s FROM bookings WHERE assigned_worker_id=? AND status IN ('approved','completed') AND date BETWEEN ? AND ?");
  $s->bind_param("iss",$workerId,$firstDay,$lastDay); $s->execute();
  $kpi_earn = (float)$s->get_result()->fetch_assoc()['s']; $s->close();

  // Column sets for each list
  $colsToday  = "$commonCols,u.name AS customer_name, u.phone AS customer_phone";
  $colsUpc    = "$commonCols,u.name AS customer_name";

  // Today’s schedule
  $sqlToday = "
    SELECT $colsToday
    FROM bookings b
    JOIN users u ON u.id=b.user_id
    WHERE b.assigned_worker_id=? AND b.date=? AND b.status IN ('assigned','approved','on_the_way','arrived','in_progress')
    ORDER BY b.time_slot ASC, b.id ASC";
  $q = $conn->prepare($sqlToday);
  $q->bind_param("is",$workerId,$today); $q->execute();
  $todayJobs = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();

  // Upcoming 7 days
  $sqlUp = "
    SELECT $colsUpc
    FROM bookings b
    JOIN users u ON u.id=b.user_id
    WHERE b.assigned_worker_id=? AND b.status IN ('assigned','approved','on_the_way','arrived','in_progress') AND b.date BETWEEN ? AND ?
    ORDER BY b.date ASC, b.time_slot ASC
    LIMIT 30";
  $q = $conn->prepare($sqlUp);
  $q->bind_param("iss",$workerId,$today,$weekTo); $q->execute();
  $upcoming = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Worker Dashboard – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --brand:#10b981; --brand-600:#059669; }
  *{box-sizing:border-box} body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:#efefef}
  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#cbd5e1;padding:20px;display:flex;flex-direction:column;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:900}
  .avatar{width:40px;height:40px;border-radius:50%;background:#1f2937;display:grid;place-items:center;color:#fff;font-weight:800}
  .nav a{display:block;color:#cbd5e1;text-decoration:none;padding:10px;border-radius:10px}
  .nav a.active,.nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .main{padding:20px;color:#0f172a}
  .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
  .hello{font-size:20px;font-weight:900}
  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  .kpi h4{margin:0;color:var(--muted);font-size:13px}
  .kpi .v{font-size:24px;font-weight:900}
  .grid2{display:grid;grid-template-columns:1.2fr .8fr;gap:12px;margin-top:12px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left;font-size:14px}
  thead th{background:#f8fafc}
  .tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid var(--line)}
  .tag.assigned{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
  .tag.approved{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}
  .tag.on_the_way{background:#e0f2fe;color:#075985;border-color:#bae6fd}
  .tag.arrived{background:#f5f3ff;color:#5b21b6;border-color:#ddd6fe}
  .tag.in_progress{background:#fef9c3;color:#854d0e;border-color:#fde68a}
  .tag.completed{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
  .btn{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 10px;cursor:pointer;font-weight:700}
  .btn.brand{background:var(--brand);border-color:var(--brand);color:#fff}
  .btn.brand:hover{background:var(--brand-600)}
  .btn.danger{background:#fee2e2;color:#b91c1c;border-color:#fecaca}
  .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .small{color:#64748b;font-size:12px}
  .select{height:36px;border:1px solid var(--line);border-radius:10px;padding:0 10px;background:#fff}
  .note{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px;border-radius:10px;margin-bottom:12px}
  @media (max-width:1100px){ .layout{grid-template-columns:1fr} .grid2{grid-template-columns:1fr} .kpis{grid-template-columns:repeat(2,1fr)} table td .btn{margin-top:6px} }
  .muted{color:#64748b}
</style>
</head>
<body>
<div class="layout">
  <aside class="side">
    <div class="brand"><div class="avatar"><?= strtoupper(substr($workerName ?? $userName,0,1)) ?></div>NeinMaid Worker</div>
    <nav class="nav">
      <a class="active" href="worker_dashboard.php">🏠 Dashboard</a>
      <a href="worker_history.php">📜 Job History</a>
      <a href="worker_finance.php">💰 Finance</a>
      <a href="worker_profile.php">👤 Profile</a>
      <a href="contact_chat.php">💬 Chats</a>
    </nav>
    <div style="flex:1"></div>
    <a class="nav" href="logout.php" style="color:#fecaca;text-decoration:none">⏻ Logout</a>
  </aside>

  <main class="main">
    <div class="top">
      <div>
        <div class="hello">Hi, <?= h($workerName ?? $userName) ?> 👋</div>
        <div class="small">Here’s your overview for <?= date('D, d M Y') ?>.</div>
      </div>

      <?php if ($workerId>0): ?>
        <div class="row">
          <span class="small">Availability</span>
          <select id="availSelect" class="select">
            <option value="Enabled" <?= ($availability==='Enabled'?'selected':'') ?>>Enabled</option>
            <option value="On Leave" <?= ($availability==='On Leave'?'selected':'') ?>>On Leave</option>
          </select>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($workerId===0): ?>
      <div class="note">
        <strong>No linked worker profile.</strong><br>
        Your account (<?= h($userMail) ?>) isn’t linked to a worker profile yet.
        <?php if ($candidates): ?>
          <div style="margin-top:8px">
            <label class="small">Select your profile to link:</label>
            <div class="row" style="margin-top:6px">
              <select id="profilePick" class="select">
                <?php foreach($candidates as $c): ?>
                  <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> — <?= h($c['name'] ?: 'Unnamed') ?> (<?= h($c['email']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <button class="btn brand" id="linkBtn">Link Now</button>
            </div>
            <div class="small" style="margin-top:6px">Ask admin to ensure your worker profile email equals your login email.</div>
          </div>
        <?php else: ?>
          <div class="small" style="margin-top:6px">Ask admin to set <code>worker_profiles.user_id</code>.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($workerId>0): ?>
      <section class="kpis">
        <div class="card kpi"><h4>Today’s Jobs</h4><div class="v"><?= (int)$kpi_today ?></div></div>
        <div class="card kpi"><h4>Needs My Action</h4><div class="v"><?= (int)$kpi_needs ?></div></div>
        <div class="card kpi"><h4>Assigned/Approved (This Month)</h4><div class="v"><?= (int)$kpi_month ?></div></div>
        <div class="card kpi"><h4>Projected Earnings (This Month)</h4><div class="v">RM <?= number_format($kpi_earn,2) ?></div></div>
      </section>

      <section class="grid2">
        <div class="card">
          <h3 style="margin:0 0 8px">Today’s Schedule</h3>
          <?php if (!$todayJobs): ?>
            <div class="small">No jobs today.</div>
          <?php else: ?>
            <div style="overflow:auto">
              <table>
                <thead><tr>
                  <th>Ref</th><th>Customer</th><th>Service</th><th>Time</th><th>Area</th><th>Address</th><th>Price</th><th>Status</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach($todayJobs as $j):
                  $hasLL = (isset($j['lat']) && $j['lat']!==null && isset($j['lng']) && $j['lng']!==null);
                  $addressOrArea = isset($j['address']) && $j['address'] ? $j['address'] : ($j['area'] ?? '');
                  $searchQuery = trim($addressOrArea ? ($addressOrArea.', Malaysia') : (($j['area'] ?: 'Malaysia').', Malaysia'));
                  $navUrl = $hasLL
                    ? ('https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($j['lat'].','.$j['lng']))
                    : ('https://www.google.com/maps/search/?api=1&query='.rawurlencode($searchQuery));
                  $price = isset($j['estimated_price']) && $j['estimated_price']!==null ? number_format((float)$j['estimated_price'],2) : '—';
                ?>
                  <tr data-id="<?= (int)$j['id'] ?>"
                      data-date="<?= h($j['date']) ?>"
                      data-timeslot="<?= h($j['time_slot']) ?>">
                    <td><strong><?= h($j['ref_code'] ?: ('#'.$j['id'])) ?></strong></td>
                    <td><?= h($j['customer_name']) ?><div class="small"><?= h($j['customer_phone'] ?: '') ?></div></td>
                    <td><?= h($j['service']) ?></td>
                    <td><?= h($j['time_slot']) ?></td>
                    <td><?= h($j['area']) ?></td>
                    <td class="muted">
                      <?php
                        echo h($addressOrArea ?: '—');
                        $cd = isset($j['custom_details']) ? tidy_details_preview($j['custom_details']) : '';
                        if ($cd !== '') echo '<div class="small">'.$cd.'</div>';
                      ?>
                    </td>
                    <td>RM <?= $price ?></td>
                    <td><span class="tag <?= h($j['status']) ?>"><?= ucfirst(str_replace('_',' ',h($j['status']))) ?></span></td>
                    <td class="row">
                      <a class="btn navbtn" data-nav="<?= h($navUrl) ?>">🚘 Navigate</a>
                      <a class="btn" href="<?=
                        'contact_chat_worker.php?worker_id='.$workerId.
                        '&booking_id='.(int)$j['id'].
                        '&booking_ref='.urlencode($j['ref_code'] ?: ('#'.$j['id']))
                      ?>">💬 Chat</a>

                      <?php if ($j['status']==='assigned'): ?>
                        <button class="btn brand ack gated">Acknowledge</button>
                        <button class="btn danger decline gated">Decline</button>
                      <?php endif; ?>

                      <?php if (in_array($j['status'],['approved','assigned'])): ?>
                        <button class="btn ontheway gated">On the way</button>
                      <?php endif; ?>

                      <?php if (in_array($j['status'],['approved','on_the_way'])): ?>
                        <button class="btn arrived gated">Arrived</button>
                      <?php endif; ?>

                      <?php if (in_array($j['status'],['arrived','approved','on_the_way'])): ?>
                        <button class="btn brand start gated">Start job</button>
                      <?php endif; ?>

                      <?php if (in_array($j['status'],['in_progress','arrived','approved','on_the_way'])): ?>
                        <button class="btn brand finish gated">Finish job</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="margin:0 0 8px">Upcoming (7 days)</h3>
          <?php if (!$upcoming): ?>
            <div class="small">Nothing scheduled.</div>
          <?php else: ?>
            <div style="overflow:auto">
              <table>
                <thead><tr>
                  <th>Ref</th><th>Customer</th><th>Service</th><th>Date</th><th>Time</th><th>Area</th><th>Address</th><th>Price</th><th>Status</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach($upcoming as $u):
                  $hasLL = (isset($u['lat']) && $u['lat']!==null && isset($u['lng']) && $u['lng']!==null);
                  $addressOrArea = isset($u['address']) && $u['address'] ? $u['address'] : ($u['area'] ?? '');
                  $searchQuery = trim($addressOrArea ? ($addressOrArea.', Malaysia') : (($u['area'] ?: 'Malaysia').', Malaysia'));
                  $navUrl = $hasLL
                    ? ('https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($u['lat'].','.$u['lng']))
                    : ('https://www.google.com/maps/search/?api=1&query='.rawurlencode($searchQuery));
                  $price = isset($u['estimated_price']) && $u['estimated_price']!==null ? number_format((float)$u['estimated_price'],2) : '—';
                ?>
                  <tr data-id="<?= (int)$u['id'] ?>"
                      data-date="<?= h($u['date']) ?>"
                      data-timeslot="<?= h($u['time_slot']) ?>">
                    <td><strong><?= h($u['ref_code'] ?: ('#'.$u['id'])) ?></strong></td>
                    <td><?= h($u['customer_name']) ?></td>
                    <td><?= h($u['service']) ?></td>
                    <td><?= h($u['date']) ?></td>
                    <td><?= h($u['time_slot']) ?></td>
                    <td><?= h($u['area']) ?></td>
                    <td class="muted">
                      <?php
                        echo h($addressOrArea ?: '—');
                        $cd = isset($u['custom_details']) ? tidy_details_preview($u['custom_details']) : '';
                        if ($cd !== '') echo '<div class="small">'.$cd.'</div>';
                      ?>
                    </td>
                    <td>RM <?= $price ?></td>
                    <td><span class="tag <?= h($u['status']) ?>"><?= ucfirst(str_replace('_',' ',h($u['status']))) ?></span></td>
                    <td class="row">
                      <!-- <a class="btn navbtn" data-nav="<?= h($navUrl) ?>">🚘 Navigate</a> -->
                      <a class="btn" href="<?=
                        'contact_chat_worker.php?worker_id='.$workerId.
                        '&booking_id='.(int)$u['id'].
                        '&booking_ref='.urlencode($u['ref_code'] ?: ('#'.$u['id']))
                      ?>">💬 Chat</a>

                      <?php if ($u['status']==='assigned'): ?>
                        <button class="btn brand ack gated">Acknowledge</button>
                        <button class="btn danger decline gated">Decline</button>
                      <?php endif; ?>

                      <?php if (in_array($u['status'],['approved','assigned'])): ?>
                        <button class="btn ontheway gated">On the way</button>
                      <?php endif; ?>

                      <?php if (in_array($u['status'],['approved','on_the_way'])): ?>
                        <button class="btn arrived gated">Arrived</button>
                      <?php endif; ?>

                      <?php if (in_array($u['status'],['arrived','approved','on_the_way'])): ?>
                        <button class="btn brand start gated">Start job</button>
                      <?php endif; ?>

                      <?php if (in_array($u['status'],['in_progress','arrived','approved','on_the_way'])): ?>
                        <button class="btn brand finish gated">Finish job</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </main>
</div>

<script>
/* ==== Config: action window (minutes) ==== */
const ACTION_WINDOW_MIN = 120; // actions enabled within ±120 minutes of start

/* Link profile */
const linkBtn = document.getElementById('linkBtn');
if (linkBtn) {
  linkBtn.addEventListener('click', async ()=>{
    const sel = document.getElementById('profilePick');
    if(!sel || !sel.value) return;
    linkBtn.disabled = true;
    const res = await fetch('worker_dashboard.php?ajax=1', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'link_profile', profile_id: sel.value})
    });
    let j = null; try{ j = await res.json(); }catch{}
    linkBtn.disabled = false;
    if(j && j.ok){ location.reload(); }
    else{ alert(j?.msg || 'Failed to link.'); }
  });
}

/* Availability */
const availSel = document.getElementById('availSelect');
if (availSel){
  availSel.addEventListener('change', async ()=>{
    const status = availSel.value;
    await fetch('worker_dashboard.php?ajax=1', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'set_availability', status})
    });
  });
}

/* Nav button opens link reliably */
document.querySelectorAll('.navbtn').forEach(a=>{
  a.addEventListener('click', (e)=>{
    const url = a.getAttribute('data-nav');
    if(url) window.open(url, '_blank');
  });
});

/* Helpers */
function rowBookingId(btn){ return btn.closest('tr')?.getAttribute('data-id'); }
function setRowStatus(btn, s){
  const tag = btn.closest('tr').querySelector('.tag');
  if(tag){ tag.className='tag '+s; tag.textContent=s.replace(/_/g,' ').replace(/^\w/,c=>c.toUpperCase()); }
}
async function postAction(action, payload){
  const res = await fetch('worker_dashboard.php?ajax=1', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action, ...payload})
  });
  let j=null; try{ j=await res.json(); }catch{}
  return j;
}

/* Time gating: enable actions only near the slot */
function parseStart(dateStr, slotStr){
  if(!dateStr) return null;
  const base = new Date(dateStr + 'T00:00:00');
  if (!slotStr) return base;
  let hh=9, mm=0;
  const s = slotStr.trim().toLowerCase();
  let m = s.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/);
  if(m){
    hh = parseInt(m[1],10); mm = m[2]?parseInt(m[2],10):0;
    const ap = m[3]||'';
    if(ap==='pm' && hh<12) hh+=12;
    if(ap==='am' && hh===12) hh=0;
  } else if (s.includes('-')) {
    const left = s.split('-')[0].trim();
    m = left.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/);
    if(m){
      hh = parseInt(m[1],10); mm = m[2]?parseInt(m[2],10):0;
      const ap = m[3]||'';
      if(ap==='pm' && hh<12) hh+=12;
      if(ap==='am' && hh===12) hh=0;
    }
  }
  const dt = new Date(base);
  dt.setHours(hh, mm, 0, 0);
  return dt;
}

function gateButtons(){
  const now = new Date();
  document.querySelectorAll('tr[data-date]').forEach(tr=>{
    const dateStr = tr.getAttribute('data-date');
    const slotStr = tr.getAttribute('data-timeslot') || '';
    const start = parseStart(dateStr, slotStr);
    const gatedBtns = tr.querySelectorAll('.gated');
    if(!start){
      gatedBtns.forEach(b=>{ b.disabled = true; b.title = 'Disabled: no start time'; });
      return;
    }
    const diffMin = Math.abs((now - start)/60000);
    const inWindow = diffMin <= ACTION_WINDOW_MIN && now >= new Date(dateStr+'T00:00:00');
    gatedBtns.forEach(b=>{
      b.disabled = !inWindow;
      b.title = inWindow ? '' : `Available within ±${ACTION_WINDOW_MIN} min of start time`;
    });
  });
}
gateButtons();
setInterval(gateButtons, 60*1000);

/* Actions */
// Acknowledge
document.querySelectorAll('.ack').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const id = rowBookingId(b); if(!id) return;
    b.disabled = true;
    const j = await postAction('ack_job',{booking_id:id});
    b.disabled = false;
    if(j?.ok){ setRowStatus(b,'approved'); } else { alert(j?.msg || 'Failed'); }
  });
});

// Decline with reason
document.querySelectorAll('.decline').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const id = rowBookingId(b); if(!id) return;
    const reason = prompt('Please provide a short reason for declining (required):');
    if (reason === null) return;
    const trimmed = (reason || '').trim();
    if (!trimmed) { alert('Decline reason is required.'); return; }
    b.disabled = true;
    const j = await postAction('decline_job',{booking_id:id, reason: trimmed});
    b.disabled = false;
    if(j?.ok){
      const tr=b.closest('tr'); tr.style.opacity=.6; tr.style.pointerEvents='none';
      setRowStatus(b,'pending');
    } else { alert(j?.msg || 'Failed'); }
  });
});

// On the way
document.querySelectorAll('.ontheway').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const id = rowBookingId(b); if(!id) return;
    b.disabled = true;
    const j = await postAction('on_the_way',{booking_id:id});
    b.disabled = false;
    if(j?.ok){ setRowStatus(b,'on_the_way'); } else { alert(j?.msg || 'Failed'); }
  });
});

// Arrived — capture GPS if allowed
async function getGeo(){
  return new Promise(resolve=>{
    if(!navigator.geolocation) return resolve(null);
    navigator.geolocation.getCurrentPosition(
      p=>resolve({lat:p.coords.latitude, lng:p.coords.longitude}),
      _=>resolve(null),
      {enableHighAccuracy:true, timeout:8000, maximumAge:0}
    );
  });
}
document.querySelectorAll('.arrived').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const id = rowBookingId(b); if(!id) return;
    b.disabled = true;
    const g = await getGeo();
    const payload = g ? {booking_id:id, lat:g.lat, lng:g.lng} : {booking_id:id};
    const j = await postAction('arrived', payload);
    b.disabled = false;
    if(j?.ok){ setRowStatus(b,'arrived'); } else { alert(j?.msg || 'Failed'); }
  });
});

// Start job
document.querySelectorAll('.start').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const id = rowBookingId(b); if(!id) return;
    b.disabled = true;
    const j = await postAction('start_job',{booking_id:id});
    b.disabled = false;
    if(j?.ok){ setRowStatus(b,'in_progress'); } else { alert(j?.msg || 'Failed'); }
  });
});

// Finish job (OTP optional)
document.querySelectorAll('.finish').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const id = rowBookingId(b); if(!id) return;
    const otp = prompt('Enter completion OTP (leave empty if not required):');
    b.disabled = true;
    const j = await postAction('finish_job',{booking_id:id, otp: (otp||'')});
    b.disabled = false;
    if(j?.ok){ setRowStatus(b,'completed'); } else { alert(j?.msg || 'Failed'); }
  });
});
</script>
</body>
</html>
