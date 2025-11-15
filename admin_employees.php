<?php
// admin_employees.php — Dashboard-style UI + same sidebar/nav as admin_dashboard
// Buffer output so redirects/headers are safe
ob_start();
// Ensure session for flash alerts
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// --- DB connection ---
$host = "localhost"; $user = "root"; $pass = ""; $db = "maid_system";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) { die("Connection failed: ".$conn->connect_error); }
$conn->set_charset('utf8mb4');

// --- Safe redirect helper ---
function safe_redirect($url){
  if (!headers_sent()){
    header("Location: $url");
    exit;
  }
  $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
  echo "<script>location.replace('$u');</script>";
  echo "<noscript><meta http-equiv='refresh' content='0;url=$u'></noscript>";
  exit;
}

// --- Flash alerts ---
function add_alert($text, $kind='error'){
  $_SESSION['alerts'] = $_SESSION['alerts'] ?? [];
  $_SESSION['alerts'][] = ['kind'=>$kind,'text'=>$text];
}

// --- Schema helpers ---
function table_exists($conn, $name){
  $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s",$name);
  $stmt->execute();
  $stmt->bind_result($cnt);
  $stmt->fetch();
  $stmt->close();
  return ($cnt > 0);
}
function column_exists($conn, $table, $col){
  $sql = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss",$table,$col);
  $stmt->execute();
  $stmt->bind_result($cnt);
  $stmt->fetch();
  $stmt->close();
  return ($cnt > 0);
}
function ensure_columns($conn){
  if (!table_exists($conn,'worker_profiles')) {
    die('<section class="card" style="padding:18px"><strong>Error:</strong> worker_profiles table not found.</section>');
  }
  if (!column_exists($conn,'worker_profiles','approval_status')) {
    $conn->query("ALTER TABLE worker_profiles
      ADD COLUMN approval_status ENUM('pending','approved','not_approved') NOT NULL DEFAULT 'pending' AFTER languages");
  }
  if (!column_exists($conn,'worker_profiles','status')) {
    $conn->query("ALTER TABLE worker_profiles
      ADD COLUMN status ENUM('Active','On Leave','Disabled') NOT NULL DEFAULT 'Active' AFTER approval_status");
  }
  if (!column_exists($conn,'worker_profiles','created_at')) {
    $conn->query("ALTER TABLE worker_profiles
      ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status");
  }
}
ensure_columns($conn);

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  // Toggle status
  if ($action==='toggle_status') {
    $id = (int)$_POST['id'];
    $new = $_POST['new_status'];
    $stmt = $conn->prepare("UPDATE worker_profiles SET status=? WHERE id=?");
    $stmt->bind_param("si",$new,$id);
    $stmt->execute(); $stmt->close();
    add_alert("Status updated to {$new}.", 'success');
    safe_redirect('admin_employees.php');
  }

  // Approve/Not approve
  if ($action==='set_approval') {
    $id = (int)$_POST['id'];
    $decision = $_POST['decision'];
    if (!in_array($decision, ['approved','not_approved'], true)) $decision = 'not_approved';
    $newStatus = ($decision==='approved') ? 'Active' : 'Disabled';
    $stmt = $conn->prepare("UPDATE worker_profiles SET approval_status=?, status=? WHERE id=?");
    $stmt->bind_param("ssi",$decision,$newStatus,$id);
    $stmt->execute(); $stmt->close();
    add_alert("Approval changed to {$decision}.", 'success');
    safe_redirect('admin_employees.php');
  }

  // Add full profile
  if ($action==='add_full') {
    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $whatsapp     = trim($_POST['whatsapp'] ?? '');
    $nationality  = trim($_POST['nationality'] ?? '');
    $ic_no        = trim($_POST['ic_no'] ?? '');
    $dob          = ($_POST['dob'] ?? '') ?: null;
    $gender       = trim($_POST['gender'] ?? '');
    $address      = trim($_POST['address'] ?? '');

    $areas            = $_POST['areas'] ?? [];
    $availability     = $_POST['availability'] ?? [];
    $areas_csv        = $areas ? implode(',', array_map('trim',$areas)) : null;
    $availability_csv = $availability ? implode(',', array_map('trim',$availability)) : null;

    $hours_from   = ($_POST['hours_from'] ?? '') ?: null;
    $hours_to     = ($_POST['hours_to'] ?? '') ?: null;
    $exp_years    = ($_POST['exp_years'] ?? '') === '' ? null : (int)$_POST['exp_years'];

    $specialties  = $_POST['specialties'] ?? [];
    $languages    = $_POST['languages'] ?? [];
    $specialties_csv = $specialties ? implode(',', array_map('trim',$specialties)) : null;
    $languages_csv   = $languages ? implode(',', array_map('trim',$languages)) : null;

    $has_tools   = isset($_POST['has_tools']) ? 1 : 0;
    $has_vehicle = isset($_POST['has_vehicle']) ? 1 : 0;

    $bank_name   = trim($_POST['bank_name'] ?? '');
    $bank_acc    = trim($_POST['bank_acc'] ?? '');
    $emer_name   = trim($_POST['emer_name'] ?? '');
    $emer_phone  = trim($_POST['emer_phone'] ?? '');

    // Duplicate email guard
    if ($email !== '') {
      $chk = $conn->prepare("SELECT id FROM worker_profiles WHERE email=? LIMIT 1");
      $chk->bind_param("s", $email);
      $chk->execute();
      $dup = $chk->get_result()->num_rows > 0;
      $chk->close();
      if ($dup) {
        add_alert("Email '{$email}' is already in use. Use a different email or edit the existing worker.");
        safe_redirect('admin_employees.php');
      }
    }

    $approval_status = 'approved';
    $status          = 'Active';

    $sql = "INSERT INTO worker_profiles
      (name, email, phone, whatsapp, nationality, ic_no, dob, gender, address,
       areas, availability_days, hours_from, hours_to, exp_years, specialties, languages,
       has_tools, has_vehicle, bank_name, bank_acc, emer_name, emer_phone, approval_status, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $types = "sssssssssssss" . "i" . "ss" . "ii" . "ssssss";
    $stmt->bind_param(
      $types,
      $name, $email, $phone, $whatsapp, $nationality, $ic_no, $dob, $gender, $address,
      $areas_csv, $availability_csv, $hours_from, $hours_to,
      $exp_years, $specialties_csv, $languages_csv,
      $has_tools, $has_vehicle, $bank_name, $bank_acc, $emer_name, $emer_phone,
      $approval_status, $status
    );
    $stmt->execute(); $stmt->close();

    add_alert("Worker '{$name}' created successfully.", 'success');
    safe_redirect('admin_employees.php');
  }

  // Edit full profile
  if ($action==='edit_full') {
    $id = (int)$_POST['id'];

    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $whatsapp     = trim($_POST['whatsapp'] ?? '');
    $nationality  = trim($_POST['nationality'] ?? '');
    $ic_no        = trim($_POST['ic_no'] ?? '');
    $dob          = ($_POST['dob'] ?? '') ?: null;
    $gender       = trim($_POST['gender'] ?? '');
    $address      = trim($_POST['address'] ?? '');

    $areas = $_POST['areas'] ?? (isset($_POST['areas_raw']) ? array_filter(array_map('trim', explode(',', $_POST['areas_raw']))) : []);
    $availability = $_POST['availability'] ?? (isset($_POST['availability_raw']) ? array_filter(array_map('trim', explode(',', $_POST['availability_raw']))) : []);
    $areas_csv        = $areas ? implode(',', $areas) : null;
    $availability_csv = $availability ? implode(',', $availability) : null;

    $hours_from   = ($_POST['hours_from'] ?? '') ?: null;
    $hours_to     = ($_POST['hours_to'] ?? '') ?: null;
    $exp_years    = ($_POST['exp_years'] ?? '') === '' ? null : (int)$_POST['exp_years'];

    $specialties_csv = trim($_POST['specialties'] ?? '');
    $specialties_csv = $specialties_csv === '' ? null : implode(',', array_filter(array_map('trim', explode(',', $specialties_csv))));
    $languages_csv   = trim($_POST['languages'] ?? '');
    $languages_csv   = $languages_csv === '' ? null : implode(',', array_filter(array_map('trim', explode(',', $languages_csv))));

    $has_tools   = isset($_POST['has_tools']) ? 1 : 0;
    $has_vehicle = isset($_POST['has_vehicle']) ? 1 : 0;

    $bank_name   = trim($_POST['bank_name'] ?? '');
    $bank_acc    = trim($_POST['bank_acc'] ?? '');
    $emer_name   = trim($_POST['emer_name'] ?? '');
    $emer_phone  = trim($_POST['emer_phone'] ?? '');

    $approval_status = $_POST['approval_status'] ?? 'approved';
    $status          = $_POST['status'] ?? 'Active';

    // Duplicate email guard (exclude current id)
    if ($email !== '') {
      $chk = $conn->prepare("SELECT id FROM worker_profiles WHERE email=? AND id<>? LIMIT 1");
      $chk->bind_param("si", $email, $id);
      $chk->execute();
      $dup = $chk->get_result()->num_rows > 0;
      $chk->close();
      if ($dup) {
        add_alert("Email '{$email}' is already in use by another worker.");
        safe_redirect('admin_employees.php');
      }
    }

    $sql = "UPDATE worker_profiles
            SET name=?, email=?, phone=?, whatsapp=?, nationality=?, ic_no=?, dob=?, gender=?, address=?,
                areas=?, availability_days=?, hours_from=?, hours_to=?, exp_years=?, specialties=?, languages=?,
                has_tools=?, has_vehicle=?, bank_name=?, bank_acc=?, emer_name=?, emer_phone=?, approval_status=?, status=?
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $types = "sssssssssssss" . "i" . "ss" . "ii" . "ssssss" . "i";
    $stmt->bind_param(
      $types,
      $name, $email, $phone, $whatsapp, $nationality, $ic_no, $dob, $gender, $address,
      $areas_csv, $availability_csv, $hours_from, $hours_to,
      $exp_years, $specialties_csv, $languages_csv,
      $has_tools, $has_vehicle, $bank_name, $bank_acc, $emer_name, $emer_phone, $approval_status, $status,
      $id
    );
    $stmt->execute(); $stmt->close();

    add_alert("Worker '{$name}' updated successfully.", 'success');
    safe_redirect('admin_employees.php');
  }

  // ========= Delete worker (hard delete) =========
  if ($action==='delete_worker') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      add_alert("Invalid worker id.", 'error');
      safe_redirect('admin_employees.php');
    }

    // Optional cleanup: unassign future bookings
    try {
      $today = (new DateTimeImmutable('today'))->format('Y-m-d');
      $st = $conn->prepare("UPDATE bookings SET assigned_worker_id=NULL WHERE assigned_worker_id=? AND (date >= ? OR date IS NULL)");
      $st->bind_param("is", $id, $today);
      $st->execute(); $st->close();
    } catch (Throwable $e) {}

    try {
      $stmt = $conn->prepare("DELETE FROM worker_profiles WHERE id=? LIMIT 1");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $affected = $stmt->affected_rows;
      $errno = $stmt->errno;
      $stmt->close();

      if ($affected > 0) {
        add_alert("Worker #{$id} deleted.", 'success');
      } else {
        if ($errno === 1451) {
          add_alert("Cannot delete worker #{$id}: referenced by other records. Disable the worker instead.", 'error');
        } else {
          add_alert("Nothing deleted — worker may not exist, or is locked by constraints.", 'error');
        }
      }
    } catch (Throwable $e) {
      add_alert("Delete failed for worker #{$id}: ".$e->getMessage(), 'error');
    }

    safe_redirect('admin_employees.php');
  }

  // Fallback
  safe_redirect('admin_employees.php');
}

// --- Fetch lists for display ---
$pending = $conn->query("SELECT id, name, email, phone, whatsapp, nationality, ic_no, dob, gender, address,
                                areas, availability_days, hours_from, hours_to, exp_years, specialties, languages,
                                has_tools, has_vehicle, bank_name, bank_acc, emer_name, emer_phone,
                                approval_status, status, created_at
                           FROM worker_profiles
                          WHERE approval_status='pending'
                          ORDER BY id DESC");

$list = $conn->query("SELECT id, name, email, phone, whatsapp, nationality, ic_no, dob, gender, address,
                             areas, availability_days, hours_from, hours_to, exp_years, specialties, languages,
                             has_tools, has_vehicle, bank_name, bank_acc, emer_name, emer_phone,
                             approval_status, status, created_at
                        FROM worker_profiles
                       WHERE approval_status <> 'pending'
                       ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin · Workers – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Use the same assets/style language as admin_dashboard -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css">
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
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:12px}
    .toolbar .title{font-weight:800;font-size:20px}
    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px;vertical-align:top}
    thead th{background:#f8fafc}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    .flash{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:12px;padding:10px;margin-bottom:12px}
    .error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:10px;margin-bottom:12px}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px}
    .notification.success{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:12px}
    .notification.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px}
    /* simple grids for modal forms */
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
    /* simple modal shell */
    #addCleanerFull, #editCleanerFull{z-index:20}
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
    <div class="toolbar">
      <div class="title">Workers</div>
      <div class="row">
        <button class="btn btn-primary" onclick="openModal('addCleanerFull')">Add Cleaner +</button>
      </div>
    </div>

    <?php if (!empty($_SESSION['alerts'])): ?>
      <?php foreach ($_SESSION['alerts'] as $al): ?>
        <div class="<?= $al['kind']==='error'?'error flash':'flash' ?>"><?= htmlspecialchars($al['text']) ?></div>
      <?php endforeach; $_SESSION['alerts']=[]; ?>
    <?php endif; ?>

    <div class="grid">
      <!-- PENDING -->
      <section class="card">
        <div class="row" style="justify-content:space-between;margin-bottom:6px">
          <h2 style="margin:0">Pending Approvals</h2>
          <div class="pill"><?= ($pending && $pending->num_rows) ? $pending->num_rows : 0 ?> item(s)</div>
        </div>
        <div class="table-wrap">
          <?php if($pending && $pending->num_rows>0): ?>
          <table style="min-width:1600px">
            <thead>
              <tr>
                <th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>WhatsApp</th>
                <th>Nationality</th><th>IC/Passport</th><th>DOB</th><th>Gender</th>
                <th>Address</th><th>Areas</th><th>Days</th><th>From</th><th>To</th>
                <th>Exp (yrs)</th><th>Specialties</th><th>Languages</th>
                <th>Tools</th><th>Vehicle</th>
                <th>Bank Name</th><th>Bank Acc</th><th>Emergency Name</th><th>Emergency Phone</th>
                <th>Approval</th><th>Status</th><th>Created</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while($r=$pending->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= htmlspecialchars($r['name']) ?></td>
                  <td><?= htmlspecialchars($r['email'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['phone'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['whatsapp'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['nationality'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['ic_no'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['dob'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['gender'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['address'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['areas'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['availability_days'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['hours_from'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['hours_to'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['exp_years'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['specialties'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['languages'] ?? '-') ?></td>
                  <td><?= !empty($r['has_tools']) ? 'Yes' : 'No' ?></td>
                  <td><?= !empty($r['has_vehicle']) ? 'Yes' : 'No' ?></td>
                  <td><?= htmlspecialchars($r['bank_name'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['bank_acc'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['emer_name'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['emer_phone'] ?? '-') ?></td>
                  <td><span class="tag"><?= htmlspecialchars($r['approval_status']) ?></span></td>
                  <td><span class="tag"><?= htmlspecialchars($r['status']) ?></span></td>
                  <td><?= htmlspecialchars($r['created_at']) ?></td>
                  <td class="row" style="gap:6px;flex-wrap:wrap">
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="set_approval">
                      <input type="hidden" name="id" value="<?=$r['id']?>">
                      <input type="hidden" name="decision" value="approved">
                      <button class="btn btn-primary">Approve</button>
                    </form>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="set_approval">
                      <input type="hidden" name="id" value="<?=$r['id']?>">
                      <input type="hidden" name="decision" value="not_approved">
                      <button class="btn">Not Approve</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this worker permanently? This cannot be undone.');">
                      <input type="hidden" name="action" value="delete_worker">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn" style="background:#ef4444;color:#fff">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="muted">No pending approvals. 🎉</div>
          <?php endif; ?>
        </div>
      </section>

      <!-- ALL WORKERS -->
      <section class="card">
        <div class="row" style="justify-content:space-between;margin-bottom:6px">
          <h2 style="margin:0">All Workers</h2>
          <div class="pill">up-to-date</div>
        </div>

        <div class="table-wrap">
          <table style="min-width:1600px">
            <thead>
              <tr>
                <th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>WhatsApp</th>
                <th>Nationality</th><th>IC/Passport</th><th>DOB</th><th>Gender</th>
                <th>Address</th><th>Areas</th><th>Days</th><th>From</th><th>To</th>
                <th>Exp (yrs)</th><th>Specialties</th><th>Languages</th>
                <th>Tools</th><th>Vehicle</th>
                <th>Bank Name</th><th>Bank Acc</th><th>Emergency Name</th><th>Emergency Phone</th>
                <th>Approval</th><th>Status</th><th>Created</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while($r=$list->fetch_assoc()): ?>
                <tr data-row='<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>'>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= htmlspecialchars($r['name']) ?></td>
                  <td><?= htmlspecialchars($r['email'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['phone'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['whatsapp'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['nationality'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['ic_no'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['dob'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['gender'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['address'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['areas'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['availability_days'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['hours_from'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['hours_to'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['exp_years'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['specialties'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['languages'] ?? '-') ?></td>
                  <td><?= !empty($r['has_tools']) ? 'Yes' : 'No' ?></td>
                  <td><?= !empty($r['has_vehicle']) ? 'Yes' : 'No' ?></td>
                  <td><?= htmlspecialchars($r['bank_name'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['bank_acc'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['emer_name'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['emer_phone'] ?? '-') ?></td>
                  <td><span class="tag"><?= htmlspecialchars($r['approval_status']) ?></span></td>
                  <td><span class="tag"><?= htmlspecialchars($r['status']) ?></span></td>
                  <td><?= htmlspecialchars($r['created_at']) ?></td>
                  <td class="row" style="gap:6px;flex-wrap:wrap">
                    <button class="btn" onclick="openEdit(this)">Edit</button>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="id" value="<?=$r['id']?>">
                      <?php $new = $r['status']==='Active' ? 'Disabled' : 'Active'; ?>
                      <input type="hidden" name="new_status" value="<?=$new?>">
                      <button class="btn"><?=$new==='Active'?'Enable':'Disable'?></button>
                    </form>
                    <?php if ($r['approval_status']==='not_approved'): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="set_approval">
                      <input type="hidden" name="id" value="<?=$r['id']?>">
                      <input type="hidden" name="decision" value="approved">
                      <button class="btn">Approve</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this worker permanently? This cannot be undone.');">
                      <input type="hidden" name="action" value="delete_worker">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn" style="background:#ef4444;color:#fff">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
</div>

<!-- ========== ADD CLEANER (FULL) MODAL ========== -->
<div id="addCleanerFull" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);padding:20px;overflow:auto">
  <div class="card" style="max-width:980px;margin:40px auto;padding:16px">
    <div class="row" style="justify-content:space-between">
      <h3 style="margin:0">Add Cleaner (Full Profile)</h3>
      <button class="btn" onclick="closeModal('addCleanerFull')">✖</button>
    </div>

    <form method="post" style="margin-top:10px">
      <input type="hidden" name="action" value="add_full">
      <div class="grid2">
        <input name="name" placeholder="Full name *" required style="width:100%;padding:10px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="email" type="email" placeholder="Email" style="width:100%;padding:10px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px">
      </div>
      <div class="grid3" style="margin-top:8px">
        <input name="phone" placeholder="Phone *" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="whatsapp" placeholder="WhatsApp" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="nationality" placeholder="Nationality" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>
      <div class="grid3" style="margin-top:8px">
        <input name="ic_no" placeholder="IC (12 digits) or Passport" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input type="date" name="dob" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <select name="gender" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          <option value="">Gender</option><option>Female</option><option>Male</option><option>Prefer not to say</option>
        </select>
      </div>
      <textarea name="address" placeholder="Address" style="width:100%;padding:10px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px"></textarea>

      <div class="grid2" style="margin-top:8px">
        <div>
          <label>Areas</label>
          <select name="areas[]" multiple size="6" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
            <option>Georgetown</option><option>Jelutong</option><option>Tanjung Tokong</option><option>Air Itam</option>
            <option>Gelugor</option><option>Bayan Lepas</option><option>Balik Pulau</option><option>Butterworth</option>
            <option>Bukit Mertajam</option><option>Perai</option><option>Nibong Tebal</option><option>Seberang Jaya</option>
          </select>
        </div>
        <div>
          <label>Available days</label>
          <div class="row" style="gap:8px;flex-wrap:wrap">
            <?php foreach (["Mon","Tue","Wed","Thu","Fri","Sat","Sun"] as $d): ?>
              <label><input type="checkbox" name="availability[]" value="<?= $d ?>"> <?= $d ?></label>
            <?php endforeach; ?>
          </div>
          <div class="row" style="margin-top:8px;gap:8px">
            <input type="time" name="hours_from" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px">
            <input type="time" name="hours_to" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          </div>
        </div>
      </div>

      <div class="grid3" style="margin-top:8px">
        <input type="number" name="exp_years" min="0" step="1" placeholder="Years of experience" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <div>
          <label>Specialties</label>
          <div class="row" style="gap:8px;flex-wrap:wrap">
            <?php foreach (["Standard","Deep Clean","Move In/Out","Office","Post-Reno","Carpet","Windows"] as $s): ?>
              <label><input type="checkbox" name="specialties[]" value="<?= $s ?>"> <?= $s ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label>Languages</label>
          <div class="row" style="gap:8px;flex-wrap:wrap">
            <?php foreach (["English","Malay","Mandarin","Tamil"] as $l): ?>
              <label><input type="checkbox" name="languages[]" value="<?= $l ?>"> <?= $l ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="grid3" style="margin-top:8px">
        <label><input type="checkbox" name="has_tools"> Has tools</label>
        <label><input type="checkbox" name="has_vehicle"> Has vehicle</label>
      </div>

      <div class="grid3" style="margin-top:8px">
        <input name="bank_name" placeholder="Bank name" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="bank_acc" placeholder="Bank account no." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="emer_name" placeholder="Emergency name" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>
      <div class="grid3" style="margin-top:8px">
        <input name="emer_phone" placeholder="Emergency phone" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>

      <div class="row" style="justify-content:flex-end;margin-top:12px">
        <button type="button" class="btn" onclick="closeModal('addCleanerFull')">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ========== EDIT (FULL) MODAL ========== -->
<div id="editCleanerFull" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);padding:20px;overflow:auto">
  <div class="card" style="max-width:980px;margin:40px auto;padding:16px">
    <div class="row" style="justify-content:space-between">
      <h3 style="margin:0">Edit Worker</h3>
      <button class="btn" onclick="closeModal('editCleanerFull')">✖</button>
    </div>

    <form method="post" style="margin-top:10px" id="editForm">
      <input type="hidden" name="action" value="edit_full">
      <input type="hidden" name="id" id="e_id">

      <div class="grid2">
        <input name="name" id="e_name" placeholder="Full name *" required style="width:100%;padding:10px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="email" id="e_email" type="email" placeholder="Email" style="width:100%;padding:10px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px">
      </div>
      <div class="grid3" style="margin-top:8px">
        <input name="phone" id="e_phone" placeholder="Phone *" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="whatsapp" id="e_whatsapp" placeholder="WhatsApp" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="nationality" id="e_nationality" placeholder="Nationality" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>
      <div class="grid3" style="margin-top:8px">
        <input name="ic_no" id="e_ic_no" placeholder="IC (12 digits) or Passport" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input type="date" name="dob" id="e_dob" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <select name="gender" id="e_gender" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          <option value="">Gender</option><option>Female</option><option>Male</option><option>Prefer not to say</option>
        </select>
      </div>
      <textarea name="address" id="e_address" placeholder="Address" style="width:100%;padding:10px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px"></textarea>

      <div class="grid2" style="margin-top:8px">
        <div>
          <label>Areas (comma separated)</label>
          <input name="areas_raw" id="e_areas_raw" placeholder="e.g. Georgetown, Bayan Lepas" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        </div>
        <div>
          <label>Available days (comma separated)</label>
          <input name="availability_raw" id="e_days_raw" placeholder="e.g. Mon,Tue,Wed" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          <div class="row" style="margin-top:8px;gap:8px">
            <input type="time" name="hours_from" id="e_hours_from" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px">
            <input type="time" name="hours_to" id="e_hours_to" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          </div>
        </div>
      </div>

      <div class="grid3" style="margin-top:8px">
        <input type="number" name="exp_years" id="e_exp_years" min="0" step="1" placeholder="Years of experience" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="specialties" id="e_specialties" placeholder="Specialties (comma separated)" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="languages" id="e_languages" placeholder="Languages (comma separated)" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>

      <div class="grid3" style="margin-top:8px">
        <label><input type="checkbox" name="has_tools" id="e_has_tools"> Has tools</label>
        <label><input type="checkbox" name="has_vehicle" id="e_has_vehicle"> Has vehicle</label>
      </div>

      <div class="grid3" style="margin-top:8px">
        <input name="bank_name" id="e_bank_name" placeholder="Bank name" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="bank_acc" id="e_bank_acc" placeholder="Bank account no." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
        <input name="emer_name" id="e_emer_name" placeholder="Emergency name" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>
      <div class="grid3" style="margin-top:8px">
        <input name="emer_phone" id="e_emer_phone" placeholder="Emergency phone" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
      </div>

      <div class="grid3" style="margin-top:8px">
        <select name="approval_status" id="e_approval_status" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          <option value="pending">pending</option>
          <option value="approved">approved</option>
          <option value="not_approved">not_approved</option>
        </select>
        <select name="status" id="e_status" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px">
          <option value="Active">Active</option>
          <option value="On Leave">On Leave</option>
          <option value="Disabled">Disabled</option>
        </select>
      </div>

      <div class="row" style="justify-content:flex-end;margin-top:12px">
        <button type="button" class="btn" onclick="closeModal('editCleanerFull')">Cancel</button>
        <button class="btn btn-primary" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(id){ const el=document.getElementById(id); if(el) el.style.display='block'; }
  function closeModal(id){ const el=document.getElementById(id); if(el) el.style.display='none'; }

  function openEdit(btn){
    const tr = btn.closest('tr');
    const data = JSON.parse(tr.getAttribute('data-row') || '{}');

    const set = (id, val)=>{ const el=document.getElementById(id); if(el) el.value = (val ?? ''); };
    document.getElementById('e_id').value = data.id || '';

    set('e_name', data.name);
    set('e_email', data.email);
    set('e_phone', data.phone);
    set('e_whatsapp', data.whatsapp);
    set('e_nationality', data.nationality);
    set('e_ic_no', data.ic_no);
    set('e_dob', data.dob);
    set('e_gender', data.gender);
    set('e_address', data.address);
    set('e_hours_from', data.hours_from);
    set('e_hours_to', data.hours_to);
    set('e_exp_years', data.exp_years);

    set('e_areas_raw', (data.areas||''));
    set('e_days_raw', (data.availability_days||''));
    set('e_specialties', (data.specialties||''));
    set('e_languages', (data.languages||''));

    document.getElementById('e_has_tools').checked   = (parseInt(data.has_tools||0) === 1);
    document.getElementById('e_has_vehicle').checked = (parseInt(data.has_vehicle||0) === 1);

    set('e_bank_name', data.bank_name);
    set('e_bank_acc', data.bank_acc);
    set('e_emer_name', data.emer_name);
    set('e_emer_phone', data.emer_phone);

    const ap = document.getElementById('e_approval_status');
    const st = document.getElementById('e_status');
    if (ap && data.approval_status) ap.value = data.approval_status;
    if (st && data.status) st.value = data.status;

    openModal('editCleanerFull');
  }
</script>

</body>
</html>
<?php
// Flush buffer at the end
ob_end_flush();
