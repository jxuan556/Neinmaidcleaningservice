<?php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'worker')) {
  header("Location: login.php"); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

$userId   = (int)$_SESSION['user_id'];
$workerId = (int)($_SESSION['worker_id'] ?? 0);

/* Resolve worker_id if not set in session */
if (!$workerId) {
  $st = $conn->prepare("SELECT id FROM worker_profiles WHERE user_id=? LIMIT 1");
  $st->bind_param("i",$userId); $st->execute();
  $row = $st->get_result()->fetch_assoc(); $st->close();
  if ($row) { $workerId = (int)$row['id']; $_SESSION['worker_id'] = $workerId; }
}
if (!$workerId) { die("Worker profile not found."); }

/* Auto-create table if missing */
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

/* Whitelist editable fields (must exist in worker_profiles) */
$allowed = [
  'name','email','phone','gender','dob','ic_passport','ic_no','nationality',
  'area','address','experience_years','specialties','languages',
  'availability_days','hours_from','hours_to','status',
  'bank_name','bank_account','bank_holder'
];

/* Build payload from POST (only non-empty values) */
$payload = [];
foreach ($allowed as $key) {
  if (array_key_exists($key, $_POST)) {
    $val = trim((string)$_POST[$key]);
    if ($val !== '') $payload[$key] = $val;
  }
}

/* If no changes, bounce back */
if (!$payload) {
  $_SESSION['flash'] = "Nothing to submit — fill at least one field.";
  header("Location: worker_profile_edit.php"); exit();
}

/* Store as pending */
$j = json_encode($payload, JSON_UNESCAPED_UNICODE);
$st = $conn->prepare("INSERT INTO worker_profile_changes (worker_id, submitted_by, payload) VALUES (?,?,?)");
$st->bind_param("iis", $workerId, $userId, $j);
$st->execute(); $st->close();

/* Optional: notify the worker with a banner next load */
$_SESSION['flash'] = "Submitted for admin review. You’ll see the result in “My Requests”.";
header("Location: worker_profile_requests.php");