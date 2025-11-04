<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'worker') {
  header("Location: login.php");
  exit();
}
date_default_timezone_set('Asia/Kuala_Lumpur');

/* ===== DB connection ===== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mask_acct($s){
  $s = preg_replace('/\s+/', '', (string)$s);
  if ($s === '') return '—';
  $len = strlen($s);
  if ($len <= 4) return str_repeat('•', max(0,$len-1)).substr($s,-1);
  return str_repeat('•', max(0,$len-4)).substr($s,-4);
}

/* ===== Self-heal for schema ===== */
function ensure_worker_profile_changes_schema(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS worker_profile_changes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      worker_id INT NOT NULL,
      payload LONGTEXT NOT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      admin_note TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
      INDEX (worker_id),
      INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
ensure_worker_profile_changes_schema($conn);

/* ===== Worker record ===== */
$workerId = (int)($_SESSION['worker_id'] ?? $_SESSION['user_id']);
$stmt = $conn->prepare("SELECT * FROM worker_profiles WHERE id=? LIMIT 1");
$stmt->bind_param("i", $workerId);
$stmt->execute();
$worker = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$worker) { die("Worker profile not found."); }

/* For sidebar avatar/name */
$userName = $_SESSION['name'] ?? 'Worker';
$workerDisplayName = $worker['name'] ?? $userName;

