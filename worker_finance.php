<?php
// worker_finance.php — SIMPLE: just show worker earnings & job list
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'worker')) {
    header("Location: login.php");
    exit();
}
date_default_timezone_set('Asia/Kuala_Lumpur');

/* ===== DB ===== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

$WORKER_SHARE = 0.95; // 95%

$userId   = (int)$_SESSION['user_id'];
$userName = (string)($_SESSION['name'] ?? 'Worker');

/* ===== Resolve worker_id for this account ===== */
$workerId = (int)($_SESSION['worker_id'] ?? 0);
$workerDisplayName = $userName;

if ($workerId <= 0) {
    // try link by worker_profiles.user_id
    $st = $conn->prepare("SELECT id,name FROM worker_profiles WHERE user_id=? LIMIT 1");
    $st->bind_param('i',$userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $workerId = (int)$row['id'];
        $_SESSION['worker_id'] = $workerId;
        $workerDisplayName = $row['name'] ?: $userName;
    }
}

/* ===== Date filter (optional, default: last 90 days) ===== */
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kuala_Lumpur'));
$defaultFrom = $today->modify('-90 days')->format('Y-m-d');
$defaultTo   = $today->format('Y-m-d');

$from = $_GET['from'] ?? $defaultFrom;
$to   = $_GET['to']   ?? $defaultTo;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   $to   = $defaultTo;

/* ===== Load data ===== */
$rows = [];
$totalShare = 0.0;
$totalGross = 0.0;

if ($workerId > 0) {
    $sql = "
      SELECT
        b.id,
        b.date,
        b.time_slot,
        b.ref_code,
        b.area,
        b.service,
        b.status,
        COALESCE(b.final_paid_amount, b.estimated_price) AS gross
      FROM bookings b
      WHERE b.assigned_worker_id = ?
        AND b.date BETWEEN ? AND ?
        AND b.status IN ('approved','completed','in_progress','arrived','on_the_way')
      ORDER BY b.date DESC, b.id DESC
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('iss',$workerId,$from,$to);
    $st->execute();
    $res = $st->get_result();
    while($r = $res->fetch_assoc()){
        $gross = (float)($r['gross'] ?? 0.0);
        $share = round($gross * $WORKER_SHARE, 2);
        $r['share'] = $share;

        $totalGross  += $gross;
        $totalShare  += $share;
        $rows[] = $r;
    }
    $st->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Worker Finance – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --brand:#10b981; --brand-600:#059669; }
  *{box-sizing:border-box}
  body{margin:0;font-family:Inter,system-ui,Arial;background:var(--bg);color:var(--ink)}
  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#cbd5e1;padding:20px;display:flex;flex-direction:column;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:900}
  .avatar{width:40px;height:40px;border-radius:50%;background:#1f2937;display:grid;place-items:center;color:#fff;font-weight:800}
  .nav a{display:block;color:#cbd5e1;text-decoration:none;padding:10px;border-radius:10px}
  .nav a.active,.nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .main{padding:20px}
  .wrap{max-width:1050px;margin:0 auto}
  h1{margin:6px 0 14px}
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  .k{font-size:13px;color:#6b7280}
  .v{font-weight:800;font-size:22px;margin-top:4px}
  .small{font-size:12px;color:#6b7280}
  .filters{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px}
  .input{padding:8px;border:1px solid var(--line);border-radius:10px}
  .btn{padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:#fff;cursor:pointer}
  .btn-primary{background:var(--brand);border-color:var(--brand);color:#fff}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;font-size:14px}
  .tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb;background:#f8fafc}
  @media (max-width:1100px){
    .layout{grid-template-columns:1fr}
    .cards{grid-template-columns:1fr}
  }
</style>
</head>
<body>
<div class="layout">
  <aside class="side">
    <div class="brand">
      <div class="avatar"><?= strtoupper(substr($workerDisplayName,0,1)) ?></div>
      NeinMaid Worker
    </div>
    <nav class="nav">
      <a href="worker_dashboard.php">🏠 Dashboard</a>
      <a href="worker_history.php">📜 Job History</a>
      <a href="worker_finance.php" class="active">💰 Finance</a>
      <a href="worker_profile.php">👤 Profile</a>
      <a href="contact_chat.php">💬 Chats</a>
    </nav>
    <div style="flex:1"></div>
    <a class="nav" href="logout.php" style="color:#fecaca;text-decoration:none">⏻ Logout</a>
  </aside>

  <main class="main">
    <div class="wrap">
      <h1>My Finance</h1>

      <?php if ($workerId <= 0): ?>
        <div class="card" style="border-color:#fecaca;background:#fef2f2;color:#b91c1c">
          No worker profile linked to this account yet. Please ask admin to link your worker profile.
        </div>
      <?php else: ?>

      <!-- Filters -->
      <form class="filters" method="get">
        <div>
          <div class="small">From</div>
          <input class="input" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
          <div class="small">To</div>
          <input class="input" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Apply</button>
      </form>

      <!-- Summary cards -->
      <div class="cards">
        <div class="card">
          <div class="k">Total jobs in range</div>
          <div class="v"><?= count($rows) ?></div>
        </div>
        <div class="card">
          <div class="k">Gross (customer paid)</div>
          <div class="v"><?= money_rm($totalGross) ?></div>
        </div>
        <div class="card">
          <div class="k">Your share (<?= (int)($WORKER_SHARE*100) ?>%)</div>
          <div class="v"><?= money_rm($totalShare) ?></div>
        </div>
      </div>

      <!-- Job list -->
      <div class="card" style="margin-top:14px">
        <div class="small" style="margin-bottom:6px">
          Showing bookings assigned to you between <?= h($from) ?> and <?= h($to) ?>.
        </div>
        <div style="overflow:auto;max-height:500px">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Ref</th>
                <th>Area</th>
                <th>Service</th>
                <th>Status</th>
                <th>Gross</th>
                <th>Your 95%</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!$rows): ?>
              <tr><td colspan="8" class="small">No jobs in this date range.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><?= h($r['date']) ?></td>
                <td><?= h($r['time_slot']) ?></td>
                <td><?= h($r['ref_code']) ?></td>
                <td><?= h($r['area']) ?></td>
                <td><?= h($r['service']) ?></td>
                <td><span class="tag"><?= h(ucfirst(str_replace('_',' ',$r['status']))) ?></span></td>
                <td><?= money_rm($r['gross']) ?></td>
                <td><?= money_rm($r['share']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php endif; // workerId > 0 ?>
    </div>
  </main>
</div>
</body>
</html>

