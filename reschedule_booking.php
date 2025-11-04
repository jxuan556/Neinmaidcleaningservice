<?php
// reschedule_booking.php — tailored for bookings table that uses `time_slot`
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli("localhost","root","","maid_system");
  $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB connection failed']); exit();
}

function s($v){ return trim((string)$v); }

// ---- Inputs
$userId    = (int)$_SESSION['user_id'];
$bookingId = (int)($_POST['booking_id'] ?? 0);
$newDate   = s($_POST['date'] ?? '');        // expected YYYY-MM-DD from <input type="date">
$newTime   = s($_POST['time'] ?? '');        // from <input type="time"> = HH:MM (24h)
$note      = s($_POST['note'] ?? '');

// ---- Basic validation
if ($bookingId <= 0 || $newDate==='') {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit();
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'Invalid date']); exit();
}
// if provided, HH:MM only (we'll store into time_slot)
if ($newTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $newTime)) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'Invalid time']); exit();
}

/* ===== ENFORCE half-hour times only (HH:00 or HH:30) ===== */
if ($newTime !== '') {
  [$hh,$mm] = explode(':', $newTime);
  if (!in_array($mm, ['00','30'], true)) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Time must be on the half hour (HH:00 or HH:30).']); exit();
  }
}

// ---- Ensure booking belongs to user & reschedulable
$stmt = $conn->prepare("SELECT id,user_id,status FROM bookings WHERE id=? LIMIT 1");
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$bk = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bk || (int)$bk['user_id'] !== $userId) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit();
}
if (in_array(strtolower($bk['status']), ['cancelled','canceled'], true)) {
  http_response_code(409);
  echo json_encode(['ok'=>false,'error'=>'This booking is cancelled']); exit();
}

// ---- Helpers: column detection
function col_exists(mysqli $c, string $table, string $col): bool {
  $table = preg_replace('/[^A-Za-z0-9_]/','',$table);
  $col   = preg_replace('/[^A-Za-z0-9_]/','',$col);
  $dbRow = $c->query("SELECT DATABASE()")->fetch_row();
  $db    = $dbRow ? $dbRow[0] : '';
  $stmt  = $c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  $stmt->bind_param('sss', $db, $table, $col);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

// Columns (check safely)
$hasTimeSlot   = col_exists($conn,'bookings','time_slot');     // yes in your table
$hasAssignedW  = col_exists($conn,'bookings','assigned_worker_id');
$hasAssignedN  = col_exists($conn,'bookings','assigned_worker_name');
$hasApprovedAt = col_exists($conn,'bookings','approved_at');
$hasAssignedAt = col_exists($conn,'bookings','assigned_at');
$hasArrivalAt  = col_exists($conn,'bookings','arrival_at');
$hasStartedAt  = col_exists($conn,'bookings','started_at');
$hasFinishedAt = col_exists($conn,'bookings','finished_at');
$hasCompletedAt= col_exists($conn,'bookings','completed_at');
$hasUpdatedAt  = col_exists($conn,'bookings','updated_at');

// ---- Build dynamic UPDATE
$sets   = ["`date`=?","`status`='pending'"];
$params = [$newDate];
$types  = "s";

// Put time into `time_slot` if present (your table uses this)
if ($hasTimeSlot && $newTime !== '') {
  $sets[]   = "`time_slot`=?";
  $params[] = $newTime;
  $types   .= "s";
}

// Clear assignment & progress timestamps (so admin can re-assign cleanly)
if ($hasAssignedW)   $sets[] = "`assigned_worker_id`=NULL";
if ($hasAssignedN)   $sets[] = "`assigned_worker_name`=NULL";
if ($hasApprovedAt)  $sets[] = "`approved_at`=NULL";
if ($hasAssignedAt)  $sets[] = "`assigned_at`=NULL";
if ($hasArrivalAt)   $sets[] = "`arrival_at`=NULL";
if ($hasStartedAt)   $sets[] = "`started_at`=NULL";
if ($hasFinishedAt)  $sets[] = "`finished_at`=NULL";
if ($hasCompletedAt) $sets[] = "`completed_at`=NULL";
if ($hasUpdatedAt)   $sets[] = "`updated_at`=NOW()";

$sql = "UPDATE `bookings` SET ".implode(', ',$sets)." WHERE id=? AND user_id=?";
$params[] = $bookingId; $types .= "i";
$params[] = $userId;    $types .= "i";

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->close();

  // optional history if the table exists
  if ($conn->query("SHOW TABLES LIKE 'booking_history'")->num_rows) {
    $stmt2 = $conn->prepare(
      "INSERT INTO booking_history (booking_id, action, note, created_at)
       VALUES (?, 'reschedule', ?, NOW())"
    );
    $stmt2->bind_param('is', $bookingId, $note);
    $stmt2->execute();
    $stmt2->close();
  }

  $conn->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  $conn->rollback();
  $DEBUG=false; // flip to true locally to see error details
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$DEBUG ? $e->getMessage() : 'Failed to reschedule']);
}

    