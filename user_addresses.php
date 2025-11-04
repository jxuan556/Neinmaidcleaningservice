<?php
/**
 * user_addresses.php — Manage multiple addresses for a user
 *
 * Suggested table (run once if not present):
 *
 * CREATE TABLE IF NOT EXISTS user_addresses (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   user_id INT NOT NULL,
 *   label VARCHAR(60) NOT NULL,              -- e.g. "Home", "Office"
 *   address_text VARCHAR(255) NOT NULL,      -- full address line
 *   home_type VARCHAR(30) DEFAULT NULL,      -- Apartment / Condominium / etc
 *   home_sqft INT DEFAULT NULL,              -- square feet
 *   is_default TINYINT(1) NOT NULL DEFAULT 0,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 *   CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];

/* Helpers */
function clean($s){ return trim(filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS)); }
function csrf_get(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); }

$HOME_TYPES = ['Apartment','Condominium','Condo','Terrace','Semi-D','Bungalow','Shoplot','Office','Other'];

$ok=null; $errors=[];
$csrf = csrf_get();

/* Ownership guard for an address id */
function load_addr_owned(mysqli $conn, int $id, int $uid): ?array {
  $q=$conn->prepare("SELECT * FROM user_addresses WHERE id=? AND user_id=? LIMIT 1");
  $q->bind_param("ii",$id,$uid);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  $q->close();
  return $r ?: null;
}

/* POST: create / update / delete / set_default */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) {
    $errors[]="Security token invalid. Please reload.";
  } else {
    $action = $_POST['action'] ?? '';
    try{
      if ($action==='create') {
        $label = clean($_POST['label'] ?? '');
        $address_text = clean($_POST['address_text'] ?? '');
        $home_type = clean($_POST['home_type'] ?? '');
        $home_sqft = ($_POST['home_sqft']!=='') ? max(0,(int)$_POST['home_sqft']) : null;
        $is_default = !empty($_POST['is_default']) ? 1 : 0;

        if ($label==='')        $errors[]="Label is required.";
        if ($address_text==='') $errors[]="Address is required.";
        if ($home_type!=='' && !in_array($home_type,$HOME_TYPES,true)) $home_type = null;

        if (!$errors) {
          // If setting default, unset others for this user
          if ($is_default) {
            $conn->query("UPDATE user_addresses SET is_default=0 WHERE user_id=".$uid);
          }
          $stmt=$conn->prepare("INSERT INTO user_addresses(user_id,label,address_text,home_type,home_sqft,is_default) VALUES(?,?,?,?,?,?)");
          $stmt->bind_param("isssii", $uid, $label, $address_text, $home_type, $home_sqft, $is_default);
          $stmt->execute(); $stmt->close();
          $ok="Address added.";
        }
      }

      if ($action==='update') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $id? load_addr_owned($conn,$id,$uid): null;
        if (!$row) { $errors[]="Address not found."; }
        else {
          $label = clean($_POST['label'] ?? '');
          $address_text = clean($_POST['address_text'] ?? '');
          $home_type = clean($_POST['home_type'] ?? '');
          $home_sqft = ($_POST['home_sqft']!=='') ? max(0,(int)$_POST['home_sqft']) : null;
          $is_default = !empty($_POST['is_default']) ? 1 : 0;

          if ($label==='')        $errors[]="Label is required.";
          if ($address_text==='') $errors[]="Address is required.";
          if ($home_type!=='' && !in_array($home_type,$HOME_TYPES,true)) $home_type = null;

          if (!$errors) {
            if ($is_default) {
              $conn->query("UPDATE user_addresses SET is_default=0 WHERE user_id=".$uid);
            }
            $stmt=$conn->prepare("UPDATE user_addresses SET label=?, address_text=?, home_type=?, home_sqft=?, is_default=? WHERE id=? AND user_id=? LIMIT 1");
            $stmt->bind_param("sssiiii", $label, $address_text, $home_type, $home_sqft, $is_default, $id, $uid);
            $stmt->execute(); $stmt->close();
            $ok="Address updated.";
          }
        }
      }

      if ($action==='delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $id? load_addr_owned($conn,$id,$uid): null;
        if (!$row) { $errors[]="Address not found."; }
        else {
          $stmt=$conn->prepare("DELETE FROM user_addresses WHERE id=? AND user_id=? LIMIT 1");
          $stmt->bind_param("ii",$id,$uid);
          $stmt->execute(); $stmt->close();
          $ok="Address deleted.";
        }
      }

      if ($action==='make_default') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $id? load_addr_owned($conn,$id,$uid): null;
        if (!$row) { $errors[]="Address not found."; }
        else {
          $conn->query("UPDATE user_addresses SET is_default=0 WHERE user_id=".$uid);
          $stmt=$conn->prepare("UPDATE user_addresses SET is_default=1 WHERE id=? AND user_id=? LIMIT 1");
          $stmt->bind_param("ii",$id,$uid);
          $stmt->execute(); $stmt->close();
          $ok="Default address set.";
        }
      }
    } catch(Throwable $e){
      $errors[]="Database error: ".$e->getMessage();
    }
  }
}

/* If editing, load the record */
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_row = $edit_id ? load_addr_owned($conn,$edit_id,$uid) : null;

