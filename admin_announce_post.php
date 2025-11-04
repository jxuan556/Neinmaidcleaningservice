<?php
// admin_announce_post.php
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
  if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
  }

  require_once __DIR__ . '/announcements_store.php';

  // Accept JSON or regular POST
  $title = '';
  $body  = '';

  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      $title = trim((string)($j['title'] ?? ''));
      $body  = trim((string)($j['body'] ?? ''));
    }
  } else {
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
  }

  if ($title === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'Title and message are required']); exit;
  }

  $author = $_SESSION['name'] ?? 'Admin';
  $ok = ann_create($title, $body, $author);

  if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>'Create failed']); exit;
  }

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server error: '.$e->getMessage()]);
}

