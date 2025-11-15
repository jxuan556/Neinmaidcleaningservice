<?php
// admin_worker_changes.php — dashboard-styled list + filters + search + approve/reject via AJAX
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  header("Location: login.php"); exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","maid_system"); $conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- Filters ---- */
$filter = $_GET['status'] ?? 'pending';
$allowed = ['pending','approved','rejected','all'];
if (!in_array($filter,$allowed,true)) $filter='pending';

$q = trim((string)($_GET['q'] ?? ''));
$params=[]; $types='';

$whereParts=[];
if ($filter !== 'all') {
  $whereParts[] = "c.status = ?";
  $params[] = $filter; $types.='s';
} else {
  $whereParts[] = "1";
}
if ($q !== '') {
  // search by worker name/email or worker_id / change id
  $whereParts[] = "(w.name LIKE ? OR w.email LIKE ? OR c.worker_id = ? OR c.id = ?)";
  $like = '%'.$q.'%';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
  $params[]=(int)$q; $types.='i';
  $params[]=(int)$q; $types.='i';
}
$where = implode(" AND ", $whereParts);

/* ---- Fetch ---- */
$rows=[];
$sql = "
  SELECT c.id,c.worker_id,c.submitted_by,c.payload,c.status,c.created_at,c.reviewed_at,c.reviewed_by,
         w.name AS worker_name,w.email AS worker_email
  FROM worker_profile_changes c
  LEFT JOIN worker_profiles w ON w.id=c.worker_id
  WHERE $where
  ORDER BY c.created_at DESC
  LIMIT 200
";
$stmt = $conn->prepare($sql);
if ($types!=='') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while($r=$res->fetch_assoc()) $rows[]=$r;
$stmt->close();

