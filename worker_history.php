<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'worker') {
  header("Location: login.php"); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* --- small helper --- */
function col_exists(mysqli $conn, $table, $col){
  $table = preg_replace('/[^A-Za-z0-9_]/','',(string)$table);
  $dbRow = $conn->query("SELECT DATABASE()")->fetch_row();
  $db = $conn->real_escape_string($dbRow[0] ?? '');
  $t  = $conn->real_escape_string($table);
  $c  = $conn->real_escape_string((string)$col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $rs = $conn->query($sql);
  return ($rs && $rs->num_rows>0);
}

/* --- resolve worker profile --- */
$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['name']  ?? 'Worker';
$userMail = $_SESSION['email'] ?? '';
$workerId = (int)($_SESSION['worker_id'] ?? 0);

if (!$workerId) {
  $st = $conn->prepare("SELECT id,name,status FROM worker_profiles WHERE user_id=? LIMIT 1");
  $st->bind_param("i",$userId); $st->execute();
  $row = $st->get_result()->fetch_assoc(); $st->close();
  if ($row){ $workerId = (int)$row['id']; $_SESSION['worker_id'] = $workerId; $workerName = $row['name'] ?? $userName; $availability = $row['status'] ?? 'Enabled'; }
}

if (!$workerId) { $workerName = $userName; $availability = 'Enabled'; }

/* --- ensure ratings table (safe if exists) --- */
$conn->query("
CREATE TABLE IF NOT EXISTS booking_ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  worker_id INT NOT NULL,
  stars TINYINT NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rating (booking_id, worker_id),
  INDEX (worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* --- columns present? --- */
$hasAddress = col_exists($conn,'bookings','address');

/* --- consider completed/past --- */
date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');

$cols = "b.id,b.ref_code,b.service,b.area".($hasAddress?",b.address":"").",b.date,b.time_slot,b.estimated_price,
         u.name AS customer_name,u.phone AS customer_phone,
         r.stars,r.comment,r.created_at AS rated_at";

/* match both possible schemas for assigned_worker_id (worker_profiles.id or users.id) */
$sql = "
  SELECT $cols
  FROM bookings b
  JOIN users u ON u.id=b.user_id
  LEFT JOIN booking_ratings r
    ON r.booking_id=b.id AND r.worker_id=?
  WHERE
    (b.assigned_worker_id = ? OR b.assigned_worker_id = ?)
    AND (
      b.status = 'completed'
      OR (b.date < ? AND b.status IN ('approved','assigned'))
    )
  ORDER BY b.date DESC, b.id DESC
  LIMIT 200
";
$st = $conn->prepare($sql);
$st->bind_param("iiis", $workerId, $workerId, $userId, $today);
$st->execute();
$list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* star renderer */
function stars($n){
  $n = (int)$n;
  if ($n<=0) return '—';
  return str_repeat('★',$n) . str_repeat('☆',max(0,5-$n));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Worker History – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --brand:#10b981; --brand-600:#059669; }
  *{box-sizing:border-box}
  body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:#0f172a}

  /* === shared layout (copied from dashboard) === */
  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#cbd5e1;padding:20px;display:flex;flex-direction:column;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:900}
  .avatar{width:40px;height:40px;border-radius:50%;background:#1f2937;display:grid;place-items:center;color:#fff;font-weight:800}
  .nav a{display:block;color:#cbd5e1;text-decoration:none;padding:10px;border-radius:10px}
  .nav a.active,.nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .main{padding:20px;color:var(--ink)}
  .small{color:#64748b;font-size:12px}

  /* content */
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left;font-size:14px;vertical-align:top}
  thead th{background:#f8fafc}
  .muted{color:#64748b}
  @media (max-width:1100px){ .layout{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="layout">
  <aside class="side">
    <div class="brand">
      <div class="avatar"><?= strtoupper(substr(($workerName ?? $userName),0,1)) ?></div>
      NeinMaid Worker
    </div>
    <nav class="nav">
      <a href="worker_dashboard.php">🏠 Dashboard</a>
      <a class="active" href="worker_history.php">📜 Job History</a>
      <a href="worker_finance.php">💰 Finance</a>
      <a href="worker_profile.php">👤 Profile</a>
      <a href="contact_chat.php">💬 Chats</a>
    </nav>
    <div style="flex:1"></div>
    <a class="nav" href="logout.php" style="color:#fecaca;text-decoration:none">⏻ Logout</a>
  </aside>

  <main class="main">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h2 style="margin:0">📜 Job History</h2>
        <div class="small"><?= date('D, d M Y') ?></div>
      </div>

      <?php if (!$list): ?>
        <div class="small">No completed/past jobs found for your account yet.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table>
            <thead>
              <tr>
                <th>Ref</th>
                <th>Date / Time</th>
                <th>Customer</th>
                <th>Service & Location</th>
                <th>Price</th>
                <th>Rating</th>
                <th>Feedback</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($list as $r):
              $addr  = isset($r['address']) && $r['address'] ? $r['address'] : ($r['area'] ?? '');
              $price = (isset($r['estimated_price']) && $r['estimated_price']!==null)
                        ? 'RM '.number_format((float)$r['estimated_price'],2)
                        : '—';
            ?>
              <tr>
                <td><strong><?= h($r['ref_code'] ?: ('#'.$r['id'])) ?></strong></td>
                <td>
                  <?= h($r['date']) ?><br>
                  <span class="small"><?= h($r['time_slot'] ?: '—') ?></span>
                </td>
                <td>
                  <?= h($r['customer_name']) ?><br>
                  <span class="small"><?= h($r['customer_phone'] ?: '') ?></span>
                </td>
                <td>
                  <?= h($r['service']) ?><br>
                  <span class="muted"><?= h($addr ?: '—') ?></span>
                </td>
                <td><?= $price ?></td>
                <td><?= stars($r['stars'] ?? 0) ?></td>
                <td class="small"><?= nl2br(h($r['comment'] ?: '—')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
