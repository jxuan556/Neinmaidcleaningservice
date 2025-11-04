<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

$booking_id = (int)($_POST['booking_id'] ?? 0);
$worker_id  = (int)($_POST['worker_id'] ?? 0);

if ($booking_id && $worker_id) {
  // ensure worker is approved/enabled
  $w = $conn->prepare("SELECT id FROM worker_profiles WHERE id=? AND LOWER(approval_status)='approved'");
  $w->bind_param('i',$worker_id);
  $w->execute();
  if ($w->get_result()->num_rows) {

    // Auto-approve if still pending, then assign
    $stmt = $conn->prepare("
      UPDATE bookings
      SET status = CASE WHEN LOWER(status)='pending' THEN 'approved' ELSE status END
      WHERE id=?
    ");
    $stmt->bind_param('i',$booking_id);
    $stmt->execute();

    $stmt = $conn->prepare("
      UPDATE bookings
      SET assigned_worker_id=?, status='assigned'
      WHERE id=? AND LOWER(status) IN ('approved','assigned')
    ");
    $stmt->bind_param('ii', $worker_id, $booking_id);
    $stmt->execute();

    $_SESSION['flash'] = "Assigned cleaner to booking #{$booking_id}.";
  } else {
    $_SESSION['flash'] = "Cleaner not found or not approved.";
  }
}
header("Location: admin_bookings.php");
exit();
