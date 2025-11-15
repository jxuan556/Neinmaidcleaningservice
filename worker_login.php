<?php
// worker_login.php — NeinMaid (same UI as user login, but for workers; supports email/phone/account_no/worker_code)
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* ------------ helpers ------------ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function looks_like_phone($s){ return (bool)preg_match('/^\+?\d[\d\s\-]{6,}$/', (string)$s); }
function norm_phone($s){ return preg_replace('/[^0-9]/','', (string)$s); }
function row_or_null(mysqli_stmt $stmt){ $stmt->execute(); $r=$stmt->get_result(); $row=$r->fetch_assoc(); $stmt->close(); return $row ?: null; }

/** ordered identifier lookup for worker_profiles */
function find_worker(mysqli $conn, string $id): ?array {
  // email
  if (filter_var($id, FILTER_VALIDATE_EMAIL)) {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE email=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  }
  // phone (normalized)
  if (looks_like_phone($id)) {
    $digits = norm_phone($id);
    $s=$conn->prepare(
      "SELECT * FROM worker_profiles
       WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),'-',''),' ','') = ?
       LIMIT 1"
    );
    $s->bind_param("s",$digits);
    if ($row=row_or_null($s)) return $row;

    // tolerate country code: compare rightmost 9 digits
    $s=$conn->prepare(
      "SELECT * FROM worker_profiles
       WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,'+',''),'-',''),' ',''),9) = RIGHT(?,9)
       LIMIT 1"
    );
    $s->bind_param("s",$digits);
    if ($row=row_or_null($s)) return $row;
  }
  // account_no (if exists)
  try {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE account_no=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  } catch(Throwable $e) {}
  // worker_code (if exists)
  try {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE worker_code=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  } catch(Throwable $e) {}

  // final attempt: raw string as email
  if (!looks_like_phone($id) && !filter_var($id, FILTER_VALIDATE_EMAIL)) {
    $s=$conn->prepare("SELECT * FROM worker_profiles WHERE email=? LIMIT 1");
    $s->bind_param("s",$id);
    if ($row=row_or_null($s)) return $row;
  }
  return null;
}

