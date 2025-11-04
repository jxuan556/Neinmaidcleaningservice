<?php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok'=>false,'msg'=>'Forbidden']); exit;
}
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* Auto-create table (just in case) */
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

/* Helper: safe column check */
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

$adminId = (int)$_SESSION['user_id'];
$id      = (int)($_POST['id'] ?? 0);
$action  = $_POST['action'] ?? '';

if (!$id || !in_array($action, ['approve','reject'], true)) {
  echo json_encode(['ok'=>false,'msg'=>'Bad request']); exit;
}

/* Fetch change */
$st = $conn->prepare("SELECT id, worker_id, payload, status FROM worker_profile_changes WHERE id=? LIMIT 1");
$st->bind_param("i",$id); $st->execute();
$chg = $st->get_result()->fetch_assoc(); $st->close();
if (!$chg || $chg['status']!=='pending') {
  echo json_encode(['ok'=>false,'msg'=>'Not found or already processed']); exit;
}

if ($action==='reject') {
  $st=$conn->prepare("UPDATE worker_profile_changes SET status='rejected', reviewed_at=NOW(), reviewed_by=? WHERE id=?");
  $st->bind_param("ii",$adminId,$id); $st->execute(); $st->close();
  echo json_encode(['ok'=>true]); exit;
}

/* Approve: apply to worker_profiles */
$payload = json_decode($chg['payload'] ?? '{}', true);
if (!is_array($payload) || !$payload) {
  echo json_encode(['ok'=>false,'msg'=>'Empty payload']); exit;
}

$allowed = [
  'name','email','phone','gender','dob','ic_passport','ic_no','nationality',
  'area','address','experience_years','specialties','languages',
  'availability_days','hours_from','hours_to','status',
  'bank_name','bank_account','bank_holder'
];

$sets=[]; $vals=[]; $types='';
foreach ($payload as $k=>$v) {
  if (!in_array($k,$allowed,true)) continue;
  if (!col_exists($conn,'worker_profiles',$k)) continue;
  $sets[]="`$k`=?"; $vals[]=(string)$v; $types.='s';
}
if (!$sets) { echo json_encode(['ok'=>false,'msg'=>'No valid columns to update']); exit; }

$vals[]=(int)$chg['worker_id']; $types.='i';
$sql="UPDATE worker_profiles SET ".implode(', ',$sets)." WHERE id=?";
$st=$conn->prepare($sql);
$st->bind_param($types, ...$vals); $st->execute(); $st->close();

/* Mark as approved */
$st=$conn->prepare("UPDATE worker_profile_changes SET status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?");
$st->bind_param("ii",$adminId,$id); $st->execute(); $st->close();

echo json_encode(['ok'=>true]);
