<?php
// auth_guard.php (snippet)
session_start();

function is_ajax_request(): bool {
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') return true;
  if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'],'application/json')!==false) return true;
  return false;
}

if (empty($_SESSION['user_id'])) {
  if (is_ajax_request()) {
    header('Content-Type: application/json; charset=utf-8', true, 401);
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
  }
  header('Location: login.php'); exit;
}


