<?php
// admin_announce_delete.php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Be explicit about error logging so you can see real causes in php_error.log
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
  // ✅ Accept either role or is_admin flag
  $isAdmin = (!empty($_SESSION['is_admin'])) || (($_SESSION['role'] ?? '') === 'admin');
  if (empty($_SESSION['user_id']) || !$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized (admin required)']);
    exit;
  }

  require_once __DIR__ . '/announcements_store.php';

  // ✅ Accept JSON or FormData
  $id = 0;
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($j['id'])) {
      $id = (int)$j['id'];
    }
  } else {
    $id = (int)($_POST['id'] ?? 0);
    // Also support querystring fallback: admin_announce_delete.php?id=123
    if ($id <= 0 && isset($_GET['id'])) $id = (int)$_GET['id'];
  }

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'Invalid announcement id']); exit;
  }

  // ✅ Delete
  if (!function_exists('ann_delete')) {
    throw new RuntimeException('announcements_store.php not loaded or ann_delete() missing');
  }

  $ok = ann_delete($id);
  if (!$ok) {
    // No row deleted (maybe already removed or wrong table)
    http_response_code(404);
    echo json_encode(['ok'=>false,'msg'=>'Not found or already deleted']);
    exit;
  }

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  // Log the exact cause so you can see it in the PHP error log
  error_log('[admin_announce_delete] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server error: '.$e->getMessage()]);
}