/* quick counts for tabs */
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'all'=>0];
$rc = $conn->query("SELECT status, COUNT(*) AS c FROM worker_profile_changes GROUP BY status");
while($c=$rc->fetch_assoc()){
  $status = $c['status'];
  if (isset($counts[$status])) $counts[$status] = (int)$c['c'];
}
$counts['all'] = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Worker Changes – NeinMaid</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_dashboard.css"><!-- reuse dashboard css -->
  <style>
    /* === Same shell & navbar as admin_finance.php === */
    body{background:#f6f7fb;color:#0f172a;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px 12px}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand img{width:28px;height:28px}
    .nav{display:flex;flex-direction:column;margin-top:12px}
    .nav a{padding:10px 12px;border-radius:10px;color:#111827;text-decoration:none;margin:2px 0}
    .nav a.active, .nav a:hover{background:#eef2ff}
    .main{padding:18px}

    /* Page */
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .row-sb{display:flex;justify-content:space-between;align-items:center;gap:10px}
    .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
    .btn:hover{background:#f8fafc}
    .btn-primary{background:#111827;color:#fff;border-color:#111827}
    .btn-primary:hover{opacity:.9}
    .badge{display:inline-flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;font-size:12px}
    .badge.pending{background:#fffbeb;border-color:#fde68a;color:#92400e}
    .badge.approved{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .badge.rejected{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .input{padding:8px;border:1px solid #e5e7eb;border-radius:10px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;font-size:14px}
    thead th{background:#f8fafc}
    .mini{font-size:12px;color:#64748b}
    .payload{background:#f8fafc;border:1px dashed #e5e7eb;border-radius:10px;padding:8px;white-space:pre-wrap}
    .tabs a{padding:8px 10px;border:1px solid #e5e7eb;border-radius:999px;text-decoration:none;color:#111827;background:#fff}
    .tabs a.active{background:#111827;color:#fff;border-color:#111827}
    @media (max-width:1100px){ .layout{grid-template-columns:1fr} .sidebar{position:sticky;top:0;z-index:5} }
  </style>
</head>
<body>

<div class="layout">
  <!-- Sidebar (identical to Finance; active on Worker Changes) -->
  <aside class="sidebar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      <div>NeinMaid</div>
    </div>

    <div class="nav">
      <a href="admin_dashboard.php">Dashboard</a>
      <a href="admin_bookings.php">Bookings</a>
      <a href="admin_employees.php">Workers</a>
      <a href="admin_services.php">Services</a>
      <a href="admin_finance.php">Finance</a>
      <a href="admin_promos.php">Promotions</a>
      <a class="active" href="admin_worker_changes.php">Worker Changes</a>
      <a href="admin_chat.php">Support Chat</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <section class="card">
      <div class="row-sb">
        <h1 style="margin:0">Worker Profile Changes</h1>
        <form class="row" method="get" action="admin_worker_changes.php">
          <input class="input" type="text" name="q" placeholder="Search name / email / ID…" value="<?php echo h($q); ?>" />
          <input type="hidden" name="status" value="<?php echo h($filter); ?>">
          <button class="btn" type="submit">Search</button>
          <a class="btn" href="admin_worker_changes.php">Reset</a>
        </form>
      </div>

      <div class="row tabs" style="margin-top:10px">
        <a class="<?php echo $filter==='pending'?'active':''; ?>"
           href="?status=pending<?php echo $q!=='' ? '&q='.urlencode($q):''; ?>">Pending
           <span class="badge pending"><?php echo (int)$counts['pending']; ?></span></a>
        <a class="<?php echo $filter==='approved'?'active':''; ?>"
           href="?status=approved<?php echo $q!=='' ? '&q='.urlencode($q):''; ?>">Approved
           <span class="badge approved"><?php echo (int)$counts['approved']; ?></span></a>
        <a class="<?php echo $filter==='rejected'?'active':''; ?>"
           href="?status=rejected<?php echo $q!=='' ? '&q='.urlencode($q):''; ?>">Rejected
           <span class="badge rejected"><?php echo (int)$counts['rejected']; ?></span></a>
        <a class="<?php echo $filter==='all'?'active':''; ?>"
           href="?status=all<?php echo $q!=='' ? '&q='.urlencode($q):''; ?>">All
           <span class="badge"><?php echo (int)$counts['all']; ?></span></a>
        <a class="btn" href="admin_dashboard.php" style="margin-left:auto">← Back</a>
      </div>
    </section>

    <section class="card" style="margin-top:12px">
      <?php if(!$rows): ?>
        <div class="mini">No records.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table>
            <thead>
              <tr>
                <th style="width:70px">#</th>
                <th style="min-width:220px">Worker</th>
                <th style="min-width:140px">Submitted</th>
                <th>Status</th>
                <th style="width:110px">Fields</th>
                <th>Preview</th>
                <th style="min-width:180px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $pc):
                $payload = json_decode($pc['payload'] ?? '{}', true) ?: [];
                $keys = array_keys($payload);
                $status = (string)$pc['status'];
              ?>
              <tr id="row<?= (int)$pc['id'] ?>">
                <td>#<?= (int)$pc['id'] ?></td>
                <td>
                  <div><strong><?= h($pc['worker_name'] ?: ('#'.$pc['worker_id'])) ?></strong></div>
                  <div class="mini"><?= h($pc['worker_email'] ?: '') ?></div>
                  <div class="mini">Worker ID: <?= (int)$pc['worker_id'] ?></div>
                </td>
                <td>
                  <div><?= h($pc['created_at']) ?></div>
                  <?php if(!empty($pc['reviewed_at'])): ?>
                    <div class="mini">Reviewed: <?= h($pc['reviewed_at']) ?><?= $pc['reviewed_by']? ' by '.h($pc['reviewed_by']):'' ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?php echo h($status); ?>"><?= ucfirst($status) ?></span>
                </td>
                <td><?= count($keys) ?> field(s)</td>
                <td>
                  <details>
                    <summary class="mini">Show</summary>
                    <div class="payload" style="margin-top:8px">
                      <?php if(!$payload): ?>
                        <div class="mini">No data.</div>
                      <?php else: foreach($payload as $k=>$v): ?>
                        <div style="margin-bottom:6px">
                          <strong><?= h($k) ?>:</strong>
                          <?php
                            if (is_array($v)) {
                              echo h(json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                            } else {
                              echo h((string)$v);
                            }
                          ?>
                        </div>
                      <?php endforeach; endif; ?>
                    </div>
                  </details>
                </td>
                <td>
                  <?php if($status==='pending'): ?>
                    <button class="btn btn-primary" onclick="act(<?= (int)$pc['id'] ?>,'approve')">Approve</button>
                    <button class="btn" onclick="act(<?= (int)$pc['id'] ?>,'reject')">Reject</button>
                  <?php else: ?>
                    <span class="mini">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<script>
async function act(id, action){
  if(!confirm(action==='approve'?'Approve this request?':'Reject this request?')) return;
  const fd=new FormData(); fd.append('id',id); fd.append('action',action);
  try{
    const r=await fetch('admin_worker_change_action.php',{method:'POST',body:fd});
    const tx=await r.text();
    let j; try{ j=JSON.parse(tx); }catch{ alert(tx); return; }
    if(j.ok){
      const row = document.getElementById('row'+id);
      if(row) row.remove();
    }else{
      alert(j.msg||'Failed');
    }
  }catch(e){
    alert('Network error: '+e.message);
  }
}
</script>
</body>
</html>

