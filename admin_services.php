<?php
// admin_services.php — Dashboard-style UI + same sidebar/nav as admin_dashboard
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
// If you want to hard-gate to admin only, uncomment below:
// if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: user_dashboard.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host="localhost"; $user="root"; $pass=""; $db="maid_system";
$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) { die("DB error"); }
$conn->set_charset('utf8mb4');

function clean($s){ return trim((string)($s ?? '')); }

$flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  if ($action==='add') {
    $svc_key = clean($_POST['svc_key']);
    $title   = clean($_POST['title']);
    $blurb   = clean($_POST['blurb']);
    $image   = clean($_POST['image_url']);
    $map     = clean($_POST['booking_map']);
    $active  = isset($_POST['is_active']) ? 1 : 0;

    if ($svc_key==='' || $title==='' || $blurb==='' || $image==='' || $map==='') {
      $flash = "Please fill all fields.";
    } else {
      $stmt = $conn->prepare("INSERT INTO services (svc_key, title, blurb, image_url, booking_map, is_active) VALUES (?,?,?,?,?,?)");
      $stmt->bind_param("sssssi", $svc_key, $title, $blurb, $image, $map, $active);
      $flash = $stmt->execute() ? "Service added." : "Failed to add (service key may already exist).";
      $stmt->close();
    }
  }

  if ($action==='toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $to = (int)($_POST['to'] ?? 1);
    if ($id>0) {
      $stmt=$conn->prepare("UPDATE services SET is_active=? WHERE id=?");
      $stmt->bind_param("ii",$to,$id);
      $stmt->execute();
      $stmt->close();
      $flash = $to? "Service enabled.":"Service disabled.";
    }
  }

  if ($action==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $stmt=$conn->prepare("DELETE FROM services WHERE id=?");
      $stmt->bind_param("i",$id);
      $stmt->execute();
      $stmt->close();
      $flash = "Service deleted.";
    }
  }

  if ($action==='edit') {
    $id      = (int)($_POST['id'] ?? 0);
    $svc_key = clean($_POST['svc_key']);
    $title   = clean($_POST['title']);
    $blurb   = clean($_POST['blurb']);
    $image   = clean($_POST['image_url']);
    $map     = clean($_POST['booking_map']);
    $active  = isset($_POST['is_active']) ? 1 : 0;

    if ($id<=0 || $svc_key==='' || $title==='' || $blurb==='' || $image==='' || $map==='') {
      $flash = "Please fill all fields.";
    } else {
      // ensure svc_key unique (excluding current id)
      $chk = $conn->prepare("SELECT id FROM services WHERE svc_key=? AND id<>? LIMIT 1");
      $chk->bind_param("si",$svc_key,$id);
      $chk->execute(); $chk->store_result();
      if ($chk->num_rows>0) {
        $flash = "Service key already in use by another record.";
        $chk->close();
      } else {
        $chk->close();
        $stmt=$conn->prepare("UPDATE services SET svc_key=?, title=?, blurb=?, image_url=?, booking_map=?, is_active=? WHERE id=?");
        $stmt->bind_param("ssssssi",$svc_key,$title,$blurb,$image,$map,$active,$id);
        $flash = $stmt->execute() ? "Service updated." : "Failed to update service.";
        $stmt->close();
      }
    }
  }
}

