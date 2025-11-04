<?php
// booking_status.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "maid_system";

$conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db']); exit;
}
$conn->set_charset('utf8mb4');

$userId = (int)$_SESSION['user_id'];
$ref    = isset($_GET['ref']) ? trim($_GET['ref']) : '';
$id     = isset($_GET['id'])  ? (int)$_GET['id'] : 0;

if ($ref === '' && $id === 0) {
  echo json_encode(['ok'=>false,'error'=>'missing id/ref']); exit;
}

if ($ref !== '') {
  $stmt = $conn->prepare("SELECT b.*, w.name AS worker_name
                          FROM bookings b
                          LEFT JOIN worker_profiles w ON w.id = b.assigned_worker_id
                          WHERE b.ref_code = ? AND b.user_id = ?
                          LIMIT 1");
  $stmt->bind_param("si", $ref, $userId);
} else {
  $stmt = $conn->prepare("SELECT b.*, w.name AS worker_name
                          FROM bookings b
                          LEFT JOIN worker_profiles w ON w.id = b.assigned_worker_id
                          WHERE b.id = ? AND b.user_id = ?
                          LIMIT 1");
  $stmt->bind_param("ii", $id, $userId);
}
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
  echo json_encode(['ok'=>false,'error'=>'not_found']); exit;
}

echo json_encode([
  'ok' => true,
  'booking' => [
    'id' => (int)$row['id'],
    'ref_code' => $row['ref_code'],
    'status' => $row['status'],
    'assigned_worker_id' => $row['assigned_worker_id'] ? (int)$row['assigned_worker_id'] : null,
    'assigned_worker_name' => $row['worker_name'] ?? null,
    'assigned_at' => $row['assigned_at'] ?? null,
    'updated_at' => $row['created_at'] ?? null, // replace with real updated_at if you have it
  ],
]);
