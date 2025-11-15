<?php
// admin_dashboard.php (fixed with safe auto-migration for worker_profile_changes)
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  header("Location: login.php"); exit();
}

require_once 'announcements_store.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

date_default_timezone_set('Asia/Kuala_Lumpur');
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$next3      = date('Y-m-d', strtotime('+3 days'));

/* ---------- helpers ---------- */
function table_has_col(mysqli $conn, string $table, string $col): bool {
  try{
    $db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    $db = $conn->real_escape_string($db);
    $t  = $conn->real_escape_string($table);
    $c  = $conn->real_escape_string($col);
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}' LIMIT 1");
    return ($rs && $rs->num_rows>0);
  }catch(Throwable $e){ return false; }
}
function get_table_columns(mysqli $conn, string $table): array {
  $cols=[]; try{ $r=$conn->query("SHOW COLUMNS FROM `$table`"); while($row=$r->fetch_assoc()) $cols[]=$row['Field']; }catch(Throwable $e){}
  return $cols;
}

/* ---------- make sure worker_profile_changes is ready ---------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS worker_profile_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    submitted_by INT NULL,
    payload TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX (worker_id), INDEX (status), INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
/* add missing columns if table already existed without them */
try{
  if (!table_has_col($conn,'worker_profile_changes','submitted_by')) {
    $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN submitted_by INT NULL AFTER worker_id");
  }
  if (!table_has_col($conn,'worker_profile_changes','payload')) {
    $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN payload TEXT NOT NULL");
  }
  if (!table_has_col($conn,'worker_profile_changes','status')) {
    $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
  }
  if (!table_has_col($conn,'worker_profile_changes','created_at')) {
    $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  }
  if (!table_has_col($conn,'worker_profile_changes','reviewed_at')) {
    $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN reviewed_at DATETIME NULL");
  }
  if (!table_has_col($conn,'worker_profile_changes','reviewed_by')) {
    $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN reviewed_by INT NULL");
  }
}catch(Throwable $e){
  // swallow — dashboard should still load; you can inspect DB later if needed
}

/* ---------- Review column auto-detect (text/comment field) ---------- */
$reviewColsFlip = array_flip(get_table_columns($conn,'booking_reviews'));
$COL_REVIEW_TEXT = null;
foreach (['comment','review','review_text','comments','message','content','text'] as $cand) {
  if (isset($reviewColsFlip[$cand])) { $COL_REVIEW_TEXT = $cand; break; }
}

/* ---------- KPIs ---------- */
$st = $conn->prepare("SELECT COUNT(*) c FROM bookings WHERE date BETWEEN ? AND ? AND status <> 'cancelled'");
$st->bind_param('ss', $monthStart, $monthEnd); $st->execute();
$k_total_month = (int)$st->get_result()->fetch_assoc()['c']; $st->close();

