<?php
// user_profile.php — NeinMaid (improved UI, same theme header/footer)
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* ===== Files & limits ===== */
$AVATAR_DIR = __DIR__ . "/uploads/avatars";
$AVATAR_URL = "uploads/avatars";
$MAX_AVATAR_BYTES = 2 * 1024 * 1024;
$ALLOWED_MIME = ['image/jpeg','image/png','image/webp'];

/* ===== Helpers ===== */
function clean($s){ return trim(filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS)); }
function csrf_get(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
function ensure_dir($p){ if(!is_dir($p)) @mkdir($p,0775,true); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== Context ===== */
$uid    = (int)$_SESSION['user_id'];
$isAuth = true;
$name   = $_SESSION['name'] ?? "Guest";

/* ===== Pull user ===== */
$stmt=$conn->prepare("SELECT id,name,email,role,
  COALESCE(phone,'') phone,
  COALESCE(address,'') address,
  COALESCE(avatar,'') avatar, password,
  home_sqft, home_type
  FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid);
$stmt->execute();
$user=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$user){ session_destroy(); header("Location: login.php"); exit(); }

$errors=[]; $ok=null;

/* Allowed types (single-select) — includes Condominium alias */
$HOME_TYPES = ['Apartment','Condominium','Condo','Terrace','Semi-D','Bungalow','Shoplot','Office','Other'];

/* ===== Handle POST ===== */
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_check($_POST['csrf'] ?? '')) {
    $errors[]="Security token invalid. Please reload.";
  } else {
    $act = $_POST['action'] ?? '';

    if($act==='update_details'){
      $nameIn      = clean($_POST['name'] ?? '');
      $email       = clean($_POST['email'] ?? '');
      $phone       = clean($_POST['phone'] ?? '');
      $address     = clean($_POST['address'] ?? '');
      $home_sqft   = ($_POST['home_sqft']!=='') ? max(0,(int)$_POST['home_sqft']) : null;
      $home_type   = clean($_POST['home_type'] ?? '');

      if($nameIn==='') $errors[]="Name is required.";
      if(!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]="Please enter a valid email address.";
      if($home_type!=='' && !in_array($home_type,$HOME_TYPES,true)) $home_type=null;

      if(!$errors && $email !== $user['email']){
        $chk=$conn->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
        $chk->bind_param("si",$email,$uid);
        $chk->execute();
        if($chk->get_result()->fetch_assoc()) $errors[]="This email is already in use.";
        $chk->close();
      }

      $avatar = $user['avatar'];
      if(!$errors && !empty($_POST['remove_avatar']) && $avatar){
        $onDisk = __DIR__ . '/' . $avatar;
        if(is_file($onDisk)) @unlink($onDisk);
        $avatar = '';
      }
      if(!$errors && !empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE){
        $f = $_FILES['avatar'];
        if($f['error'] !== UPLOAD_ERR_OK){ $errors[]="Failed to upload image (code {$f['error']})."; }
        else {
          if($f['size'] > $MAX_AVATAR_BYTES) $errors[]="Image too large (max 2MB).";
          $mime = mime_content_type($f['tmp_name']);
          if(!in_array($mime,$ALLOWED_MIME,true)) $errors[]="Only JPG, PNG or WebP allowed.";
          if(!$errors){
            ensure_dir($AVATAR_DIR);
            if($avatar){ $old=__DIR__.'/'.$avatar; if(is_file($old)) @unlink($old); }
            $ext = ($mime==='image/png')?'.png' : (($mime==='image/webp')?'.webp' : '.jpg');
            $fname = 'u'.$uid.'_'.bin2hex(random_bytes(4)).$ext;
            $dest  = $AVATAR_DIR.'/'.$fname;
            if(!move_uploaded_file($f['tmp_name'],$dest)) $errors[]="Failed to save uploaded image.";
            else $avatar = $AVATAR_URL.'/'.$fname;
          }
        }
      }

      if(!$errors){
        try{
          $stmt = $conn->prepare("UPDATE users 
            SET name=?, email=?, phone=?, address=?, avatar=?, home_sqft=?, home_type=? 
            WHERE id=? LIMIT 1");
          if(!$stmt) { throw new Exception("Failed to prepare statement."); }
          $stmt->bind_param("sssssisi",
            $nameIn, $email, $phone, $address, $avatar, $home_sqft, $home_type, $uid
          );
          $stmt->execute();
          $stmt->close();

          $ok="Profile updated";

          $_SESSION['name']=$nameIn; $_SESSION['email']=$email;
          $user['name']=$nameIn; $user['email']=$email; $user['phone']=$phone;
          $user['address']=$address; $user['avatar']=$avatar;
          $user['home_sqft']=$home_sqft; $user['home_type']=$home_type;
          $name = $nameIn;
        } catch (Throwable $e){
          $errors[] = "Database error: ".$e->getMessage();
        }
      }
    }

    if($act==='change_password'){
      $cur = (string)($_POST['current_password'] ?? '');
      $npw = (string)($_POST['new_password'] ?? '');
      $cpw = (string)($_POST['confirm_password'] ?? '');

      if($npw!==$cpw) $errors[]="New passwords do not match.";
      if(!password_verify($cur, $user['password'])) $errors[]="Current password is incorrect.";
      if(!$errors){
        $hash = password_hash($npw, PASSWORD_DEFAULT);
        try{
          $stmt=$conn->prepare("UPDATE users SET password=? WHERE id=? LIMIT 1");
          if(!$stmt){ throw new Exception("Failed to prepare statement."); }
          $stmt->bind_param("si",$hash,$uid);
          $stmt->execute();
          $stmt->close();
          $ok="Password changed successfully";
        } catch(Throwable $e){
          $errors[]="Database error: ".$e->getMessage();
        }
      }
    }
  }
}

