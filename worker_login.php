<?php
// worker_login.php — NeinMaid (email / phone / account_no login for workers)
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* ------------ helpers ------------ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function looks_like_phone($s){ return (bool)preg_match('/^\+?\d[\d\s\-]{6,}$/', $s); }
function norm_phone($s){ return preg_replace('/[^0-9]/','', (string)$s); } // keep digits only
function row_or_null(mysqli_stmt $stmt){ $stmt->execute(); $r=$stmt->get_result(); $row=$r->fetch_assoc(); $stmt->close(); return $row ?: null; }

/**
 * Try to find worker by identifier in this order:
 * 1) email
 * 2) phone (digits-only match)
 * 3) account_no (if column exists)
 * 4) worker_code (if column exists)
 */
function find_worker(mysqli $conn, string $id): ?array {
  // by email
  if (filter_var($id, FILTER_VALIDATE_EMAIL)) {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE email=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  }

  // by phone (normalize)
  if (looks_like_phone($id)) {
    $digits = norm_phone($id);
    $s=$conn->prepare(
      "SELECT * FROM worker_profiles
       WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),'-',''),' ','') = ?
       LIMIT 1"
    );
    $s->bind_param("s",$digits);
    if ($row=row_or_null($s)) return $row;

    // Fallback: compare rightmost 9 digits (tolerate country code)
    $s=$conn->prepare(
      "SELECT * FROM worker_profiles
       WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,'+',''),'-',''),' ',''),
                   9) = RIGHT(?, 9) LIMIT 1"
    );
    $s->bind_param("s",$digits);
    if ($row=row_or_null($s)) return $row;
  }

  // by account_no (if exists)
  try {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE account_no=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  } catch (Throwable $e) { /* column missing, ignore */ }

  // by worker_code (if exists)
  try {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE worker_code=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  } catch (Throwable $e) { /* column missing, ignore */ }

  // last: email again if raw string (non-phone)
  if (!looks_like_phone($id) && !filter_var($id, FILTER_VALIDATE_EMAIL)) {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE email=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  }

  return null;
}

/* ------------ POST: login ------------ */
$msg = ""; $type = "";
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $identifier = trim($_POST['identifier'] ?? '');
  $passRaw    = (string)($_POST['password'] ?? '');

  if ($identifier === '' || $passRaw === '') {
    $msg="Please enter your account and password."; $type="error";
  } else {
    $worker = find_worker($conn, $identifier);

    if (!$worker) {
      $msg = "Account not found. Check email/phone/account no."; $type="error";
    } else {
      // Gate: approved + enabled (case/space tolerant)
      $approved = in_array(strtolower(trim((string)($worker['approval_status'] ?? ''))), ['approved','approve','active'], true);
      $enabled  = in_array(strtolower(trim((string)($worker['status'] ?? ''))),           ['enabled','enable','active'], true);

      if (!$approved || !$enabled) {
        $msg = "Your account is not active yet. (Approval/Status)"; $type="error";
      } else {
        $hash = (string)($worker['password'] ?? '');
        $ok = false;

        if ($hash) {
          // Normal path
          if (password_verify($passRaw, $hash)) $ok = true;

          // Migration helper: if stored as plain (NOT recommended), accept once and re-hash.
          $looksHashed = (strlen($hash) >= 50 && str_starts_with($hash, '$'));
          if (!$ok && !$looksHashed && hash_equals($hash, $passRaw)) {
            $new = password_hash($passRaw, PASSWORD_DEFAULT);
            $u=$conn->prepare("UPDATE worker_profiles SET password=? WHERE id=?");
            $uid=(int)$worker['id'];
            $u->bind_param("si",$new,$uid);
            $u->execute(); $u->close();
            $ok = true;
          }
        }

        if ($ok) {
          session_regenerate_id(true);
          $_SESSION['user_id']     = (int)$worker['id'];             // you may want a separate key like worker_id
          $_SESSION['worker_id']   = (int)$worker['id'];
          $_SESSION['email']       = $worker['email'] ?? '';
          $_SESSION['name']        = $worker['name']  ?? 'Worker';
          $_SESSION['role']        = 'worker';
          $_SESSION['is_admin']    = 0;

          header("Location: worker_dashboard.php"); // change to your worker home
          exit;
        } else {
          $msg="Incorrect password."; $type="error";
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Worker Login – NeinMaid</title>
  <link rel="stylesheet" href="user.css">
  <style>
    .auth-card{max-width:520px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
    .brand{display:flex;gap:8px;align-items:center}
    .brand img{width:28px;height:28px}
    .title{margin:6px 0 0}
    .sub{color:#6b7280;margin:4px 0 12px}
    .input{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:12px}
    .btn-cta{background:#b91c1c;border:0;color:#fff;border-radius:12px;padding:10px 14px;cursor:pointer}
    .row{margin:10px 0}
    .notification{border-radius:10px;padding:10px;margin-bottom:10px}
    .notification.success{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46}
    .notification.error{background:#fff6f6;border:1px solid #f1c0c0;color:#7f1d1d}
  </style>
</head>
<body>
  <div class="auth-card">
    <div class="brand"><img src="maid.png" alt="NeinMaid"><strong>NEINMAID</strong><span class="brand-badge">Worker</span></div>
    <h2 class="title">Worker Login</h2>
    <p class="sub">Use your <strong>email</strong>, <strong>phone</strong>, or <strong>account number</strong>.</p>

    <?php if($msg): ?>
      <div class="notification <?= h($type) ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="row">
        <input class="input" name="identifier" placeholder="Email / Phone / Account No" required>
      </div>
      <div class="row">
        <input class="input" type="password" name="password" placeholder="Password" required minlength="8">
      </div>
      <div class="row">
        <button class="btn-cta" type="submit">Sign in</button>
        <a class="btn-secondary" href="login.php" style="margin-left:10px">User login</a>
      </div>
    </form>
  </div>
</body>
</html>
