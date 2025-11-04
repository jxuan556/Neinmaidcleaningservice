<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

$AVATAR_DIR = __DIR__ . "/uploads/avatars";
$AVATAR_URL = "uploads/avatars";
$MAX_AVATAR_BYTES = 2 * 1024 * 1024;
$ALLOWED_MIME = ['image/jpeg','image/png','image/webp'];

function clean($s){ return trim(filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS)); }
function csrf_get(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
function ensure_dir($p){ if(!is_dir($p)) @mkdir($p,0775,true); }

$uid  = (int)$_SESSION['user_id'];
$isAuth = true;
$name = $_SESSION['name'] ?? "Guest";

/* Pull user (includes address, home_sqft, home_type) */
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

/* Allowed types (single-select) — includes Condominium */
$HOME_TYPES = ['Apartment','Condominium','Condo','Terrace','Semi-D','Bungalow','Shoplot','Office','Other'];

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_check($_POST['csrf'] ?? '')) {
    $errors[]="Security token invalid. Please reload.";
  } else {
    $act = $_POST['action'] ?? '';

    if($act==='update_details'){
      $nameIn      = clean($_POST['name'] ?? '');
      $email     = clean($_POST['email'] ?? '');
      $phone     = clean($_POST['phone'] ?? '');
      $address   = clean($_POST['address'] ?? '');
      $home_sqft = ($_POST['home_sqft']!=='') ? max(0,(int)$_POST['home_sqft']) : null;
      $home_type = clean($_POST['home_type'] ?? '');

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

          $ok="Profile updated.";

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
          $ok="Password changed successfully.";
        } catch(Throwable $e){
          $errors[]="Database error: ".$e->getMessage();
        }
      }
    }
  }
}

