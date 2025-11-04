<?php
session_start();
if(!isset($_SESSION['user_id'])||($_SESSION['role']??'')!=='worker'){header("Location: login.php");exit();}
$conn=new mysqli("localhost","root","","maid_system");$conn->set_charset('utf8mb4');
$workerId=(int)($_SESSION['worker_id']??$_SESSION['user_id']);
$stmt=$conn->prepare("SELECT * FROM jobs WHERE assigned_worker_id=? ORDER BY date DESC,time_from DESC");
$stmt->bind_param("i",$workerId);$stmt->execute();$jobs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>My Jobs</title>
<style>body{font-family:Inter;background:#f6f7fb;padding:40px}table{width:100%;border-collapse:collapse;background:#fff}
th,td{padding:12px;border:1px solid #eee}th{background:#f9fafb;text-align:left}</style></head>
<body><h1>My Jobs</h1>
<table><thead><tr><th>Date</th><th>Customer</th><th>Address</th><th>Time</th><th>Pay</th><th>Status</th></tr></thead>
<tbody><?php foreach($jobs as $j): ?><tr>
<td><?php echo htmlspecialchars($j['date']);?></td>
<td><?php echo htmlspecialchars($j['customer_name']);?></td>
<td><?php echo htmlspecialchars($j['address']);?></td>
<td><?php echo substr($j['time_from'],0,5).'-'.substr($j['time_to'],0,5);?></td>
<td>RM <?php echo number_format($j['pay_amount'],2);?></td>
<td><?php echo htmlspecialchars($j['status']);?></td>
</tr><?php endforeach;?></tbody></table>
<a href="worker_dashboard.php">← Back</a></body></html>
