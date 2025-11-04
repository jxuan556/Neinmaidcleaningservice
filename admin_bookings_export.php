<?php
// admin_bookings_export.php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system");
$conn->set_charset('utf8mb4');

$status = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
$types  = '';

if ($status !== 'all') { $where[] = "b.status = ?"; $params[] = $status; $types .= 's'; }
if ($search !== '') {
  $like = "%{$search}%";
  $where[] = "(u.name LIKE ? OR b.service LIKE ? OR b.area LIKE ? OR b.ref_code LIKE ?)";
  array_push($params, $like, $like, $like, $like);
  $types .= 'ssss';
}
$sqlWhere = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$sql = "
  SELECT b.id, b.ref_code, u.name AS customer, b.service, b.area, b.date, b.time_slot,
         b.estimated_price, b.travel_fee, b.status, IFNULL(w.name,'') AS cleaner, b.created_at
  FROM bookings b
  JOIN users u ON u.id=b.user_id
  LEFT JOIN worker_profiles w ON w.id=b.assigned_worker_id
  $sqlWhere
  ORDER BY b.created_at DESC
  LIMIT 2000
";
$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=bookings_export.csv');

$out = fopen('php://output','w');
fputcsv($out, ['ID','Ref','Customer','Service','Area','Date','Time','Estimated','Travel Fee','Status','Cleaner','Created']);

while($r = $res->fetch_assoc()){
  fputcsv($out, [
    $r['id'], $r['ref_code'], $r['customer'], $r['service'], $r['area'],
    $r['date'], $r['time_slot'], $r['estimated_price'], $r['travel_fee'],
    ucfirst($r['status']), $r['cleaner'], $r['created_at']
  ]);
}
fclose($out);
exit();
