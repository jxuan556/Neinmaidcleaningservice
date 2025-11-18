<?php
// admin_finance.php — SIMPLE: just show totals + booking list
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

date_default_timezone_set('Asia/Kuala_Lumpur');

/* ===== DB ===== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "maid_system";

$err = '';
try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    $err = 'Database error: '.$e->getMessage();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n, 2, '.', ''); }

// fixed share: 95% to worker, 5% to platform
$WORKER_SHARE = 0.95;

/* ===== Date filter (default: this month) ===== */
$today      = new DateTimeImmutable('today', new DateTimeZone('Asia/Kuala_Lumpur'));
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$monthEnd   = $today->modify('last day of this month')->format('Y-m-d');

$from = $_GET['from'] ?? $monthStart;
$to   = $_GET['to']   ?? $monthEnd;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $monthStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $monthEnd;

/* ===== Load data ===== */
$summary  = ['jobs'=>0,'gross'=>0.0,'worker'=>0.0,'platform'=>0.0];
$bookings = [];

if (!$err) {
    // We only READ from the DB, very simple.
    $sql = "
        SELECT
            b.id,
            b.date,
            b.ref_code,
            b.status,
            b.assigned_worker_id,
            COALESCE(b.final_paid_amount, b.estimated_price) AS gross,
            w.name AS worker_name
        FROM bookings b
        LEFT JOIN worker_profiles w ON w.id = b.assigned_worker_id
        WHERE b.date BETWEEN ? AND ?
          AND b.assigned_worker_id IS NOT NULL
          AND b.status IN ('approved','completed','in_progress','arrived','on_the_way')
        ORDER BY b.date ASC, b.id ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        $gross = (float)($r['gross'] ?? 0);
        $workerAmt   = round($gross * $WORKER_SHARE, 2);
        $platformAmt = round($gross - $workerAmt, 2);

        $summary['jobs']++;
        $summary['gross']    += $gross;
        $summary['worker']   += $workerAmt;
        $summary['platform'] += $platformAmt;

        $r['worker_amount']   = $workerAmt;
        $r['platform_amount'] = $platformAmt;
        $bookings[] = $r;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin · Finance – NeinMaid</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css"><!-- optional -->
  <style>
    body{background:#f6f7fb;color:#0f172a;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .main{padding:18px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .row-sb{display:flex;justify-content:space-between;align-items:center}
    .input{padding:8px;border:1px solid #e5e7eb;border-radius:10px}
    .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
    .btn-primary{background:#111827;color:#fff;border-color:#111827}
    .btn-primary:hover{opacity:.9}
    .small{font-size:12px;color:#6b7280}
    .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px}
    .kpi-val{font-size:24px;font-weight:800}
    .table-wrap{overflow:auto;margin-top:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;font-size:14px}
    thead th{background:#f8fafc}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb;background:#f8fafc}
    @media(max-width:900px){ .grid-3{grid-template-columns:1fr} }
  </style>
</head>
<body>

<?php include __DIR__ . '/admin_header.php'; ?>

<main class="main">
  <?php if($err): ?>
    <div class="card" style="border-color:#fecaca;background:#fef2f2;color:#b91c1c">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <section class="card">
    <div class="row-sb">
      <h1 style="margin:0">Finance (View Only)</h1>
      <form class="row" method="get">
        <div>
          <div class="small">From</div>
          <input class="input" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
          <div class="small">To</div>
          <input class="input" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <button class="btn btn-primary" type="submit" style="align-self:flex-end">Apply</button>
      </form>
    </div>
  </section>

  <section class="grid-3">
    <div class="card">
      <div class="small">Total Jobs</div>
      <div class="kpi-val"><?= (int)$summary['jobs'] ?></div>
    </div>
    <div class="card">
      <div class="small">Gross (Customers paid)</div>
      <div class="kpi-val"><?= money_rm($summary['gross']) ?></div>
    </div>
    <div class="card">
      <div class="small">Worker Share (95%) / Platform (5%)</div>
      <div class="kpi-val">
        <?= money_rm($summary['worker']) ?> <span class="small">worker</span><br>
        <?= money_rm($summary['platform']) ?> <span class="small">platform</span>
      </div>
    </div>
  </section>

  <section class="card" style="margin-top:12px">
    <div class="row-sb">
      <h3 style="margin:0">Bookings in range</h3>
      <span class="small">Showing bookings with a worker assigned</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Ref</th>
            <th>Status</th>
            <th>Worker</th>
            <th>Gross</th>
            <th>Worker 95%</th>
            <th>Platform 5%</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$bookings): ?>
          <tr><td colspan="7" class="small">No bookings in this date range.</td></tr>
        <?php else: foreach($bookings as $b): ?>
          <tr>
            <td><?= h($b['date']) ?></td>
            <td><?= h($b['ref_code']) ?></td>
            <td><span class="tag"><?= h(ucfirst(str_replace('_',' ',$b['status']))) ?></span></td>
            <td><?= h($b['worker_name'] ?: ('#'.$b['assigned_worker_id'])) ?></td>
            <td><?= money_rm($b['gross']) ?></td>
            <td><?= money_rm($b['worker_amount']) ?></td>
            <td><?= money_rm($b['platform_amount']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
