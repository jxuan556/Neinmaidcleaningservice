<?php
session_start();

require_once __DIR__ . '/config.php';          // loads .env only
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ---------- Ensure DB connection ---------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $DB_HOST = getenv('DB_HOST') ?: 'localhost';
  $DB_USER = getenv('DB_USER') ?: 'root';
  $DB_PASS = getenv('DB_PASS') ?: '';
  $DB_NAME = getenv('DB_NAME') ?: 'maid_system';
  $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
  $conn->set_charset('utf8mb4');
}

/* ---------- Helpers ---------- */
function redirect_with_msg($ok, $msg){
  $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
  header("Location: forgot_password.php");
  exit;
}

/* ---------- Validate input ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect_with_msg(false, 'Invalid request.');
}

$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirect_with_msg(false, 'Please enter a valid email.');
}

/* ---------- Step 1: Check user (avoid enumeration) ---------- */
$st = $conn->prepare("SELECT id, name FROM users WHERE email=? LIMIT 1");
$st->bind_param("s", $email);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

if (!$user) {
  redirect_with_msg(true, 'If that email is registered, a reset link has been sent.');
}

/* ---------- Step 2: Create token (1-hour expiry) ---------- */
$token  = bin2hex(random_bytes(32)); // Generate random token
$thash  = hash('sha256', $token); // Hash the token
$expiry = (new DateTime('+1 hour'))->format('Y-m-d H:i:s'); // Set expiry to 1 hour

/* ---------- Create helper table if missing (idempotent) ---------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS password_resets (
    email        VARCHAR(255) NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    expires_at   DATETIME NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    KEY token_hash (token_hash),
    KEY expires_at (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- Step 3: Upsert reset record ---------- */
$st = $conn->prepare("REPLACE INTO password_resets(email, token_hash, expires_at) VALUES(?, ?, ?)");
$st->bind_param("sss", $email, $thash, $expiry);
$st->execute();
$st->close();

/* ---------- Step 4: Build reset link ---------- */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$resetUrl = $scheme . '://' . $host . $base . '/reset_form.php?token=' . urlencode($token) . '&email=' . urlencode($email);

/* ---------- Step 5: Send email via Mailtrap ---------- */
$mail = new PHPMailer(true);
try {
  $mail->isSMTP();
  $mail->Host       = getenv('MAIL_HOST') ?: 'smtp.mailtrap.io'; // Mailtrap SMTP server
  $mail->SMTPAuth   = true;
  $mail->Port       = (int)(getenv('MAIL_PORT') ?: 2525); // SMTP port for Mailtrap
  $mail->Username   = getenv('MAIL_USERNAME') ?: ''; // Mailtrap username
  $mail->Password   = getenv('MAIL_PASSWORD') ?: ''; // Mailtrap password

  // Sender details
  $mail->setFrom(
    getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@neinmaid.local',
    getenv('MAIL_FROM_NAME') ?: 'NeinMaid'
  );
  // Recipient details
  $mail->addAddress($email, $user['name'] ?? 'Customer');
  $mail->Subject = 'Reset your NeinMaid password';
  $mail->isHTML(true);

  // Safely output the name and reset URL
  $safeName = htmlspecialchars($user['name'] ?? 'there', ENT_QUOTES, 'UTF-8');
  $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

  // Email body content
  $mail->Body = '
    <div style="font-family:system-ui,Arial,sans-serif;font-size:15px;color:#111">
      <p>Hi '.$safeName.',</p>
      <p>We received a request to reset your NeinMaid password.</p>
      <p>
        <a href="'.$safeUrl.'" style="display:inline-block;padding:10px 14px;background:#ec4899;color:#fff;border-radius:10px;text-decoration:none">
          Reset Password
        </a>
      </p>
      <p>Or copy & paste this link:<br>'.$safeUrl.'</p>
      <p style="color:#6b7280">This link expires in 1 hour. If you didn’t request this, you can ignore this email.</p>
    </div>';
  $mail->AltBody = "Reset your password:\n$resetUrl\n(This link expires in 1 hour.)";

  // Send the email
  $mail->send();
  redirect_with_msg(true, 'If that email is registered, a reset link has been sent.');
} catch (Exception $e) {
  redirect_with_msg(false, 'There was an error sending the reset link. Please try again later.');
}
?>
