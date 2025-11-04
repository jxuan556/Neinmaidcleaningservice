<?php
// admin_user_overview.php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  header("Location: login.php"); exit();
}
date_default_timezone_set('Asia/Kuala_Lumpur');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
if ($uid<=0){ header("Location: admin_users.php"); exit(); }

/* Load basic user */
$user=null;
try{
  $st = $conn->prepare("SELECT id, name, email, COALESCE(phone,'') AS phone,
    COALESCE(address_line,'') AS address_line,
    COALESCE(home_type,'') AS home_type,
    COALESCE(home_sqft,'') AS home_sqft,
    COALESCE(created_at,'') AS created_at
    FROM users WHERE id=? LIMIT 1");
  $st->bind_param("i",$uid); $st->execute();
  $user = $st->get_result()->fetch_assoc(); $st->close();
}catch(Throwable $e){}
if (!$user){ header("Location: admin_users.php"); exit(); }

/* Counters */
$totalBookings = 0; $paidBookings=0; $cancelled=0;
try{
  $r = $conn->query("SELECT
    SUM(CASE WHEN status<>'cancelled' THEN 1 ELSE 0 END) AS total_non_cancel,
    SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) AS paid_count,
    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled_count
    FROM bookings WHERE user_id=".$uid);
  $rr=$r->fetch_assoc();
  $totalBookings = (int)($rr['total_non_cancel'] ?? 0);
  $paidBookings  = (int)($rr['paid_count'] ?? 0);
  $cancelled     = (int)($rr['cancelled_count'] ?? 0);
}catch(Throwable $e){}

/* Recent bookings */
$bookings=[];
try{
  $sql="SELECT b.id,b.ref_code,b.date,b.time_slot,b.status,b.service,b.area,b.estimated_price,
              COALESCE(w.name,'') AS worker_name
        FROM bookings b
        LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
        WHERE b.user_id=?
        ORDER BY b.id DESC LIMIT 15";
  $st = $conn->prepare($sql); $st->bind_param("i",$uid); $st->execute();
  $rs = $st->get_result(); while($row=$rs->fetch_assoc()) $bookings[]=$row; $st->close();
}catch(Throwable $e){}

/* Addresses */
$addresses=[];
try{
  $st=$conn->prepare("SELECT label,address_text,COALESCE(home_type,'') AS home_type,COALESCE(home_sqft,'') AS home_sqft,is_default
                      FROM user_addresses WHERE user_id=? ORDER BY is_default DESC, id DESC");
  $st->bind_param("i",$uid); $st->execute();
  $rs=$st->get_result(); while($row=$rs->fetch_assoc()) $addresses[]=$row; $st->close();
}catch(Throwable $e){}

/* Latest payments */
$payments=[];
try{
  $st=$conn->prepare("SELECT created_at, booking_ref, amount, status, gateway_ref
                      FROM payments_log WHERE user_id=? ORDER BY id DESC LIMIT 10");
  $st->bind_param("i",$uid); $st->execute();
  $rs=$st->get_result(); while($row=$rs->fetch_assoc()) $payments[]=$row; $st->close();
}catch(Throwable $e){}

/* Reviews */
$reviews=[];
try{
  // Try to detect review text column
  $reviewCol='comment';
  $cols = []; $r=$conn->query("SHOW COLUMNS FROM booking_reviews");
  while($c=$r->fetch_assoc()) $cols[strtolower($c['Field'])]=true;
  foreach(['comment','review','review_text','comments','message','content','text'] as $cand){
    if(isset($cols[$cand])){ $reviewCol=$cand; break; }
  }

  $st=$conn->prepare("
    SELECT br.rating, br.$reviewCol AS comment, br.created_at, b.ref_code
    FROM booking_reviews br
    JOIN bookings b ON b.id=br.booking_id
    WHERE b.user_id=?
    ORDER BY br.id DESC LIMIT 10
  ");
  $st->bind_param("i",$uid); $st->execute();
  $rs=$st->get_result(); while($row=$rs->fetch_assoc()) $reviews[]=$row; $st->close();
}catch(Throwable $e){}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Overview – Admin · NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,sans-serif;background:#f7f7fb;margin:0}
    .layout{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px}
    .brand{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-weight:800}
    .nav a{display:block;padding:10px 12px;border-radius:10px;color:#111;text-decoration:none;margin:6px 0;border:1px solid #e5e7eb;background:#fff}
    .nav a.active{background:#111827;color:#fff;border-color:#111827}
    .main{padding:18px}
    .grid{display:grid;grid-template-columns:2fr 1fr;gap:16px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
    .kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .kpi{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff}
    .kpi .v{font-size:28px;font-weight:800}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #eef2f7;padding:10px;text-align:left;vertical-align:top}
    .mini{color:#6b7280;font-size:12px}
    .btn{background:#635bff;color:#fff;border:0;border-radius:12px;padding:8px 12px;text-decoration:none}
    .btn-ghost{background:#fff;border:1px solid #e5e7eb;color:#111;border-radius:12px;padding:8px 12px;text-decoration:none}
    .row{display:flex;justify-content:space-between;align-items:center;margin:4px 0}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">
      <img src="maid.png" alt="" style="width:28px;height:28px"> <div>NeinMaid</div>
    </div>
    <div class="nav">
      <a href="admin_dashboard.php">Dashboard</a>
      <a href="admin_bookings.php">Bookings</a>
      <a href="admin_users.php">Users</a>
      <a href="admin_profile.php">My Profile</a>
      <a class="active" href="admin_user_overview.php?uid=<?= (int)$uid ?>">User Overview</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <main class="main">
    <div class="row" style="gap:10px">
      <h2 style="margin:0">User Overview</h2>
      <a class="btn-ghost" href="admin_users.php">← Back to Users</a>
    </div>

    <div class="grid">
      <div class="card">
        <div class="row">
          <div>
            <div style="font-weight:800"><?= h($user['name']) ?></div>
            <div class="mini"><?= h($user['email']) ?> · <?= h($user['phone']) ?></div>
            <div class="mini">Joined: <?= h($user['created_at']) ?></div>
          </div>
          <div>
            <!-- In future you could add "impersonate" safely (read-only) -->
            <span class="pill">ID #<?= (int)$user['id'] ?></span>
          </div>
        </div>

        <div class="kpis" style="margin-top:12px">
          <div class="kpi"><div class="mini">Total Bookings</div><div class="v"><?= (int)$totalBookings ?></div></div>
          <div class="kpi"><div class="mini">Paid</div><div class="v"><?= (int)$paidBookings ?></div></div>
          <div class="kpi"><div class="mini">Cancelled</div><div class="v"><?= (int)$cancelled ?></div></div>
        </div>

        <h3 style="margin:16px 0 8px">Recent Bookings</h3>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>Ref</th><th>Date</th><th>Time</th><th>Service</th><th>Area</th><th>Status</th><th>Cleaner</th><th>Total</th>
            </tr></thead>
            <tbody>
              <?php if(!$bookings): ?>
                <tr><td colspan="8" class="mini">No bookings found.</td></tr>
              <?php else: foreach($bookings as $b): ?>
                <tr>
                  <td><?= h($b['ref_code']) ?></td>
                  <td><?= h($b['date']) ?></td>
                  <td><?= h($b['time_slot']) ?></td>
                  <td><?= h($b['service']) ?></td>
                  <td><?= h($b['area']) ?></td>
                  <td><span class="pill"><?= h(ucfirst($b['status'])) ?></span></td>
                  <td><?= $b['worker_name']? h($b['worker_name']): '—' ?></td>
                  <td><?= money_rm($b['estimated_price']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3 style="margin:0 0 8px">Default Address</h3>
        <div class="mini"><?= h($user['address_line']) ?: '—' ?></div>
        <div class="mini">Home Type: <?= h($user['home_type']) ?: '—' ?> · Size: <?= h((string)$user['home_sqft']) ?: '—' ?> sqft</div>

        <h3 style="margin:16px 0 8px">Saved Addresses</h3>
        <?php if(!$addresses): ?>
          <div class="mini">No saved addresses.</div>
        <?php else: ?>
          <ul style="padding-left:18px;margin:6px 0">
            <?php foreach($addresses as $a): ?>
              <li style="margin:6px 0">
                <div><?= h($a['label']) ?> <?= ((int)$a['is_default']===1)?'· <strong>DEFAULT</strong>':'' ?></div>
                <div class="mini"><?= h($a['address_text']) ?></div>
                <div class="mini"><?= h($a['home_type']) ?> · <?= h((string)$a['home_sqft']) ?> sqft</div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <h3 style="margin:16px 0 8px">Latest Payments</h3>
        <?php if(!$payments): ?>
          <div class="mini">No payments found.</div>
        <?php else: ?>
          <ul style="padding-left:18px;margin:6px 0">
            <?php foreach($payments as $p): ?>
              <li style="margin:6px 0">
                <div><?= h($p['created_at']) ?> · <?= h($p['booking_ref']) ?> · <?= money_rm($p['amount']) ?></div>
                <div class="mini">Status: <?= h($p['status']) ?> · GW Ref: <?= h($p['gateway_ref']) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <h3 style="margin:16px 0 8px">Recent Reviews</h3>
        <?php if(!$reviews): ?>
          <div class="mini">No reviews by this user.</div>
        <?php else: ?>
          <ul style="padding-left:18px;margin:6px 0">
            <?php foreach($reviews as $r): ?>
              <li style="margin:6px 0">
                <div>Ref <?= h($r['ref_code']) ?> · Rating <?= (int)$r['rating'] ?>★ · <span class="mini"><?= h($r['created_at']) ?></span></div>
                <div class="mini"><?= $r['comment']? h($r['comment']) : '(no comment)' ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>