<?php
// admin_promos.php — Dashboard-style UI, supports optional per_user_limit & per_user_amount_cap
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// Gate: allow if role === admin OR is_admin flag
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin' && empty($_SESSION['is_admin']))) {
  header("Location: login.php"); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function clean($s){ return trim(filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS)); }
function col_exists(mysqli $db, string $table, string $col): bool {
  try {
    $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->bind_param("s", $col);
    $stmt->execute(); $res = $stmt->get_result();
    $ok  = (bool)$res->fetch_row();
    $stmt->close();
    return $ok;
  } catch(Throwable $e){ return false; }
}

$errors=[]; $ok=null;
$has_per_user = col_exists($conn, 'promo_codes', 'per_user_limit');
$has_user_cap = col_exists($conn, 'promo_codes', 'per_user_amount_cap');

/* Load row for edit mode (if any) */
$editingRow = null;
if (isset($_GET['edit'])) {
  $eid = (int)$_GET['edit'];
  $stmt = $conn->prepare("SELECT * FROM promo_codes WHERE id=? LIMIT 1");
  $stmt->bind_param("i",$eid);
  $stmt->execute();
  $editingRow = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$editingRow) $errors[] = "Promo not found (id $eid).";
}

try {
  /* CREATE */
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create') {
    $code        = strtoupper(clean($_POST['code']??''));            // stored uppercase
    $percent_off = (float)($_POST['percent_off']??0);                // decimal fraction 0.10 = 10%
    $min_spend   = ($_POST['min_spend']   !== '') ? (float)$_POST['min_spend']   : null;
    $max_disc    = ($_POST['max_discount']!== '') ? (float)$_POST['max_discount'] : null;
    $usage_limit = ($_POST['usage_limit'] !== '') ? (int)$_POST['usage_limit']   : null;
    $per_user    = ($has_per_user && $_POST['per_user_limit']!=='') ? (int)$_POST['per_user_limit'] : null;
    $user_cap    = ($has_user_cap && $_POST['per_user_amount_cap']!=='') ? (float)$_POST['per_user_amount_cap'] : null;
    $starts_at   = clean($_POST['starts_at']??'');
    $ends_at     = clean($_POST['ends_at']??'');

    if ($code==='') $errors[]='Code is required.';
    if ($percent_off<=0 || $percent_off>0.90) $errors[]='Percent off must be between 0.01 and 0.90 (as decimal, e.g. 0.10).';
    if ($starts_at!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/',$starts_at)) $errors[]='Invalid Starts At format.';
    if ($ends_at!==''   && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/',$ends_at))   $errors[]='Invalid Ends At format.';
    if ($has_user_cap && $user_cap !== null && $user_cap < 0) $errors[]='Per-user discount cap must be ≥ 0.00 RM.';

    // duplicate check
    if (!$errors) {
      $chk=$conn->prepare("SELECT id FROM promo_codes WHERE code=? LIMIT 1");
      $chk->bind_param("s",$code);
      $chk->execute();
      if ($chk->get_result()->fetch_assoc()) $errors[]="A promo with code '$code' already exists.";
      $chk->close();
    }

    if (!$errors) {
      $vals = [$code,$percent_off,$min_spend,$max_disc,$usage_limit];
      $types='sddd'.'i';
      $cols=['code','percent_off','min_spend','max_discount','usage_limit'];

      if ($has_per_user) { $vals[]=$per_user; $types.='i'; $cols[]='per_user_limit'; }
      if ($has_user_cap) { $vals[]=$user_cap; $types.='d'; $cols[]='per_user_amount_cap'; }

      $startsParam = ($starts_at==='') ? null : $starts_at;
      $endsParam   = ($ends_at==='')   ? null : $ends_at;
      $vals[]=$startsParam; $vals[]=$endsParam; $types.='ss';
      $cols[]='starts_at'; $cols[]='ends_at';

      $sql="INSERT INTO promo_codes (".implode(',',$cols).",active) VALUES (".str_repeat('?,',count($vals)-1)."?,1)";
      $stmt=$conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute(); $stmt->close();
      $ok="Promo created.";
    }
  }

  /* UPDATE */
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update') {
    $id          = (int)($_POST['id']??0);
    $code        = strtoupper(clean($_POST['code']??''));
    $percent_off = (float)($_POST['percent_off']??0);
    $min_spend   = ($_POST['min_spend']   !== '') ? (float)$_POST['min_spend']   : null;
    $max_disc    = ($_POST['max_discount']!== '') ? (float)$_POST['max_discount'] : null;
    $usage_limit = ($_POST['usage_limit'] !== '') ? (int)$_POST['usage_limit']   : null;
    $per_user    = ($has_per_user && $_POST['per_user_limit']!=='') ? (int)$_POST['per_user_limit'] : null;
    $user_cap    = ($has_user_cap && $_POST['per_user_amount_cap']!=='') ? (float)$_POST['per_user_amount_cap'] : null;
    $starts_at   = clean($_POST['starts_at']??'');
    $ends_at     = clean($_POST['ends_at']??'');

    if ($id<=0) $errors[]='Invalid promo id.';
    if ($code==='') $errors[]='Code is required.';
    if ($percent_off<=0 || $percent_off>0.90) $errors[]='Percent off must be between 0.01 and 0.90 (as decimal, e.g. 0.10).';
    if ($starts_at!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/',$starts_at)) $errors[]='Invalid Starts At format.';
    if ($ends_at!==''   && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/',$ends_at))   $errors[]='Invalid Ends At format.';
    if ($has_user_cap && $user_cap !== null && $user_cap < 0) $errors[]='Per-user discount cap must be ≥ 0.00 RM.';

    // duplicate (exclude this id)
    if (!$errors) {
      $chk=$conn->prepare("SELECT id FROM promo_codes WHERE code=? AND id<>? LIMIT 1");
      $chk->bind_param("si",$code,$id);
      $chk->execute();
      if ($chk->get_result()->fetch_assoc()) $errors[]="A promo with code '$code' already exists.";
      $chk->close();
    }

    if (!$errors) {
      $vals = [$code,$percent_off,$min_spend,$max_disc,$usage_limit];
      $types='sddd'.'i';
      $set = "code=?, percent_off=?, min_spend=?, max_discount=?, usage_limit=?";

      if ($has_per_user) { $set.=", per_user_limit=?"; $vals[]=$per_user; $types.='i'; }
      if ($has_user_cap) { $set.=", per_user_amount_cap=?"; $vals[]=$user_cap; $types.='d'; }

      $startsParam = ($starts_at==='') ? null : $starts_at;
      $endsParam   = ($ends_at==='')   ? null : $ends_at;
      $set .= ", starts_at=?, ends_at=?";
      $vals[]=$startsParam; $vals[]=$endsParam; $types.='ss';

      $vals[]=$id; $types.='i';

      $sql="UPDATE promo_codes SET $set WHERE id=? LIMIT 1";
      $stmt=$conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute(); $stmt->close();
      $ok="Promo updated.";

      // reload edit row to reflect current values
      $stmt = $conn->prepare("SELECT * FROM promo_codes WHERE id=? LIMIT 1");
      $stmt->bind_param("i",$id);
      $stmt->execute();
      $editingRow = $stmt->get_result()->fetch_assoc();
      $stmt->close();
    }
  }

  /* ACTIVATE / DEACTIVATE */
  if (isset($_GET['deactivate'])) {
    $id=(int)$_GET['deactivate'];
    $stmt=$conn->prepare("UPDATE promo_codes SET active=0 WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$id);
    $stmt->execute(); $stmt->close();
    $ok="Promo deactivated.";
  }
  if (isset($_GET['activate'])) {
    $id=(int)$_GET['activate'];
    $stmt=$conn->prepare("UPDATE promo_codes SET active=1 WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$id);
    $stmt->execute(); $stmt->close();
    $ok="Promo activated.";
  }

  /* DELETE */
  if (isset($_GET['delete'])) {
    $id=(int)$_GET['delete'];
    $stmt=$conn->prepare("DELETE FROM promo_codes WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$id);
    $stmt->execute(); $stmt->close();
    $ok="Promo deleted.";
    if ($editingRow && (int)$editingRow['id']===$id) $editingRow=null; // if you deleted what you were editing
  }

} catch (Throwable $e) {
  $errors[] = "Error: ".$e->getMessage();
}

/* List */
$rows=[];
try{
  $res=$conn->query("SELECT * FROM promo_codes ORDER BY created_at DESC");
  if ($res) $rows=$res->fetch_all(MYSQLI_ASSOC);
}catch(Throwable $e){ $errors[]="Query failed: ".$e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Admin · Promotions – NeinMaid</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
  /* Dashboard-style shell (matches your other admin pages) */
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
  .muted{color:#6b7280}
  .pill{padding:6px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc}
  .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
  .btn:hover{background:#f8fafc}
  .btn-primary{background:#111827;color:#fff;border-color:#111827}
  .btn-primary:hover{opacity:.9}
  .btn-danger{background:#ef4444;color:#fff;border-color:#ef4444}
  .input, select{padding:8px;border:1px solid #e5e7eb;border-radius:10px;width:100%}
  .grid{display:grid;grid-template-columns:2fr 1fr;gap:12px;align-items:start}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;font-size:14px;vertical-align:top}
  thead th{background:#f8fafc}
  .ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:10px;border-radius:10px;margin-bottom:12px}
  .err{background:#fff1f2;border:1px solid #fda4af;color:#991b1b;padding:10px;border-radius:10px;margin-bottom:12px}
  .actions a, .actions button{margin-right:6px}
  @media (max-width:1100px){ .grid{grid-template-columns:1fr} }
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
      <a href="admin_dashboard.php">Dashboard</a>
      <a href="admin_bookings.php">Bookings</a>
      <a href="admin_employees.php">Workers</a>
      <a href="admin_services.php">Services</a>
      <a href="admin_finance.php">Finance</a>
      <a class="active" href="admin_promos.php">Promotions</a>
      <a href="admin_worker_changes.php">Worker Changes</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <section class="card">
      <div class="row-sb">
        <h1 style="margin:0">Promotions</h1>
        <div class="row">
          <?php if($ok): ?><div class="pill"><?= h($ok) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if($errors): ?>
        <div class="err" style="margin-top:10px">
          <strong>Please fix:</strong>
          <ul style="margin:8px 0 0 18px">
            <?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

    <section class="grid" style="margin-top:12px">
      <!-- List -->
      <div class="card">
        <div class="row-sb">
          <h3 style="margin:0">All Promos</h3>
          <a class="btn" href="admin_dashboard.php">← Back to Admin</a>
        </div>
        <div style="overflow:auto; margin-top:10px">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>% Off</th>
                <th>Min</th>
                <th>Cap</th>
                <th>Total Limit</th>
                <?php if ($has_per_user): ?><th>Per-User Limit</th><?php endif; ?>
                <?php if ($has_user_cap): ?><th>Per-User Cap (RM)</th><?php endif; ?>
                <th>Used</th>
                <th>Window</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if($rows): foreach($rows as $r): ?>
                <tr>
                  <td><strong><?= h($r['code']) ?></strong></td>
                  <td><?= number_format(((float)$r['percent_off'])*100,2) ?>%</td>
                  <td><?= $r['min_spend']!==null? 'RM'.number_format((float)$r['min_spend'],2):'—' ?></td>
                  <td><?= $r['max_discount']!==null? 'RM'.number_format((float)$r['max_discount'],2):'—' ?></td>
                  <td><?= $r['usage_limit']!==null? (int)$r['usage_limit']:'—' ?></td>
                  <?php if ($has_per_user): ?>
                    <td><?= isset($r['per_user_limit']) && $r['per_user_limit']!==null ? (int)$r['per_user_limit'] : '—' ?></td>
                  <?php endif; ?>
                  <?php if ($has_user_cap): ?>
                    <td><?= isset($r['per_user_amount_cap']) && $r['per_user_amount_cap']!==null ? 'RM'.number_format((float)$r['per_user_amount_cap'],2) : '—' ?></td>
                  <?php endif; ?>
                  <td><?= (int)($r['used_count'] ?? 0) ?></td>
                  <td class="muted"><?= h($r['starts_at'] ?: '—') ?> → <?= h($r['ends_at'] ?: '—') ?></td>
                  <td><?= !empty($r['active']) ? 'Active' : 'Off' ?></td>
                  <td class="actions">
                    <a class="btn" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
                    <?php if(!empty($r['active'])): ?>
                      <a class="btn" href="?deactivate=<?= (int)$r['id'] ?>" onclick="return confirm('Deactivate this promo?')">Deactivate</a>
                    <?php else: ?>
                      <a class="btn" href="?activate=<?= (int)$r['id'] ?>">Activate</a>
                    <?php endif; ?>
                    <a class="btn btn-danger" href="?delete=<?= (int)$r['id'] ?>" onclick="return confirm('Delete this promo code permanently?')">Delete</a>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr>
                  <td colspan="<?= 9 + ($has_per_user?1:0) + ($has_user_cap?1:0) ?>" class="muted">No promos yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Create / Edit -->
      <div class="card">
        <?php if ($editingRow): ?>
          <h3 style="margin:0 0 8px">Edit Promo</h3>
          <form method="post">
            <input type="hidden" name="action" value="update" />
            <input type="hidden" name="id" value="<?= (int)$editingRow['id'] ?>" />
            <div style="display:grid;gap:10px">
              <div>
                <label class="muted">Code</label>
                <input class="input" name="code" value="<?= h($editingRow['code']) ?>" required>
              </div>
              <div>
                <label class="muted">Percent Off (0.10 = 10%)</label>
                <input class="input" name="percent_off" type="number" step="0.01" min="0.01" max="0.90" value="<?= h($editingRow['percent_off']) ?>" required>
              </div>
              <div>
                <label class="muted">Min Spend (RM)</label>
                <input class="input" name="min_spend" type="number" step="0.01" value="<?= h($editingRow['min_spend']) ?>">
              </div>
              <div>
                <label class="muted">Max Discount Cap (RM)</label>
                <input class="input" name="max_discount" type="number" step="0.01" value="<?= h($editingRow['max_discount']) ?>">
              </div>
              <div>
                <label class="muted">Total Usage Limit (blank = unlimited)</label>
                <input class="input" name="usage_limit" type="number" step="1" min="0" value="<?= h($editingRow['usage_limit']) ?>">
              </div>
              <?php if ($has_per_user): ?>
              <div>
                <label class="muted">Per-User Usage Limit (blank = unlimited)</label>
                <input class="input" name="per_user_limit" type="number" step="1" min="0" value="<?= h($editingRow['per_user_limit']) ?>">
              </div>
              <?php endif; ?>
              <?php if ($has_user_cap): ?>
              <div>
                <label class="muted">Per-User Discount Cap (RM, blank = unlimited)</label>
                <input class="input" name="per_user_amount_cap" type="number" step="0.01" min="0" value="<?= h($editingRow['per_user_amount_cap']) ?>">
              </div>
              <?php endif; ?>
              <div>
                <label class="muted">Starts At (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)</label>
                <input class="input" name="starts_at" value="<?= h($editingRow['starts_at']) ?>">
              </div>
              <div>
                <label class="muted">Ends At (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)</label>
                <input class="input" name="ends_at" value="<?= h($editingRow['ends_at']) ?>">
              </div>
              <div class="row" style="justify-content:flex-end">
                <button class="btn btn-primary" type="submit">Save changes</button>
                <a class="btn" href="admin_promos.php">Cancel</a>
              </div>
            </div>
          </form>
        <?php else: ?>
          <h3 style="margin:0 0 8px">Create Promo</h3>
          <form method="post">
            <input type="hidden" name="action" value="create" />
            <div style="display:grid;gap:10px">
              <div>
                <label class="muted">Code</label>
                <input class="input" name="code" placeholder="WELCOME10" required>
              </div>
              <div>
                <label class="muted">Percent Off (0.10 = 10%)</label>
                <input class="input" name="percent_off" type="number" step="0.01" min="0.01" max="0.90" placeholder="e.g. 0.10" required>
              </div>
              <div>
                <label class="muted">Min Spend (RM)</label>
                <input class="input" name="min_spend" type="number" step="0.01" placeholder="e.g. 60.00">
              </div>
              <div>
                <label class="muted">Max Discount Cap (RM)</label>
                <input class="input" name="max_discount" type="number" step="0.01" placeholder="e.g. 40.00">
              </div>
              <div>
                <label class="muted">Total Usage Limit (blank = unlimited)</label>
                <input class="input" name="usage_limit" type="number" step="1" min="1" placeholder="e.g. 100">
              </div>
              <?php if ($has_per_user): ?>
              <div>
                <label class="muted">Per-User Usage Limit (blank = unlimited)</label>
                <input class="input" name="per_user_limit" type="number" step="1" min="1" placeholder="e.g. 1">
              </div>
              <?php endif; ?>
              <?php if ($has_user_cap): ?>
              <div>
                <label class="muted">Per-User Discount Cap (RM, blank = unlimited)</label>
                <input class="input" name="per_user_amount_cap" type="number" step="0.01" min="0" placeholder="e.g. 50.00">
              </div>
              <?php endif; ?>
              <div>
                <label class="muted">Starts At</label>
                <input class="input" name="starts_at" placeholder="2025-11-03 00:00:00">
              </div>
              <div>
                <label class="muted">Ends At</label>
                <input class="input" name="ends_at" placeholder="2026-01-01 00:00:00">
              </div>
              <div class="row" style="justify-content:flex-end">
                <button class="btn btn-primary" type="submit">Create</button>
              </div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </main>
</div>

</body>
</html>
