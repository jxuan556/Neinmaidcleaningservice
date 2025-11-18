<?php
// review_submit.php – handle customer rating + review for a completed booking

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

date_default_timezone_set('Asia/Kuala_Lumpur');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("localhost", "root", "", "maid_system");
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    die("Database error: " . $e->getMessage());
}

$userId    = (int)($_SESSION['user_id'] ?? 0);
$bookingId = (int)($_POST['booking_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$comment   = trim((string)($_POST['comments'] ?? ''));
$ref       = $_POST['ref'] ?? '';

if ($userId <= 0 || $bookingId <= 0 || $rating < 1 || $rating > 5) {
    header("Location: booking_success.php");
    exit();
}

// verify this booking belongs to this user and is completed
$st = $conn->prepare("
  SELECT b.id, b.user_id, b.assigned_worker_id
  FROM bookings b
  WHERE b.id = ? AND b.user_id = ? AND b.status IN ('completed','done','finished')
  LIMIT 1
");
$st->bind_param('ii', $bookingId, $userId);
$st->execute();
$bk = $st->get_result()->fetch_assoc();
$st->close();

if (!$bk) {
    // no permission / not completed
    header("Location: booking_success.php");
    exit();
}

$workerId = (int)($bk['assigned_worker_id'] ?? 0);

// insert or update rating
$st2 = $conn->prepare("
  INSERT INTO booking_ratings (booking_id, worker_id, stars, comment, created_at)
  VALUES (?,?,?,?, NOW())
  ON DUPLICATE KEY UPDATE
    worker_id = VALUES(worker_id),
    stars     = VALUES(stars),
    comment   = VALUES(comment),
    created_at = VALUES(created_at)
");
$st2->bind_param('iiis', $bookingId, $workerId, $rating, $comment);
$st2->execute();
$st2->close();

// (Optional) you could notify admin or worker here using notifications.php

header("Location: booking_success.php#sect-past");
exit();
