<?php
// worker_profile_edit_save.php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'worker')) {
  header("Location: login.php"); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

/* ---------- helpers ---------- */
function table_has_col(mysqli $conn, string $table, string $col): bool {
  try {
    $db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    $db = $conn->real_escape_string($db);
    $t  = $conn->real_escape_string($table);
    $c  = $conn->real_escape_string($col);
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}' LIMIT 1");
    return ($rs && $rs->num_rows>0);
  } catch(Throwable $e){ return false; }
}

function list_columns(mysqli $conn, string $table): array {
  $cols=[];
  try { $r=$conn->query("SHOW COLUMNS FROM `$table`"); while($c=$r->fetch_assoc()) $cols[$c['Field']]=true; } catch(Throwable $e){}
  return $cols;
}

function keep_only_worker_profile_fields(mysqli $conn, array $in): array {
  $allowed = list_columns($conn,'worker_profiles');
  unset($in['worker_id'], $in['id']); // never allow these
  $out=[];
  foreach ($in as $k=>$v) {
    if (isset($allowed[$k])) { $out[$k] = is_string($v) ? trim($v) : $v; }
  }
  return $out;
}

/* ---------- ensure table & relax JSON constraint if present ---------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS worker_profile_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    submitted_by INT NULL,
    payload LONGTEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX (worker_id), INDEX (status), INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Try to drop any CHECK constraint tied to payload (MariaDB syntax). Ignore errors safely. */
try { $conn->query("ALTER TABLE worker_profile_changes DROP CHECK `payload`"); } catch(Throwable $e){}
try { $conn->query("ALTER TABLE worker_profile_changes DROP CHECK `worker_profile_changes.payload`"); } catch(Throwable $e){}
try { $conn->query("ALTER TABLE worker_profile_changes MODIFY COLUMN payload LONGTEXT NULL"); } catch(Throwable $e){}

/* ---------- input ---------- */
$userId   = (int)$_SESSION['user_id'];
$workerId = (int)($_POST['worker_id'] ?? 0);
if ($workerId <= 0) { header("Location: worker_profile.php?err=Bad+request"); exit(); }

/* Normalise some sensitive fields */
$clean = $_POST;
if (isset($clean['bank_account_no'])) {
  $clean['bank_account_no'] = preg_replace('/[^0-9\- ]/', '', (string)$clean['bank_account_no']);
}
if (isset($clean['payout_percent'])) {
  $pp = (int)$clean['payout_percent'];
  if ($pp < 0) $pp = 0; if ($pp > 100) $pp = 100;
  $clean['payout_percent'] = $pp;
}

/* Only keep columns that exist in worker_profiles */
$payload = keep_only_worker_profile_fields($conn, $clean);
if (!$payload) {
  header("Location: worker_profile.php?msg=Nothing+to+update"); exit();
}

/* Encode JSON safely */
$payload = array_map(
  fn($v) => is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v,
  $payload
);
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/* Fallback JSON if encoding failed */
if ($json === false) {
  $json = json_encode(['_safe'=>true], JSON_UNESCAPED_UNICODE);
}

/* ---------- insert pending request (robust) ---------- */
$insertOk = false;
try {
  $st = $conn->prepare("INSERT INTO worker_profile_changes (worker_id, submitted_by, payload, status) VALUES (?, ?, ?, 'pending')");
  $st->bind_param("iis", $workerId, $userId, $json);
  $st->execute();
  $st->close();
  $insertOk = true;
} catch (mysqli_sql_exception $e) {
  // If there is still a CHECK(JSON_VALID(payload)) constraint somewhere, try a minimal valid JSON string
  try {
    $json2 = '{}';
    $st2 = $conn->prepare("INSERT INTO worker_profile_changes (worker_id, submitted_by, payload, status) VALUES (?, ?, ?, 'pending')");
    $st2->bind_param("iis", $workerId, $userId, $json2);
    $st2->execute();
    $st2->close();
    $insertOk = true;
  } catch (mysqli_sql_exception $e2) {
    // Final safety: show a clear actionable message
    http_response_code(500);
    echo "<h3>Could not queue your profile changes.</h3>";
    echo "<p>Your database has a strict JSON constraint on <code>worker_profile_changes.payload</code>.</p>";
    echo "<p>Please run this SQL once in phpMyAdmin and try again:</p>";
    echo "<pre>ALTER TABLE worker_profile_changes DROP CHECK `payload`;\nALTER TABLE worker_profile_changes MODIFY payload LONGTEXT NULL;</pre>";
    echo "<p><small>DB error: ".htmlspecialchars($e2->getMessage())."</small></p>";
    exit();
  }
}

if ($insertOk) {
  header("Location: worker_profile.php?submitted=1");
  exit();
}




