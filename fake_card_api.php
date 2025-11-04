<?php
// fake_card_api.php
header('Content-Type: application/json');

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($auth, 'Bearer ') !== 0) {
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Missing auth']); exit;
}
$key = substr($auth, 7);
if ($key !== 'sk_test_fakeapikey') { // match your confirm_booking.php constant
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Invalid key']); exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$amount   = isset($data['amount']) ? (float)$data['amount'] : 0.00;
$cardNum  = preg_replace('/\D/','',$data['card_number'] ?? '');
$exp      = $data['card_exp'] ?? '';
$cvc      = $data['card_cvc'] ?? '';
$ref      = $data['reference'] ?? 'ref_'.bin2hex(random_bytes(4));

/**
 * Demo rules:
 * - Card starting with 4242 => always success
 * - Card starting with 4000 => decline
 * - Anything else => success if amount <= 999.99, else decline
 */
if (preg_match('/^4242/',$cardNum)) {
  echo json_encode(['success'=>true,'id'=>'txn_'.bin2hex(random_bytes(3)),'message'=>'ok','ref'=>$ref]); exit;
}
if (preg_match('/^4000/',$cardNum)) {
  http_response_code(402);
  echo json_encode(['success'=>false,'message'=>'Card declined (test).']); exit;
}
if ($amount > 999.99) {
  http_response_code(402);
  echo json_encode(['success'=>false,'message'=>'Amount exceeds limit (test).']); exit;
}
echo json_encode(['success'=>true,'id'=>'txn_'.bin2hex(random_bytes(3)),'message'=>'ok','ref'=>$ref]);