/* List addresses (default first) */
$list=[];
$q=$conn->prepare("SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC, id DESC");
$q->bind_param("i",$uid);
$q->execute();
$rows=$q->get_result();
while($r=$rows->fetch_assoc()) $list[]=$r;
$q->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Addresses – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="foundation.css">
  <style>
    .wrap{max-width:980px;margin:18px auto;padding:0 12px}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    .muted{color:#6b7280}
    .tag{display:inline-block;padding:2px 8px;border:1px solid #e5e7eb;border-radius:10px;font-size:12px;background:#fff}
    .tag--default{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .addr-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px}
    .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .actions .btn-secondary{height:34px}
    @media (max-width:700px){ .grid-2,.grid-3{grid-template-columns:1fr} }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:10px">
      <div class="row" style="gap:10px">
        <a class="btn-secondary" href="user_profile.php">← Back to Profile</a>
        <a class="btn-secondary" href="user_dashboard.php">Dashboard</a>
      </div>
      <h2 class="title" style="margin:0">My Addresses</h2>
    </div>

    <?php if($ok): ?><div class="notification success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>
    <?php if($errors): ?>
      <div class="notification error">
        <ul style="margin:6px 0 0 18px"><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
      </div>
    <?php endif; ?>

    <div class="grid-2">
      <!-- Address List -->
      <div class="card">
        <h3 class="title" style="margin:0 0 8px">Saved Addresses</h3>
        <?php if(!$list): ?>
          <div class="muted">No saved addresses yet.</div>
        <?php else: foreach($list as $a): ?>
          <div class="addr-card" style="margin-bottom:10px">
            <div class="row" style="justify-content:space-between">
              <div class="row">
                <strong><?php echo htmlspecialchars($a['label']); ?></strong>
                <?php if($a['is_default']): ?><span class="tag tag--default">Default</span><?php endif; ?>
              </div>
              <div class="muted" style="font-size:12px"><?php echo htmlspecialchars($a['created_at']); ?></div>
            </div>
            <div class="muted" style="margin:4px 0 6px"><?php echo htmlspecialchars($a['address_text']); ?></div>
            <div class="row" style="gap:14px;margin-bottom:8px">
              <div class="tag"><?php echo htmlspecialchars($a['home_type'] ?: '—'); ?></div>
              <div class="tag"><?php echo ($a['home_sqft']!==null && $a['home_sqft']!=='') ? (int)$a['home_sqft'].' sq ft' : '—'; ?></div>
            </div>
            <div class="actions row" style="gap:6px">
              <a class="btn-secondary" href="?edit=<?php echo (int)$a['id']; ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete this address?');">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                <button class="btn-secondary" type="submit">Delete</button>
              </form>
              <?php if(!$a['is_default']): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                  <input type="hidden" name="action" value="make_default">
                  <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                  <button class="btn-secondary" type="submit">Make Default</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Create / Edit Form -->
      <div class="card">
        <?php if($edit_row): ?>
          <h3 class="title" style="margin:0 0 8px">Edit Address</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$edit_row['id']; ?>">

            <label class="label">Label
              <input class="input" name="label" placeholder="e.g. Home, Office" required
                     value="<?php echo htmlspecialchars($edit_row['label']); ?>">
            </label>

            <label class="label" style="margin-top:8px">Address
              <input class="input" name="address_text" placeholder="Street, city, postcode" required
                     value="<?php echo htmlspecialchars($edit_row['address_text']); ?>">
            </label>

            <div class="grid-2" style="margin-top:8px">
              <label class="label">Home type
                <select class="input" name="home_type">
                  <option value="">Select type…</option>
                  <?php foreach($HOME_TYPES as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($edit_row['home_type']===$opt)?'selected':''; ?>>
                      <?php echo htmlspecialchars($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="label">Home size (sq ft)
                <input class="input" type="number" min="0" step="1" name="home_sqft" placeholder="e.g. 1200"
                       value="<?php echo htmlspecialchars((string)($edit_row['home_sqft'] ?? '')); ?>">
              </label>
            </div>

            <label class="checkline" style="margin-top:10px">
              <input type="checkbox" name="is_default" value="1" <?php echo $edit_row['is_default']?'checked':''; ?>> Set as default
            </label>

            <div class="actions-row" style="margin-top:10px">
              <a class="btn-secondary" href="user_addresses.php">Cancel</a>
              <button class="btn-cta" type="submit">Save changes</button>
            </div>
          </form>
        <?php else: ?>
          <h3 class="title" style="margin:0 0 8px">Add New Address</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create">

            <label class="label">Label
              <input class="input" name="label" placeholder="e.g. Home, Office" required>
            </label>

            <label class="label" style="margin-top:8px">Address
              <input class="input" name="address_text" placeholder="Street, city, postcode" required>
            </label>

            <div class="grid-2" style="margin-top:8px">
              <label class="label">Home type
                <select class="input" name="home_type">
                  <option value="">Select type…</option>
                  <?php foreach($HOME_TYPES as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="label">Home size (sq ft)
                <input class="input" type="number" min="0" step="1" name="home_sqft" placeholder="e.g. 1200">
              </label>
            </div>

            <label class="checkline" style="margin-top:10px">
              <input type="checkbox" name="is_default" value="1"> Set as default
            </label>

            <div class="actions-row" style="margin-top:10px">
              <a class="btn-secondary" href="user_profile.php">Cancel</a>
              <button class="btn-cta" type="submit">Add address</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer-links mt-14">
      <a href="user_dashboard.php">Dashboard</a>
      <a href="confirm_booking.php">Bookings</a>
      <a href="logout.php">Logout</a>
    </div>
  </div>
</body>
</html>
