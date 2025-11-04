<?php
// chat_api_worker.php — JSON endpoints for booking-scoped user↔worker chat
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";

try {
  $conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
  $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'DB error']); exit;
}

$role     = $_SESSION['role'] ?? 'user';
$isWorker = ($role === 'worker');
$uid      = (int)$_SESSION['user_id'];

$action   = $_REQUEST['action'] ?? '';

/* === Bootstrap chat tables if missing === */
$conn->query("
  CREATE TABLE IF NOT EXISTS chat_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    worker_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_thread (booking_id, worker_id, user_id),
    INDEX(booking_id), INDEX(worker_id), INDEX(user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
  CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    sender ENUM('user','worker') NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(thread_id),
    CONSTRAINT fk_cm_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* === Helpers === */
function json_ok($data=[]){ echo json_encode(['ok'=>true]+$data); exit; }
function json_err($msg,$code=400){ http_response_code($code); echo json_encode(['ok'=>false,'msg'=>$msg]); exit; }

/* === Access check for a booking & worker pairing === */
function assert_access_for_booking(mysqli $conn, bool $isWorker, int $uid, int $bookingId, int $workerId): array {
  if ($bookingId<=0 || $workerId<=0) json_err('Invalid parameters', 400);

  if ($isWorker) {
    // worker must be the assigned worker for this booking
    $st = $conn->prepare("SELECT b.user_id AS user_id, b.assigned_worker_id AS worker_id
                          FROM bookings b WHERE b.id=? LIMIT 1");
    $st->bind_param("i",$bookingId); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row || (int)$row['worker_id'] !== (int)$workerId) json_err('Forbidden',403);

    // Find worker_profiles.user_id for chat user_id? We still use bookings.user_id for customer id.
    return ['user_id'=>(int)$row['user_id']];
  } else {
    // user must own this booking
    $st = $conn->prepare("SELECT b.user_id AS user_id, b.assigned_worker_id AS worker_id
                          FROM bookings b WHERE b.id=? AND b.user_id=? LIMIT 1");
    $st->bind_param("ii",$bookingId,$uid); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) json_err('Forbidden',403);
    if ((int)$row['worker_id'] !== (int)$workerId) {
      // Allow chat even if worker not yet assigned? Safer to require match:
      json_err('Worker not assigned to this booking', 409);
    }
    return ['user_id'=>(int)$row['user_id']];
  }
}

/* === Ensure a thread exists for (booking_id, worker_id, user_id) === */
if ($action === 'ensure_thread') {
  $bookingId = (int)($_GET['booking_id'] ?? 0);
  $workerId  = (int)($_GET['worker_id']  ?? 0);

  $acc = assert_access_for_booking($conn, $isWorker, $uid, $bookingId, $workerId);
  $userIdForThread = (int)$acc['user_id'];

  // Upsert thread
  $conn->begin_transaction();
  try{
    // Try find
    $st = $conn->prepare("SELECT id FROM chat_threads WHERE booking_id=? AND worker_id=? AND user_id=? LIMIT 1");
    $st->bind_param("iii",$bookingId,$workerId,$userIdForThread);
    $st->execute(); $res = $st->get_result(); $row = $res->fetch_assoc(); $st->close();

    if ($row) {
      $threadId = (int)$row['id'];
    } else {
      $st = $conn->prepare("INSERT INTO chat_threads (booking_id, worker_id, user_id) VALUES (?,?,?)");
      $st->bind_param("iii",$bookingId,$workerId,$userIdForThread);
      $st->execute(); $threadId = (int)$st->insert_id; $st->close();
    }
    $conn->commit();
  } catch(Throwable $e){
    $conn->rollback(); json_err('Unable to ensure thread',500);
  }

  json_ok(['thread_id'=>$threadId]);
}

/* === Fetch messages since an id === */
if ($action === 'fetch') {
  $threadId = (int)($_GET['thread_id'] ?? 0);
  $sinceId  = (int)($_GET['since_id'] ?? 0);

  if ($threadId<=0) json_err('Invalid thread',400);

  // Check membership of this thread
  $st = $conn->prepare("SELECT booking_id, worker_id, user_id FROM chat_threads WHERE id=? LIMIT 1");
  $st->bind_param("i",$threadId); $st->execute();
  $t = $st->get_result()->fetch_assoc(); $st->close();
  if (!$t) json_err('Thread not found',404);

  // Verify access by mapping thread → booking/worker, then reusing assert_access
  // We only need to ensure the session belongs to either that user (owner) or that worker (assigned).
  if ($isWorker) {
    // Find the worker_profiles.user_id for this worker_id (not strictly needed here; we rely on bookings assignment)
    $acc = assert_access_for_booking($conn, true, $uid, (int)$t['booking_id'], (int)$t['worker_id']);
  } else {
    // For user, ensure they own the booking in this thread
    $acc = assert_access_for_booking($conn, false, $uid, (int)$t['booking_id'], (int)$t['worker_id']);
  }

  // Fetch messages
  if ($sinceId > 0) {
    $st = $conn->prepare("SELECT id, sender, body, created_at FROM chat_messages WHERE thread_id=? AND id>? ORDER BY id ASC");
    $st->bind_param("ii",$threadId,$sinceId);
  } else {
    $st = $conn->prepare("SELECT id, sender, body, created_at FROM chat_messages WHERE thread_id=? ORDER BY id ASC");
    $st->bind_param("i",$threadId);
  }
  $st->execute(); $rs = $st->get_result();
  $out = [];
  while($m = $rs->fetch_assoc()){
    $sender = $m['sender']==='worker' ? 'worker' : 'user';
    $mine = ($isWorker ? ($sender==='worker') : ($sender==='user'));
    $out[] = [
      'id' => (int)$m['id'],
      'sender' => $mine ? 'me' : 'them',
      'body' => $m['body'],
      'created_at' => $m['created_at'],
    ];
  }
  $st->close();

  json_ok(['messages'=>$out]);
}

/* === Send a message === */
if ($action === 'send') {
  $threadId = (int)($_POST['thread_id'] ?? 0);
  $body     = trim((string)($_POST['body'] ?? ''));

  if ($threadId<=0) json_err('Invalid thread',400);
  if ($body==='')  json_err('Empty message',422);
  if (mb_strlen($body) > 1000) $body = mb_substr($body, 0, 1000);

  // Confirm thread & access
  $st = $conn->prepare("SELECT booking_id, worker_id, user_id FROM chat_threads WHERE id=? LIMIT 1");
  $st->bind_param("i",$threadId); $st->execute();
  $t = $st->get_result()->fetch_assoc(); $st->close();
  if (!$t) json_err('Thread not found',404);

  if ($isWorker) {
    assert_access_for_booking($conn, true, $uid, (int)$t['booking_id'], (int)$t['worker_id']);
    $sender = 'worker';
  } else {
    assert_access_for_booking($conn, false, $uid, (int)$t['booking_id'], (int)$t['worker_id']);
    $sender = 'user';
  }

  $st = $conn->prepare("INSERT INTO chat_messages (thread_id, sender, body) VALUES (?,?,?)");
  $st->bind_param("iss",$threadId,$sender,$body);
  $st->execute(); $msgId = (int)$st->insert_id; $st->close();

  json_ok(['id'=>$msgId]);
}

/* === Unknown === */
json_err('Unknown action',400);

