<?php
// chat_api.php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

header('Content-Type: application/json; charset=utf-8');

function jerr($msg,$code=400){ http_response_code($code); echo json_encode(['ok'=>false,'msg'=>$msg]); exit; }
function ok($data=[]){ echo json_encode(['ok'=>true]+$data); exit; }
function h($s){ return trim((string)$s); }

$isAdmin = !empty($_SESSION['is_admin']);
$userId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$act = $_GET['action'] ?? $_POST['action'] ?? '';

/* Ensure thread belongs to current user (if user) or just exists (if admin) */
function get_thread(mysqli $c, int $tid, bool $admin, int $uid){
  $st = $c->prepare("SELECT * FROM support_threads WHERE id=? LIMIT 1");
  $st->bind_param("i",$tid); $st->execute();
  $t = $st->get_result()->fetch_assoc(); $st->close();
  if(!$t) return null;
  if(!$admin){
    if((int)$t['user_id'] !== $uid) return null;
  }
  return $t;
}

/* Create or fetch user thread */
if ($act === 'ensure_thread') {
  if (!$userId) jerr('Not logged in', 401);
  $st = $conn->prepare("SELECT id FROM support_threads WHERE user_id=? ORDER BY id DESC LIMIT 1");
  $st->bind_param("i",$userId); $st->execute();
  $row = $st->get_result()->fetch_assoc(); $st->close();
  if ($row) ok(['thread_id'=>(int)$row['id']]);

  // create new with session name/email if present
  $name = $_SESSION['name'] ?? 'User';
  $email = $_SESSION['email'] ?? null;

  $st = $conn->prepare("INSERT INTO support_threads(user_id,name,email) VALUES(?,?,?)");
  $st->bind_param("iss",$userId,$name,$email); $st->execute();
  $tid = $st->insert_id; $st->close();
  ok(['thread_id'=>$tid]);
}

/* Admin: list threads */
if ($act === 'admin_list') {
  if (!$isAdmin) jerr('Forbidden', 403);
  $status = ($_GET['status'] ?? 'open') === 'closed' ? 'closed' : 'open';
  $q = trim($_GET['q'] ?? '');
  $where = "status=?";
  $types = "s";
  $params = [$status];
  if ($q !== '') {
    $where .= " AND (name LIKE ? OR email LIKE ?)";
    $types .= "ss";
    $like = "%$q%";
    $params[]=$like; $params[]=$like;
  }
  $sql="SELECT id,user_id,name,email,status,last_at,created_at FROM support_threads WHERE $where ORDER BY last_at DESC LIMIT 100";
  $st=$conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
  $res=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  ok(['threads'=>$res]);
}

/* Fetch messages (both roles) */
if ($act === 'fetch') {
  $tid = (int)($_GET['thread_id'] ?? 0);
  if (!$tid) jerr('thread_id required');
  $t = get_thread($conn, $tid, $isAdmin, $userId);
  if (!$t) jerr('Thread not found', 404);

  $sinceId = (int)($_GET['since_id'] ?? 0);
  if ($sinceId>0) {
    $st=$conn->prepare("SELECT * FROM support_messages WHERE thread_id=? AND id>? ORDER BY id ASC");
    $st->bind_param("ii",$tid,$sinceId);
  } else {
    $st=$conn->prepare("SELECT * FROM support_messages WHERE thread_id=? ORDER BY id ASC LIMIT 200");
    $st->bind_param("i",$tid);
  }
  $st->execute();
  $msgs=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  // read receipts
  if ($isAdmin) {
    $up=$conn->prepare("UPDATE support_messages SET is_read_admin=1 WHERE thread_id=?");
    $up->bind_param("i",$tid); $up->execute(); $up->close();
  } else {
    $up=$conn->prepare("UPDATE support_messages SET is_read_user=1 WHERE thread_id=?");
    $up->bind_param("i",$tid); $up->execute(); $up->close();
  }

  ok(['messages'=>$msgs]);
}

/* Send message (both roles) */
if ($act === 'send') {
  $tid = (int)($_POST['thread_id'] ?? 0);
  $body = h($_POST['body'] ?? '');
  if (!$tid || $body==='') jerr('thread_id and body required');

  $t = get_thread($conn, $tid, $isAdmin, $userId);
  if (!$t) jerr('Thread not found', 404);
  if ($t['status']==='closed' && !$isAdmin) jerr('Thread closed');

  $sender = $isAdmin ? 'admin' : 'user';
  $sid = $isAdmin ? (int)$userId : ($userId ?: null);

  $st=$conn->prepare("INSERT INTO support_messages(thread_id,sender,sender_id,body,is_read_admin,is_read_user) VALUES(?,?,?,?,?,?)");
  $ira = $isAdmin ? 1 : 0; // admin sees their own as read
  $iru = $isAdmin ? 0 : 1; // user sees their own as read
  $st->bind_param("isissi",$tid,$sender,$sid,$body,$ira,$iru);
  $st->execute(); $mid=$st->insert_id; $st->close();

  $up=$conn->prepare("UPDATE support_threads SET last_at=NOW(), status='open' WHERE id=?");
  $up->bind_param("i",$tid); $up->execute(); $up->close();

  ok(['message_id'=>$mid]);
}

/* Admin: open/close thread */
if ($act === 'set_status') {
  if (!$isAdmin) jerr('Forbidden', 403);
  $tid=(int)($_POST['thread_id'] ?? 0);
  $status = ($_POST['status'] ?? '')==='closed' ? 'closed' : 'open';
  if(!$tid) jerr('thread_id required');
  $t = get_thread($conn,$tid,true,0); if(!$t) jerr('Thread not found',404);
  $st=$conn->prepare("UPDATE support_threads SET status=?, last_at=NOW() WHERE id=?");
  $st->bind_param("si",$status,$tid); $st->execute(); $st->close();
  ok();
}

jerr('Unknown action', 404);
