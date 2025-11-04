<?php
// create_worker_account.php
session_start();

/* ===== PHP ERROR / JSON SAFETY ===== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/* ===== DB CONNECTION ===== */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "maid_system";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset('utf8mb4');

/* ===== Helpers ===== */
function is_ajax() {
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
  if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
  if ((isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) return true;
  return false;
}

$msg = ""; $type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  try {
    $name   = trim($_POST['name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $pass1  = $_POST['password'] ?? '';
    $pass2  = $_POST['password_confirm'] ?? '';
    $agreed = isset($_POST['agree']);

    $phone        = trim($_POST['phone'] ?? '');
    $whatsapp     = trim($_POST['whatsapp'] ?? '');
    $nationality  = trim($_POST['nationality'] ?? '');
    $ic_no        = trim($_POST['ic_no'] ?? '');
    $dob          = trim($_POST['dob'] ?? '');
    $gender       = trim($_POST['gender'] ?? '');
    $address      = trim($_POST['address'] ?? '');

    $areas        = $_POST['areas'] ?? [];
    $availability = $_POST['availability'] ?? [];

    $hours_from_h = trim($_POST['hours_from_h'] ?? '');
    $hours_to_h   = trim($_POST['hours_to_h'] ?? '');

    $exp_years    = trim($_POST['exp_years'] ?? '');
    $exp_months   = trim($_POST['exp_months'] ?? '');

    $specialties  = $_POST['specialties'] ?? [];
    $languages    = $_POST['languages'] ?? [];
    $has_tools    = isset($_POST['has_tools']) ? 1 : 0;
    $has_vehicle  = isset($_POST['has_vehicle']) ? 1 : 0;

    $bank_name    = trim($_POST['bank_name'] ?? '');
    $bank_acc     = trim($_POST['bank_acc'] ?? '');
    $emer_name    = trim($_POST['emer_name'] ?? '');
    $emer_phone   = trim($_POST['emer_phone'] ?? '');

    $hours_from = ($hours_from_h !== '') ? sprintf('%02d:00', (int)$hours_from_h) : '';
    $hours_to   = ($hours_to_h   !== '') ? sprintf('%02d:00', (int)$hours_to_h)   : '';

    $errors = [];
    if ($name === '' || $email === '' || $pass1 === '' || $pass2 === '' || $phone === '') {
      $errors['form'] = "Please fill in all required fields.";
    }
    if (!preg_match('/^[A-Za-z ]{3,50}$/', $name)) $errors['name'] = "Name must be 3–50 letters/spaces.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = "Invalid email address.";
    if ($pass1 !== $pass2) $errors['password_confirm'] = "Passwords do not match.";
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$/', $pass1)) $errors['password'] = "Password needs A–Z, a–z, number, symbol (min 8).";
    if (!$agreed) $errors['agree'] = "You must agree to the Terms.";
    if ($ic_no !== '' && !preg_match('/^([0-9]{12}|[A-Za-z0-9]{6,20})$/', $ic_no)) $errors['ic_no'] = "IC 12 digits or Passport 6–20 alphanumeric.";
    if ($phone === '' || !preg_match('/^[0-9]{9,15}$/', $phone)) $errors['phone'] = "Phone must be 9–15 digits.";
    if (($hours_from && !preg_match('/^\d{2}:00$/', $hours_from)) || ($hours_to && !preg_match('/^\d{2}:00$/', $hours_to))) {
      $errors['hours_from'] = "Time must be whole hours (e.g. 08:00)";
      $errors['hours_to']   = "Time must be whole hours (e.g. 18:00)";
    }

    $exp_years_i  = ($exp_years === '') ? 0 : (int)$exp_years;
    $exp_months_i = ($exp_months === '') ? 0 : (int)$exp_months;
    if ($exp_years_i < 0 || $exp_years_i > 60) $errors['exp_years'] = "Years must be between 0 and 60.";
    if ($exp_months_i < 0 || $exp_months_i > 11) $errors['exp_months'] = "Months must be 0–11.";
    $exp_total_months = $exp_years_i * 12 + $exp_months_i;

    if (empty($errors)) {
      $chk = $conn->prepare("SELECT 1 FROM worker_profiles WHERE email=? LIMIT 1");
      $chk->bind_param("s", $email);
      $chk->execute();
      $dup = $chk->get_result()->num_rows > 0;
      $chk->close();
      if ($dup) $errors['email'] = "Email is already in use.";
    }

    if (!empty($errors)) {
      if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'errors'=>$errors]); exit;
      } else {
        $msg = implode(' ', array_values($errors)); $type="error";
      }
    } else {
      $hash = password_hash($pass1, PASSWORD_DEFAULT);

      $dob        = ($dob        === '') ? null : $dob;
      $hours_from = ($hours_from === '') ? null : $hours_from;
      $hours_to   = ($hours_to   === '') ? null : $hours_to;

      $whatsapp    = ($whatsapp    === '') ? null : $whatsapp;
      $nationality = ($nationality === '') ? null : $nationality;
      $ic_no       = ($ic_no       === '') ? null : $ic_no;
      $gender      = ($gender      === '') ? null : $gender;
      $address     = ($address     === '') ? null : $address;
      $bank_name   = ($bank_name   === '') ? null : $bank_name;
      $bank_acc    = ($bank_acc    === '') ? null : $bank_acc;
      $emer_name   = ($emer_name   === '') ? null : $emer_name;
      $emer_phone  = ($emer_phone  === '') ? null : $emer_phone;

      $areas_csv        = ($areas)        ? implode(',', array_map('trim', $areas)) : null;
      $availability_csv = ($availability) ? implode(',', array_map('trim', $availability)) : null;
      $specialties_csv  = ($specialties)  ? implode(',', array_map('trim', $specialties))  : null;
      $languages_csv    = ($languages)    ? implode(',', array_map('trim', $languages))    : null;

      $exp_years_store  = $exp_total_months;

      $sql = "INSERT INTO worker_profiles
        (name, email, password, phone, whatsapp, nationality, ic_no, dob, gender, address,
         areas, availability_days, hours_from, hours_to, exp_years, specialties, languages,
         has_tools, has_vehicle, bank_name, bank_acc, emer_name, emer_phone, approval_status, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

      $stmt = $conn->prepare($sql);
      $types = "ssssssssssssssissiissssss";
      $approval_status = 'pending';
      $status = 'Disabled';

      $stmt->bind_param(
        $types,
        $name, $email, $hash, $phone, $whatsapp, $nationality, $ic_no, $dob, $gender, $address,
        $areas_csv, $availability_csv, $hours_from, $hours_to,
        $exp_years_store, $specialties_csv, $languages_csv,
        $has_tools, $has_vehicle, $bank_name, $bank_acc, $emer_name, $emer_phone,
        $approval_status, $status
      );
      $stmt->execute();
      $stmt->close();

      if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true, 'redirect'=>'login.php']); exit;
      } else {
        $_SESSION['success'] = "🎉 Worker profile created! Your account is pending approval.";
        header("Location: login.php"); exit();
      }
    }
  } catch (Throwable $e) {
    if (is_ajax()) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false, 'errors'=>['form' => 'Server error: '.$e->getMessage()]]); exit;
    }
    die('Server error: '.$e->getMessage());
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Register as Worker – NeinMaid</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root{
    --bg: linear-gradient(180deg,#ffffff 0%, #fff5fa 55%, #fde7f1 100%);
    --card:#ffffff;
    --ink:#111827;
    --muted:#6b7280;
    --line:#e5e7eb;
    --pink:#ec4899; --pink-2:#db2777;
    --ok:#065f46; --ok-bg:#ecfdf5; --ok-br:#6ee7b7;
    --er:#991b1b; --er-bg:#fff1f2; --er-br:#fda4af;
    --radius:18px; --radius-sm:12px;
    --shadow:0 12px 28px rgba(0,0,0,.08);
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0; background:var(--bg);
    font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    color:var(--ink); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
  }

  /* CENTER EVERYTHING */
  .page{
    min-height:100svh; /* supports mobile safe viewport */
    display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    gap:16px; padding:16px;
  }

  .container-slim{max-width:900px;width:100%;}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px}

  .brand{display:flex;align-items:center;gap:10px;color:var(--pink);font-weight:800;letter-spacing:.08em;text-transform:uppercase}
  .brand img{width:28px;height:28px}
  .brand .word{font-size:13px}
  .title{margin:10px 0 2px;font-size:28px;font-weight:900;color:var(--ink); text-align:left}
  .sub{margin:0 0 14px;color:var(--muted); text-align:left}

  .notification{border-radius:12px;padding:10px 12px;margin-bottom:12px;font-size:14px}
  .notification.error{background:var(--er-bg);border:1px solid var(--er-br);color:var(--er)}
  .notification.success{background:var(--ok-bg);border:1px solid var(--ok-br);color:var(--ok)}

  .input, .textarea, select{
    width:100%; padding:12px 12px; border:1px solid var(--line); border-radius:12px;
    background:#fafafa; color:var(--ink); font-size:15px; transition:.2s;
  }
  .input:focus, .textarea:focus, select:focus{outline:none;border-color:var(--pink);background:#fff}
  .textarea{min-height:110px;resize:vertical}

  .section-title{font-weight:800;margin:6px 0 10px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  .mt-8{margin-top:8px} .mt-10{margin-top:10px} .mt-14{margin-top:14px}

  .wizard-steps{display:flex;gap:8px;margin:10px 0 18px; justify-content:flex-start}
  .dot{width:10px;height:10px;border-radius:999px;background:#e5e7eb}
  .dot.active{background:var(--pink)}
  .dot.done{background:#9ca3af}
  .step{display:none}
  .step.active{display:block}
  .step-actions{display:flex;justify-content:space-between;gap:8px;margin-top:16px}

  .btn-nav{
    background:linear-gradient(90deg,var(--pink),var(--pink-2)); color:#fff;
    border:0;border-radius:12px;padding:10px 14px;cursor:pointer;font-weight:800
  }
  .btn-back{
    background:#fff;color:#111;border:1px solid var(--line);border-radius:12px;padding:10px 14px;cursor:pointer;font-weight:700
  }
  .btn-secondary{
    display:inline-block;text-decoration:none;
    background:#fff;color:#111;border:1px solid var(--line);
    border-radius:12px;padding:10px 14px;font-weight:700
  }

  .chips{display:flex;gap:8px;flex-wrap:wrap}
  .chip{display:inline-flex;gap:6px;align-items:center;padding:8px 10px;border:1px solid var(--line);border-radius:999px;background:#fff;cursor:pointer}
  .chip input{accent-color:var(--pink)}

  .field-note{font-size:13px;color:#9ca3af;margin-top:6px;min-height:18px}
  .field-ok{color:#15803d}
  .field-error{color:#b91c1c}
  .error-input{border-color:#fda4af !important;background:#fff5f7 !important}

  .summary{background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:8px}
  .summary .row{display:flex;justify-content:space-between;margin:6px 0;gap:12px}
  .checkline{display:flex;align-items:center;gap:10px}
  .checkrow{display:flex;align-items:flex-start;gap:10px}
  .link{color:var(--pink);text-decoration:none} .link:hover{text-decoration:underline}
  .center{text-align:center}

  .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);padding:20px;z-index:50}
  .modal-box{max-width:720px;margin:30px auto;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:16px;max-height:85vh;overflow:auto}
  .modal-head{display:flex;justify-content:space-between;align-items:center}
  .hr{height:1px;border:0;background:#e5e7eb;margin:10px 0}

  @media (max-width:900px){
    .grid-3{grid-template-columns:1fr}
    .grid-2{grid-template-columns:1fr}
  }
</style>
</head>
<body>
  <div class="page">
    <div class="container-slim">
      <div class="card">
        <?php if($msg): ?>
          <div class="notification <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="brand brand--left">
          <img class="brand-mark" src="maid.png" alt="NeinMaid">
          <span class="word">NEINMAID</span>
        </div>

        <h1 class="title">Register as Worker</h1>
        <p class="sub">Create your cleaner account to receive assignments.</p>

        <div class="wizard-steps" id="wizDots">
          <div class="dot active"></div>
          <div class="dot"></div>
          <div class="dot"></div>
          <div class="dot"></div>
          <div class="dot"></div>
        </div>

        <form method="post" action="" id="workerForm" novalidate autocomplete="off">
          <!-- STEP 1 -->
          <div class="step active" data-step="1">
            <div class="section-title">Account</div>
            <div class="grid-2">
              <div>
                <input class="input" id="name" type="text" name="name" placeholder="Full name *" required autocomplete="off">
                <div id="nameNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="email" type="email" name="email" placeholder="Email address *" required autocomplete="off">
                <div id="emailNote" class="field-note"></div>
              </div>
            </div>

            <div class="grid-2 mt-10">
              <div>
                <input class="input" id="password" type="password" name="password" placeholder="Password *" required
                  minlength="8"
                  pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$"
                  title="At least 8 chars with uppercase, lowercase, number, and special character.">
                <div id="passNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="password_confirm" type="password" name="password_confirm" placeholder="Confirm password *" required>
                <div id="confirmNote" class="field-note"></div>
              </div>
            </div>

            <div class="step-actions">
              <span></span>
              <button class="btn-nav" type="button" id="next1">Next</button>
            </div>
          </div>

          <!-- STEP 2 -->
          <div class="step" data-step="2">
            <div class="section-title">Contact & Identity</div>
            <div class="grid-3">
              <div>
                <input class="input" id="phone" name="phone" placeholder="Phone (required for jobs) *" required>
                <div id="phoneNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="whatsapp" name="whatsapp" placeholder="WhatsApp (optional)">
                <div id="waNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="nationality" name="nationality" placeholder="Nationality">
                <div id="natNote" class="field-note"></div>
              </div>
            </div>

            <div class="grid-3 mt-10">
              <div>
                <input class="input" id="ic_no" name="ic_no" placeholder="IC (12 digits) or Passport (6–20 alphanumeric)">
                <div id="icNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="dob" name="dob" type="date" placeholder="Date of Birth">
                <div id="dobNote" class="field-note"></div>
              </div>
              <div>
                <select id="gender" name="gender" class="input">
                  <option value="">Gender</option>
                  <option>Female</option><option>Male</option><option>Prefer not to say</option>
                </select>
                <div id="genderNote" class="field-note"></div>
              </div>
            </div>

            <div class="mt-10">
              <textarea class="input textarea" id="address" name="address" placeholder="Current Address"></textarea>
              <div id="addrNote" class="field-note"></div>
            </div>

            <div class="step-actions">
              <button class="btn-nav btn-back" type="button" data-back>Back</button>
              <button class="btn-nav" type="button" id="next2">Next</button>
            </div>
          </div>

          <!-- STEP 3 -->
          <div class="step" data-step="3">
            <div class="section-title">Work Preferences</div>
            <div class="grid-2">
              <div>
                <label class="label">Areas you can serve</label>
                <select class="input" id="areas" name="areas[]" multiple size="6">
                  <option>Georgetown</option><option>Jelutong</option><option>Tanjung Tokong</option><option>Air Itam</option>
                  <option>Gelugor</option><option>Bayan Lepas</option><option>Balik Pulau</option><option>Butterworth</option>
                  <option>Bukit Mertajam</option><option>Perai</option><option>Nibong Tebal</option><option>Seberang Jaya</option>
                </select>
                <div class="field-note">Hold Ctrl (Windows) / Cmd (Mac) to select multiple.</div>
                <div id="areasNote" class="field-note"></div>
              </div>

              <div>
                <label class="label">Availability</label>
                <div class="chips" id="daysWrap">
                  <?php foreach (["Mon","Tue","Wed","Thu","Fri","Sat","Sun"] as $d): ?>
                    <label class="chip"><input type="checkbox" name="availability[]" value="<?= $d ?>" class="dayChk"> <?= $d ?></label>
                  <?php endforeach; ?>
                </div>

                <div class="grid-2 mt-8">
                  <select class="input" id="hours_from_h" name="hours_from_h">
                    <option value="">From (Hour)</option>
                    <?php for($h=0;$h<24;$h++): ?>
                      <option value="<?= $h ?>"><?= sprintf('%02d:00', $h) ?></option>
                    <?php endfor; ?>
                  </select>

                  <select class="input" id="hours_to_h" name="hours_to_h">
                    <option value="">To (Hour)</option>
                    <?php for($h=0;$h<24;$h++): ?>
                      <option value="<?= $h ?>"><?= sprintf('%02d:00', $h) ?></option>
                    <?php endfor; ?>
                  </select>
                </div>

                <div id="availNote" class="field-note"></div>
              </div>
            </div>

            <div class="grid-3 mt-10">
              <div class="grid-2">
                <div>
                  <input class="input" id="exp_years" name="exp_years" type="number" min="0" max="60" step="1" placeholder="Years of experience">
                </div>
                <div>
                  <input class="input" id="exp_months" name="exp_months" type="number" min="0" max="11" step="1" placeholder="Months (0–11)">
                </div>
                <div id="expNote" class="field-note" style="grid-column:1 / -1;"></div>
              </div>

              <div>
                <label class="label">Specialties</label>
                <div class="chips wrap" id="specWrap">
                  <?php foreach (["Standard","Deep Clean","Move In/Out","Office","Post-Reno","Carpet","Windows"] as $s): ?>
                    <label class="chip"><input type="checkbox" name="specialties[]" value="<?= $s ?>" class="specChk"> <?= $s ?></label>
                  <?php endforeach; ?>
                </div>
                <div id="specNote" class="field-note"></div>
              </div>

              <div>
                <label class="label">Languages</label>
                <div class="chips wrap" id="langWrap">
                  <?php foreach (["English","Malay","Mandarin","Tamil"] as $l): ?>
                    <label class="chip"><input type="checkbox" name="languages[]" value="<?= $l ?>" class="langChk"> <?= $l ?></label>
                  <?php endforeach; ?>
                </div>
                <div id="langNote" class="field-note"></div>
              </div>
            </div>

            <div class="grid-3 mt-10">
              <label class="checkline"><input type="checkbox" id="has_tools" name="has_tools"> I can bring basic tools</label>
              <label class="checkline"><input type="checkbox" id="has_vehicle" name="has_vehicle"> I have my own transport</label>
            </div>

            <div class="step-actions">
              <button class="btn-nav btn-back" type="button" data-back>Back</button>
              <button class="btn-nav" type="button" id="next3">Next</button>
            </div>
          </div>

          <!-- STEP 4 -->
          <div class="step" data-step="4">
            <div class="section-title">Payout & Emergency</div>
            <div class="grid-3">
              <div>
                <input class="input" id="bank_name" name="bank_name" placeholder="Bank name">
                <div id="bankNameNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="bank_acc" name="bank_acc" placeholder="Bank account number">
                <div id="bankNote" class="field-note"></div>
              </div>
              <div>
                <input class="input" id="emer_name" name="emer_name" placeholder="Emergency contact name">
                <div id="emerNameNote" class="field-note"></div>
              </div>
            </div>
            <div class="grid-3 mt-10">
              <div>
                <input class="input" id="emer_phone" name="emer_phone" placeholder="Emergency contact phone">
                <div id="emerNote" class="field-note"></div>
              </div>
            </div>

            <div class="step-actions">
              <button class="btn-nav btn-back" type="button" data-back>Back</button>
              <button class="btn-nav" type="button" id="next4">Next</button>
            </div>
          </div>

          <!-- STEP 5 -->
          <div class="step" data-step="5">
            <div class="section-title">Review & Terms</div>
            <div class="summary" id="reviewBox"></div>

            <div class="checkrow" style="margin-top:12px">
              <input id="agree" type="checkbox" name="agree" required>
              <label for="agree">I have read and agree to the
                <a href="#" class="link" onclick="openModal('termsModal');return false;">Terms & Privacy Policy</a>.
              </label>
            </div>

            <div class="step-actions">
              <button class="btn-nav btn-back" type="button" data-back>Back</button>
              <button class="btn-nav" type="submit">Create worker account</button>
            </div>
            <p class="field-note mt-10">By creating an account, you acknowledge our policies.</p>
          </div>
        </form>
      </div>

      <div class="center mt-14">
        <a href="login.php" class="btn-secondary" role="button">← Back to Login</a>
      </div>
    </div>
  </div>

  <!-- Terms & Privacy Modal -->
  <div id="termsModal" class="modal">
    <div class="modal-box">
      <div class="modal-head">
        <h3>Terms of Service & Privacy Policy</h3>
        <button class="btn-secondary" onclick="closeModal('termsModal')">Close ✖</button>
      </div>
      <hr class="hr">
      <h4>1) Eligibility</h4>
      <p>You must be 18+ and legally eligible to work in Malaysia.</p>
      <h4>2) Work & Conduct</h4>
      <p>Arrive on time and complete the scope safely and professionally.</p>
      <h4>3) Payments</h4>
      <p>Payouts are made to the bank details you provide.</p>
      <h4>4) Background & Verification</h4>
      <p>We may request identity verification.</p>
      <h4>5) Privacy</h4>
      <p>We collect your data to operate the service; we don’t sell it.</p>
      <h4>6) Safety</h4>
      <p>Report hazards or incidents immediately.</p>
      <h4>7) Changes</h4>
      <p>Policies may be updated over time.</p>
    </div>
  </div>

<script>
  function openModal(id){ const m=document.getElementById(id); if(m){ m.style.display='block'; } }
  function closeModal(id){ const m=document.getElementById(id); if(m){ m.style.display='none'; } }

  const ok = (id,msg)=>{ const n=document.getElementById(id); if(!n) return; n.textContent=msg; n.className="field-note field-ok"; }
  const err= (id,msg)=>{ const n=document.getElementById(id); if(!n) return; n.textContent=msg; n.className="field-note field-error"; }

  function valName(){ const v=nameEl().value.trim(); if(!/^[A-Za-z ]{3,50}$/.test(v)){ err('nameNote','❌ Only letters & spaces, 3–50 characters'); return false; } ok('nameNote','✔ Looks good'); return true; }
  function valEmail(){ const v=document.getElementById('email').value.trim(); if(!/^[^@]+@[^@]+\.[^@]+$/.test(v)){ err('emailNote','❌ Invalid email'); return false; } ok('emailNote','✔ Valid email'); return true; }
  function valPass(){ const v=document.getElementById('password').value; if(!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_]).{8,}$/.test(v)){ err('passNote','❌ Weak password'); return false; } ok('passNote','✔ Strong password'); return true; }
  function valConfirm(){ const p=document.getElementById('password').value, c=document.getElementById('password_confirm').value; if(!c || p!==c){ err('confirmNote','❌ Passwords do not match'); return false; } ok('confirmNote','✔ Match'); return true; }
  function valPhone(){ const v=document.getElementById('phone').value.trim(); if(!/^[0-9]{9,15}$/.test(v)){ err('phoneNote','❌ Phone must be 9–15 digits'); return false; } ok('phoneNote','✔ Valid'); return true; }
  function valWA(){ const e=document.getElementById('whatsapp'); if(!e) return true; const v=e.value.trim(); if(!v){ document.getElementById('waNote').textContent=''; return true; } if(!/^[0-9]{9,15}$/.test(v)){ err('waNote','❌ WhatsApp must be digits only'); return false; } ok('waNote','✔ Valid'); return true; }
  function valNationality(){ const v=document.getElementById('nationality').value.trim(); if(!v){ document.getElementById('natNote').textContent=''; return true; } if(!/^[A-Za-z ]{3,30}$/.test(v)){ err('natNote','❌ Letters & spaces only'); return false; } ok('natNote','✔ Valid'); return true; }
  function valIC(){ const v=document.getElementById('ic_no').value.trim(); if(!v){ document.getElementById('icNote').textContent=''; return true; } if(!/^([0-9]{12}|[A-Za-z0-9]{6,20})$/.test(v)){ err('icNote','❌ IC 12 digits, or Passport 6–20 alphanumeric'); return false; } ok('icNote','✔ Valid'); return true; }
  function valDOB(){ const el=document.getElementById('dob'); const n=document.getElementById('dobNote'); if(!el.value){ n.textContent=''; return true; } const d=new Date(el.value), now=new Date(); let age=now.getFullYear()-d.getFullYear(); const m=now.getMonth()-d.getMonth(); if(m<0 || (m===0 && now.getDate()<d.getDate())) age--; if(age<18){ err('dobNote','❌ Must be at least 18'); return false; } ok('dobNote','✔ Age OK'); return true; }
  function valGender(){ ok('genderNote',''); return true; }
  function valAddr(){ const v=document.getElementById('address').value.trim(); if(!v){ document.getElementById('addrNote').textContent=''; return true; } if(v.length<6){ err('addrNote','❌ Address seems too short'); return false; } ok('addrNote',''); return true; }
  function valAreas(){ const sel=[...document.getElementById('areas').selectedOptions].length; if(sel===0){ document.getElementById('areasNote').textContent=''; return true; } ok('areasNote',`✔ ${sel} area(s) selected`); return true; }
  function valAvail(){ const f=document.getElementById('hours_from_h').value, t=document.getElementById('hours_to_h').value; const timeOk = (!f && !t) || (/^\d+$/.test(f) && /^\d+$/.test(t)); if(!timeOk){ err('availNote','❌ Select whole hours only (e.g. 08:00)'); return false; } ok('availNote',''); return true; }
  function valExp(){ const y=document.getElementById('exp_years').value; const m=document.getElementById('exp_months').value; const yi=(y===''?0:+y), mi=(m===''?0:+m); if(yi<0 || yi>60){ err('expNote','❌ Years must be 0–60'); return false; } if(mi<0 || mi>11){ err('expNote','❌ Months must be 0–11'); return false; } ok('expNote', yi || mi ? `✔ ${yi}y ${mi}m` : ''); return true; }
  function valSpecs(){ const c=[...document.querySelectorAll('.specChk:checked')].length; if(c>0){ ok('specNote',`✔ ${c} specialty selected`); return true; } document.getElementById('specNote').textContent=''; return true; }
  function valLangs(){ const c=[...document.querySelectorAll('.langChk:checked')].length; if(c>0){ ok('langNote',`✔ ${c} language(s)`); return true; } document.getElementById('langNote').textContent=''; return true; }
  function valBankName(){ const v=document.getElementById('bank_name').value.trim(); if(!v){ document.getElementById('bankNameNote').textContent=''; return true; } if(!/^[A-Za-z ]{3,40}$/.test(v)){ err('bankNameNote','❌ Letters & spaces only'); return false; } ok('bankNameNote',''); return true; }
  function valBankAcc(){ const v=document.getElementById('bank_acc').value.trim(); if(!v){ document.getElementById('bankNote').textContent=''; return true; } if(!/^[0-9]{8,20}$/.test(v)){ err('bankNote','❌ 8–20 digits'); return false; } ok('bankNote',''); return true; }
  function valEmerName(){ const v=document.getElementById('emer_name').value.trim(); if(!v){ document.getElementById('emerNameNote').textContent=''; return true; } if(!/^[A-Za-z ]{3,50}$/.test(v)){ err('emerNameNote','❌ Letters & spaces only'); return false; } ok('emerNameNote',''); return true; }
  function valEmerPhone(){ const v=document.getElementById('emer_phone').value.trim(); if(!v){ document.getElementById('emerNote').textContent=''; return true; } if(!/^[0-9]{9,15}$/.test(v)){ err('emerNote','❌ 9–15 digits'); return false; } ok('emerNote',''); return true; }

  const nameEl = () => document.getElementById('name');

  (function(){
    const steps = Array.from(document.querySelectorAll('.step'));
    const dots  = Array.from(document.querySelectorAll('#wizDots .dot'));
    const form  = document.getElementById('workerForm');
    const LS_KEY = 'nm_worker_form';
    const LS_STEP= 'nm_worker_step';
    const EXCLUDE = new Set(['name','email','password','password_confirm','bank_acc']);

    function saveForm(){
      const data = {};
      Array.from(form.elements).forEach(el=>{
        if (!el.name || EXCLUDE.has(el.name)) return;
        if (el.type==='checkbox'){
          if (el.name.endsWith('[]')){
            if(!data[el.name]) data[el.name]=[];
            if(el.checked) data[el.name].push(el.value);
          } else {
            data[el.name]=el.checked?1:0;
          }
        } else if (el.type==='radio'){
          if(el.checked) data[el.name]=el.value;
        } else if (el.multiple){
          data[el.name]=Array.from(el.selectedOptions).map(o=>o.value);
        } else {
          data[el.name]=el.value;
        }
      });
      localStorage.setItem(LS_KEY, JSON.stringify(data));
    }

    function loadForm(){
      const raw = localStorage.getItem(LS_KEY);
      if(!raw) return;
      let data={}; try{ data=JSON.parse(raw)||{}; }catch(_){}
      delete data.name; delete data.email;
      Array.from(form.elements).forEach(el=>{
        if (!el.name || !(el.name in data)) return;
        const v = data[el.name];
        if (el.type==='checkbox'){
          if (el.name.endsWith('[]')){
            el.checked = Array.isArray(v) && v.includes(el.value);
          } else {
            el.checked = !!v;
          }
        } else if (el.type==='radio'){
          el.checked = (el.value==v);
        } else if (el.multiple){
          const set = new Set(Array.isArray(v)?v:[]);
          Array.from(el.options).forEach(o=>o.selected=set.has(o.value));
        } else { el.value = v; }
      });
    }

    function saveStep(i){ localStorage.setItem(LS_STEP, String(i)); }
    function loadStep(){ const v = parseInt(localStorage.getItem(LS_STEP)||'0',10); return isNaN(v)?0:Math.min(Math.max(v,0), steps.length-1); }

    function goto(i){
      steps.forEach((s,k)=>s.classList.toggle('active', k===i));
      dots.forEach((d,k)=>{
        d.classList.remove('active','done');
        if (k<i) d.classList.add('done'); else if (k===i) d.classList.add('active');
      });
      if (i===4) fillReview();
      saveStep(i);
      window.scrollTo({top:0, behavior:'smooth'});
    }

    function step1Valid(){ return valName() & valEmail() & valPass() & valConfirm(); }
    function step2Valid(){ return valPhone() & valWA() & valNationality() & valIC() & valDOB() & valGender() & valAddr(); }
    function step3Valid(){ return valAreas() & valAvail() & valExp() & valSpecs() & valLangs(); }
    function step4Valid(){ return valBankName() & valBankAcc() & valEmerName() & valEmerPhone(); }

    document.getElementById('next1').addEventListener('click', ()=>{ if (step1Valid()) goto(1); });
    document.getElementById('next2').addEventListener('click', ()=>{ if (step2Valid()) goto(2); });
    document.getElementById('next3').addEventListener('click', ()=>{ if (step3Valid()) goto(3); });
    document.getElementById('next4').addEventListener('click', ()=>{ if (step4Valid()) goto(4); });
    document.querySelectorAll('[data-back]').forEach(b=>b.addEventListener('click', ()=>{ const cur=parseInt(localStorage.getItem(LS_STEP)||'0',10); goto(Math.max(0,cur-1)); }));

    function valOrDash(v){ return v && (''+v).trim()!=='' ? v : '—'; }
    function listFrom(name){ return Array.from(document.querySelectorAll(`input[name="${name}[]"]:checked`)).map(el=>el.value).join(', ') || '—'; }
    function multiSelect(id){ return Array.from(document.getElementById(id).selectedOptions).map(o=>o.value).join(', ') || '—'; }

    function fillReview(){
      const box = document.getElementById('reviewBox');
      const rows = [
        ['Name', valOrDash(document.getElementById('name').value)],
        ['Email', valOrDash(document.getElementById('email').value)],
        ['Phone', valOrDash(document.getElementById('phone').value)],
        ['Nationality', valOrDash(document.getElementById('nationality').value)],
        ['IC/Passport', valOrDash(document.getElementById('ic_no').value)],
        ['Gender', valOrDash(document.getElementById('gender').value)],
        ['Areas', multiSelect('areas')],
        ['Availability', listFrom('availability')],
        ['Hours', (function(){ const f=document.getElementById('hours_from_h').value, t=document.getElementById('hours_to_h').value; if(!f && !t) return '—'; const pad=x=>String(x).padStart(2,'0')+':00'; return (f?pad(f):'—')+' – '+(t?pad(t):'—'); })()],
        ['Experience', (function(){ const y=document.getElementById('exp_years').value||0; const m=document.getElementById('exp_months').value||0; return (+y||+m)?`${y}y ${m}m`:'—'; })()],
        ['Specialties', listFrom('specialties')],
        ['Languages', listFrom('languages')],
        ['Tools/Vehicle', (document.getElementById('has_tools').checked?'Tools ':'')+(document.getElementById('has_vehicle').checked?'Vehicle':'') || '—'],
        ['Bank', valOrDash(document.getElementById('bank_name').value)+' '+(document.getElementById('bank_acc').value?'(entered)':'—')],
        ['Emergency', valOrDash(document.getElementById('emer_name').value)+' / '+valOrDash(document.getElementById('emer_phone').value)],
      ];
      box.innerHTML = rows.map(([a,b])=>`<div class="row"><span>${a}</span><strong>${b}</strong></div>`).join('');
    }

    form.addEventListener('input', saveForm);
    form.addEventListener('change', saveForm);
    loadForm();
    goto(loadStep());
    window.addEventListener('beforeunload', saveForm);
  })();

  (function(){
    const form = document.getElementById('workerForm');
    const LS_KEY = 'nm_worker_form', LS_STEP='nm_worker_step';

    function setFieldError(fieldId, message){
      const map = {
        name:'nameNote', email:'emailNote', password:'passNote', password_confirm:'confirmNote',
        phone:'phoneNote', whatsapp:'waNote', nationality:'natNote', ic_no:'icNote',
        dob:'dobNote', gender:'genderNote', address:'addrNote', areas:'areasNote',
        hours_from:'availNote', hours_to:'availNote', exp_years:'expNote', exp_months:'expNote',
        specialties:'specNote', languages:'langNote',
        bank_name:'bankNameNote', bank_acc:'bankNote', emer_name:'emerNameNote', emer_phone:'emerNote',
        agree:null, form:null
      };
      const noteId = map[fieldId] || null;
      if (noteId){
        const el = document.getElementById(noteId);
        if (el){ el.textContent = message; el.className = "field-note field-error"; }
        const input = document.getElementById(fieldId) || document.getElementById(fieldId+'_h');
        if (input) input.classList.add('error-input');
      } else {
        showBanner(message, 'error');
      }
    }
    function clearErrors(){
      document.querySelectorAll('.field-note').forEach(n=>{ n.textContent=''; n.className='field-note'; });
      document.querySelectorAll('.error-input').forEach(i=>i.classList.remove('error-input'));
      const banner = document.querySelector('.notification'); if (banner) banner.remove();
    }
    function showBanner(text, kind){
      const c = document.createElement('div');
      c.className = 'notification ' + (kind==='error' ? 'error' : 'success');
      c.textContent = text;
      document.querySelector('.card').prepend(c);
    }

    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      clearErrors();

      const fd = new FormData(form);
      fd.append('ajax','1');

      try{
        const res = await fetch(location.href, {
          method: 'POST',
          headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
          body: fd
        });

        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch (err) {
          console.error('Non-JSON response from server:', text);
          showBanner('Server error. See console for details.', 'error');
          return;
        }

        if (data.ok){
          localStorage.removeItem(LS_KEY);
          localStorage.removeItem(LS_STEP);
          showBanner('Profile created! Redirecting…','success');
          setTimeout(()=>{ window.location = data.redirect || 'login.php'; }, 600);
        } else {
          const errs = data.errors || {'form':'Please fix the highlighted fields.'};
          let first = '';
          Object.entries(errs).forEach(([field, msg])=>{
            if (!first) first = msg;
            setFieldError(field, msg);
          });
          if (first) showBanner(first, 'error');
        }
      } catch (err){
        console.error(err);
        showBanner('Network error. Please try again.', 'error');
      }
    });
  })();
</script>
</body>
</html>
