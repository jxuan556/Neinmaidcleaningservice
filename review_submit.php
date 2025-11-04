<?php
/**
 * review_submit.php — saves/updates a review in booking_ratings.
 * Works when booking_ratings has NO user_id column.
 */
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

$userId    = (int)($_SESSION['user_id'] ?? 0);
$bookingId = (int)($_POST['booking_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$comments  = trim((string)($_POST['comments'] ?? ''));
$ref       = (string)($_POST['ref'] ?? '');

if ($userId<=0 || $bookingId<=0 || $rating<1 || $rating>5) {
  header("Location: booking_success.php?ref=".urlencode($ref)); exit();
}

/* Verify the booking belongs to this user, and obtain worker_id (if any) */
$stmt = $conn->prepare("SELECT user_id, assigned_worker_id FROM bookings WHERE id=? LIMIT 1");
$stmt->bind_param("i", $bookingId);
$stmt->execute();
$bk = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bk || (int)$bk['user_id'] !== $userId) {
  header("Location: booking_success.php?ref=".urlencode($ref)); exit();
}
$workerId = $bk['assigned_worker_id'] !== null ? (int)$bk['assigned_worker_id'] : null;

/* Upsert by booking_id (since booking_ratings has no user_id column) */
$stmt = $conn->prepare("SELECT id FROM booking_ratings WHERE booking_id=? LIMIT 1");
$stmt->bind_param("i", $bookingId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
  $stmt = $conn->prepare("UPDATE booking_ratings SET stars=?, comment=?, created_at=NOW() WHERE id=?");
  $stmt->bind_param("isi", $rating, $comments, $existing['id']);
  $stmt->execute();
  $stmt->close();
} else {
  if ($workerId === null) {
    $stmt = $conn->prepare("
      INSERT INTO booking_ratings (booking_id, worker_id, stars, comment, created_at)
      VALUES (?, NULL, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $bookingId, $rating, $comments);
  } else {
    $stmt = $conn->prepare("
      INSERT INTO booking_ratings (booking_id, worker_id, stars, comment, created_at)
      VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iiis", $bookingId, $workerId, $rating, $comments);
  }
  $stmt->execute();
  $stmt->close();
}

/* Back to the list, scroll to the card */
header("Location: booking_success.php?ref=".urlencode($ref)."#bk-".urlencode($ref));
exit;
