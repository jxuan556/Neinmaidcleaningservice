<?php
// reset_password.php – handles sending reset email via Mailtrap

session_start();
require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "maid_system";

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    $_SESSION['reset_error'] = "Database connection failed.";
    header("Location: forgot_password.php");
    exit();
}

/* --- helper: ensure column exists --- */
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
    return ($rs && $rs->num_rows > 0);
}

/* --- Make sure reset columns exist --- */
try {
    if (!col_exists($conn,'users','reset_token')) {
        $conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL");
    }
    if (!col_exists($conn,'users','reset_expires')) {
        $conn->query("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL");
    }
} catch (Throwable $e) {
    // ignore if permissions restricted; we'll still try to continue
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: forgot_password.php");
    exit();
}

/* --- Validate email --- */
$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['reset_error'] = "Please enter a valid email address.";
    header("Location: forgot_password.php");
    exit();
}

/* --- Find user --- */
$stmt = $conn->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // we don't reveal if email exists – just generic message
    $_SESSION['reset_success'] = "If an account exists for that email, a reset link has been sent.";
    header("Location: forgot_password.php");
    exit();
}

/* --- Generate token & store hash --- */
$token      = bin2hex(random_bytes(16));
$tokenHash  = password_hash($token, PASSWORD_DEFAULT);
$expiresAt  = date('Y-m-d H:i:s', time() + 3600); // 1 hour

$upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
$upd->bind_param("ssi", $tokenHash, $expiresAt, $user['id']);
$upd->execute();
$upd->close();

/* --- Build reset link --- */
$appUrl   = getenv('APP_URL') ?: (isset($_SERVER['HTTP_HOST'])
             ? ( (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                 . $_SERVER['HTTP_HOST'] )
             : 'http://localhost');

$resetLink = rtrim($appUrl, '/') . '/reset_form.php?token=' . urlencode($token) .
             '&email=' . urlencode($user['email']);

/* --- Mailtrap / SMTP config (from .env) --- */
$mailHost = getenv('MAILTRAP_HOST') ?: 'sandbox.smtp.mailtrap.io';
$mailPort = getenv('MAILTRAP_PORT') ?: 2525;
$mailUser = getenv('MAILTRAP_USER') ?: '';
$mailPass = getenv('MAILTRAP_PASS') ?: '';
$mailFrom = getenv('MAIL_FROM')      ?: 'no-reply@example.com';
$mailFromName = getenv('MAIL_FROM_NAME') ?: 'NeinMaid';

/* --- Send email --- */
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $mailHost;
    $mail->SMTPAuth   = true;
    $mail->Port       = $mailPort;
    $mail->Username   = $mailUser;
    $mail->Password   = $mailPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->setFrom($mailFrom, $mailFromName);
    $mail->addAddress($user['email'], $user['name'] ?: $user['email']);

    $mail->isHTML(true);
    $mail->Subject = 'Reset your NeinMaid password';

    $body  = '<p>Hi '.htmlspecialchars($user["name"] ?: "there", ENT_QUOTES,'UTF-8').',</p>';
    $body .= '<p>We received a request to reset the password for your NeinMaid account.</p>';
    $body .= '<p>Click the button below to set a new password:</p>';
    $body .= '<p><a href="'.htmlspecialchars($resetLink,ENT_QUOTES,'UTF-8').'" ';
    $body .= 'style="display:inline-block;padding:10px 16px;border-radius:8px;';
    $body .= 'background:#ec4899;color:#fff;text-decoration:none;font-weight:600;">';
    $body .= 'Reset Password</a></p>';
    $body .= '<p>If the button doesn’t work, copy and paste this link into your browser:<br>';
    $body .= '<code>'.htmlspecialchars($resetLink,ENT_QUOTES,'UTF-8').'</code></p>';
    $body .= '<p>This link will expire in 1 hour. If you did not request a reset, you can ignore this email.</p>';
    $body .= '<p>— NeinMaid</p>';

    $mail->Body = $body;

    $mail->send();

    $_SESSION['reset_success'] = "If an account exists for that email, a reset link has been sent.";
    header("Location: forgot_password.php");
    exit();
} catch (Exception $e) {
    $_SESSION['reset_error'] = "Failed to send reset email. Please try again later.";
    header("Location: forgot_password.php");
    exit();
}

