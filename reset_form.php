<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "localhost"; $DB_USER = "root"; $DB_PASS = ""; $DB_NAME = "maid_system";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset('utf8mb4');

function flash($ok, $msg) { $_SESSION['rf_flash'] = ['ok' => $ok, 'msg' => $msg]; }
function out($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $token = trim($_POST['token'] ?? '');
  $pass1 = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password_confirm'] ?? '');

  // Basic validation: email and token
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '' || $pass1 === '') {
    flash(false, 'Invalid submission.');
    header("Location: reset_form.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
  }

  // Check if passwords match
  if ($pass1 !== $pass2) {
    flash(false, 'Passwords do not match.');
    header("Location: reset_form.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
  }

  // Password length validation
  if (strlen($pass1) < 8) {
    flash(false, 'Password must be at least 8 characters.');
    header("Location: reset_form.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
  }

  // Password complexity validation: at least one uppercase and one special character
  if (!preg_match('/[A-Z]/', $pass1) || !preg_match('/[\W_]/', $pass1)) {
    flash(false, 'Password must contain at least one uppercase letter and one special character.');
    header("Location: reset_form.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
  }

  // Verify token
  $thash = hash('sha256', $token);
  $now   = date('Y-m-d H:i:s');

  $st = $conn->prepare("SELECT email FROM password_resets WHERE email=? AND token_hash=? AND expires_at > ? LIMIT 1");
  $st->bind_param("sss", $email, $thash, $now);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) {
    flash(false, 'This reset link is invalid or expired.');
    header("Location: forgot_password.php");
    exit;
  }

  // Update user password
  $hash = password_hash($pass1, PASSWORD_DEFAULT);
  $st = $conn->prepare("UPDATE users SET password=? WHERE email=? LIMIT 1");
  $st->bind_param("ss", $hash, $email);
  $st->execute();
  $st->close();

  // Consume the token
  $st = $conn->prepare("DELETE FROM password_resets WHERE email=?");
  $st->bind_param("s", $email);
  $st->execute();
  $st->close();

  flash(true, 'Password updated. You can now sign in.');
  header("Location: login.php");
  exit;
}

/* ----- GET: validate token quickly (to show form) ----- */
$email = trim($_GET['email'] ?? '');
$token = trim($_GET['token'] ?? '');
$valid = false;

if ($email && $token && filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $thash = hash('sha256', $token);
  $now = date('Y-m-d H:i:s');

  $st = $conn->prepare("SELECT email FROM password_resets WHERE email=? AND token_hash=? AND expires_at > ? LIMIT 1");
  $st->bind_param("sss", $email, $thash, $now);
  $st->execute();
  $valid = (bool) $st->get_result()->fetch_assoc();
  $st->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Reset Password – NeinMaid</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            background: #fafafa;
            font-family: Inter, system-ui, Arial;
        }

        .page {
            min-height: 100svh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 16px 40px rgba(17, 24, 39, .06);
            padding: 22px;
        }

        .title {
            margin: 0 0 6px;
            font-weight: 900;
            font-size: 26px;
        }

        .muted {
            color: #6b7280;
            margin: 0 0 14px;
        }

        .alert {
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .ok {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            color: #065f46;
        }

        .err {
            background: #fff1f2;
            border: 1px solid #fda4af;
            color: #991b1b;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fafafa;
            font-size: 15px;
            margin: 8px 0;
        }

        .btn {
            width: 100%;
            padding: 12px 14px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(90deg, #ec4899, #db2777);
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }

        a {
            color: #ec4899;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <h1 class="title">Reset Password</h1>
            <p class="muted">Enter a new password for your account.</p>

            <?php if (!empty($_SESSION['rf_flash'])): $f = $_SESSION['rf_flash']; unset($_SESSION['rf_flash']); ?>
                <div class="alert <?php echo $f['ok'] ? 'ok' : 'err'; ?>"><?php echo out($f['msg']); ?></div>
            <?php endif; ?>

            <?php if (!$valid): ?>
                <div class="alert err">This reset link is invalid or has expired. <a href="forgot_password.php">Request a new one</a>.</div>
            <?php else: ?>
                <form method="POST" action="reset_form.php" novalidate>
                    <input type="hidden" name="email" value="<?php echo out($email); ?>">
                    <input type="hidden" name="token" value="<?php echo out($token); ?>">

                    <label>New password</label>
                    <input type="password" name="password" minlength="8" required placeholder="At least 8 characters, 1 uppercase letter, and 1 special character">

                    <label>Confirm new password</label>
                    <input type="password" name="password_confirm" minlength="8" required placeholder="Re-enter password">

                    <button class="btn" type="submit">Update password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
