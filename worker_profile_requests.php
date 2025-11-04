<?php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'worker')) {
  header("Location: login.php"); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$userId   = (int)$_SESSION['user_id'];
$workerId = (int)($_SESSION['worker_id'] ?? 0);
if (!$workerId) {
  $st = $conn->prepare("SELECT id FROM worker_profiles WHERE user_id=? LIMIT 1");
  $st->bind_param("i",$userId); $st->execute();
  $row=$st->get_result()->fetch_assoc(); $st->close();
  if ($row) { $workerId=(int)$row['id']; $_SESSION['worker_id']=$workerId; }
}
if (!$workerId) { die("Worker profile not found."); }

/* Ensure table exists */
$conn->query("
CREATE TABLE IF NOT EXISTS worker_profile_changes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  submitted_by INT NOT NULL,
  payload JSON NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by INT NULL,
  INDEX (worker_id), INDEX (status), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Load my requests */
$rows=[];
$st=$conn->prepare("SELECT id,status,payload,created_at,reviewed_at FROM worker_profile_changes WHERE worker_id=? ORDER BY id DESC LIMIT 50");
$st->bind_param("i",$workerId); $st->execute();
$res=$st->get_result(); while($r=$res->fetch_assoc()) $rows[]=$r; $st->close();

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Profile Change Requests – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  body{margin:0;font-family:Inter,Arial,sans-serif;background:#f6f7fb;color:#0f172a}
  .wrap{max-width:1000px;margin:24px auto;padding:0 14px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
  .flash{margin-bottom:12px;background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;padding:10px 12px;border-radius:10px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;font-size:14px}
  thead th{background:#f8fafc}
  .muted{color:#64748b}
  .tag{display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px}
  .tag.pending{background:#fff7ed;border-color:#fdba74;color:#9a3412}
  .tag.approved{background:#ecfdf5;border-color:#86efac;color:#065f46}
  .tag.rejected{background:#fef2f2;border-color:#fecaca;color:#991b1b}
  .mini{font-size:12px;color:#64748b}
</style>
</head>
<body>
  <div class="wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h2 style="margin:0">📝 My Profile Change Requests</h2>
      <a href="worker_profile_edit.php" class="mini">← Back to Edit Profile</a>
    </div>

    <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

    <div class="card">
      <?php if(!$rows): ?>
        <div class="muted">No requests yet.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Status</th><th>Submitted</th><th>Reviewed</th><th>Requested Changes</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r):
              $payload = json_decode($r['payload'] ?? '{}', true) ?: [];
            ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><span class="tag <?= h($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                <td><?= h($r['created_at']) ?></td>
                <td><?= h($r['reviewed_at'] ?: '—') ?></td>
                <td>
                  <?php if(!$payload): ?>
                    <span class="muted">—</span>
                  <?php else: ?>
                    <ul style="margin:0;padding-left:16px">
                      <?php foreach($payload as $k=>$v): ?>
                        <li><strong><?= h($k) ?>:</strong> <?= h(is_array($v)?json_encode($v):$v) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