// Fetch services
$res = $conn->query("SELECT * FROM services ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin · Services – NeinMaid</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin_dashboard.css"><!-- reuse dashboard css -->
<style>
  /* Match admin_dashboard look */
  body{background:#f6f7fb;color:#0f172a;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif}
  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:16px 12px}
  .brand{display:flex;align-items:center;gap:10px;font-weight:800}
  .brand img{width:28px;height:28px}
  .nav{display:flex;flex-direction:column;margin-top:12px}
  .nav a{padding:10px 12px;border-radius:10px;color:#111827;text-decoration:none;margin:2px 0}
  .nav a.active, .nav a:hover{background:#eef2ff}
  .main{padding:18px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .right{display:flex;justify-content:flex-end}
  .btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;text-decoration:none;color:#111827}
  .btn:hover{background:#f8fafc}
  .btn-primary{background:#111827;color:#fff;border-color:#111827}
  .btn-primary:hover{opacity:.9}
  .pill{padding:2px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc}
  .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:12px}
  .toolbar .title{font-weight:800;font-size:20px}
  .flash{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:12px;padding:10px;margin-bottom:12px}
  .error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:10px;margin-bottom:12px}
  .table-wrap{overflow:auto}
  table{width:100%;border-collapse:collapse}
  th,td{padding:12px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:14px;vertical-align:top}
  thead th{background:#f8fafc}
  img.thumb{width:70px;height:44px;object-fit:cover;border-radius:8px}
  .badge{display:inline-flex;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:800}
  .on{background:#dcfce7;color:#065f46}
  .off{background:#ffe7e7;color:#7a1c1c}
  /* Modal */
  .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);padding:20px;z-index:50}
  .modal.open{display:block}
  .modal .inner{max-width:880px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;
    box-shadow:0 18px 40px rgba(0,0,0,.1);padding:16px;max-height:85vh;overflow:auto}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .grid1{display:grid;grid-template-columns:1fr;gap:12px}
  input,textarea,select{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px}
  textarea{min-height:84px}
  @media (max-width:900px){ .grid2{grid-template-columns:1fr} }
</style>
</head>
<body>

<div class="layout">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid">
      <div>NeinMaid</div>
    </div>

    <div class="nav">
      <a class="active" href="admin_dashboard.php">Dashboard</a>
      <a href="admin_bookings.php">Bookings</a>
      <a href="admin_employees.php">Workers</a>
      <a href="admin_services.php">Services</a>
      <a href="admin_finance.php">Finance</a>
      <a href="admin_promos.php">Promotions</a>
      <a href="admin_worker_changes.php">Worker Changes</a>
      <a href="admin_chat.php">Support Chat</a>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <!-- Top toolbar -->
    <div class="toolbar">
      <div class="title">Services</div>
    </div>

    <!-- Flash -->
    <?php if($flash): ?>
      <div class="flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Add service -->
    <section class="card">
      <details>
        <summary style="cursor:pointer;font-weight:800">Add service +</summary>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="action" value="add">
          <div class="grid2">
            <div>
              <label>Service Key (unique)</label>
              <input name="svc_key" placeholder="e.g. basic, office, custom" required>
            </div>
            <div>
              <label>Title</label>
              <input name="title" placeholder="e.g. Basic House Cleaning" required>
            </div>
            <div class="grid1">
              <label>Short blurb</label>
              <textarea name="blurb" placeholder="Short description under title" required></textarea>
            </div>
            <div>
              <label>Image URL / path</label>
              <input name="image_url" placeholder="e.g. house.jpg or https://.../house.jpg" required>
            </div>
            <div>
              <label>Map to booking service</label>
              <select name="booking_map" required>
                <option value="">Select…</option>
                <option>Standard House Cleaning</option>
                <option>Office & Commercial Cleaning</option>
                <option>Spring / Deep Cleaning</option>
                <option>Move In/Out Cleaning</option>
                <option>Custom Cleaning Plans</option>
              </select>
            </div>
            <div>
              <label><input type="checkbox" name="is_active" checked> Active</label>
            </div>
          </div>
          <div class="right" style="margin-top:12px">
            <button type="reset" class="btn">Reset</button>
            <button class="btn btn-primary" type="submit">Save</button>
          </div>
        </form>
      </details>
    </section>

    <!-- List -->
    <section class="card" style="margin-top:12px">
      <div class="row" style="justify-content:space-between;margin-bottom:6px">
        <h2 style="margin:0">All Services</h2>
        <div class="pill">
          <?= ($res && $res->num_rows) ? ($res->num_rows.' item(s)') : '0 item' ?>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:6%">ID</th>
              <th style="width:14%">Key</th>
              <th style="width:18%">Title</th>
              <th>Blurb</th>
              <th style="width:12%">Image</th>
              <th style="width:16%">Booking Map</th>
              <th style="width:8%">Active</th>
              <th style="width:22%">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if($res && $res->num_rows): while($row=$res->fetch_assoc()): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><?= htmlspecialchars($row['svc_key']) ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['blurb']) ?></td>
                <td><img class="thumb" src="<?= htmlspecialchars($row['image_url']) ?>" alt=""></td>
                <td><?= htmlspecialchars($row['booking_map']) ?></td>
                <td><?= $row['is_active'] ? '<span class="badge on">ON</span>' : '<span class="badge off">OFF</span>' ?></td>
                <td class="row" style="gap:8px;flex-wrap:wrap">
                  <button class="btn" type="button"
                    onclick='openEdit(<?= json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>Edit</button>

                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="to" value="<?= $row['is_active']?0:1 ?>">
                    <button class="btn"><?= $row['is_active']?'Disable':'Enable' ?></button>
                  </form>

                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this service? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button class="btn">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="8" class="muted" style="text-align:center">No services yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<!-- EDIT MODAL -->
<div id="modalEdit" class="modal" aria-hidden="true">
  <div class="inner">
    <div class="row" style="justify-content:space-between;align-items:center">
      <h3 style="margin:0">Edit Service</h3>
      <button class="btn" type="button" onclick="closeModal('modalEdit')">✖</button>
    </div>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="e_id">
      <div class="grid2">
        <div>
          <label>Service Key (unique)</label>
          <input id="e_svc_key" name="svc_key" required>
        </div>
        <div>
          <label>Title</label>
          <input id="e_title" name="title" required>
        </div>
        <div class="grid1">
          <label>Short blurb</label>
          <textarea id="e_blurb" name="blurb" required></textarea>
        </div>
        <div>
          <label>Image URL / path</label>
          <input id="e_image_url" name="image_url" required>
        </div>
        <div>
          <label>Map to booking service</label>
          <select id="e_booking_map" name="booking_map" required>
            <option value="">Select…</option>
            <option>Standard House Cleaning</option>
            <option>Office & Commercial Cleaning</option>
            <option>Spring / Deep Cleaning</option>
            <option>Move In/Out Cleaning</option>
            <option>Custom Cleaning Plans</option>
          </select>
        </div>
        <div>
          <label><input type="checkbox" id="e_is_active" name="is_active"> Active</label>
        </div>
      </div>
      <div class="right" style="margin-top:12px">
        <button class="btn" type="button" onclick="closeModal('modalEdit')">Cancel</button>
        <button class="btn btn-primary" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(id){ const m=document.getElementById(id); if(m){ m.classList.add('open'); m.setAttribute('aria-hidden','false'); } }
  function closeModal(id){ const m=document.getElementById(id); if(m){ m.classList.remove('open'); m.setAttribute('aria-hidden','true'); } }

  function openEdit(row){
    document.getElementById('e_id').value          = row.id || '';
    document.getElementById('e_svc_key').value     = row.svc_key || '';
    document.getElementById('e_title').value       = row.title || '';
    document.getElementById('e_blurb').value       = row.blurb || '';
    document.getElementById('e_image_url').value   = row.image_url || '';
    document.getElementById('e_booking_map').value = row.booking_map || '';
    document.getElementById('e_is_active').checked = (String(row.is_active) === '1');
    openModal('modalEdit');
  }

  // Close on ESC / overlay click
  (function(){
    const m = document.getElementById('modalEdit');
    m.addEventListener('click', (e)=> { if (e.target.id === 'modalEdit') closeModal('modalEdit'); });
    window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeModal('modalEdit'); });
  })();
</script>
</body>
</html>
