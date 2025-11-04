<?php
session_start();

/* ===== DB CONNECTION ===== */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "maid_system";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

/* ===== FLASH MESSAGE ===== */
$message = "";
$messageType = "";
if (!empty($_SESSION['success'])) {
  $message = $_SESSION['success'];
  $messageType = "success";
  unset($_SESSION['success']);
}

/* ===== HANDLE LOGIN ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $email   = trim($_POST['email']);
  $passRaw = $_POST['password'];

  $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res && $res->num_rows === 1) {
    $user = $res->fetch_assoc();

    if (password_verify($passRaw, $user['password'])) {
      session_regenerate_id(true);

      $_SESSION['user_id'] = $user['id'];
      $_SESSION['email']   = $user['email'];
      $_SESSION['name']    = $user['name'];
      $_SESSION['role']    = $user['role'];
      $_SESSION['is_admin'] = ($user['role'] === 'admin') ? 1 : 0;

      if ($user['role'] !== 'admin') $_SESSION['show_promo_modal'] = true;

      $message = "✅ Login successful! Redirecting...";
      $messageType = "success";

      if ($user['role'] === "admin") {
        header("refresh:1.2; url=admin_dashboard.php");
      } else {
        header("refresh:1.2; url=user_dashboard.php?promo=1");
      }
    } else {
      $message = "❌ Wrong password!";
      $messageType = "error";
    }
  } else {
    $message = "❌ No account found!";
    $messageType = "error";
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>NeinMaid – Login</title>
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
    margin:0;
    height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
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
    width:100%;padding:12px;border-radius:10px;
    border:0;
    background:linear-gradient(90deg,#ec4899,#db2777);
    color:white;
    font-weight:700;font-size:15px;cursor:pointer;
    transition:opacity .2s,transform .1s;
  }
  .btn:hover{opacity:.9;}
  .btn:active{transform:translateY(1px);}
  .note{
    border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:14px;
  }
  .note.success{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;}
  .note.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}
  .meta{
    display:flex;justify-content:space-between;align-items:center;
    font-size:14px;color:var(--text-muted);margin:6px 0 14px;
  }
  .meta a{color:var(--pink-accent);text-decoration:none;}
  .meta a:hover{text-decoration:underline;}
  .alt{
    margin-top:12px;text-align:center;font-size:14px;color:var(--text-muted);
  }
  .alt a{color:var(--pink-accent);text-decoration:none;}
  .alt a:hover{text-decoration:underline;}
  .strength{font-size:13px;margin-top:6px;color:#9ca3af}
  .strength.strong{color:#15803d;}
  .strength.medium{color:#ca8a04;}
</style>
</head>
<body>
  <main class="auth">
    <div class="brand"><img src="maid.png" alt="NeinMaid" style="width:28px;height:28px"> NEINMAID</div>
    <h1 class="title">Login</h1>
    <p class="sub">Welcome back! Sign in to continue.</p>

    <?php if (!empty($message)): ?>
      <div class="note <?= htmlspecialchars($messageType) ?>">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="field">
        <input class="input" type="email" name="email" placeholder="Email" required>
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
      Don’t have an account?
      <a href="create_account.php">Register for free</a>
      <span style="margin:0 6px;">|</span>
      <a href="create_worker_account.php">Register as worker</a>
      <div style="margin-top:8px">
        Already a worker? <a href="worker_login.php">Login here</a>
      </div>
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
