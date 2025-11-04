<?php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'worker')) {
  header("Location: login.php"); exit();
}
date_default_timezone_set('Asia/Kuala_Lumpur');

/* ===== DB ===== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

/* Resolve user & worker ids/names for side nav avatar */
$userId   = (int)($_SESSION['user_id']);
$userName = (string)($_SESSION['name'] ?? 'Worker');
$workerId = (int)($_SESSION['worker_id'] ?? $_SESSION['user_id']);

/* WORKER SHARE = 95% */
$WORKER_SHARE = 0.95;

/* ----- schema heal for worker_payouts ----- */
function col_exists(mysqli $c, string $table, string $col): bool {
  try { $s=$c->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $s->bind_param('s',$col); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close(); return (bool)$r; }
  catch(Throwable $e){ return false; }
}
function table_exists(mysqli $c, string $table): bool {
  try { $c->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
try {
  if (!table_exists($conn,'worker_payouts')) {
    $conn->query("
      CREATE TABLE worker_payouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        worker_id INT NOT NULL,
        booking_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        paid_at DATETIME NULL,
        transfer_ref VARCHAR(100) NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_worker_booking(worker_id, booking_id),
        INDEX(worker_id),
        INDEX(paid_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  } else {
    if (!col_exists($conn,'worker_payouts','booking_id')) {
      $conn->query("ALTER TABLE worker_payouts ADD COLUMN booking_id INT NOT NULL AFTER worker_id");
      try { $conn->query("ALTER TABLE worker_payouts ADD UNIQUE KEY uniq_worker_booking(worker_id, booking_id)"); } catch(Throwable $e){}
    }
    if (!col_exists($conn,'worker_payouts','amount'))       $conn->query("ALTER TABLE worker_payouts ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0");
    if (!col_exists($conn,'worker_payouts','paid_at'))      $conn->query("ALTER TABLE worker_payouts ADD COLUMN paid_at DATETIME NULL");
    if (!col_exists($conn,'worker_payouts','transfer_ref')) $conn->query("ALTER TABLE worker_payouts ADD COLUMN transfer_ref VARCHAR(100) NULL");
    if (!col_exists($conn,'worker_payouts','note'))         $conn->query("ALTER TABLE worker_payouts ADD COLUMN note VARCHAR(255) NULL");
  }
} catch (Throwable $e) { /* ignore */ }

/* ----- load worker profile/bank (also for avatar name) ----- */
$bank = ['bank_name'=>null,'bank_acc'=>null,'name'=>null,'payout_preference'=>null];
$worker = null; $workerDisplayName = $userName;

try {
  $stmt = $conn->prepare("SELECT * FROM worker_profiles WHERE id=? LIMIT 1");
  $stmt->bind_param('i',$workerId); $stmt->execute();
  $worker = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($worker) {
    $bank['bank_name']         = $worker['bank_name']         ?? null;
    $bank['bank_acc']          = $worker['bank_acc']          ?? null;
    $bank['name']              = $worker['name']              ?? null;
    $bank['payout_preference'] = $worker['payout_preference'] ?? null;
    $workerDisplayName         = $worker['name'] ?: $userName;
  }
} catch(Throwable $e){}

/* ----- earnings ----- */
$today = date('Y-m-d');
$rows_unpaid = [];
$rows_paid   = [];

$baseSQL = "
  SELECT
    b.id, b.ref_code, b.date, b.time_slot, b.area, b.service,
    COALESCE(b.final_paid_amount, b.estimated_price) AS gross_amount,
    p.amount AS paid_amount, p.paid_at, p.transfer_ref, p.note
  FROM bookings b
  LEFT JOIN worker_payouts p
    ON p.worker_id = ? AND p.booking_id = b.id
  WHERE b.assigned_worker_id = ?
    AND b.status IN ('approved','assigned','cancelled','pending','completed')
    AND b.date <= ?
  ORDER BY b.date DESC, b.id DESC
";
try {
  $stmt = $conn->prepare($baseSQL);
  $stmt->bind_param('iis', $workerId, $workerId, $today);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){
    $gross = (float)($r['gross_amount'] ?? 0);
    $share = round($gross * $WORKER_SHARE, 2);
    $r['_share'] = $share;

    if (!empty($r['paid_at'])) $rows_paid[] = $r; else $rows_unpaid[] = $r;
  }
  $stmt->close();
} catch(Throwable $e){
  $fallbackSQL = "
    SELECT
      b.id, b.ref_code, b.date, b.time_slot, b.area, b.service,
      COALESCE(b.final_paid_amount, b.estimated_price) AS gross_amount
    FROM bookings b
    WHERE b.assigned_worker_id = ?
      AND b.status IN ('approved','assigned','cancelled','pending','completed')
      AND b.date <= ?
    ORDER BY b.date DESC, b.id DESC
  ";
  $stmt = $conn->prepare($fallbackSQL);
  $stmt->bind_param('is', $workerId, $today);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){
    $gross = (float)($r['gross_amount'] ?? 0);
    $share = round($gross * $WORKER_SHARE, 2);
    $r['_share'] = $share;
    $rows_unpaid[] = $r;
  }
  $stmt->close();
}

$tot_unpaid = array_sum(array_map(fn($r)=> (float)$r['_share'], $rows_unpaid));
$tot_paid   = array_sum(array_map(fn($r)=> (float)$r['_share'], $rows_paid));
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

  /* === shared layout (same as dashboard/history) === */
  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#cbd5e1;padding:20px;display:flex;flex-direction:column;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:900}
  .avatar{width:40px;height:40px;border-radius:50%;background:#1f2937;display:grid;place-items:center;color:#fff;font-weight:800}
  .nav a{display:block;color:#cbd5e1;text-decoration:none;padding:10px;border-radius:10px}
  .nav a.active,.nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .main{padding:20px}

  /* page cards/tables */
  .wrap{max-width:1050px;margin:0 auto}
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  .k{font-size:13px;color:#6b7280}
  .v{font-weight:800;font-size:22px;margin-top:4px}
  h1{margin:6px 0 14px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;font-size:14px}
  .tag{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb;color:#374151;background:#f8fafc}
  .ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
  .bad{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
  .muted{color:#6b7280}
  .bank{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:8px}
  .btn{background:#0ea5e9;color:#fff;border:0;border-radius:10px;padding:8px 12px;text-decoration:none}
  .tip{font-size:12px;color:#6b7280;margin-top:6px}
  .switch{display:inline-flex;gap:8px;align-items:center;font-size:12px;color:#374151}
  .switch input{transform:scale(1.2)}
  @media (max-width:1100px){ .layout{grid-template-columns:1fr} .cards{grid-template-columns:1fr} .grid-2{grid-template-columns:1fr} }
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
      <a href="worker_finance.php">💰 Finance</a>
      <a href="worker_profile.php">👤 Profile</a>
      <a href="contact_chat.php">💬 Chats</a>
    </nav>
    <div style="flex:1"></div>
    <a class="nav" href="logout.php" style="color:#fecaca;text-decoration:none">⏻ Logout</a>
  </aside>

  <main class="main">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1>My Finance</h1>
        <label class="switch"><input type="checkbox" id="autoRefresh"> Auto-refresh (20s)</label>
      </div>

      <!-- Summary -->
      <div class="cards">
        <div class="card">
          <div class="k">Unpaid (eligible)</div>
          <div class="v"><?= money_rm($tot_unpaid) ?></div>
          <div class="tip">Your share is <?= (int)($WORKER_SHARE*100) ?>% of customer payment.</div>
        </div>
        <div class="card">
          <div class="k">Paid to date</div>
          <div class="v"><?= money_rm($tot_paid) ?></div>
          <div class="tip">Includes marked payouts with transfer reference.</div>
        </div>
        <div class="card">
          <div class="k">Bank details</div>
          <div class="bank">
            <div><span class="k">Bank</span><div><strong><?= h($bank['bank_name'] ?? '—') ?></strong></div></div>
            <div><span class="k">Account Name</span><div><strong><?= h($bank['name'] ?? '—') ?></strong></div></div>
            <div><span class="k">Account No.</span><div><strong><?= h($bank['bank_acc'] ?? '—') ?></strong></div></div>
          </div>
          <div class="tip">Update via <a href="worker_profile_edit.php">Edit Profile</a>. Changes are sent to admin for approval.</div>
        </div>
      </div>

      <div class="grid-2" style="margin-top:14px">
        <!-- Unpaid -->
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <h3 style="margin:0">Unpaid</h3>
            <span class="tag bad"><?= count($rows_unpaid) ?> items · <?= money_rm($tot_unpaid) ?></span>
          </div>
          <div class="tip">Admin will batch payouts; contact support if something is missing.</div>
          <div style="overflow:auto;max-height:430px;margin-top:8px">
            <table>
              <thead><tr>
                <th>Ref</th><th>Date</th><th>Time</th><th>Area</th><th>Service</th><th class="right">Your <?= (int)($WORKER_SHARE*100) ?>%</th>
              </tr></thead>
              <tbody>
              <?php if(!$rows_unpaid): ?>
                <tr><td colspan="6" class="muted">No unpaid items.</td></tr>
              <?php else: foreach($rows_unpaid as $r): ?>
                <tr>
                  <td><?= h($r['ref_code']) ?></td>
                  <td><?= h($r['date']) ?></td>
                  <td><?= h($r['time_slot']) ?></td>
                  <td><?= h($r['area']) ?></td>
                  <td><?= h($r['service']) ?></td>
                  <td style="text-align:right"><strong><?= money_rm($r['_share']) ?></strong></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Paid -->
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <h3 style="margin:0">Paid</h3>
            <span class="tag ok"><?= count($rows_paid) ?> items · <?= money_rm($tot_paid) ?></span>
          </div>
          <div class="tip">Includes transfer reference and paid time.</div>
          <div style="overflow:auto;max-height:430px;margin-top:8px">
            <table>
              <thead><tr>
                <th>Ref</th><th>Paid At</th><th>Ref#</th><th class="right">Your <?= (int)($WORKER_SHARE*100) ?>%</th>
              </tr></thead>
              <tbody>
              <?php if(!$rows_paid): ?>
                <tr><td colspan="4" class="muted">No paid records yet.</td></tr>
              <?php else: foreach($rows_paid as $r): ?>
                <tr>
                  <td><?= h($r['ref_code']) ?></td>
                  <td><?= h($r['paid_at']) ?></td>
                  <td><?= h($r['transfer_ref'] ?? '-') ?></td>
                  <td style="text-align:right"><strong><?= money_rm($r['_share']) ?></strong></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /.wrap -->
  </main>
</div><!-- /.layout -->

<script>
// ---- Auto refresh (20s) with remembered toggle ----
const key='worker_fin_auto_refresh';
const box=document.getElementById('autoRefresh');
try{ box.checked = localStorage.getItem(key)==='1'; }catch(e){}
let timer=null;
function arm(){
  if(timer) clearInterval(timer);
  if(box.checked){
    timer=setInterval(()=>{ location.reload(); }, 20000);
  }
}
box.addEventListener('change',()=>{
  try{ localStorage.setItem(key, box.checked?'1':'0'); }catch(e){}
  arm();
});
arm();
</script>
</body>
</html>
