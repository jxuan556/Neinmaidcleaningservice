<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
  SELECT b.*, u.name AS customer_name, u.email AS customer_email,
         w.name AS worker_name
  FROM bookings b
  JOIN users u ON u.id=b.user_id
  LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
  WHERE b.id=?
");
$stmt->bind_param('i',$id);
$stmt->execute();
$bk = $stmt->get_result()->fetch_assoc();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Booking #<?= (int)$id ?></title>
  <link rel="stylesheet" href="admin.css">
</head>
<body>
  <div class="card" style="max-width:820px;margin:20px auto;padding:18px">
    <?php if(!$bk): ?>
      <p>Booking not found.</p>
    <?php else: ?>
      <h3 style="margin:0 0 10px">Booking #<?= (int)$bk['id'] ?> (<?= htmlspecialchars(strtoupper($bk['status'])) ?>)</h3>
      <div>Ref: <strong><?= htmlspecialchars($bk['ref_code'] ?: '—') ?></strong></div>
      <hr>
      <div><strong>Customer:</strong> <?= htmlspecialchars($bk['customer_name']) ?> (<?= htmlspecialchars($bk['customer_email']) ?>)</div>
      <div><strong>Service:</strong> <?= htmlspecialchars($bk['service']) ?></div>
      <div><strong>Area:</strong> <?= htmlspecialchars($bk['area']) ?></div>
      <div><strong>Date/Time:</strong> <?= htmlspecialchars($bk['date'].' '.$bk['time_slot']) ?></div>
      <div><strong>Cleaner:</strong> <?= htmlspecialchars($bk['worker_name'] ?: '—') ?></div>
      <div><strong>Travel Fee:</strong> RM <?= number_format($bk['travel_fee'],2) ?></div>
      <div><strong>Estimated Price:</strong> RM <?= number_format($bk['estimated_price'],2) ?></div>
      <?php if($bk['service']==='Custom Cleaning Plans'): ?>
        <div><strong>Custom Hours:</strong> <?= htmlspecialchars($bk['custom_hours'] ?: '—') ?></div>
        <div><strong>Custom Budget:</strong> RM <?= htmlspecialchars($bk['custom_budget'] ?: '—') ?></div>
        <div><strong>Details:</strong><br><?= nl2br(htmlspecialchars($bk['custom_details'])) ?></div>
      <?php endif; ?>
      <div style="margin-top:14px">
        <a class="btn" href="admin_bookings.php">← Back</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
