<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'msg'=>'Not authenticated']); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";

try {
  $conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
  $conn->set_charset('utf8mb4');

  // Basic validation
  $uid   = (int)$_SESSION['user_id'];
  $label = trim($_POST['label'] ?? '');
  $text  = trim($_POST['address_text'] ?? '');
  $type  = trim($_POST['home_type'] ?? '');
  $sqft  = $_POST['home_sqft'] !== '' ? (int)$_POST['home_sqft'] : null;

  if ($label === '' || $text === '' || $type === '') {
    echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit;
  }

  $stmt = $conn->prepare(
    "INSERT INTO user_addresses (user_id,label,address_text,home_type,home_sqft,is_default)
     VALUES (?,?,?,?,?,0)"
  );
  $stmt->bind_param("isssi", $uid, $label, $text, $type, $sqft);
  $stmt->execute();
  $newId = $stmt->insert_id;
  $stmt->close();

  echo json_encode([
    'ok'=>true,
    'data'=>[
      'id'=>$newId,
      'label'=>$label,
      'address_text'=>$text,
      'home_type'=>$type,
      'home_sqft'=>$sqft
    ]
  ]);
} catch (Throwable $e) {
  // Never echo raw HTML or stack traces — keep it JSON
  echo json_encode(['ok'=>false,'msg'=>'Server error: '.$e->getMessage()]);
}
