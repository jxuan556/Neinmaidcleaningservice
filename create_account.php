<?php
session_start();

// ===== DB CONNECTION =====
$host = "localhost";
$user = "root";
$pass = "";
$db   = "maid_system";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$message = "";
$messageType = "error"; // default

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name             = trim($_POST['name']);
    $email            = trim($_POST['email']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $mobile           = trim($_POST['mobile']);

    if ($password !== $confirm_password) {
        $message = "❌ Passwords do not match!";
    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        $message = "❌ Only Gmail addresses are allowed!";
    } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$/", $password)) {
        $message = "❌ Password does not meet complexity requirements.";
    } else {
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        $checkEmail->store_result();

        if ($checkEmail->num_rows > 0) {
            $message = "❌ Email is already registered!";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $role = "customer";
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, mobile, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $email, $hashed, $mobile, $role);
            if ($stmt->execute()) {
                $_SESSION['success'] = "✅ Account created successfully! Please login.";
                header("Location: login.php");
                exit;
            } else {
                $message = "❌ Something went wrong. Try again.";
            }
            $stmt->close();
        }
        $checkEmail->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Account – NeinMaid</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{
      /* Palette: bright minimal with soft pink accent */
      --bg-grad: linear-gradient(180deg,#ffffff 0%, #fff5fa 55%, #fde7f1 100%);
      --card: #ffffff;
      --ink: #111827;
      --muted:#6b7280;
      --line:#e5e7eb;

      --pink-acc:#ec4899;   /* accent */
      --pink-acc-2:#db2777; /* gradient tail */

      --shadow: 0 10px 30px rgba(0,0,0,.08);
      --radius: 18px;
      --radius-sm: 12px;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      display:flex;align-items:center;justify-content:center;
      background:var(--bg-grad);
      font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      color:var(--ink);
      -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
    }

    /* Shell */
    .auth{
      width:min(460px,94%);
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:34px 28px;
    }
    .brand{
      display:flex;align-items:center;gap:10px;
      font-weight:800;letter-spacing:.08em;text-transform:uppercase;
      color:var(--pink-acc);
      font-size:13px;
    }
    .brand img{width:28px;height:28px}
    .title{margin:10px 0 4px;font-weight:800;font-size:28px;color:var(--ink)}
    .sub{margin:0 0 18px;color:var(--muted)}

    /* Form */
    .field{margin-bottom:14px;position:relative}
    .input{
      width:100%;padding:12px 12px;
      border:1px solid var(--line);border-radius:12px;
      background:#fafafa;color:var(--ink);font-size:15px;
      transition:.2s;
    }
    .input:focus{outline:none;border-color:var(--pink-acc);background:#fff}
    .toggle{
      position:absolute;right:10px;top:50%;transform:translateY(-50%);
      border:0;background:none;cursor:pointer;color:#9ca3af;font-size:15px;
    }

    .btn{
      width:100%;border:0;border-radius:12px;cursor:pointer;
      padding:12px 14px;font-weight:800;font-size:15px;color:#fff;
      background:linear-gradient(90deg,var(--pink-acc),var(--pink-acc-2));
      transition:opacity .2s, transform .08s;
    }
    .btn:hover{opacity:.95}
    .btn:active{transform:translateY(1px)}

    /* Messages */
    .note{
      border-radius:12px;padding:10px 12px;margin:6px 0 14px;font-size:14px;
      border:1px solid #fca5a5;color:#991b1b;background:#fff1f2;
    }
    .note.success{border-color:#6ee7b7;color:#065f46;background:#ecfdf5}

    /* Helpers */
    .row-between{display:flex;justify-content:space-between;align-items:center;gap:10px}
    .links{margin-top:12px;text-align:center;font-size:14px;color:var(--muted)}
    .links a{color:var(--pink-acc);text-decoration:none}
    .links a:hover{text-decoration:underline}
    .hint{font-size:13px;color:#9ca3af;margin-top:6px}
    .hint.ok{color:#15803d}
    .hint.bad{color:#b91c1c}
  </style>
</head>
<body>
  <main class="auth">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      NEINMAID
    </div>
    <h1 class="title">Create Account</h1>
    <p class="sub">Join our trusted cleaning network.</p>

    <?php if (!empty($message)): ?>
      <div class="note <?php echo ($messageType==='success'?'success':''); ?>">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="field">
        <input class="input" type="text" name="name" placeholder="Full Name" required>
      </div>

      <div class="field">
        <input class="input" type="email" name="email" placeholder="Email (Gmail only)" required
               pattern="[a-zA-Z0-9._%+-]+@gmail\.com$"
               title="Only Gmail addresses are allowed">
      </div>

      <div class="field">
        <input class="input" type="password" id="password" name="password" placeholder="Password" required
               minlength="8"
               pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$"
               title="At least 8 chars with uppercase, lowercase, number, and symbol.">
        <button class="toggle" type="button" onclick="toggle('password')">👁</button>
        <div id="strengthMessage" class="hint"></div>
      </div>

      <div class="field">
        <input class="input" type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
        <button class="toggle" type="button" onclick="toggle('confirm_password')">👁</button>
        <div id="matchMessage" class="hint"></div>
      </div>

      <div class="field">
        <input class="input" type="text" name="mobile" placeholder="Mobile Number" required>
      </div>

      <button class="btn" type="submit">Sign Up</button>
    </form>

    <div class="links">
      Already have an account? <a href="login.php">Sign in</a>
    </div>
  </main>

  <script>
    function toggle(id){
      const el=document.getElementById(id);
      el.type = el.type==='password' ? 'text' : 'password';
    }

    // Strength + Match checker
    (function(){
      const password=document.getElementById("password");
      const confirm=document.getElementById("confirm_password");
      const strength=document.getElementById("strengthMessage");
      const match=document.getElementById("matchMessage");

      password.addEventListener("input",()=>{
        const v=password.value;
        let label="", cls="";
        if(!v){ label=""; cls=""; }
        else if(v.length>=8 && /[A-Z]/.test(v)&&/[a-z]/.test(v)&&/\d/.test(v)&&/[@$!%*?&_]/.test(v)){
          label="Password Strength: Strong"; cls="ok";
        } else if(v.length>=6){
          label="Password Strength: Medium"; cls="";
        } else {
          label="Password Strength: Weak"; cls="bad";
        }
        strength.textContent = label;
        strength.className = "hint "+cls;
        if(confirm.value) check();
      });

      confirm.addEventListener("input", check);

      function check(){
        if(!confirm.value){
          match.textContent=""; match.className="hint"; return;
        }
        if(confirm.value===password.value){
          match.textContent="✅ Passwords match"; match.className="hint ok";
        }else{
          match.textContent="❌ Passwords do not match"; match.className="hint bad";
        }
      }
    })();
  </script>
</body>
</html>