/* ------------ POST: login ------------ */
$message=""; $messageType="";
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $identifier = trim($_POST['identifier'] ?? '');
  $passRaw    = (string)($_POST['password'] ?? '');

  if ($identifier === '' || $passRaw === '') {
    $message="❌ Please enter your account and password."; $messageType="error";
  } else {
    $worker = find_worker($conn, $identifier);
    if (!$worker) {
      $message="❌ Account not found. Check email/phone/account no/worker code."; $messageType="error";
    } else {
      // gate: approval + enabled
      $approved = in_array(strtolower(trim((string)($worker['approval_status'] ?? ''))), ['approved','approve','active'], true);
      $enabled  = in_array(strtolower(trim((string)($worker['status'] ?? ''))),           ['enabled','enable','active'], true);

      if (!$approved || !$enabled) {
        $message="❌ Your account is not active yet (approval/status)."; $messageType="error";
      } else {
        $hash = (string)($worker['password'] ?? '');
        $ok = false;
        if ($hash) {
          if (password_verify($passRaw, $hash)) $ok = true;

          // migration: if stored as plain, accept once and re-hash
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
          $_SESSION['user_id']   = (int)$worker['id'];   // keep same key structure
          $_SESSION['worker_id'] = (int)$worker['id'];
          $_SESSION['email']     = $worker['email'] ?? '';
          $_SESSION['name']      = $worker['name']  ?? 'Worker';
          $_SESSION['role']      = 'worker';
          $_SESSION['is_admin']  = 0;

          $message="✅ Login successful! Redirecting…";
          $messageType="success";
          header("refresh:1.2; url=worker_dashboard.php");
        } else {
          $message="❌ Incorrect password."; $messageType="error";
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
<title>NeinMaid – Worker Login</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --pink-soft:#fce7f3;
    --pink-accent:#ec4899;
    --bg-gradient: linear-gradient(180deg,#fff 0%,#fdf2f8 50%,#fce7f3 100%);
    --card-bg:#ffffff;
    --border:#e5e7eb;
    --text:#111827;
    --text-muted:#6b7280;
    --shadow:0 8px 28px rgba(0,0,0,.08);
  }
  *{box-sizing:border-box}
  body{
    margin:0;height:100vh;display:flex;align-items:center;justify-content:center;
    background:var(--bg-gradient);
    font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    color:var(--text);
  }
  .auth{
    width:min(440px,95%);
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:18px;
    padding:36px 30px;
    box-shadow:var(--shadow);
  }
  .brand{
    display:flex;align-items:center;gap:10px;
    font-weight:800;letter-spacing:.1em;
    color:var(--pink-accent);text-transform:uppercase;
    font-size:14px;
  }
  .badge{
    margin-left:6px;padding:2px 8px;border-radius:999px;
    background:#ffe4ef;border:1px solid #fecdd3;color:#b91c1c;font-size:12px
  }
  .title{font-size:28px;font-weight:800;margin:12px 0 4px;color:var(--text);}
  .sub{margin:0 0 18px;color:var(--text-muted);}
  .input{
    width:100%;padding:10px 12px;border:1px solid var(--border);
    border-radius:10px;font-size:15px;color:var(--text);
    background:#fafafa;transition:.2s;
  }
  .input:focus{outline:none;border-color:var(--pink-accent);background:#fff;}
  .toggle-eye{
    position:absolute;right:12px;top:50%;transform:translateY(-50%);
    background:none;border:0;cursor:pointer;color:#9ca3af;
  }
  .field{position:relative;margin-bottom:14px;}
  .btn{
    width:100%;padding:12px;border-radius:10px;border:0;
    background:linear-gradient(90deg,#ec4899,#db2777);
    color:white;font-weight:700;font-size:15px;cursor:pointer;
    transition:opacity .2s,transform .1s;
  }
  .btn:hover{opacity:.9;}
  .btn:active{transform:translateY(1px);}
  .note{border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:14px;}
  .note.success{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;}
  .note.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}
  .meta{
    display:flex;justify-content:space-between;align-items:center;
    font-size:14px;color:var(--text-muted);margin:6px 0 14px;
  }
  .meta a{color:var(--pink-accent);text-decoration:none;}
  .meta a:hover{text-decoration:underline;}
  .alt{margin-top:12px;text-align:center;font-size:14px;color:var(--text-muted);}
  .alt a{color:var(--pink-accent);text-decoration:none;}
  .alt a:hover{text-decoration:underline;}
  .strength{font-size:13px;margin-top:6px;color:#9ca3af}
  .strength.strong{color:#15803d;}
  .strength.medium{color:#ca8a04;}
</style>
</head>
<body>
  <main class="auth">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid" style="width:28px;height:28px"> NEINMAID
      <span class="badge">Worker</span>
    </div>
    <h1 class="title">Worker Login</h1>
    <p class="sub">Use your <strong>email</strong></p>

    <?php if (!empty($message)): ?>
      <div class="note <?= h($messageType) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="field">
        <input class="input" name="identifier" placeholder="Email" required>
      </div>
      <div class="field">
        <input class="input" type="password" id="password" name="password"
               placeholder="Password" required minlength="8"
               pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$"
               title="Password must be at least 8 characters, include uppercase, lowercase, number, and symbol.">
        <button class="toggle-eye" type="button" onclick="togglePassword()">👁</button>
        <div id="strengthMessage" class="strength"></div>
      </div>

      <div class="meta">
        <label><input type="checkbox" name="remember"> Remember me</label>
        <a href="forgot_password.php">Forgot password?</a>
      </div>

      <button class="btn" type="submit">Sign in</button>
    </form>

    <div class="alt">
      Not a worker?
      <a href="login.php">Go to user login</a>
      <span style="margin:0 6px;">|</span>
      <a href="create_worker_account.php">Register as worker</a>
    </div>
  </main>

  <script>
    function togglePassword(){
      const p=document.getElementById('password');
      p.type = p.type==='password'?'text':'password';
    }
    const pwd=document.getElementById("password");
    const msg=document.getElementById("strengthMessage");
    pwd.addEventListener("input",()=>{
      const v=pwd.value;
      let s="Weak",c="";
      if(v.length>=8 && /[A-Z]/.test(v)&&/[a-z]/.test(v)&&/\d/.test(v)&&/[@$!%*?&_]/.test(v)){
        s="Strong";c="strong";
      }else if(v.length>=6){s="Medium";c="medium";}
      msg.textContent=v.length?"Password Strength: "+s:"";
      msg.className="strength "+c;
    });
  </script>
</body>
</html>

