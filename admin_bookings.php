<?php
// admin_bookings.php — Dashboard-style UI + same sidebar/nav as admin_dashboard
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { header('Location: login.php'); exit(); }
date_default_timezone_set('Asia/Kuala_Lumpur');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";

$errBanner='';
try{ $conn=new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME); $conn->set_charset('utf8mb4'); }
catch(Throwable $e){ $errBanner='Database connection failed: '.$e->getMessage(); }

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }

/* ===== detect review text column (for future-proofing) ===== */
function get_table_columns(mysqli $conn, string $table): array {
  $cols=[]; try{ $r=$conn->query("SHOW COLUMNS FROM `$table`"); while($row=$r->fetch_assoc()) $cols[]=$row['Field']; }catch(Throwable $e){}
  return $cols;
}
$reviewColsFlip = array_flip(get_table_columns($conn,'booking_reviews'));
$COL_REVIEW_TEXT = null;
foreach(['comment','review','review_text','comments','message','content','text'] as $cand){
  if(isset($reviewColsFlip[$cand])){ $COL_REVIEW_TEXT=$cand; break; }
}

/* ===== helpers ===== */
function parse_timeslot_minutes(?string $slot): ?int {
  $s=trim((string)$slot);
  if(!preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i',$s,$m)) return null;
  $h=(int)$m[1]; $min=(int)$m[2]; $ap=strtoupper($m[3]);
  if($h===12) $h=0;
  if($ap==='PM') $h+=12;
  return $h*60+$min;
}
function is_past_booking_row(array $b): bool {
  $today = new DateTimeImmutable('today');
  $bdate = DateTimeImmutable::createFromFormat('Y-m-d', (string)($b['date'] ?? ''));
  if(!$bdate) return false;
  if($bdate < $today) return true;
  if($bdate > $today) return false;
  $slotMin = parse_timeslot_minutes($b['time_slot'] ?? '');
  if($slotMin===null) return false;
  $now = new DateTimeImmutable('now');
  $nowMin = (int)$now->format('G')*60 + (int)$now->format('i');
  return $slotMin < $nowMin;
}

/* ===== Actions ===== */
$flash='';
if(!$errBanner && $_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $action=$_POST['action'] ?? ''; $booking_id=(int)($_POST['booking_id'] ?? 0);
    if(!$action || $booking_id<=0) throw new RuntimeException('Invalid request.');
    $st=$conn->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
    $st->bind_param('i',$booking_id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
    if(!$row) throw new RuntimeException('Booking not found.');

    if($action==='approve'){
      $st=$conn->prepare("UPDATE bookings SET status='approved' WHERE id=?");
      $st->bind_param('i',$booking_id);
      $st->execute(); $flash="Booking #$booking_id approved.";
    }elseif($action==='cancel'){
      $st=$conn->prepare("UPDATE bookings SET status='cancelled' WHERE id=?");
      $st->bind_param('i',$booking_id);
      $st->execute(); $flash="Booking #$booking_id cancelled.";
    }elseif($action==='assign'){
      $worker_id=(int)($_POST['worker_id'] ?? 0);
      if($worker_id<=0) throw new RuntimeException('Select a worker.');
      $workerName='';
      $q=$conn->prepare("SELECT name FROM worker_profiles WHERE id=? LIMIT 1");
      $q->bind_param('i',$worker_id); $q->execute();
      $res=$q->get_result(); if($r=$res->fetch_assoc()) $workerName=$r['name']; $q->close();

      $now=date('Y-m-d H:i:s');
      $st=$conn->prepare("UPDATE bookings SET assigned_worker_id=?, assigned_worker_name=?, assigned_at=?, status='assigned' WHERE id=?");
      $st->bind_param('issi',$worker_id,$workerName,$now,$booking_id);
      $st->execute(); $flash="Assigned worker #$worker_id ($workerName) to booking #$booking_id.";
    }
    header("Location: admin_bookings.php?msg=".urlencode($flash)); exit();
  }catch(Throwable $e){ $errBanner='Action failed: '.$e->getMessage(); }
}

/* ===== Load bookings ===== */
$pendingUpcoming=[]; $approvedUpcoming=[]; $pastBookings=[]; $workers=[];
if(!$errBanner){
  try{
    $sql="SELECT b.*, u.name user_name, u.email user_email, w.name worker_name
          FROM bookings b
          JOIN users u ON u.id=b.user_id
          LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
          ORDER BY b.date DESC, b.time_slot DESC, b.id DESC";
    $res=$conn->query($sql);
    while($r=$res->fetch_assoc()){
      $status=strtolower((string)$r['status']);
      $isPast=is_past_booking_row($r) || in_array($status,['cancelled','completed','done','finished'],true);
      if($isPast) $pastBookings[]=$r;
      elseif($status==='pending') $pendingUpcoming[]=$r;
      else $approvedUpcoming[]=$r;
    }
    $wr=$conn->query("SELECT id,name FROM worker_profiles WHERE approval_status='approved' ORDER BY name ASC");
    while($r=$wr->fetch_assoc()) $workers[]=$r;
  }catch(Throwable $e){ $errBanner='Load failed: '.$e->getMessage(); }
}

/* ===== UI helpers to match dashboard tags ===== */
function status_tag($s){
  $s=strtolower($s);
  $map=['approved'=>'ok','assigned'=>'ok','cancelled'=>'bad','completed'=>'bad','pending'=>'pending'];
  $cls=$map[$s]??'muted';
  return '<span class="tag '.$cls.'">'.h(ucfirst($s)).'</span>';
}
function display_booking_details($b){
  return '
  <div><b>Travel Fee:</b> '.money_rm($b['travel_fee']).'</div>
  <div><b>Custom Details:</b> '.h($b['custom_details']).'</div>
  <div><b>Payment:</b> '.h($b['payment_status']).' / '.h($b['payment_ref']).'</div>
  <div><b>Promo:</b> '.h($b['promo_code']).'</div>
  <div><b>Final Paid:</b> '.money_rm($b['final_paid_amount']).'</div>
  <div><b>Address:</b> '.h($b['address']).'</div>
  <div><b>Arrived:</b> '.h($b['arrived_at']).'</div>
  <div><b>Started:</b> '.h($b['started_at']).'</div>
  <div><b>Finished:</b> '.h($b['finished_at']).'</div>
  <div><b>OTP:</b> '.h($b['completion_otp']).'</div>';
}

$groups = [
  'Pending (upcoming)' => $pendingUpcoming,
  'Approved / Assigned (upcoming)' => $approvedUpcoming,
  'Past / Completed' => $pastBookings
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin · Bookings – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Reuse the same assets as dashboard -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css">
  <style>
    /* Borrow the key bits from admin_dashboard inline style so this page matches perfectly */
    body{background:#f6f7fb;color:#0f172a}
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px 12px}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand img{width:28px;height:28px}
    .nav{display:flex;flex-direction:column;margin-top:12px}
    .nav a{padding:10px 12px;border-radius:10px;color:#111827;text-decoration:none;margin:2px 0}
    .nav a.active, .nav a:hover{background:#eef2ff}
    .main{padding:18px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .row{display:flex;gap:10px;align-items:center}
    .right{display:flex;justify-content:flex-end}
    .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
    .btn:hover{background:#f8fafc}
    .btn-primary{background:#111827;color:#fff;border-color:#111827}
    .btn-primary:hover{opacity:.9}
    .muted{color:#64748b}
    .small{font-size:12px}
    .pill{padding:2px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc}
    .section-h1{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px;vertical-align:top}
    thead th{background:#f8fafc}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    .flash{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:12px;padding:10px;margin-bottom:12px}
    .error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:10px;margin-bottom:12px}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px}
    .tag.ok{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
    .tag.pending{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
    .tag.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .tag.muted{background:#f8fafc;border-color:#e5e7eb;color:#64748b}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:12px}
    .toolbar .title{font-weight:800;font-size:20px}
    .mt-2{margin-top:8px}
  </style>
</head>
<body>

<div class="layout">
  <!-- Sidebar (copied from admin_dashboard for 1:1 look) -->
  <aside class="sidebar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      <div>NeinMaid</div>
    </div>

    <div class="nav">
      <a href="admin_dashboard.php">Dashboard</a>
      <a class="active" href="admin_bookings.php">Bookings</a>
      <a href="admin_employees.php">Workers</a>
      <a href="admin_services.php">Services</a>
      <a href="admin_finance.php">Finance</a>
      <a href="admin_promos.php">Promotions</a>
      <a href="admin_worker_changes.php">Worker Changes</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <div class="toolbar">
      <div class="title">Bookings</div>
      <div class="row">
        <a class="btn" href="?export=1">Export CSV</a>
      </div>
    </div>

    <?php if(!empty($_GET['msg'])): ?><div class="flash"><?= h($_GET['msg']) ?></div><?php endif; ?>
    <?php if($errBanner): ?><div class="error"><?= h($errBanner) ?></div><?php endif; ?>

    <!-- Groups rendered with dashboard-style cards -->
    <div class="grid">
      <?php foreach($groups as $title=>$rows): ?>
      <section class="card">
        <div class="section-h1">
          <h2 style="margin:0"><?= h($title) ?></h2>
          <div class="pill"><?= count($rows) ?> item(s)</div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th><th>Ref</th><th>User</th><th>Service / Area</th>
                <th>Date / Time</th><th>Status</th><th>Worker</th>
                <th>Price</th><th>Created</th><th>Details</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!$rows): ?>
              <tr><td colspan="11" class="muted">No records.</td></tr>
            <?php else: foreach($rows as $b): ?>
              <tr>
                <td><?= (int)$b['id'] ?></td>
                <td><?= h($b['ref_code']) ?></td>
                <td><?= h($b['user_name']) ?><div class="muted small"><?= h($b['user_email']) ?></div></td>
                <td><?= h($b['service']) ?><br><span class="muted"><?= h($b['area']) ?></span></td>
                <td><?= h($b['date']) ?><br><span class="muted"><?= h($b['time_slot']) ?></span></td>
                <td><?= status_tag($b['status']) ?></td>
                <td><?= $b['assigned_worker_id'] ? h($b['worker_name'] ?: ('#'.$b['assigned_worker_id'])) : '—' ?></td>
                <td><?= money_rm($b['estimated_price']) ?></td>
                <td><?= h($b['created_at']) ?></td>
                <td><?= display_booking_details($b) ?></td>
                <td>
                  <?php if($title!=='Past / Completed'): ?>
                    <form method="post" style="margin-bottom:6px">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                      <button class="btn btn-primary">Approve</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Cancel this booking?');" style="margin-bottom:6px">
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                      <button class="btn">Cancel</button>
                    </form>
                    <form method="post" class="mt-2">
                      <input type="hidden" name="action" value="assign">
                      <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                      <select name="worker_id" required>
                        <option value="">Select worker…</option>
                        <?php foreach($workers as $w): ?>
                          <option value="<?= (int)$w['id'] ?>" <?= ((int)$b['assigned_worker_id']===(int)$w['id'])?'selected':'' ?>>
                            <?= h($w['name']) ?> (ID <?= (int)$w['id'] ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-primary"><?= !empty($b['assigned_worker_id'])?'Reassign':'Assign' ?></button>
                    </form>
                  <?php else: ?>
                    <span class="muted small">Past — actions disabled</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endforeach; ?>
    </div>
  </main>
</div>

</body>
</html>
