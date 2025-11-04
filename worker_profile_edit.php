<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'worker') { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* --- ensure worker_profile_changes has all columns we need --- */
$conn->query("
  CREATE TABLE IF NOT EXISTS worker_profile_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    submitted_by INT NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX (worker_id), INDEX (status), INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$hasSubmittedBy = $conn->query("
  SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='worker_profile_changes' AND COLUMN_NAME='submitted_by' LIMIT 1
")->num_rows > 0;
if (!$hasSubmittedBy) {
  $conn->query("ALTER TABLE worker_profile_changes ADD COLUMN submitted_by INT NULL AFTER worker_id");
}

/* --- current worker profile --- */
$userId   = (int)$_SESSION['user_id'];
$workerId = (int)($_SESSION['worker_id'] ?? 0);
if (!$workerId) {
  $st=$conn->prepare("SELECT id FROM worker_profiles WHERE user_id=? LIMIT 1");
  $st->bind_param("i",$userId); $st->execute();
  $row=$st->get_result()->fetch_assoc(); $st->close();
  if ($row){ $workerId=(int)$row['id']; $_SESSION['worker_id']=$workerId; }
}
if (!$workerId) { die("No worker profile linked."); }

$st=$conn->prepare("SELECT * FROM worker_profiles WHERE id=? LIMIT 1");
$st->bind_param("i",$workerId); $st->execute();
$worker=$st->get_result()->fetch_assoc(); $st->close();
if(!$worker){ die("Worker profile not found."); }

/* --- check if there is a pending change already --- */
$pending=null;
$st=$conn->prepare("SELECT id,created_at FROM worker_profile_changes WHERE worker_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
$st->bind_param("i",$workerId); $st->execute();
$pending=$st->get_result()->fetch_assoc(); $st->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Profile – Worker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --brand:#10b981; --brand-700:#15803d; }
  *{box-sizing:border-box} body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
  .wrap{max-width:980px;margin:20px auto;padding:0 12px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
  h1{margin:0 0 6px}
  .muted{color:var(--muted)}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .field{display:flex;flex-direction:column;gap:6px}
  label{font-size:12px;font-weight:700;color:#64748b}
  input,select,textarea{padding:10px;border:1px solid var(--line);border-radius:10px;width:100%;background:#fafafa}
  .section{margin-top:16px}
  .actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:10px 14px;border:1px solid var(--line);border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
  .btn.primary{background:var(--brand);border:0;color:#fff;font-weight:700}
  .btn.primary:hover{background:var(--brand-700)}
  .note{font-size:12px;color:#64748b}
  .banner{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:10px 12px;border-radius:12px;margin-bottom:12px}
  @media (max-width:900px){ .grid2{grid-template-columns:1fr;} }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Edit My Profile</h1>
    <div class="muted">Changes are sent to Admin for approval. Your current public profile doesn’t change until approved.</div>

    <?php if($pending): ?>
      <div class="banner">You already have a pending request #<?= (int)$pending['id'] ?> (<?= h($pending['created_at']) ?>). Submitting again will create another request.</div>
    <?php endif; ?>

    <form action="worker_profile_edit_save.php" method="post">
      <input type="hidden" name="worker_id" value="<?= (int)$workerId ?>">

      <!-- Identity -->
      <div class="section"><h3>Identity</h3></div>
      <div class="grid2">
        <div class="field"><label>Name</label><input name="name" value="<?= h($worker['name'] ?? '') ?>"></div>
        <div class="field"><label>Email</label><input name="email" type="email" value="<?= h($worker['email'] ?? '') ?>"></div>

        <div class="field"><label>Phone</label><input name="phone" value="<?= h($worker['phone'] ?? '') ?>"></div>
        <div class="field"><label>Gender</label>
          <select name="gender">
            <?php $g = strtolower((string)($worker['gender'] ?? '')); ?>
            <option value="">Select…</option>
            <option <?= $g==='male'?'selected':'' ?>>Male</option>
            <option <?= $g==='female'?'selected':'' ?>>Female</option>
            <option <?= $g==='other'?'selected':'' ?>>Other</option>
          </select>
        </div>

        <div class="field"><label>Date of Birth (YYYY-MM-DD)</label><input name="dob" value="<?= h($worker['dob'] ?? '') ?>"></div>
        <div class="field"><label>IC / Passport</label><input name="ic_passport" value="<?= h($worker['ic_passport'] ?? '') ?>"></div>

        <div class="field"><label>Nationality</label><input name="nationality" value="<?= h($worker['nationality'] ?? '') ?>"></div>
        <div class="field"><label>IC No</label><input name="ic_no" value="<?= h($worker['ic_no'] ?? '') ?>"></div>
      </div>

      <!-- Location -->
      <div class="section"><h3>Location</h3></div>
      <div class="grid2">
        <div class="field"><label>Area</label><input name="area" value="<?= h($worker['area'] ?? '') ?>"></div>
        <div class="field" style="grid-column:1 / -1"><label>Address</label><input name="address" value="<?= h($worker['address'] ?? '') ?>"></div>
      </div>

      <!-- Work -->
      <div class="section"><h3>Work & Availability</h3></div>
      <div class="grid2">
        <div class="field"><label>Experience (years)</label><input name="experience_years" type="number" min="0" step="1" value="<?= h($worker['experience_years'] ?? '0') ?>"></div>
        <div class="field"><label>Specialties</label><input name="specialties" value="<?= h($worker['specialties'] ?? '') ?>"></div>
        <div class="field"><label>Languages</label><input name="languages" value="<?= h($worker['languages'] ?? '') ?>"></div>
        <div class="field"><label>Availability Days</label><input name="availability_days" placeholder="e.g. Mon–Fri" value="<?= h($worker['availability_days'] ?? '') ?>"></div>
        <div class="field"><label>Hours From (HH:MM)</label><input name="hours_from" value="<?= h($worker['hours_from'] ?? '') ?>"></div>
        <div class="field"><label>Hours To (HH:MM)</label><input name="hours_to" value="<?= h($worker['hours_to'] ?? '') ?>"></div>
      </div>

      <!-- Banking -->
      <div class="section"><h3>Banking & Payout</h3> <div class="note">Admin will verify changes for compliance before updates go live.</div></div>
      <div class="grid2">
        <div class="field"><label>Bank Name</label><input name="bank_name" placeholder="e.g. Maybank" value="<?= h($worker['bank_name'] ?? '') ?>"></div>
        <div class="field"><label>Bank Account No.</label><input name="bank_account_no" placeholder="digits only" value="<?= h($worker['bank_account_no'] ?? '') ?>"></div>
      </div>

      <div class="actions">
        <a class="btn" href="worker_profile.php">Cancel</a>
        <button class="btn primary" type="submit">Submit for Admin Approval</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
