<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "maid_system");

$id = (int)$_GET['id'];
$action = $_GET['action'] ?? '';

if ($id && in_array($action, ['approve','decline'])) {
  $status = ($action === 'approve') ? 'approved' : 'declined';
  $stmt = $conn->prepare("UPDATE bookings SET status=? WHERE id=?");
  $stmt->bind_param("si", $status, $id);
  $stmt->execute();
}

header("Location: admin_bookings.php");
exit();