$st = $conn->prepare("
  SELECT COUNT(DISTINCT assigned_worker_id) c
  FROM bookings
  WHERE date = ?
    AND assigned_worker_id IS NOT NULL
    AND status IN ('assigned','approved')
");
$st->bind_param('s', $today); $st->execute();
$k_active_cleaners = (int)$st->get_result()->fetch_assoc()['c']; $st->close();

$k_pending = (int)$conn->query("SELECT COUNT(*) c FROM bookings WHERE status='pending'")->fetch_assoc()['c'];

$st = $conn->prepare("
  SELECT ROUND(AVG(br.rating),1) AS r
  FROM booking_reviews br
  JOIN bookings b ON b.id = br.booking_id
  WHERE b.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
$k_avg_rating = $r && $r['r']!==null ? (float)$r['r'] : null;

/* ---------- Announcements ---------- */
$ann = ann_list(5);

/* ---------- Unassigned within 3 days ---------- */
$soon=[];
$st=$conn->prepare("
  SELECT b.id,b.ref_code,u.name AS user_name,b.area,b.service,b.date,b.time_slot
  FROM bookings b JOIN users u ON u.id=b.user_id
  WHERE b.date BETWEEN ? AND ?
    AND b.assigned_worker_id IS NULL
    AND b.status IN ('pending','approved')
  ORDER BY b.date ASC,b.time_slot ASC
  LIMIT 10
");
$st->bind_param('ss',$today,$next3); $st->execute();
$rs=$st->get_result(); while($row=$rs->fetch_assoc()) $soon[]=$row; $st->close();

/* ---------- Recent bookings (with latest review) ---------- */
$recent=[];
$reviewSelect = $COL_REVIEW_TEXT ? (", br.`$COL_REVIEW_TEXT` AS review_comment") : (", NULL AS review_comment");
$sqlRecent = "
  SELECT b.id,b.ref_code,b.area,b.service,b.date,b.time_slot,b.status,b.assigned_worker_id,b.estimated_price,
         u.name AS user_name, u.email AS user_email, w.name AS worker_name,
         br.rating AS review_rating $reviewSelect, br.created_at AS review_created
  FROM bookings b
  JOIN users u ON u.id=b.user_id
  LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
  LEFT JOIN (
    SELECT r1.*
    FROM booking_reviews r1
    JOIN (
      SELECT booking_id, MAX(id) AS max_id
      FROM booking_reviews
      GROUP BY booking_id
    ) mx ON mx.max_id = r1.id
  ) br ON br.booking_id = b.id
  ORDER BY b.id DESC
  LIMIT 12
";
$res=$conn->query($sqlRecent);
while($row=$res->fetch_assoc()) $recent[]=$row;

/* ---------- Latest reviews ---------- */
$latestReviews=[];
$commentAlias = $COL_REVIEW_TEXT ? "br.`$COL_REVIEW_TEXT` AS comment" : "NULL AS comment";
$sqlLR = "
  SELECT br.booking_id, br.rating, $commentAlias, br.created_at, u.name AS user_name, b.ref_code
  FROM booking_reviews br
  JOIN bookings b ON b.id = br.booking_id
  JOIN users u ON u.id = b.user_id
  ORDER BY br.created_at DESC
  LIMIT 8
";
$res=$conn->query($sqlLR);
while($row=$res->fetch_assoc()) $latestReviews[]=$row;

/* ---------- Charts (month) ---------- */
$labels=[]; $bookingsPerDay=[]; $revenuePerDay=[];
$statusCounts=['pending'=>0,'assigned'=>0,'approved'=>0,'cancelled'=>0];

for($ts=strtotime($monthStart); $ts<=strtotime($monthEnd); $ts+=86400){
  $d=date('Y-m-d',$ts);
  $labels[] = date('d',$ts);
  $bookingsPerDay[$d]=0; $revenuePerDay[$d]=0.0;
}
$stmt=$conn->prepare("SELECT date, COUNT(*) c FROM bookings WHERE date BETWEEN ? AND ? AND status<>'cancelled' GROUP BY date");
$stmt->bind_param('ss',$monthStart,$monthEnd); $stmt->execute();
$r=$stmt->get_result(); while($row=$r->fetch_assoc()){ if(isset($bookingsPerDay[$row['date']])) $bookingsPerDay[$row['date']] = (int)$row['c']; }
$stmt->close();

$stmt=$conn->prepare("SELECT date, COALESCE(SUM(estimated_price),0) s FROM bookings WHERE date BETWEEN ? AND ? AND status IN ('assigned','approved') GROUP BY date");
$stmt->bind_param('ss',$monthStart,$monthEnd); $stmt->execute();
$r=$stmt->get_result(); while($row=$r->fetch_assoc()){ if(isset($revenuePerDay[$row['date']])) $revenuePerDay[$row['date']] = (float)$row['s']; }
$stmt->close();

$stmt=$conn->prepare("SELECT LOWER(status) s, COUNT(*) c FROM bookings WHERE date BETWEEN ? AND ? GROUP BY LOWER(status)");
$stmt->bind_param('ss',$monthStart,$monthEnd); $stmt->execute();
$r=$stmt->get_result(); while($row=$r->fetch_assoc()){ if(isset($statusCounts[$row['s']])) $statusCounts[$row['s']] = (int)$row['c']; }
$stmt->close();

$volSeries = array_values($bookingsPerDay);
$revSeries = array_map(fn($n)=>round((float)$n,2), array_values($revenuePerDay));

/* ---------- Worker profile change requests (pending) ---------- */
$pendingChanges=[];
$q = "
  SELECT c.id, c.worker_id, c.submitted_by, c.payload, c.status, c.created_at,
         w.name AS worker_name, w.email AS worker_email
  FROM worker_profile_changes c
  LEFT JOIN worker_profiles w ON w.id = c.worker_id
  WHERE c.status='pending'
  ORDER BY c.created_at ASC
  LIMIT 20
";
$res = $conn->query($q);
while($row=$res->fetch_assoc()) $pendingChanges[]=$row;

/* ---------- ui helper ---------- */
function tag($s){
  $s=strtolower($s);
  $map=['pending'=>'pending','assigned'=>'ok','approved'=>'ok','cancelled'=>'bad'];
  $cls=$map[$s]??'muted';
  return '<span class="tag '.$cls.'">'.ucfirst($s).'</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body{background:#f6f7fb;color:#0f172a}
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px 12px}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand img{width:28px;height:28px}
    .nav{display:flex;flex-direction:column;margin-top:12px}
    .nav a{padding:10px 12px;border-radius:10px;color:#111827;text-decoration:none;margin:2px 0}
    .nav a.active, .nav a:hover{background:#eef2ff}
    .main{padding:18px}
    .cards4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    .kpi{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .kpi-title{color:#64748b;font-size:12px}
    .kpi-value{font-size:28px;font-weight:800}
    .kpi-sub{color:#94a3b8;font-size:12px}
    .grid-analytics{display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-top:12px}
    .grid-two{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .card-head{font-weight:700;margin-bottom:8px}
    .row{display:flex;gap:10px;align-items:center}
    .right{display:flex;justify-content:flex-end}
    .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
    .btn:hover{background:#f8fafc}
    .btn-ghost{background:transparent}
    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px;vertical-align:top}
    thead th{background:#f8fafc}
    .updates{list-style:none;margin:0;padding:0}
    .updates li{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9}
    .badge{width:24px;height:24px;display:grid;place-items:center;border-radius:999px;background:#eef2ff}
    .u-body{flex:1}
    .u-title{font-weight:700}
    .u-sub{color:#475569}
    .u-meta{color:#94a3b8;font-size:12px}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px}
    .tag.ok{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
    .tag.pending{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
    .tag.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .muted{color:#64748b}
    .mini{font-size:12px;color:#64748b}
    .payload{background:#f8fafc;border:1px dashed #e5e7eb;border-radius:10px;padding:8px;white-space:pre-wrap}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
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
    <!-- KPIs -->
    <section class="cards4">
      <div class="kpi kpi-orange">
        <div class="kpi-title">All Bookings (Month)</div>
        <div class="kpi-value"><?= (int)$k_total_month ?></div>
        <div class="kpi-sub">Updated <?= date('g:i a') ?></div>
      </div>
      <div class="kpi kpi-green">
        <div class="kpi-title">Active Cleaners Today</div>
        <div class="kpi-value"><?= (int)$k_active_cleaners ?></div>
        <div class="kpi-sub">Updated <?= date('g:i a') ?></div>
      </div>
      <div class="kpi kpi-red">
        <div class="kpi-title">Pending Bookings</div>
        <div class="kpi-value"><?= (int)$k_pending ?></div>
        <div class="kpi-sub">Updated <?= date('g:i a') ?></div>
      </div>
      <div class="kpi kpi-cyan">
        <div class="kpi-title">Avg Rating (30d)</div>
        <div class="kpi-value"><?= $k_avg_rating!==null ? $k_avg_rating.'★' : '—' ?></div>
        <div class="kpi-sub">Updated <?= date('g:i a') ?></div>
      </div>
    </section>

    <!-- Analytics + Quick CTA -->
    <section class="grid-analytics">
      <div class="card">
        <div class="card-head">Sales Analytics (This Month)</div>
        <canvas id="chartBookings"></canvas>
      </div>
      <div class="card project">
        <div class="card-head">Today Snapshot</div>
        <div class="dial" style="text-align:center;padding:18px 0">
          <div style="font-size:40px;font-weight:800;line-height:1"><?= (int)$k_active_cleaners ?></div>
          <div class="mini">Active Cleaners</div>
        </div>
        <a class="btn w-full" href="admin_bookings.php">Download Overall Report</a>
      </div>
    </section>

    <!-- Revenue & Status -->
    <section class="grid-two">
      <div class="card">
        <div class="card-head">Daily Estimated Revenue</div>
        <canvas id="chartRevenue"></canvas>
      </div>
      <div class="card">
        <div class="card-head">Status Breakdown (Month)</div>
        <canvas id="chartStatus"></canvas>
      </div>
    </section>

    <!-- Worker Profile Change Requests -->
    <section class="card" style="margin-top:12px">
      <div class="card-head row" style="justify-content:space-between">
        <span>Worker Profile Change Requests (Pending)</span>
        <a href="admin_employees.php" class="btn btn-ghost">Open Workers</a>
      </div>
      <?php if(!$pendingChanges): ?>
        <div class="muted">No pending requests.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Worker</th><th>Submitted</th><th>Fields</th><th>Preview</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($pendingChanges as $pc):
              $payload = json_decode($pc['payload'] ?? '{}', true) ?: [];
              $keys = array_keys($payload);
            ?>
              <tr id="chg<?= (int)$pc['id'] ?>">
                <td>#<?= (int)$pc['id'] ?></td>
                <td>
                  <?= h($pc['worker_name'] ?: ('#'.$pc['worker_id'])) ?><br>
                  <span class="mini"><?= h($pc['worker_email'] ?: '') ?></span>
                </td>
                <td><?= h($pc['created_at']) ?></td>
                <td><?= count($keys) ?> field(s)</td>
                <td>
                  <details>
                    <summary class="mini">Show</summary>
                    <div class="payload">
                      <?php if(!$payload): ?>
                        <span class="muted">—</span>
                      <?php else: ?>
                        <?php foreach($payload as $k=>$v): ?>
                          <div><strong><?= h($k) ?>:</strong> <?= h(is_array($v)?json_encode($v):$v) ?></div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </details>
                </td>
                <td class="actions">
                  <button class="btn" onclick="approveReq(<?= (int)$pc['id'] ?>)">Approve</button>
                  <button class="btn" onclick="rejectReq(<?= (int)$pc['id'] ?>)">Reject</button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- Unassigned + Latest Reviews -->
    <section class="grid-two" style="margin-top:12px">
      <div class="card">
        <div class="card-head">Unassigned (next 3 days)</div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Ref</th><th>Date</th><th>Time</th><th>Customer</th><th>Area</th><th>Service</th></tr></thead>
            <tbody>
              <?php if(!$soon): ?>
                <tr><td colspan="6" class="muted">All good—nothing to assign.</td></tr>
              <?php else: foreach($soon as $s): ?>
                <tr>
                  <td><?= h($s['ref_code']) ?></td>
                  <td><?= h($s['date']) ?></td>
                  <td><?= h($s['time_slot']) ?></td>
                  <td><?= h($s['user_name']) ?></td>
                  <td><?= h($s['area']) ?></td>
                  <td><?= h($s['service']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="right mt-8">
          <a class="btn" href="admin_bookings.php">Go assign →</a>
        </div>
      </div>

      <div class="card">
        <div class="card-head row" style="justify-content:space-between">
          <span>Latest Reviews</span>
          <a class="btn btn-ghost" href="admin_bookings.php">Open Bookings</a>
        </div>
        <ul class="updates">
          <?php if(!$latestReviews): ?>
            <li class="muted">No reviews yet.</li>
          <?php else: foreach($latestReviews as $lr): ?>
            <li>
              <div class="badge">★</div>
              <div class="u-body">
                <div class="u-title">
                  <?= h($lr['user_name']) ?> · Ref <?= h($lr['ref_code']) ?> · Rating <?= (int)$lr['rating'] ?>★
                </div>
                <?php if(!empty($lr['comment'])): ?>
                  <div class="u-sub"><?= h(mb_strimwidth($lr['comment'],0,120,'…')) ?></div>
                <?php else: ?>
                  <div class="u-sub muted">(no comment)</div>
                <?php endif; ?>
                <div class="u-meta"><?= h($lr['created_at']) ?></div>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </section>

    <!-- Latest Updates (Announcements) -->
    <section class="grid-two" style="margin-top:12px">
      <div class="card">
        <div class="card-head">Latest Updates</div>
        <ul class="updates">
          <?php if(!$ann): ?>
            <li class="muted">No announcements yet.</li>
          <?php else: foreach($ann as $a): ?>
            <li>
              <div class="badge">i</div>
              <div class="u-body">
                <div class="u-title"><?= h($a['title']) ?></div>
                <div class="u-sub"><?= h($a['body']) ?></div>
                <div class="u-meta"><?= h($a['created_at']) ?> · <?= h($a['author']??'Admin') ?></div>
              </div>
              <button class="btn btn-ghost sm" onclick="delAnn(<?= (int)$a['id'] ?>)">Delete</button>
            </li>
          <?php endforeach; endif; ?>
        </ul>
        <div class="right"><button class="btn" onclick="openAnn()">Post Announcement</button></div>
      </div>

      <div class="card info">
        <div class="card-head">Quick Links</div>
        <div class="row" style="flex-wrap:wrap">
          <a class="btn" href="admin_finance.php">Finance Overview</a>
          <a class="btn" href="admin_employees.php">Manage Workers</a>
          <a class="btn" href="admin_services.php">Edit Services</a>
          <a class="btn" href="admin_bookings.php?export=1">Export Bookings</a>
        </div>
      </div>
    </section>

    <!-- Recent bookings -->
    <section class="card" style="margin-top:12px">
      <div class="card-head row" style="justify-content:space-between">
        <span>Recent Bookings</span>
        <div class="row">
          <a class="btn btn-ghost" href="admin_bookings.php">Manage</a>
          <a class="btn btn-ghost" href="admin_bookings.php?export=1">Export</a>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Ref</th><th>Customer</th><th>Area</th><th>Service</th><th>Date</th><th>Time</th><th>Status</th><th>Cleaner</th>
              <th>Total</th><th>Rating</th><th>Review</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$recent): ?>
              <tr><td colspan="11" class="muted">No bookings yet.</td></tr>
            <?php else: foreach($recent as $b): ?>
              <tr>
                <td><?= h($b['ref_code']) ?></td>
                <td><?= h($b['user_name']) ?> <div class="mini"><?= h($b['user_email']) ?></div></td>
                <td><?= h($b['area']) ?></td>
                <td><?= h($b['service']) ?></td>
                <td><?= h($b['date']) ?></td>
                <td><?= h($b['time_slot']) ?></td>
                <td><?= tag($b['status']) ?></td>
                <td><?= $b['assigned_worker_id'] ? h($b['worker_name'] ?: ('#'.$b['assigned_worker_id'])) : '—' ?></td>
                <td><?= money_rm($b['estimated_price']) ?></td>
                <td><?= $b['review_rating']!==null ? ((int)$b['review_rating']).'★' : '—' ?></td>
                <td><?= !empty($b['review_comment']) ? h(mb_strimwidth($b['review_comment'],0,50,'…')) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<!-- Post Announcement modal -->
<div id="annModal" class="modal">
  <div class="modal-box">
    <div class="row" style="justify-content:space-between;margin-bottom:8px">
      <h3 style="margin:0">Broadcast Announcement</h3>
      <button class="btn btn-ghost" onclick="closeAnn()">✖</button>
    </div>
    <input id="annTitle" class="input" placeholder="Title" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:8px">
    <textarea id="annBody" class="input" rows="4" placeholder="Message" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px"></textarea>
    <div class="right" style="margin-top:8px"><button class="btn" onclick="sendAnn()">Send</button></div>
  </div>
</div>

<script>
  /* Charts */
  const lbls  = <?= json_encode($labels) ?>;
  const vols  = <?= json_encode($volSeries) ?>;
  const revs  = <?= json_encode($revSeries) ?>;
  const status = <?= json_encode($statusCounts) ?>;

  new Chart(document.getElementById('chartBookings'), {
    type:'line',
    data:{ labels:lbls, datasets:[{ label:'Bookings', data:vols, tension:.35 }]},
    options:{ plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 }}}}
  });

  new Chart(document.getElementById('chartRevenue'), {
    type:'bar',
    data:{ labels:lbls, datasets:[{ label:'Revenue', data:revs }]},
    options:{ plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true, ticks:{ callback:v=>'RM '+v }}}}
  });

  new Chart(document.getElementById('chartStatus'), {
    type:'doughnut',
    data:{ labels:['Pending','Assigned','Approved','Cancelled'],
      datasets:[{ data:[status.pending||0,status.assigned||0,status.approved||0,status.cancelled||0] }]},
    options:{ plugins:{ legend:{ position:'bottom' }}, cutout:'55%' }
  });

  /* Announcements */
  function openAnn(){ document.getElementById('annModal').classList.add('show'); }
  function closeAnn(){ document.getElementById('annModal').classList.remove('show'); }
  async function sendAnn(){
    const t=document.getElementById('annTitle').value.trim();
    const b=document.getElementById('annBody').value.trim();
    if(!t||!b){ alert('Title and message required.'); return; }
    const fd=new FormData(); fd.append('title',t); fd.append('body',b);
    const r=await fetch('admin_announce_post.php',{method:'POST',body:fd}); const tx=await r.text();
    let j; try{ j=JSON.parse(tx); }catch{ alert(tx); return; }
    if(j.ok){ location.reload(); } else alert(j.msg||'Failed');
  }
  async function delAnn(id){
    if(!confirm('Delete this announcement?')) return;
    const fd=new FormData(); fd.append('id',id);
    const r=await fetch('admin_announce_delete.php',{method:'POST',body:fd});
    const tx=await r.text(); let j; try{ j=JSON.parse(tx); }catch{ alert(tx); return; }
    if(j.ok){ location.reload(); } else alert(j.msg||'Failed');
  }

  /* Worker change requests actions */
  async function approveReq(id){
    if(!confirm('Approve this profile update?')) return;
    const fd=new FormData(); fd.append('id',id); fd.append('action','approve');
    const r=await fetch('admin_worker_change_action.php',{method:'POST',body:fd});
    const tx=await r.text(); let j; try{ j=JSON.parse(tx); }catch{ alert(tx); return; }
    if(j.ok){ document.getElementById('chg'+id)?.remove(); } else alert(j.msg||'Failed');
  }
  async function rejectReq(id){
    if(!confirm('Reject this profile update?')) return;
    const fd=new FormData(); fd.append('id',id); fd.append('action','reject');
    const r=await fetch('admin_worker_change_action.php',{method:'POST',body:fd});
    const tx=await r.text(); let j; try{ j=JSON.parse(tx); }catch{ alert(tx); return; }
    if(j.ok){ document.getElementById('chg'+id)?.remove(); } else alert(j.msg||'Failed');
  }
</script>

</body>
</html>




