<?php
// admin_finance.php — dashboard-style UI + robust bank/account detection + 95% worker share + auto-refresh
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: login.php"); exit();
}
date_default_timezone_set('Asia/Kuala_Lumpur');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";

$err='';
try {
  $conn=new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
  $conn->set_charset('utf8mb4');
} catch(Throwable $e) { $err='DB error: '.$e->getMessage(); }

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

// ---------- helpers ----------
function col_exists(mysqli $conn, string $table, string $col): bool {
  try {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($col)."'");
    return $res && $res->num_rows > 0;
  } catch(Throwable $e) { return false; }
}
function pick_first_existing(mysqli $conn, string $table, array $cands, string $fallback){
  foreach($cands as $c){ if(col_exists($conn,$table,$c)) return $c; }
  return $fallback;
}

// ---------- bootstrap ----------
if(!$err){
  try {
    $conn->query("ALTER TABLE worker_profiles
      ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) NULL,
      ADD COLUMN IF NOT EXISTS bank_account_no VARCHAR(64) NULL,
      ADD COLUMN IF NOT EXISTS payout_percent DECIMAL(5,2) NULL
    ");
  } catch(Throwable $e) {
    try{$conn->query("ALTER TABLE worker_profiles ADD COLUMN bank_name VARCHAR(100) NULL");}catch(Throwable $e2){}
    try{$conn->query("ALTER TABLE worker_profiles ADD COLUMN bank_account_no VARCHAR(64) NULL");}catch(Throwable $e2){}
    try{$conn->query("ALTER TABLE worker_profiles ADD COLUMN payout_percent DECIMAL(5,2) NULL");}catch(Throwable $e2){}
  }
  try {
    $conn->query("CREATE TABLE IF NOT EXISTS worker_payouts(
      id INT AUTO_INCREMENT PRIMARY KEY,
      worker_id INT NOT NULL,
      period_start DATE NOT NULL,
      period_end DATE NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
      bank_name VARCHAR(100) NULL,
      bank_account_no VARCHAR(64) NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      paid_at DATETIME NULL,
      INDEX(worker_id), INDEX(period_start), INDEX(period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch(Throwable $e) { $err='Bootstrap failed: '.$e->getMessage(); }
}

// ---------- detect columns ----------
$wpBankCol  = pick_first_existing($conn,'worker_profiles',['bank_name','bank','bank_provider'],'bank_name');
$wpAccCol   = pick_first_existing($conn,'worker_profiles',['bank_account_no','bank_account','account_no','account_number'],'bank_account_no');
$wpPctCol   = pick_first_existing($conn,'worker_profiles',['payout_percent','share_percent'],'payout_percent');
$wpUserIdCol= col_exists($conn,'worker_profiles','user_id') ? 'user_id' : null;

$uBankCol = col_exists($conn,'users','bank_name') ? 'bank_name' : (col_exists($conn,'users','bank') ? 'bank' : null);
$uAccCol  = col_exists($conn,'users','bank_account_no') ? 'bank_account_no' :
            (col_exists($conn,'users','bank_account') ? 'bank_account' :
            (col_exists($conn,'users','account_no') ? 'account_no' :
            (col_exists($conn,'users','account_number') ? 'account_number' : null)));

// ---------- inputs ----------
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kuala_Lumpur'));
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$monthEnd   = $today->modify('last day of this month')->format('Y-m-d');

$from = $_GET['from'] ?? $monthStart;
$to   = $_GET['to']   ?? $monthEnd;

/* DEFAULT SHARE: 95% */
$shareInput = isset($_GET['share']) ? (float)$_GET['share'] : 95.0;

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from = $monthStart;
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   $to   = $monthEnd;
if($shareInput<0 || $shareInput>100) $shareInput = 95.0;

// ---------- actions ----------
$flash='';
if(!$err && $_SERVER['REQUEST_METHOD']==='POST'){
  $act = $_POST['action'] ?? '';

  if($act==='mark_paid'){
    $pid=(int)($_POST['payout_id']??0);
    if($pid>0){
      try{
        $stmt=$conn->prepare("UPDATE worker_payouts SET status='paid', paid_at=NOW() WHERE id=? AND status='pending'");
        $stmt->bind_param('i',$pid); $stmt->execute(); $stmt->close();
        $flash='Payout #'.$pid.' marked as PAID.';
      }catch(Throwable $e){ $flash='Fail to mark paid: '.$e->getMessage(); }
    }
  }

  if($act==='generate_payouts'){
    try{
      $joinUsersFallback = ($wpUserIdCol && ($uBankCol || $uAccCol))
        ? "LEFT JOIN users u2 ON u2.id = w.$wpUserIdCol"
        : "";

      $sql="
        SELECT b.id, b.assigned_worker_id AS wid,
               COALESCE(w.$wpPctCol, ?) AS worker_pct,
               COALESCE(b.final_paid_amount, b.estimated_price) AS gross,
               COALESCE(w.$wpBankCol, ".($uBankCol?"u2.$uBankCol":"NULL").") AS bank_name,
               COALESCE(w.$wpAccCol,  ".($uAccCol ?"u2.$uAccCol" :"NULL").") AS bank_account_no
        FROM bookings b
        LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
        $joinUsersFallback
        WHERE b.date BETWEEN ? AND ?
          AND b.assigned_worker_id IS NOT NULL
          AND b.status IN ('approved','assigned')
      ";
      $stmt=$conn->prepare($sql);
      $stmt->bind_param('dss',$shareInput,$from,$to);
      $stmt->execute();
      $rs=$stmt->get_result();

      $agg=[];
      while($r=$rs->fetch_assoc()){
        $wid=(int)$r['wid'];
        $pct=(float)$r['worker_pct']; if($pct<=0||$pct>100) $pct=$shareInput;
        $gross=(float)$r['gross']; $amt=round($gross*($pct/100),2);
        if(!isset($agg[$wid])) $agg[$wid]=['amount'=>0.0,'bank'=>$r['bank_name']??'','acc'=>$r['bank_account_no']??''];
        $agg[$wid]['amount'] += $amt;
      }
      $stmt->close();

      if($agg){
        $stmt=$conn->prepare("INSERT INTO worker_payouts(worker_id,period_start,period_end,amount,status,bank_name,bank_account_no,notes)
                              VALUES(?,?,?,?, 'pending', ?, ?, ?)");
        foreach($agg as $wid=>$a){
          $amount=round($a['amount'],2); if($amount<=0) continue;
          $notes='Auto-generated '.date('Y-m-d H:i:s').' @ '.$shareInput.'% base share';
          $stmt->bind_param('issdsss',$wid,$from,$to,$amount,$a['bank'],$a['acc'],$notes);
          $stmt->execute();
        }
        $stmt->close();
        $flash='Payouts generated for '.count($agg).' worker(s).';
      } else {
        $flash='No eligible bookings in this range.';
      }
    }catch(Throwable $e){ $flash='Generate error: '.$e->getMessage(); }
  }

  if($act==='export_csv'){
    $joinUsersFallback = ($wpUserIdCol && ($uBankCol || $uAccCol))
      ? "LEFT JOIN users u2 ON u2.id = w.$wpUserIdCol"
      : "";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=finance_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['BookingID','Date','Ref','Status','WorkerID','WorkerName','Bank','Account','Gross','WorkerShare(%)','WorkerAmount','PlatformAmount']);

    $sql="
      SELECT b.id,b.date,b.ref_code,b.status,
             w.id AS wid,w.name AS wname,
             COALESCE(w.$wpBankCol, ".($uBankCol?"u2.$uBankCol":"NULL").") AS bank_name,
             COALESCE(w.$wpAccCol,  ".($uAccCol ?"u2.$uAccCol" :"NULL").") AS bank_account_no,
             COALESCE(w.$wpPctCol, ?) AS worker_pct,
             COALESCE(b.final_paid_amount,b.estimated_price) AS gross
      FROM bookings b
      LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
      $joinUsersFallback
      WHERE b.date BETWEEN ? AND ?
        AND b.assigned_worker_id IS NOT NULL
        AND b.status IN ('approved','assigned')
      ORDER BY b.date ASC,b.id ASC";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param('dss',$shareInput,$from,$to);
    $stmt->execute();
    $rs=$stmt->get_result();
    while($r=$rs->fetch_assoc()){
      $gross=(float)$r['gross'];
      $pct=(float)$r['worker_pct']; if($pct<=0||$pct>100) $pct=$shareInput;
      $wAmt=round($gross*($pct/100),2); $pAmt=round($gross-$wAmt,2);
      fputcsv($out,[
        $r['id'],$r['date'],$r['ref_code'],$r['status'],
        $r['wid'],$r['wname'],$r['bank_name'],$r['bank_account_no'],
        number_format($gross,2,'.',''),$pct,number_format($wAmt,2,'.',''),number_format($pAmt,2,'.','')
      ]);
    }
    fclose($out); exit;
  }
}

// ---------- compute page data ----------
$summary=['gross'=>0.0,'worker'=>0.0,'platform'=>0.0,'jobs'=>0];
$bookings=[]; $workers=[]; $payouts=[];

if(!$err){
  $joinUsersFallback = ($wpUserIdCol && ($uBankCol || $uAccCol))
    ? "LEFT JOIN users u2 ON u2.id = w.$wpUserIdCol"
    : "";

  $sql="
    SELECT b.id,b.date,b.ref_code,b.status,
           COALESCE(b.final_paid_amount,b.estimated_price) AS gross,
           w.id AS wid, w.name AS wname,
           COALESCE(w.$wpBankCol, ".($uBankCol?"u2.$uBankCol":"NULL").") AS bank_name,
           COALESCE(w.$wpAccCol,  ".($uAccCol ?"u2.$uAccCol" :"NULL").") AS bank_account_no,
           COALESCE(w.$wpPctCol, ?) AS worker_pct
    FROM bookings b
    LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
    $joinUsersFallback
    WHERE b.date BETWEEN ? AND ?
      AND b.assigned_worker_id IS NOT NULL
      AND b.status IN ('approved','assigned')
    ORDER BY b.date ASC,b.id ASC";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param('dss',$shareInput,$from,$to);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($r=$rs->fetch_assoc()){
    $gross=(float)$r['gross'];
    $pct=(float)$r['worker_pct']; if($pct<=0||$pct>100) $pct=$shareInput;
    $wAmt=round($gross*($pct/100),2);
    $pAmt=round($gross-$wAmt,2);
    $summary['gross'] += $gross;
    $summary['worker']+= $wAmt;
    $summary['platform']+= $pAmt;
    $summary['jobs']++;
    $r['worker_amount']=$wAmt; $r['platform_amount']=$pAmt; $r['pct']=$pct;
    $bookings[]=$r;
  }
  $stmt->close();

  $res=$conn->query("
    SELECT id,name,
           COALESCE($wpBankCol, NULL) AS bank_name,
           COALESCE($wpAccCol,  NULL) AS bank_account_no,
           COALESCE($wpPctCol, 0) AS payout_percent
    FROM worker_profiles
    ORDER BY name ASC
  ");
  while($row=$res->fetch_assoc()) $workers[]=$row;

  $res=$conn->query("SELECT p.*, w.name AS worker_name
                     FROM worker_payouts p
                     LEFT JOIN worker_profiles w ON w.id=p.worker_id
                     ORDER BY p.id DESC
                     LIMIT 20");
  while($row=$res->fetch_assoc()) $payouts[]=$row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin · Finance – NeinMaid</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css"><!-- reuse dashboard css if present -->
  <style>
    /* Match admin_dashboard look */
    body{background:#f6f7fb;color:#0f172a;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px 12px}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand img{width:28px;height:28px}
    .nav{display:flex;flex-direction:column;margin-top:12px}
    .nav a{padding:10px 12px;border-radius:10px;color:#111827;text-decoration:none;margin:2px 0}
    .nav a.active, .nav a:hover{background:#eef2ff}
    .main{padding:18px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .row-sb{display:flex;justify-content:space-between;align-items:center}
    .right{display:flex;justify-content:flex-end}
    .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
    .btn:hover{background:#f8fafc}
    .btn-primary{background:#111827;color:#fff;border-color:#111827}
    .btn-primary:hover{opacity:.9}
    .input{padding:8px;border:1px solid #e5e7eb;border-radius:10px}
    .small{font-size:12px}
    .muted{color:#6b7280}
    .pill{padding:2px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc}
    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;font-size:14px;vertical-align:top}
    thead th{background:#f8fafc}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb}
    .tag.paid{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .tag.pending{background:#fffbeb;border-color:#fde68a;color:#92400e}
    .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .grid-2{display:grid;grid-template-columns:2fr 1fr;gap:12px}
    @media (max-width:1100px){ .grid-2{grid-template-columns:1fr} }
  </style>
</head>
<body>

<div class="layout">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      <div>NeinMaid</div>
    </div>

    <div class="nav">
      <a class="active" href="admin_dashboard.php">Dashboard</a>
      <a href="admin_bookings.php">Bookings</a>
      <a href="admin_employees.php">Workers</a>
      <a href="admin_services.php">Services</a>
      <a href="admin_finance.php">Finance</a>
      <a href="admin_promos.php">Promotions</a>
      <a href="admin_worker_changes.php">Worker Changes</a>
      <a href="admin_chat.php">Support Chat</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <!-- Top toolbar -->
    <section class="card">
      <div class="row-sb">
        <h1 style="margin:0">Finance</h1>
        <div class="row">
          <label class="small muted" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" id="autoRefresh" style="transform:scale(1.2)"> Auto-refresh (20s)
          </label>
          <?php if($flash): ?><div class="pill"><?= h($flash) ?></div><?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Filters -->
    <section class="card" style="margin-top:12px">
      <form class="row-sb" method="get" action="admin_finance.php">
        <div class="row">
          <div>
            <div class="small muted">From</div>
            <input class="input" type="date" name="from" value="<?= h($from) ?>">
          </div>
          <div>
            <div class="small muted">To</div>
            <input class="input" type="date" name="to" value="<?= h($to) ?>">
          </div>
          <div>
            <div class="small muted">Worker Share % (default 95)</div>
            <input class="input" type="number" name="share" value="<?= h($shareInput) ?>" min="0" max="100" step="0.1" style="width:110px">
          </div>
        </div>
        <div class="row">
          <button class="btn btn-primary" type="submit">Apply</button>
          <button class="btn" type="submit" formaction="admin_finance.php" formmethod="post" name="action" value="export_csv">Export CSV</button>
        </div>
      </form>
    </section>

    <!-- KPIs -->
    <section class="grid-3" style="margin-top:12px">
      <div class="card">
        <div class="small muted">Gross (<?= h($from) ?> → <?= h($to) ?>)</div>
        <div style="font-size:24px;font-weight:800"><?= money_rm($summary['gross']) ?></div>
      </div>
      <div class="card">
        <div class="small muted">Worker Share</div>
        <div style="font-size:24px;font-weight:800"><?= money_rm($summary['worker']) ?></div>
      </div>
      <div class="card">
        <div class="small muted">Platform Fee</div>
        <div style="font-size:24px;font-weight:800"><?= money_rm($summary['platform']) ?></div>
      </div>
    </section>

    <!-- Bookings + Worker Directory -->
    <section class="grid-2" style="margin-top:12px">
      <div class="card">
        <div class="row-sb">
          <div class="row">
            <div class="pill">Jobs: <?= (int)$summary['jobs'] ?></div>
          </div>
          <form method="post">
            <input type="hidden" name="action" value="generate_payouts">
            <button class="btn btn-primary" type="submit">Generate Payouts for Range</button>
          </form>
        </div>
        <div class="table-wrap" style="margin-top:10px">
          <table>
            <thead>
              <tr>
                <th>Date</th><th>Ref</th><th>Status</th>
                <th>Worker</th><th>Bank</th><th>Account</th>
                <th>Gross</th><th>Share %</th><th>Worker</th><th>Platform</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$bookings): ?>
                <tr><td colspan="10" class="muted">No data in range.</td></tr>
              <?php else: foreach($bookings as $b): ?>
                <tr>
                  <td><?= h($b['date']) ?></td>
                  <td><?= h($b['ref_code']) ?></td>
                  <td><span class="tag"><?= h(ucfirst($b['status'])) ?></span></td>
                  <td><?= h($b['wname']).' (ID '.$b['wid'].')' ?></td>
                  <td><?= h($b['bank_name'] ?: '—') ?></td>
                  <td><?= h($b['bank_account_no'] ?: '—') ?></td>
                  <td><?= money_rm($b['gross']) ?></td>
                  <td><?= number_format((float)$b['pct'],1) ?>%</td>
                  <td><?= money_rm($b['worker_amount']) ?></td>
                  <td><?= money_rm($b['platform_amount']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="row-sb">
          <h3 style="margin:0">Worker Bank Directory</h3>
        </div>
        <div class="table-wrap" style="margin-top:10px;max-height:520px">
          <table>
            <thead><tr><th>Name</th><th>Bank</th><th>Account</th><th>Share%</th></tr></thead>
            <tbody>
            <?php if(!$workers): ?>
              <tr><td colspan="4" class="muted">No workers yet.</td></tr>
            <?php else: foreach($workers as $w): ?>
              <tr>
                <td><?= h($w['name']).' (ID '.$w['id'].')' ?></td>
                <td><?= h($w['bank_name'] ?: '—') ?></td>
                <td><?= h($w['bank_account_no'] ?: '—') ?></td>
                <td><?= $w['payout_percent']>0? number_format($w['payout_percent'],1).'%' : '—'; ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="small muted" style="margin-top:8px">
          If blank, set banks on the worker profile (columns <code><?= h($wpBankCol) ?></code>, <code><?= h($wpAccCol) ?></code>).
        </div>
      </div>
    </section>

    <!-- Payouts Ledger -->
    <section class="card" style="margin-top:12px">
      <div class="row-sb">
        <h3 style="margin:0">Payouts Ledger</h3>
      </div>
      <div class="table-wrap" style="margin-top:10px">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Worker</th><th>Period</th><th>Bank</th><th>Account</th>
              <th>Amount</th><th>Status</th><th>Created</th><th>Paid At</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$payouts): ?>
            <tr><td colspan="10" class="muted">No payouts yet. Use “Generate Payouts for Range”.</td></tr>
          <?php else: foreach($payouts as $p): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td><?= h($p['worker_name'] ?: ('#'.$p['worker_id'])) ?></td>
              <td><?= h($p['period_start']).' → '.h($p['period_end']) ?></td>
              <td><?= h($p['bank_name'] ?: '—') ?></td>
              <td><?= h($p['bank_account_no'] ?: '—') ?></td>
              <td><?= money_rm($p['amount']) ?></td>
              <td><span class="tag <?= $p['status']==='paid'?'paid':'pending' ?>"><?= ucfirst($p['status']) ?></span></td>
              <td class="small muted"><?= h($p['created_at']) ?></td>
              <td class="small muted"><?= h($p['paid_at'] ?: '—') ?></td>
              <td>
                <?php if($p['status']==='pending'): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Mark payout #<?= (int)$p['id'] ?> as PAID?');">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="payout_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-primary">Mark Paid</button>
                  </form>
                <?php else: ?>
                  <button class="btn" disabled>Paid</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<script>
// ---- Auto refresh (20s) with remembered toggle ----
const key='finance_auto_refresh';
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
