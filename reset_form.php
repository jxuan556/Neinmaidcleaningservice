<?php
// reset_form.php – verify token + let user set a new password
session_start();
require __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "maid_system";

try {
    $conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    die("Database connection failed.");
}

/* helper: column exists */
function col_exists(mysqli $conn, string $table, string $col): bool {
    $table = preg_replace('/[^A-Za-z0-9_]/','',$table);
    $dbRow = $conn->query("SELECT DATABASE()")->fetch_row();
    $db = $conn->real_escape_string($dbRow[0] ?? '');
    $t  = $conn->real_escape_string($table);
    $c  = $conn->real_escape_string($col);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}'
            LIMIT 1";
    $rs = $conn->query($sql);
    return ($rs && $rs->num_rows>0);
}

/* ensure reset columns exist (just in case) */
try{
    if (!col_exists($conn,'users','reset_token')) {
        $conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL");
    }
    if (!col_exists($conn,'users','reset_expires')) {
        $conn->query("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL");
    }
} catch (Throwable $e) {}

/* --- read token & email from URL --- */
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

$token = trim($token);
$email = trim($email);

$invalidLink = false;
$user = null;

if ($token === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $invalidLink = true;
} else {
    $stmt = $conn->prepare("SELECT id, name, email, reset_token, reset_expires FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s",$email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !$user['reset_token'] || !$user['reset_expires']) {
        $invalidLink = true;
    } else {
        // check expiry
        if (strtotime($user['reset_expires']) < time()) {
            $invalidLink = true;
        } else {
            // verify token
            if (!password_verify($token, $user['reset_token'])) {
                $invalidLink = true;
            }
        }
    }
}

/* --- handle POST (change password) --- */
$changeSuccess = false;
$errorMsg = '';

if (!$invalidLink && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if (strlen($pass1) < 6) {
        $errorMsg = 'Password should be at least 6 characters.';
    } elseif ($pass1 !== $pass2) {
        $errorMsg = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass1, PASSWORD_DEFAULT);

        $upd = $conn->prepare("
          UPDATE users
          SET password = ?, reset_token = NULL, reset_expires = NULL
          WHERE id = ?
        ");
        $upd->bind_param("si", $hash, $user['id']);
        $upd->execute();
        $upd->close();

        $changeSuccess = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg-grad: linear-gradient(180deg,#ffffff 0%, #fafafa 40%, #fff4f9 100%);
      --ink:#0f172a; --muted:#6b7280; --line:#e5e7eb; --card:#ffffff;
      --pink:#ec4899; --pink-2:#db2777;
      --ok:#065f46; --ok-bg:#ecfdf5; --ok-br:#6ee7b7;
      --er:#991b1b; --er-bg:#fff1f2; --er-br:#fda4af;
      --shadow:0 16px 40px rgba(17,24,39,.08);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0; background:var(--bg-grad);
      font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      color:var(--ink);
    }
    .page{min-height:100svh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{
      width:100%;max-width:520px;background:var(--card);border-radius:18px;
      border:1px solid var(--line);box-shadow:var(--shadow);padding:22px;
    }
    .brand{display:flex;align-items:center;gap:10px;margin-bottom:4px;
      color:var(--pink);text-transform:uppercase;font-weight:900;letter-spacing:.08em;font-size:13px}
    .brand .dot{width:8px;height:8px;border-radius:999px;background:var(--pink)}
    .title{margin:.25rem 0 0;font-size:26px;font-weight:900}
    .slogan{margin:4px 0 18px;color:var(--muted);font-size:14px}
    .field{margin-bottom:12px}
    .input{
      width:100%;padding:12px;border-radius:12px;border:1px solid var(--line);
      background:#fafafa;font-size:15px;
    }
    .input:focus{outline:none;border-color:var(--pink);background:#fff;
      box-shadow:0 0 0 3px rgba(244,114,182,.25);}
    .btn{
      width:100%;padding:12px 14px;border-radius:12px;border:0;
      cursor:pointer;font-weight:800;font-size:15px;
      background:linear-gradient(90deg,var(--pink),var(--pink-2));color:#fff;
    }
    .btn:hover{filter:brightness(1.03)}
    .alert{border-radius:12px;padding:10px 12px;margin-bottom:12px;font-size:14px}
    .alert.error{background:var(--er-bg);border:1px solid var(--er-br);color:var(--er)}
    .alert.success{background:var(--ok-bg);border:1px solid var(--ok-br);color:var(--ok)}
    .subtle{text-align:center;color:var(--muted);font-size:14px;margin-top:14px}
    .link{color:var(--pink);text-decoration:none}
    .link:hover{text-decoration:underline}
  </style>
</head>
<body>
<div class="page">
  <div class="card">
    <div class="brand">
      <span class="dot"></span>
      <span>NEINMAID</span>
    </div>

    <?php if ($invalidLink): ?>
      <h1 class="title">Link not valid</h1>
      <p class="slogan">
        This reset link is invalid or has expired. Please request a new one.
      </p>
      <div class="alert error">The reset link could not be verified.</div>
      <p class="subtle">
        <a class="link" href="forgot_password.php">Request a new reset link</a>
      </p>
    <?php elseif ($changeSuccess): ?>
      <h1 class="title">Password updated</h1>
      <p class="slogan">Your password has been changed successfully.</p>
      <div class="alert success">You can now log in with your new password.</div>
      <p class="subtle">
        <a class="link" href="login.php">Back to login</a>
      </p>
    <?php else: ?>
      <h1 class="title">Set a new password</h1>
      <p class="slogan">
        Enter a strong password you haven’t used before on NeinMaid.
      </p>

      <?php if ($errorMsg): ?>
        <div class="alert error"><?= htmlspecialchars($errorMsg,ENT_QUOTES,'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="field">
          <input class="input" type="password" name="password" placeholder="New password" required>
        </div>
        <div class="field">
          <input class="input" type="password" name="password_confirm" placeholder="Confirm new password" required>
        </div>
        <button type="submit" class="btn">Save new password</button>
      </form>

      <p class="subtle">
        Remembered your password?
        <a class="link" href="login.php">Back to login</a>
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