/* ===== View model ===== */
$csrf = csrf_get();
$avatarUrl = $user['avatar'] ?: 'https://api.dicebear.com/8.x/initials/svg?seed='.urlencode($user['name'] ?: 'U');
$roleBadge = ucfirst($user['role'] ?? 'user');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Profile – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#f8fafc; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
      --brand:#b91c1c; --ink-2:#111827; --radius:14px; --shadow:0 10px 30px rgba(2,6,23,.08);
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink)}
    a{color:inherit;text-decoration:none}
    img{display:block;max-width:100%}

    /* ===== Navbar (same as user_dashboard) ===== */
    .header{position:sticky;top:0;z-index:1000;background:rgba(255,255,255,.9);backdrop-filter:saturate(150%) blur(8px);border-bottom:1px solid var(--line);transition:box-shadow .2s,border-color .2s,background .2s}
    .header.scrolled{ background:#fff; box-shadow:var(--shadow); border-color:#e2e8f0; }
    .nav-wrap{max-width:1200px;margin:0 auto;padding:10px 16px;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px}
    @media (min-width:901px){ .nav-wrap{column-gap:28px}.brand{padding-right:14px}.nav-center{padding-left:16px;border-left:1px solid var(--line)} }
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:30px;height:30px}
    .brand-name{font-weight:900;letter-spacing:.4px}
    .nav-center{display:flex;align-items:center;justify-content:center;gap:10px}
    .nav-link{padding:8px 12px;border-radius:10px;border:1px solid transparent}
    .nav-link:hover{background:#fff;border-color:var(--line)}
    .nav-search{margin-left:10px;display:flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 10px;min-width:220px;box-shadow:0 2px 8px rgba(15,23,42,.03)}
    .nav-search input{border:none;outline:none;background:transparent;width:100%;font-size:14px;color:#111}
    .nav-search button{background:none;border:0;cursor:pointer;font-size:16px}
    .nav-right{display:flex;justify-content:flex-end;align-items:center;gap:8px}
    .btn,.nav-btn{border-radius:10px;padding:9px 12px;border:1px solid var(--line);background:#fff;cursor:pointer}
    .btn-primary{background:var(--brand);color:#fff;border-color:var(--brand)}
    .hi{font-size:13px;color:#6b7280;margin:0 6px}
    .hamburger{display:none;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 10px;cursor:pointer}
    .mobile-panel{display:none;border-top:1px solid var(--line);background:#fff}
    .mobile-panel.open{display:block;animation:drop .18s ease}
    @keyframes drop{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
    .mobile-inner{padding:10px 16px;display:grid;gap:10px}
    .mobile-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .mobile-actions{display:flex;gap:8px;flex-wrap:wrap}
    .mobile-search{display:flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:12px;padding:8px 10px}
    @media (max-width:900px){ .nav-center{display:none} .hamburger{display:inline-flex} }

    /* ===== Footer (same as dashboard) ===== */
    .footer{background:#0f172a;color:#e5e7eb;margin-top:26px}
    .footgrid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:16px 12px}
    .copyright{max-width:1100px;margin:0 auto;color:#94a3b8;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #1f2937}

    /* ===== Page ===== */
    .wrap{max-width:1100px;margin:18px auto;padding:0 12px}
    .grid{display:grid;grid-template-columns:280px 1fr;gap:14px}
    @media (max-width:960px){ .grid{grid-template-columns:1fr} }

    .side{display:grid;gap:12px;align-self:start;position:sticky;top:76px}
    .side .card{padding:14px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 2px 10px rgba(15,23,42,.03)}
    .header-card{display:flex;align-items:center;gap:12px;padding:16px}
    .avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line)}
    .badge{display:inline-block;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700}

    .section{padding:16px}
    .title{margin:0 0 4px;font-weight:900}
    .sub{color:#6b7280;margin:0 0 8px}

    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:760px){ .form-grid{grid-template-columns:1fr} }
    .label{font-weight:600;display:block;margin-bottom:6px}
    .input,select,textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:10px;font:inherit;background:#fff}
    .input:focus,select:focus,textarea:focus{outline:none;border-color:#cbd5e1;box-shadow:0 0 0 3px rgba(2,132,199,.08)}
    .hr{border:0;border-top:1px dashed var(--line);margin:12px 0}

    .actions-row{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}
    .btn-cta{background:var(--brand);color:#fff;border:1px solid var(--brand);border-radius:10px;padding:10px 14px;cursor:pointer}
    .btn-secondary{background:#fff;border:1px solid var(--line);padding:10px 14px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}

    .note{color:#6b7280;font-size:12px}
    .notif{max-width:1100px;margin:10px auto;padding:10px 12px;border-radius:12px;border:1px solid}
    .notif.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .notif.err{background:#fff1f2;border-color:#fecdd3;color:#9f1239}

    /* Avatar uploader row */
    .avatar-row{display:flex;gap:12px;align-items:center}
    .file{font-size:14px}
    .checkline{font-size:14px;color:#0f172a;margin-top:4px;display:block}

    /* Password strength */
    .strength{height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden}
    .strength > div{height:100%;width:0%}
    .weak{background:#fca5a5}
    .okok{background:#fde68a}
    .good{background:#86efac}
  </style>
</head>
<body>

  <!-- ===== Navbar ===== -->
  <header id="siteHeader" class="header">
    <div class="nav-wrap" aria-label="Primary">
      <div class="brand">
        <img src="maid.png" alt="NeinMaid logo">
        <div class="brand-name">NeinMaid</div>
      </div>

      <div class="nav-center">
        <a class="nav-link" href="user_dashboard.php">Home</a>
        <a class="nav-link" href="booking_success.php">Booking</a>
        <a class="nav-link" href="careers.php">Careers</a>
        <a class="nav-link" href="contact_chat.php">Messages</a>
        <div class="nav-search" role="search">
          <button class="search-btn" aria-label="Search" onclick="dashSearch()">🔍</button>
          <input type="text" id="navSearch" placeholder="Search services..." onkeydown="if(event.key==='Enter') dashSearch()" />
        </div>
      </div>

      <div class="nav-right">
        <button class="btn btn-primary" onclick="location.href='book.php'">Book</button>
        <?php if ($isAuth): ?>
          <span class="hi">Hi, <?= h($name) ?></span>
          <a class="btn" href="logout.php">Log out</a>
        <?php else: ?>
          <a class="btn" href="login.php">Log in</a>
        <?php endif; ?>
        <button class="hamburger" aria-label="Toggle menu" onclick="toggleMenu()">☰</button>
      </div>
    </div>

    <div id="mobilePanel" class="mobile-panel" aria-label="Mobile menu">
      <div class="mobile-inner">
        <div class="mobile-search">🔍
          <input type="text" id="mNavSearch" placeholder="Search services..." onkeydown="if(event.key==='Enter') dashSearch(true)" style="border:0;outline:0;width:100%;background:transparent">
        </div>
        <div class="mobile-links">
          <a class="nav-btn" href="user_dashboard.php#services" onclick="toggleMenu()">Services</a>
          <a class="nav-btn" href="user_dashboard.php#pricing" onclick="toggleMenu()">Pricing</a>
          <a class="nav-btn" href="booking_success.php" onclick="toggleMenu()">Booking</a>
          <a class="nav-btn" href="careers.php" onclick="toggleMenu()">Careers</a>
          <a class="nav-btn" href="contact_chat.php" onclick="toggleMenu()">Messages</a>
          <a class="nav-btn" href="user_profile.php" onclick="toggleMenu()">Profile</a>
        </div>
        <div class="mobile-actions">
          <button class="btn btn-primary" onclick="location.href='book.php'">Book Now</button>
          <?php if ($isAuth): ?><a class="btn" href="logout.php">Log out</a><?php else: ?><a class="btn" href="login.php">Log in</a><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- ===== Notifications ===== -->
  <?php if($ok): ?>
    <div class="notif ok">✅ <?= h($ok) ?></div>
  <?php endif; ?>
  <?php if($errors): ?>
    <div class="notif err">
      <strong>Fix the following:</strong>
      <ul style="margin:6px 0 0 18px">
        <?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- ===== Content ===== -->
  <main class="wrap">
    <!-- Header card -->
    <div class="card header-card" style="margin-bottom:12px">
      <img id="avatarPreviewTop" class="avatar" src="<?= h($avatarUrl) ?>" alt="Avatar">
      <div>
        <div class="title" style="font-size:20px"><?= h($user['name'] ?: 'User') ?></div>
        <div class="sub"><?= h($user['email']) ?></div>
        <div style="margin-top:6px"><span class="badge"><?= h($roleBadge) ?></span></div>
      </div>
    </div>

    <div class="grid">
      <!-- Left sidebar -->
      <aside class="side">
        <div class="card">
          <div class="title" style="margin:0 0 8px">Profile Menu</div>
          <div style="display:grid;gap:6px">
            <a href="#account" class="btn-secondary" style="text-align:left">👤 Account</a>
            <a href="#homeinfo" class="btn-secondary" style="text-align:left">🏠 Home details</a>
            <a href="#security" class="btn-secondary" style="text-align:left">🔒 Security</a>
          </div>
        </div>
        <div class="card">
          <div class="title" style="margin:0 0 8px">Tips</div>
          <div class="note">Keep your address precise for better travel-fee estimates.</div>
        </div>
      </aside>

      <!-- Right content -->
      <section>
        <div class="card section" id="account">
          <h2 class="title">Account</h2>
          <p class="sub">Update your personal details and avatar</p>
          <div class="hr"></div>

          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_details">

            <div class="avatar-row">
              <img id="avatarPreview" class="avatar" src="<?= h($avatarUrl) ?>" alt="Avatar">
              <div>
                <label class="label">Profile photo</label>
                <input class="file" type="file" name="avatar" accept="image/*" onchange="previewAvatar(this)">
                <div class="note">JPG/PNG/WebP • max 2MB</div>
                <label class="checkline"><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</label>
              </div>
            </div>

            <div class="hr"></div>

            <div class="form-grid">
              <div>
                <label class="label">Full name</label>
                <input class="input" name="name" value="<?= h($user['name']) ?>" required>
              </div>
              <div>
                <label class="label">Email</label>
                <input class="input" type="email" name="email" value="<?= h($user['email']) ?>" required>
              </div>
              <div>
                <label class="label">Phone</label>
                <input class="input" name="phone" placeholder="+60…" value="<?= h($user['phone']) ?>">
              </div>
              <div>
                <label class="label">Address (primary)</label>
                <input class="input" name="address" placeholder="Street, city, postcode" value="<?= h($user['address']) ?>">
                <div class="note">Need more than one? <a href="user_addresses.php">Manage multiple addresses</a></div>
              </div>
            </div>

            <div class="actions-row">
              <a class="btn-secondary" href="user_dashboard.php">Cancel</a>
              <button class="btn-cta" type="submit">Save changes</button>
            </div>
          </form>
        </div>

        <div class="card section" id="homeinfo" style="margin-top:12px">
          <h2 class="title">Home details</h2>
          <p class="sub">These help us estimate time & travel fees</p>
          <div class="hr"></div>

          <form method="post" enctype="multipart/form-data" onsubmit="return submitHomeWithAccount()">
            <!-- We reuse the same action as account for atomic save -->
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_details">
            <!-- Hidden fields for unchanged account values so both sections save gracefully -->
            <input type="hidden" name="name" value="<?= h($user['name']) ?>">
            <input type="hidden" name="email" value="<?= h($user['email']) ?>">
            <input type="hidden" name="phone" value="<?= h($user['phone']) ?>">
            <input type="hidden" name="address" value="<?= h($user['address']) ?>">

            <div class="form-grid">
              <div>
                <label class="label">Home size (sq ft)</label>
                <input class="input" type="number" min="0" step="1" name="home_sqft" placeholder="e.g. 1200" value="<?= h((string)($user['home_sqft'] ?? '')) ?>">
              </div>
              <div>
                <label class="label">Home type</label>
                <select class="input" name="home_type">
                  <option value="">Select type…</option>
                  <?php foreach($HOME_TYPES as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= ($user['home_type']===$opt)?'selected':'' ?>><?= h($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="actions-row">
              <button class="btn-cta" type="submit">Save home details</button>
            </div>
          </form>
        </div>

        <div class="card section" id="security" style="margin-top:12px">
          <h2 class="title">Security</h2>
          <p class="sub">Change your password</p>
          <div class="hr"></div>

          <form method="post" onsubmit="return validatePasswordMatch()">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="change_password">

            <label class="label">Current password</label>
            <input class="input" type="password" name="current_password" required>

            <div class="form-grid">
              <div>
                <label class="label">New password</label>
                <input class="input" id="newpw" type="password" name="new_password" required
                       minlength="8"
                       pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$"
                       title="At least 8 chars, include uppercase, lowercase, number, and special character."
                       oninput="updateStrength(this.value)">
                <div class="strength" style="margin-top:6px"><div id="bar"></div></div>
                <div id="hint" class="note" style="margin-top:6px">Use 8+ chars with Aa, 0-9, and a symbol.</div>
              </div>
              <div>
                <label class="label">Confirm new password</label>
                <input class="input" id="confirmpw" type="password" name="confirm_password" required>
                <div id="matchnote" class="note" style="margin-top:6px"></div>
              </div>
            </div>

            <div class="actions-row">
              <button class="btn-cta" type="submit">Update password</button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </main>

  <!-- ===== Footer ===== -->
  <footer class="footer">
    <div class="footgrid">
      <div>
        <div style="font-weight:800;font-size:18px;margin-bottom:8px">NeinMaid</div>
        <div style="color:#d1d5db">Professional maid & cleaning services in Penang.</div>
        <div style="margin-top:10px;color:#d1d5db">Currently serving Penang only.</div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:8px">Company</div>
        <div><a href="about.php">About</a></div>
        <div><a href="careers.php">Careers</a></div>
        <div><a href="contact_chat.php">Contact</a></div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:8px">Services</div>
        <div><a href="user_dashboard.php#services">House Cleaning</a></div>
        <div><a href="user_dashboard.php#services">Deep Cleaning</a></div>
        <div><a href="user_dashboard.php#services">Office Cleaning</a></div>
        <div><a href="user_dashboard.php#services">Move In/Out</a></div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:8px">Help</div>
        <div><a href="contact_chat.php">Support</a></div>
        <div><a href="faq.php">FAQs</a></div>
      </div>
    </div>
    <div class="copyright">
      <div>© <?= date('Y') ?> NeinMaid • All rights reserved</div>
      <div>Made with ❤️ in Penang</div>
    </div>
  </footer>

  <script>
    // Navbar behavior + dashboard search redirect
    const header = document.getElementById('siteHeader');
    const mobilePanel = document.getElementById('mobilePanel');
    function toggleMenu(){ mobilePanel.classList.toggle('open'); }
    window.addEventListener('scroll', () => {
      if (window.scrollY > 4) header.classList.add('scrolled');
      else header.classList.remove('scrolled');
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 900) mobilePanel.classList.remove('open');
    });
    function dashSearch(isMobile=false){
      const q = (isMobile ? document.getElementById('mNavSearch') : document.getElementById('navSearch')).value.trim();
      const url = 'user_dashboard.php' + (q ? ('?q='+encodeURIComponent(q)) : '') + '#services';
      window.location.href = url;
    }

    // Avatar live preview (sync header & form)
    function previewAvatar(input){
      if(!input.files || !input.files[0]) return;
      const url = URL.createObjectURL(input.files[0]);
      document.getElementById('avatarPreview').src = url;
      document.getElementById('avatarPreviewTop').src = url;
    }

    // Home detail quick form : ensure account values are set (already mirrored by hidden fields)
    function submitHomeWithAccount(){ return true; }

    // Password strength meter
    function scorePassword(pw){
      let score = 0;
      if(!pw) return 0;
      const variations = {
        digits: /\d/.test(pw),
        lower: /[a-z]/.test(pw),
        upper: /[A-Z]/.test(pw),
        special: /[@$!%*?&_]/.test(pw)
      };
      let variationCount = 0;
      for (const k in variations){ variationCount += variations[k] ? 1 : 0; }
      score += variationCount * 10;
      score += Math.min(20, pw.length * 2);
      return Math.min(100, score);
    }
    function updateStrength(val){
      const bar = document.getElementById('bar');
      const hint = document.getElementById('hint');
      const s = scorePassword(val);
      bar.style.width = s + '%';
      bar.className = '';
      if (s < 40){ bar.classList.add('weak'); hint.textContent = 'Weak — add length & symbols.'; }
      else if (s < 70){ bar.classList.add('okok'); hint.textContent = 'Okay — try more length & mix.'; }
      else { bar.classList.add('good'); hint.textContent = 'Strong password 👍'; }
      checkMatch();
    }
    function checkMatch(){
      const a = document.getElementById('newpw').value;
      const b = document.getElementById('confirmpw').value;
      const note = document.getElementById('matchnote');
      if(!b){ note.textContent=''; return true; }
      if(a===b){ note.textContent='Passwords match ✓'; note.style.color='#065f46'; return true; }
      note.textContent='Passwords do not match'; note.style.color='#9f1239'; return false;
    }
    function validatePasswordMatch(){ return checkMatch(); }
    document.getElementById('confirmpw')?.addEventListener('input', checkMatch);
  </script>
</body>
</html>