/* ===== Pending change ===== */
$pendingInfo=null;
$st=$conn->prepare("SELECT id,created_at FROM worker_profile_changes WHERE worker_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
$st->bind_param("i",$workerId);
$st->execute();
$pendingInfo=$st->get_result()->fetch_assoc();
$st->close();

/* ===== Status badge ===== */
$status = strtolower((string)($worker['status'] ?? 'active'));
$chipClass = [
  'active'    => 'chip-green',
  'inactive'  => 'chip-gray',
  'suspended' => 'chip-red'
][$status] ?? 'chip-gray';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Worker Profile – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#0f172a; --muted:#64748b; --bg:#f6f7fb; --card:#ffffff; --bd:#e5e7eb;
    --green:#22c55e; --green-700:#15803d; --red:#ef4444; --gray:#475569;
  }
  *{box-sizing:border-box}
  body{margin:0; font-family:Inter,Arial,sans-serif; background:var(--bg); color:var(--ink);}

  /* === shared layout (same as dashboard/history/finance) === */
  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#cbd5e1;padding:20px;display:flex;flex-direction:column;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:900}
  .avatar{width:40px;height:40px;border-radius:50%;background:#1f2937;display:grid;place-items:center;color:#fff;font-weight:800}
  .nav a{display:block;color:#cbd5e1;text-decoration:none;padding:10px;border-radius:10px}
  .nav a.active,.nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .main{padding:20px}

  /* === page content styles (your original) === */
  .wrap{max-width:980px; margin:0 auto; padding:0 12px}
  .banner{max-width:980px; margin:0 auto 12px; background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:12px 16px; border-radius:12px}
  .card{background:var(--card); border:1px solid var(--bd); border-radius:16px; box-shadow:0 6px 18px rgba(0,0,0,0.06); padding:20px}
  h1{margin:0 0 6px}
  .muted{color:var(--muted)}
  .grid2{display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px}
  .field{border:1px solid var(--bd); border-radius:12px; padding:12px 14px; background:#fafafa}
  .label{font-size:12px; font-weight:700; color:var(--muted); margin-bottom:4px}
  .value{font-weight:600; font-size:15px; word-break:break-word}
  .section-title{margin:18px 0 6px; font-size:18px}
  .actions{display:flex; gap:10px; margin-top:18px}
  .btn{padding:12px 16px; border:0; border-radius:10px; font-weight:700; cursor:pointer}
  .btn.back{background:#e5e7eb; color:#374151; text-decoration:none; display:inline-block}
  .btn.edit{background:var(--green); color:#fff; text-decoration:none; display:inline-block}
  .btn.edit:hover{background:var(--green-700)}
  .chip{display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700}
  .chip-green{background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46}
  .chip-gray{background:#f1f5f9; border:1px solid #e2e8f0; color:#334155}
  .chip-red{background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d}
  @media (max-width:900px){
    .layout{grid-template-columns:1fr}
    .grid2{grid-template-columns:1fr;}
  }
</style>
</head>
<body>

<div class="layout">
  <!-- Sidebar -->
  <aside class="side">
    <div class="brand">
      <div class="avatar"><?= strtoupper(substr($workerDisplayName,0,1)) ?></div>
      NeinMaid Worker
    </div>
    <nav class="nav">
      <a href="worker_dashboard.php">🏠 Dashboard</a>
      <a href="worker_history.php">📜 Job History</a>
      <a href="worker_finance.php">💰 Finance</a>
      <a class="active" href="worker_profile.php">👤 Profile</a>
      <a href="contact_chat.php">💬 Chats</a>
    </nav>
    <div style="flex:1"></div>
    <a class="nav" href="logout.php" style="color:#fecaca;text-decoration:none">⏻ Logout</a>
  </aside>

  <!-- Main content -->
  <main class="main">
    <?php if($pendingInfo): ?>
      <div class="banner">
        Your profile change request <strong>#<?= (int)$pendingInfo['id'] ?></strong> is pending since <?= h($pendingInfo['created_at']) ?>.
        Admin has been notified and will review it soon.
      </div>
    <?php endif; ?>

    <div class="wrap">
      <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
          <div>
            <h1>My Profile</h1>
            <div class="muted">These are your current approved details.</div>
          </div>
          <span class="chip <?= $chipClass ?>">Status: <?= h(ucfirst($status)) ?></span>
        </div>

        <!-- Identity -->
        <h3 class="section-title">Identity</h3>
        <div class="grid2">
          <div class="field"><div class="label">Name</div><div class="value"><?= h($worker['name']) ?></div></div>
          <div class="field"><div class="label">Email</div><div class="value"><?= h($worker['email']) ?></div></div>
          <div class="field"><div class="label">Phone</div><div class="value"><?= h($worker['phone']) ?></div></div>
          <div class="field"><div class="label">Gender</div><div class="value"><?= h($worker['gender']) ?></div></div>
          <div class="field"><div class="label">Date of Birth</div><div class="value"><?= h($worker['dob']) ?></div></div>
          <div class="field"><div class="label">IC / Passport</div><div class="value"><?= h($worker['ic_passport']) ?></div></div>
          <div class="field"><div class="label">Nationality</div><div class="value"><?= h($worker['nationality']) ?></div></div>
          <div class="field"><div class="label">IC No</div><div class="value"><?= h($worker['ic_no']) ?></div></div>
        </div>

        <!-- Location -->
        <h3 class="section-title">Location</h3>
        <div class="grid2">
          <div class="field"><div class="label">Area</div><div class="value"><?= h($worker['area']) ?></div></div>
          <div class="field"><div class="label">Address</div><div class="value"><?= h($worker['address']) ?></div></div>
        </div>

        <!-- Work Info -->
        <h3 class="section-title">Work & Availability</h3>
        <div class="grid2">
          <div class="field"><div class="label">Experience (Years)</div><div class="value"><?= h($worker['experience_years']) ?></div></div>
          <div class="field"><div class="label">Specialties</div><div class="value"><?= h($worker['specialties']) ?></div></div>
          <div class="field"><div class="label">Languages</div><div class="value"><?= h($worker['languages']) ?></div></div>
          <div class="field"><div class="label">Availability Days</div><div class="value"><?= h($worker['availability_days']) ?></div></div>
          <div class="field"><div class="label">Hours From</div><div class="value"><?= h($worker['hours_from']) ?></div></div>
          <div class="field"><div class="label">Hours To</div><div class="value"><?= h($worker['hours_to']) ?></div></div>
        </div>

        <!-- Banking / Payout -->
        <h3 class="section-title">Banking & Payout</h3>
        <div class="grid2">
          <div class="field"><div class="label">Bank Name</div><div class="value"><?= h($worker['bank_name'] ?? '—') ?></div></div>
          <div class="field"><div class="label">Bank Account No.</div><div class="value"><?= h(mask_acct($worker['bank_account_no'] ?? '')) ?></div></div>
          <div class="field"><div class="label">Profile Created</div><div class="value"><?= h($worker['created_at'] ?? '—') ?></div></div>
        </div>

        <div class="actions">
          <a class="btn back" href="worker_dashboard.php">← Back</a>
          <a class="btn edit" href="worker_profile_edit.php">✏ Edit Profile (Submit for Approval)</a>
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