$csrf = csrf_get();
$avatarUrl = $user['avatar'] ?: 'https://api.dicebear.com/8.x/initials/svg?seed='.urlencode($user['name'] ?: 'U');
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

    /* ===== Navbar (same theme as dashboard) ===== */
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

    /* ===== Page styles ===== */
    .wrap{max-width:1100px;margin:18px auto;padding:0 12px}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:900px){ .grid-2{grid-template-columns:1fr} }
    .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px}
    .title{margin:0;font-weight:800}
    .sub{color:#6b7280;margin:4px 0 0}
    .center{text-align:center}
    .hr{border:0;border-top:1px dashed var(--line);margin:10px 0 12px}

    .label{font-weight:600;display:block;margin-bottom:6px}
    .input{width:100%;border:1px solid var(--line);border-radius:12px;padding:10px;font:inherit;background:#fff}
    .input:focus{outline:none;border-color:#cbd5e1;box-shadow:0 0 0 3px rgba(2,132,199,.08)}
    .mt-8{margin-top:8px}.mt-10{margin-top:10px}

    .actions-row{display:flex;justify-content:flex-end;gap:8px;margin-top:12px;flex-wrap:wrap}
    .btn-cta{background:var(--brand);color:#fff;border:1px solid var(--brand);border-radius:10px;padding:10px 14px;cursor:pointer}
    .btn-secondary{background:#fff;border:1px solid var(--line);padding:10px 14px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}

    .notification{max-width:1100px;margin:10px auto;padding:10px 12px;border-radius:12px;border:1px solid}
    .notification.success{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .notification.error{background:#fff1f2;border-color:#fecdd3;color:#9f1239}

    .avatar-row{display:flex;gap:12px;align-items:center}
    .avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line)}
    .field-note{color:#6b7280;font-size:12px}
    .checkline{font-size:14px;color:#0f172a}

    .footer-links{max-width:1100px;margin:18px auto;padding:0 12px;display:flex;gap:10px;flex-wrap:wrap;color:#6b7280}
    .footer-links a{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 12px}
  </style>
</head>
<body>

  <!-- ===== Navbar ===== -->
  <header id="siteHeader" class="header">
    <div class="nav-wrap" aria-label="Primary">
      <!-- Left: brand -->
      <div class="brand">
        <img src="maid.png" alt="NeinMaid logo">
        <div class="brand-name">NeinMaid</div>
      </div>

      <!-- Center: links + search -->
      <div class="nav-center">
        <a class="nav-link" href="user_dashboard.php#services">Services</a>
        <a class="nav-link" href="user_dashboard.php#pricing">Pricing</a>
        <a class="nav-link" href="booking_success.php">Booking</a>
        <a class="nav-link" href="careers.php">Careers</a>
        <a class="nav-link" href="contact_chat.php">Messages</a>
        <a class="nav-link" href="user_profile.php">Profile</a>
        <div class="nav-search" role="search">
          <button class="search-btn" aria-label="Search" onclick="dashSearch()">🔍</button>
          <input type="text" id="navSearch" placeholder="Search services..."
                 onkeydown="if(event.key==='Enter') dashSearch()" />
        </div>
      </div>

      <!-- Right: actions -->
      <div class="nav-right">
        <button class="btn btn-primary" onclick="location.href='book.php'">Book</button>
        <?php if ($isAuth): ?>
          <span class="hi">Hi, <?= htmlspecialchars($name) ?></span>
          <a class="btn" href="logout.php">Log out</a>
        <?php else: ?>
          <a class="btn" href="login.php">Log in</a>
        <?php endif; ?>
        <button class="hamburger" aria-label="Toggle menu" onclick="toggleMenu()">☰</button>
      </div>
    </div>

    <!-- Mobile panel -->
    <div id="mobilePanel" class="mobile-panel" aria-label="Mobile menu">
      <div class="mobile-inner">
        <div class="mobile-search">
          🔍
          <input type="text" id="mNavSearch" placeholder="Search services..."
                 onkeydown="if(event.key==='Enter') dashSearch(true)"
                 style="border:0;outline:0;width:100%;background:transparent">
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
          <?php if ($isAuth): ?>
            <a class="btn" href="logout.php">Log out</a>
          <?php else: ?>
            <a class="btn" href="login.php">Log in</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- ===== Notifications ===== -->
  <?php if($ok): ?>
    <div class="notification success"><?= htmlspecialchars($ok) ?></div>
  <?php endif; ?>
  <?php if($errors): ?>
    <div class="notification error">
      <strong>Fix the following:</strong>
      <ul style="margin:6px 0 0 18px">
        <?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- ===== Content ===== -->
  <main class="wrap">
    <div class="grid-2">
      <!-- Details -->
      <div class="card">
        <h2 class="title center" style="margin-bottom:10px">My Profile</h2>
        <p class="sub center">Update your details & home info</p>
        <hr class="hr">

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="update_details">

          <div class="avatar-row">
            <img id="avatarPreview" class="avatar" src="<?= htmlspecialchars($avatarUrl) ?>" alt="Avatar">
            <div class="avatar-actions">
              <label class="label">Profile photo</label>
              <input type="file" name="avatar" accept="image/*" onchange="previewAvatar(this)">
              <div class="field-note">JPG/PNG/WebP • max 2MB</div>
              <label class="checkline" style="margin-top:8px">
                <input type="checkbox" name="remove_avatar" value="1"> Remove current photo
              </label>
            </div>
          </div>

          <div class="grid-2 mt-10">
            <div>
              <label class="label">Full name</label>
              <input class="input" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>
            <div>
              <label class="label">Email</label>
              <input class="input" type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
          </div>

          <div class="grid-2 mt-10">
            <div>
              <label class="label">Phone</label>
              <input class="input" name="phone" placeholder="+60…" value="<?= htmlspecialchars($user['phone']) ?>">
            </div>
            <div>
              <label class="label">Address (primary)</label>
              <input class="input" name="address" placeholder="Street, city, postcode" value="<?= htmlspecialchars($user['address']) ?>">
              <div class="field-note">Need more than one? <a href="user_addresses.php">Manage multiple addresses</a></div>
            </div>
          </div>

          <div class="grid-2 mt-10">
            <div>
              <label class="label">Home size (sq ft)</label>
              <input class="input" type="number" min="0" step="1" name="home_sqft" placeholder="e.g. 1200"
                     value="<?= htmlspecialchars((string)($user['home_sqft'] ?? '')) ?>">
            </div>
            <div>
              <label class="label">Home type</label>
              <select class="input" name="home_type">
                <option value="">Select type…</option>
                <?php foreach($HOME_TYPES as $opt): ?>
                  <option value="<?= htmlspecialchars($opt) ?>" <?= ($user['home_type']===$opt)?'selected':'' ?>>
                    <?= htmlspecialchars($opt) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="actions-row">
            <a class="btn-secondary" href="user_dashboard.php">Cancel</a>
            <button class="btn-cta" type="submit">Save changes</button>
          </div>
        </form>
      </div>

      <!-- Password -->
      <div class="card">
        <h3 class="title center" style="margin-bottom:10px">Change Password</h3>
        <p class="sub center">Keep your account secure</p>
        <hr class="hr">

        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="change_password">

          <label class="label mt-8">Current password</label>
          <input class="input" type="password" name="current_password" required>

          <label class="label mt-8">New password</label>
          <input class="input" type="password" name="new_password" required
                 minlength="8"
                 pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$"
                 title="At least 8 chars, include uppercase, lowercase, number, and special character.">

          <label class="label mt-8">Confirm new password</label>
          <input class="input" type="password" name="confirm_password" required>

          <div class="actions-row">
            <button class="btn-cta" type="submit">Update password</button>
          </div>
        </form>
      </div>
    </div>
  </main>

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

    function previewAvatar(input){
      if(!input.files || !input.files[0]) return;
      document.getElementById('avatarPreview').src = URL.createObjectURL(input.files[0]);
    }
  </script>
</body>
</html>
