<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

$id = (int)($_POST['id'] ?? 0);
$action = strtolower($_POST['action'] ?? '');

$map = [
  'approve' => 'approved',
  'decline' => 'declined',
  'cancel'  => 'cancelled',
];

if ($id && isset($map[$action])) {
  $status = $map[$action];

  if ($action === 'approve' || $action === 'decline') {
    $stmt = $conn->prepare("UPDATE bookings SET status=? WHERE id=? AND LOWER(status)='pending'");
  } else {
    $stmt = $conn->prepare("UPDATE bookings SET status=? WHERE id=? AND LOWER(status) NOT IN ('cancelled','declined')");
  }
  $stmt->bind_param('si', $status, $id);
  $stmt->execute();

  $_SESSION['flash'] = "Booking #{$id} set to ".ucfirst($status).".";
}
header("Location: admin_bookings.php");
exit();
