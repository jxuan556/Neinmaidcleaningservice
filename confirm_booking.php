<?php
/**
 * confirm_booking.php — NeinMaid (Stripe, MYR)
 * Test card: 4242 4242 4242 4242
 */
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
date_default_timezone_set('Asia/Kuala_Lumpur');

/* ---------- CONFIG ---------- */
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "maid_system";

/* Stripe TEST keys (move to env in prod) */
require_once __DIR__ . '/config.php';  // load .env
$stripeSecret = getenv('STRIPE_SECRET_KEY') ?: ''; // secret key from .env
$stripePublic = getenv('STRIPE_PUBLISHABLE_KEY') ?: ''; // optional, can also be in .env

if (!defined('STRIPE_SECRET')) {
  define('STRIPE_SECRET', $stripeSecret ?: 'sk_test_REPLACE_ME'); // put your real secret if no .env
}
if (!defined('STRIPE_PUBLISHABLE')) {
  define('STRIPE_PUBLISHABLE', $stripePublic ?: 'pk_test_REPLACE_ME'); // put your real pk if no .env
}

$SUPPORT_EMAIL    = "hello@neinmaid.com";
$SUPPORT_WHATSAPP = "60123456789";

/* ---------- PRICING ---------- */
$HOURLY_RATES = [
  'Standard House Cleaning'      => 40,
  'Office & Commercial Cleaning' => 45,
  'Spring / Deep Cleaning'       => 50,
  'Move In/Out Cleaning'         => 55,
  'Custom Cleaning Plans'        => 45,
];
$DEFAULT_HOURS = [
  'Standard House Cleaning'      => 2,
  'Office & Commercial Cleaning' => 2,
  'Spring / Deep Cleaning'       => 3,
  'Move In/Out Cleaning'         => 4,
  'Custom Cleaning Plans'        => 0,
];
$AREA_FEES = [
  'Georgetown'=>0,'Jelutong'=>5,'Tanjung Tokong'=>5,'Air Itam'=>5,'Gelugor'=>5,
  'Bayan Lepas'=>10,'Balik Pulau'=>15,'Butterworth'=>20,'Bukit Mertajam'=>20,
  'Perai'=>20,'Nibong Tebal'=>20,'Seberang Jaya'=>20
];
$TYPE_FEES = [
  'Apartment / Condo'   => 10,
  'Terrace'             => 15,
  'Semi-D'              => 25,
  'Bungalow'            => 40,
  'Shoplot'             => 20,
  'Office / Commercial' => 30,
  'Other'               => 0,
];
define('TOOLS_FEE', 25);

function size_surcharge(int $sf): int {
  if ($sf <= 0)    return 0;
  if ($sf <= 800)  return 0;
  if ($sf <= 1200) return 20;
  if ($sf <= 1800) return 40;
  if ($sf <= 2500) return 70;
  return 100;
}

/* ---------- SLOTS ---------- */
function generate_slots($start='08:00', $end='20:00', $stepMin=30) {
  $out = [];
  [$sH,$sM] = array_map('intval', explode(':',$start));
  [$eH,$eM] = array_map('intval', explode(':',$end));
  $startMin = $sH*60+$sM; $endMin = $eH*60+$eM;
  for($m=$startMin;$m<=$endMin;$m+=$stepMin){
    $hh = floor($m/60); $mm = $m%60;
    $period = $hh>=12?'PM':'AM'; $h12 = ($hh%12) ?: 12;
    $out[] = sprintf('%02d:%02d %s',$h12,$mm,$period);
  }
  return $out;
}
$ALLOWED_SLOTS = generate_slots();

/* ---------- UTIL ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } // for output only
function clean($s){ return trim((string)$s); }                               // DO NOT escape here
function enc($s){ return rawurlencode($s); }
function money_rm($n){ return 'RM'.number_format((float)$n,2,'.',''); }
function booking_ref(){ return 'NM'.date('Ymd').strtoupper(bin2hex(random_bytes(2))); }
function is_valid_future_date($iso){
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$iso)) return false;
  $d = DateTime::createFromFormat('Y-m-d', $iso);
  if(!$d) return false;
  $d->setTime(0,0,0);
  $today = new DateTime('today');
  return $d >= $today;
}

/* ---------- DB ---------- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

/* ---------- PROMO ---------- */
function get_promo_from_db(mysqli $conn, string $code) : ?array {
  $sql = "SELECT * FROM promo_codes WHERE code=? LIMIT 1";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("s",$code);
  $stmt->execute(); $res=$stmt->get_result();
  $row=$res?$res->fetch_assoc():null; $stmt->close();
  if(!$row) return null;
  $now = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
  if ((int)$row['active']!==1) return null;
  if (!empty($row['starts_at']) && $now < new DateTime($row['starts_at'])) return null;
  if (!empty($row['ends_at'])   && $now > new DateTime($row['ends_at']))   return null;
  if (!is_null($row['usage_limit']) && (int)$row['used_count'] >= (int)$row['usage_limit']) return null;
  return $row;
}
function apply_promo(float $subtotal, array $promo) : array {
  $rate=(float)$promo['percent_off'];
  $min = is_null($promo['min_spend']) ? null : (float)$promo['min_spend'];
  $cap = is_null($promo['max_discount']) ? null : (float)$promo['max_discount'];
  if(!is_null($min) && $subtotal < $min) return [$subtotal,0.0,"Order doesn't meet minimum spend."];
  $disc = round($subtotal*$rate,2);
  if(!is_null($cap)) $disc=min($disc,$cap);
  $final=max(0.0,round($subtotal-$disc,2));
  return [$final,$disc,null];
}
function increment_promo_usage(mysqli $c,string $code){ $c->query("UPDATE promo_codes SET used_count=used_count+1 WHERE code='".$c->real_escape_string($code)."' LIMIT 1"); }
function get_active_promos(mysqli $conn): array{
  $rows=[]; $now=(new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d H:i:s');
  $sql="SELECT code,percent_off,min_spend,max_discount FROM promo_codes
        WHERE active=1 AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>=?)
          AND (usage_limit IS NULL OR used_count<usage_limit)
        ORDER BY created_at DESC LIMIT 20";
  $st=$conn->prepare($sql); $st->bind_param('ss',$now,$now); $st->execute();
  $r=$st->get_result(); while($x=$r->fetch_assoc()) $rows[]=$x; $st->close();
  return $rows;
}

/* ---------- STRIPE REST ---------- */
function stripe_request(string $method,string $endpoint,array $data=null): array{
  $ch=curl_init();
  $url='https://api.stripe.com'.$endpoint;
  $headers=['Authorization: Bearer '.STRIPE_SECRET,'Content-Type: application/x-www-form-urlencoded'];
  $opts=[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers];
  if($data!==null) $opts[CURLOPT_POSTFIELDS]=http_build_query($data,'','&');
  curl_setopt_array($ch,$opts);
  $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $json=$resp?json_decode($resp,true):null;
  return ['ok'=>($code>=200 && $code<300),'code'=>$code,'json'=>$json,'raw'=>$resp];
}

/* ---------- CALCULATOR ---------- */
function compute_totals_and_validate(
  &$errors,$HOURLY_RATES,$DEFAULT_HOURS,$AREA_FEES,$ALLOWED_SLOTS,$TYPE_FEES,
  $service,$area,$date,$time,$custHrs,$custDet,$propType,$propSqft,$needTools
){
  $tNorm = preg_replace('/\s+/', ' ', trim((string)$time));
  if (!isset($HOURLY_RATES[$service])) $errors[]="Invalid service selected.";
  if (!isset($AREA_FEES[$area]))       $errors[]="Invalid area selected.";
  if (!in_array($tNorm,$ALLOWED_SLOTS,true)) $errors[]="Invalid time slot.";
  if (!is_valid_future_date($date))    $errors[]="Invalid date.";

  $travel = (float)($AREA_FEES[$area] ?? 0);
  $hours = $DEFAULT_HOURS[$service] ?? 0;
  if($service==='Custom Cleaning Plans'){
    if($custHrs===null || $custHrs<=0) $errors[]="Enter hours for custom plan.";
    if(mb_strlen($custDet)<6)          $errors[]="Add more custom details.";
    $hours=(float)$custHrs;
  }
  $rate=(float)($HOURLY_RATES[$service] ?? 0);
  $serviceCost=$hours*$rate;

  $typeFee = $propType!=='' ? (int)($TYPE_FEES[$propType] ?? 0) : 0;
  $sizeFee = size_surcharge((int)$propSqft);
  $toolsFee= $needTools ? TOOLS_FEE : 0;

  $subtotal=$serviceCost+$travel+$typeFee+$sizeFee+$toolsFee;
  return [$tNorm,$travel,$hours,$rate,$serviceCost,$typeFee,$sizeFee,$toolsFee,$subtotal];
}

/* ---------- LOG ---------- */
function log_payment(mysqli $c,int $uid,string $ref,float $amount,string $status,?string $gwRef,?string $req,?string $resp,?int $http): void{
  $sql="INSERT INTO payments_log(user_id,booking_ref,amount,currency,status,gateway_ref,request_json,response_json,http_code)
        VALUES(?,?,?,?,?,?,?,?,?)";
  $st=$c->prepare($sql); $currency='MYR';
  $st->bind_param("isdssssii",$uid,$ref,$amount,$currency,$status,$gwRef,$req,$resp,$http);
  $st->execute(); $st->close();
}

/* ---------- STATE ---------- */
$errors=[]; $promoWarning=null; $stage='review';
$refShown=null; $clientSecret=null; $paymentIntentId=null;

/* ---------- AJAX: RECALC + NEW PI ON PROMO/SUMMARY CHANGE ---------- */
if(isset($_POST['ajax']) && $_POST['ajax']==='recalc'){
  // IMPORTANT: decode HTML entities from previous page fields
  $service   = html_entity_decode(clean($_POST['service'] ?? ''), ENT_QUOTES, 'UTF-8');
  $area      = html_entity_decode(clean($_POST['area'] ?? ''),    ENT_QUOTES, 'UTF-8');
  $date      = clean($_POST['date'] ?? '');
  $time      = clean($_POST['time_slot'] ?? '');
  $custDet   = clean($_POST['custom_details'] ?? '');
  $custHrs   = ($_POST['custom_hours']  !== '') ? (float)$_POST['custom_hours']  : null;
  $custBud   = ($_POST['custom_budget'] !== '') ? (float)$_POST['custom_budget'] : null;
  $promoCode = strtoupper(clean($_POST['promo_code'] ?? ''));

  $propName  = html_entity_decode(clean($_POST['h_property_name'] ?? ''), ENT_QUOTES, 'UTF-8');
  $propType  = html_entity_decode(clean($_POST['h_property_type'] ?? ''), ENT_QUOTES, 'UTF-8');
  $propSqft  = (int)($_POST['h_property_sqft'] ?? 0);
  $needTools = (isset($_POST['h_need_tools']) && $_POST['h_need_tools']=='1');

  [$tNorm,$travelFee,$hours,$rate,$serviceCost,$typeFee,$sizeFee,$toolsFee,$subtotal] =
    compute_totals_and_validate($errors,$HOURLY_RATES,$DEFAULT_HOURS,$AREA_FEES,$ALLOWED_SLOTS,$TYPE_FEES,
      $service,$area,$date,$time,$custHrs,$custDet,$propType,$propSqft,$needTools);

  if($errors){ echo json_encode(['ok'=>false,'msg'=>implode(' ',$errors)]); exit; }

  $discount=0.0; $promoRow=null; $total=$subtotal; $promoNote=null;
  if(!empty($promoCode)){
    $promoRow=get_promo_from_db($conn,$promoCode);
    if($promoRow){ [$total,$discount,$e]=apply_promo($subtotal,$promoRow); if($e){ $promoNote=$e; $total=$subtotal; $discount=0.0; } }
    else { $promoNote="Invalid or unavailable promo."; }
  }
  if($total<2.00){ echo json_encode(['ok'=>false,'msg'=>'Total must be at least RM 2.00']); exit; }

  // (Re)create a PI for this exact total
  $amountCents=(int)round($total*100);
  $payload=[
    'amount'=>$amountCents,'currency'=>'myr','description'=>'NeinMaid booking payment',
    'automatic_payment_methods[enabled]'=>'true',
    'metadata[user_id]'=>(string)$_SESSION['user_id'],
    'metadata[service]'=>$service,'metadata[area]'=>$area,'metadata[date]'=>$date,'metadata[time]'=>$tNorm,
    'metadata[propertyType]'=>$propType,'metadata[propertySqft]'=>$propSqft,'metadata[needTools]'=>$needTools?'1':'0',
    'metadata[promoCode]'=>$promoCode,
  ];
  $res=stripe_request('POST','/v1/payment_intents',$payload);
  if(!$res['ok'] || empty($res['json']['client_secret'])){ echo json_encode(['ok'=>false,'msg'=>'Stripe init failed.']); exit; }

  $_SESSION['nm_pi_id']=$res['json']['id']; $_SESSION['nm_pi_amount']=$amountCents;

  echo json_encode([
    'ok'=>true,
    'clientSecret'=>$res['json']['client_secret'],
    'paymentIntentId'=>$res['json']['id'],
    'serviceCost'=>$serviceCost,'travelFee'=>$travelFee,'typeFee'=>$typeFee,'sizeFee'=>$sizeFee,'toolsFee'=>$toolsFee,
    'discount'=>$discount,'subtotal'=>$subtotal,'total'=>$total,'promoNote'=>$promoNote
  ]); exit;
}

/* ---------- FINALIZE AFTER STRIPE SUCCESS ---------- */
if(isset($_POST['action']) && $_POST['action']==='stripe_finalize'){
  $service   = html_entity_decode(clean($_POST['service'] ?? ''), ENT_QUOTES, 'UTF-8');
  $area      = html_entity_decode(clean($_POST['area'] ?? ''),    ENT_QUOTES, 'UTF-8');
  $date      = clean($_POST['date'] ?? '');
  $time      = clean($_POST['time_slot'] ?? '');
  $custDet   = clean($_POST['custom_details'] ?? '');
  $custHrs   = ($_POST['custom_hours']  !== '') ? (float)$_POST['custom_hours']  : null;
  $custBud   = ($_POST['custom_budget'] !== '') ? (float)$_POST['custom_budget'] : null;
  $promoCode = strtoupper(clean($_POST['promo_code'] ?? ''));
  $piId      = clean($_POST['payment_intent_id'] ?? '');

  $propName  = html_entity_decode(clean($_POST['h_property_name'] ?? ''), ENT_QUOTES, 'UTF-8');
  $propType  = html_entity_decode(clean($_POST['h_property_type'] ?? ''), ENT_QUOTES, 'UTF-8');
  $propSqft  = (int)($_POST['h_property_sqft'] ?? 0);
  $needTools = (isset($_POST['h_need_tools']) && $_POST['h_need_tools']=='1');

  [$tNorm,$travelFee,$hours,$rate,$serviceCost,$typeFee,$sizeFee,$toolsFee,$subtotal] =
    compute_totals_and_validate($errors,$HOURLY_RATES,$DEFAULT_HOURS,$AREA_FEES,$ALLOWED_SLOTS,$TYPE_FEES,
      $service,$area,$date,$time,$custHrs,$custDet,$propType,$propSqft,$needTools);

  $discount=0.0; $promoRow=null; $total=$subtotal;
  if(!empty($promoCode)){
    $promoRow=get_promo_from_db($conn,$promoCode);
    if($promoRow){ [$total,$discount,$e]=apply_promo($subtotal,$promoRow); if($e){ $total=$subtotal; $discount=0.0; $promoWarning=$e; } }
    else { $promoWarning="Invalid promo."; }
  }
  if($total<2.00) $errors[]="Total too low.";
  if(empty($piId)) $errors[]="Missing payment reference.";

  if(empty($errors)){
    $res=stripe_request('GET','/v1/payment_intents/'.rawurlencode($piId));
    if(!$res['ok'] || empty($res['json']['status'])) $errors[]="Stripe verification failed.";
    else{
      if($res['json']['status']!=='succeeded'){
        $reference=booking_ref();
        log_payment($conn,(int)$_SESSION['user_id'],$reference,$total,'failed',$piId,json_encode(['verify_pi'=>$piId]),$res['raw'],$res['code']);
        $errors[]="Payment not completed.";
      }else{
        // Save booking
        $stmt=$conn->prepare("INSERT INTO bookings
          (user_id,service,area,travel_fee,date,time_slot,custom_details,custom_hours,custom_budget,estimated_price,status,ref_code,payment_status,payment_ref,promo_code,final_paid_amount)
          VALUES (?,?,?,?,?,?,?,?,?,?,'pending',?,'paid',?,?,?)");
        $uid=(int)$_SESSION['user_id']; $reference=booking_ref();
        $est=$subtotal; $final=round($total,2);
        $types="issdsssdddsssd";
        $stmt->bind_param($types,$uid,$service,$area,$travelFee,$date,$tNorm,$custDet,$custHrs,$custBud,$est,$reference,$piId,$promoCode,$final);
        if($stmt->execute()){
          if($promoRow) increment_promo_usage($conn,$promoRow['code']);
          log_payment($conn,$uid,$reference,$final,'success',$piId,json_encode(['verify_pi'=>$piId]),$res['raw'],$res['code']);
          $stage='success'; $refShown=$reference;
          unset($_SESSION['nm_pi_id'],$_SESSION['nm_pi_amount']);
        }else{
          log_payment($conn,$uid,$reference,$final,'error',$piId,json_encode(['verify_pi'=>$piId]),$res['raw'],$res['code']);
          $errors[]="Failed to save booking.";
        }
        $stmt->close();
      }
    }
  }
}

/* ---------- FIRST LOAD (INITIAL PI) ---------- */
if($stage==='review'){
  // decode any entities coming from previous page
  $service  = html_entity_decode(clean($_POST['service'] ?? ''), ENT_QUOTES, 'UTF-8');
  $area     = html_entity_decode(clean($_POST['area'] ?? ''),    ENT_QUOTES, 'UTF-8');
  $date     = clean($_POST['date'] ?? '');
  $time     = clean($_POST['time_slot'] ?? '');
  $custDet  = clean($_POST['custom_details'] ?? '');
  $custHrs  = (isset($_POST['custom_hours'])  && $_POST['custom_hours']  !== '') ? (float)$_POST['custom_hours']  : null;
  $custBud  = (isset($_POST['custom_budget']) && $_POST['custom_budget'] !== '') ? (float)$_POST['custom_budget'] : null;
  $promoCode= strtoupper(clean($_POST['promo_code'] ?? ''));

  $propName  = html_entity_decode(clean($_POST['h_property_name'] ?? ''), ENT_QUOTES, 'UTF-8');
  $propType  = html_entity_decode(clean($_POST['h_property_type'] ?? ''), ENT_QUOTES, 'UTF-8');
  $propSqft  = (int)($_POST['h_property_sqft'] ?? 0);
  $needTools = (isset($_POST['h_need_tools']) && $_POST['h_need_tools']=='1');

  [$tNorm,$travelFee,$hours,$rate,$serviceCost,$typeFee,$sizeFee,$toolsFee,$subtotal] =
    compute_totals_and_validate($errors,$HOURLY_RATES,$DEFAULT_HOURS,$AREA_FEES,$ALLOWED_SLOTS,$TYPE_FEES,
      $service,$area,$date,$time,$custHrs,$custDet,$propType,$propSqft,$needTools);

  $discountRM=0.0; $promoRow=null; $totalForUi=$subtotal;
  if(!empty($promoCode)){
    $promoRow=get_promo_from_db($conn,$promoCode);
    if($promoRow){ [$totalForUi,$discountRM,$tmpErr]=apply_promo($subtotal,$promoRow); if($tmpErr){ $promoWarning=$tmpErr; $totalForUi=$subtotal; $discountRM=0.0; } }
    else { $promoWarning="Invalid or unavailable promo."; }
  }
  if($errors){ /* don’t create PI if invalid */ }
  else{
    if ($totalForUi < 2.00) { $errors[]="Total must be at least RM 2.00"; }
    else{
      $amountCents=(int)round($totalForUi*100);
      $payload=[
        'amount'=>$amountCents,'currency'=>'myr','description'=>'NeinMaid booking payment',
        'automatic_payment_methods[enabled]'=>'true',
        'metadata[user_id]'=>(string)$_SESSION['user_id'],
        'metadata[service]'=>$service,'metadata[area]'=>$area,'metadata[date]'=>$date,'metadata[time]'=>$tNorm,
        'metadata[propertyType]'=>$propType,'metadata[propertySqft]'=>$propSqft,'metadata[needTools]'=>$needTools?'1':'0',
        'metadata[promoCode]'=>$promoCode,
      ];
      $res=stripe_request('POST','/v1/payment_intents',$payload);
      if($res['ok'] && !empty($res['json']['client_secret'])){
        $paymentIntentId=$res['json']['id']; $clientSecret=$res['json']['client_secret'];
        $_SESSION['nm_pi_id']=$paymentIntentId; $_SESSION['nm_pi_amount']=$amountCents;
      }else{ $errors[]="Unable to initialize Stripe."; }
    }
  }
  $activePromos=get_active_promos($conn);
}

/* ---------- UI helpers ---------- */
$whatsBase="https://wa.me/{$SUPPORT_WHATSAPP}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Confirm Booking – NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="confirm.css">
  <style>
    body { background:#f7f7fb; }
    .navbar{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:#fff;border-bottom:1px solid #e5e7eb}
    .brand{display:flex;gap:8px;align-items:center}
    .brand img{width:28px;height:28px}
    .wrap{max-width:1000px;margin:18px auto;padding:0 12px}
    .grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
    .row{display:flex;justify-content:space-between;align-items:center;margin:8px 0}
    .row--top{align-items:flex-start}
    .sep{border:0;border-top:1px dashed #e5e7eb;margin:12px 0}
    .btn{background:#635bff;color:#fff;border:0;border-radius:12px;padding:10px 14px;text-decoration:none;display:inline-block}
    .btn-ghost{background:#fff;color:#111;border:1px solid #e5e7eb;border-radius:12px;padding:10px 14px;text-decoration:none;display:inline-block}
    .muted{color:#6b7280}
    .summary{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
    .note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#fff6f6;border:1px solid #f1c0c0;color:#991b1b;padding:10px;border-radius:10px;margin-bottom:12px}
    .banner-success{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:12px;border-radius:12px;margin:10px 0}
    .tag{color:#6b7280;font-size:12px;margin-top:6px}
    .est{font-weight:800}
    .card-input{width:100%; padding:8px; margin:6px 0; border:1px solid #e5e7eb; border-radius:12px; background:#fff;}
    #payment-element { padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#fff; }
    .hint{color:#6b7280;font-size:12px}
    .right { text-align:right }
  </style>
</head>
<body>
  <div class="navbar">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid logo">
      <h2 style="margin:0">NeinMaid</h2>
    </div>
  </div>

  <div class="wrap">
    <?php if ($stage==='success' && empty($errors)): ?>
      <div class="card">
        <h1>✅ Booking Confirmed!</h1>
        <p class="muted">Thank you. Your booking has been received and payment completed.</p>
        <div class="banner-success">
          <strong>Reference:</strong> <?= h($refShown ?? '—') ?><br>Keep this reference when contacting support.
        </div>
        <?php $mailto="mailto:$SUPPORT_EMAIL?subject=".enc("Booking help (Ref: ".($refShown ?? '').")")."&body=".enc("Hi NeinMaid, I need help with my booking. Ref: ".($refShown ?? '')); ?>
        <div class="connect"><a href="<?= $mailto ?>">✉️ Email</a></div>
        <div class="actions" style="margin-top:10px">
          <a class="btn" href="user_dashboard.php">Go to Dashboard</a>
          <a class="btn-ghost" href="book.php">Make another booking</a>
        </div>
      </div>
    <?php else: ?>
      <div class="grid">
        <div class="card">
          <h1>Review & Confirm</h1>

          <?php if (!empty($promoWarning)): ?>
            <div class="note"><strong>Promo:</strong> <?= h($promoWarning) ?></div>
          <?php endif; ?>
          <?php if ($errors): ?>
            <div class="err"><?= h(implode(' ', $errors)) ?></div>
          <?php endif; ?>

          <div class="review">
            <div class="row"><span>Service</span><strong><?= h($service ?? '—') ?></strong></div>
            <div class="row"><span>Area</span><strong><?= h($area ?? '—') ?></strong></div>
            <div class="row"><span>Travel fee</span><strong id="sumTravel"><?= money_rm($travelFee ?? 0) ?></strong></div>
            <div class="row"><span>Date</span><strong><?= h($date ?? '—') ?></strong></div>
            <div class="row"><span>Time</span><strong><?= h($tNorm ?? $time ?? '—') ?></strong></div>

            <?php if (!empty($propType) || !empty($propSqft) || !empty($propName) || !empty($needTools)): ?>
              <hr class="sep">
              <div class="row"><span>Property type</span><strong><?= h($propType ?: '—') ?></strong></div>
              <div class="row"><span>Size</span><strong><?= $propSqft ? h($propSqft).' sqft' : '—' ?></strong></div>
              <div class="row"><span>Place</span><strong><?= h($propName ?: '—') ?></strong></div>
              <div class="row"><span>Tools & chemicals</span><strong><?= $needTools ? 'Required (+RM25)' : 'Not required' ?></strong></div>
            <?php endif; ?>

            <?php if (($service ?? '')==='Custom Cleaning Plans'): ?>
              <hr class="sep">
              <div class="row"><span>Estimated hours</span><strong><?= h(($custHrs!==null)?$custHrs:'—') ?></strong></div>
              <div class="row"><span>Preferred budget</span><strong><?= ($custBud!==null)?money_rm($custBud):'—' ?></strong></div>
              <div class="row row--top"><span>Custom details</span><div class="custom-details"><?= nl2br(h($custDet)) ?></div></div>
            <?php endif; ?>
          </div>

          <!-- Finalize form -->
          <form method="post" action="" id="finalizeForm">
            <input type="hidden" name="action" value="stripe_finalize">
            <input type="hidden" name="payment_intent_id" id="payment_intent_id" value="">
            <input type="hidden" name="service" value="<?= h($service ?? '') ?>">
            <input type="hidden" name="area" value="<?= h($area ?? '') ?>">
            <input type="hidden" name="date" value="<?= h($date ?? '') ?>">
            <input type="hidden" name="time_slot" value="<?= h($tNorm ?? $time ?? '') ?>">
            <input type="hidden" name="custom_details" value="<?= h($custDet ?? '') ?>">
            <input type="hidden" name="custom_hours" value="<?= h(($custHrs!==null)?$custHrs:'') ?>">
            <input type="hidden" name="custom_budget" value="<?= h(($custBud!==null)?$custBud:'') ?>">

            <!-- Property -->
            <input type="hidden" name="h_property_name" value="<?= h($propName ?? '') ?>">
            <input type="hidden" name="h_property_type" value="<?= h($propType ?? '') ?>">
            <input type="hidden" name="h_property_sqft" value="<?= h((string)($propSqft ?? 0)) ?>">
            <input type="hidden" name="h_need_tools" value="<?= $needTools ? '1' : '0' ?>">

            <!-- Promo -->
            <label for="promo_code">Promo code (optional)</label>
            <input id="promo_code" name="promo_code" class="card-input" value="<?= h($promoCode ?? '') ?>" placeholder="e.g. WELCOME10">

            <?php if (!empty($activePromos)): ?>
              <div class="hint">Active promos:</div>
              <select class="card-input" id="promo_select">
                <option value="">Select a promo…</option>
                <?php foreach($activePromos as $p):
                  $label = $p['code'].' — '.((float)$p['percent_off']*100).'% off'
                         .(isset($p['min_spend']) && $p['min_spend']!==null ? ' (min RM'.number_format($p['min_spend'],2).')':'' );
                ?>
                  <option value="<?= h($p['code']) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </form>

          <hr class="sep">

          <div id="payment-element"></div>

          <div style="margin-top:10px;" class="right">
            <button class="btn" id="payButton" <?= ($clientSecret && empty($errors)) ? '' : 'disabled'; ?>>
              Pay & Confirm (<span id="payLabel"><?= money_rm($totalForUi ?? $subtotal ?? 0) ?></span>)
            </button>
            <a href="book.php" class="btn-ghost">Edit Details</a>
            <div class="tag">Stripe test mode — use card 4242 4242 4242 4242</div>
          </div>
        </div>

        <!-- Summary -->
        <div class="summary" id="summaryBox">
          <div class="row"><span>Service cost</span><strong id="sumService"><?= money_rm($serviceCost ?? 0) ?></strong></div>
          <div class="row"><span>Travel fee</span><strong id="sumTravel2"><?= money_rm($travelFee ?? 0) ?></strong></div>
          <div class="row"><span>Property type surcharge</span><strong id="sumType"><?= money_rm($typeFee ?? 0) ?></strong></div>
          <div class="row"><span>Size surcharge</span><strong id="sumSize"><?= money_rm($sizeFee ?? 0) ?></strong></div>
          <div class="row"><span>Tools & chemicals</span><strong id="sumTools"><?= money_rm($toolsFee ?? 0) ?></strong></div>
          <div class="row" id="rowPromo" style="<?= (!empty($discountRM ?? 0))?'':'display:none'; ?>">
            <span>Promo <span id="sumPromoCode"><?= h($promoCode ?? '') ?></span></span>
            <strong id="sumDiscount">-<?= money_rm($discountRM ?? 0) ?></strong>
          </div>
          <hr class="sep">
          <div class="row"><span>Total</span><strong class="est" id="sumTotal"><?= money_rm(($totalForUi ?? $subtotal ?? 0)) ?></strong></div>
        </div>
      </div>
    <?php endif; ?>
  </div>

<?php if ($stage==='review' && $clientSecret): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
  const stripePubKey = <?= json_encode(STRIPE_PUBLISHABLE) ?>;
  let clientSecret   = <?= json_encode($clientSecret ?? '') ?>;
  const finalizeForm = document.getElementById('finalizeForm');
  const promoInput   = document.getElementById('promo_code');
  const promoSelect  = document.getElementById('promo_select');
  const payBtn       = document.getElementById('payButton');

  let stripe = Stripe(stripePubKey);
  let elements = stripe.elements({ clientSecret });
  let paymentElement = elements.create('payment');
  paymentElement.mount('#payment-element');

  function fmtRM(v){ return 'RM'+Number(v).toFixed(2); }
  function updateSummary(d){
    document.getElementById('sumService').textContent = fmtRM(d.serviceCost);
    document.getElementById('sumTravel').textContent  = fmtRM(d.travelFee);
    document.getElementById('sumTravel2').textContent = fmtRM(d.travelFee);
    document.getElementById('sumType').textContent    = fmtRM(d.typeFee);
    document.getElementById('sumSize').textContent    = fmtRM(d.sizeFee);
    document.getElementById('sumTools').textContent   = fmtRM(d.toolsFee);
    if (Number(d.discount) > 0){
      document.getElementById('rowPromo').style.display='';
      document.getElementById('sumDiscount').textContent = '-'+fmtRM(d.discount);
      document.getElementById('sumPromoCode').textContent = (promoInput.value||'').toUpperCase();
    } else {
      document.getElementById('rowPromo').style.display='none';
    }
    document.getElementById('sumTotal').textContent = fmtRM(d.total);
    document.getElementById('payLabel').textContent = fmtRM(d.total);
  }

  async function recalc(){
    const fd = new FormData(finalizeForm);
    fd.delete('action'); fd.append('ajax','recalc');
    fd.set('promo_code',(promoInput.value||'').trim().toUpperCase());
    const r = await fetch(location.href,{method:'POST',body:fd});
    const j = await r.json().catch(()=>null);
    if(!j || !j.ok){ alert(j && j.msg ? j.msg : 'Recalculation failed.'); return; }

    updateSummary(j);

    clientSecret = j.clientSecret;
    elements = stripe.elements({ clientSecret });
    paymentElement.unmount();
    paymentElement = elements.create('payment');
    paymentElement.mount('#payment-element');

    document.getElementById('payment_intent_id').value = j.paymentIntentId;
    payBtn.disabled = false;
  }

  promoInput?.addEventListener('change', recalc);
  promoSelect?.addEventListener('change', ()=>{ promoInput.value = promoSelect.value || ''; recalc(); });

  // Seed current PI id
  document.getElementById('payment_intent_id').value = <?= json_encode($paymentIntentId ?? '') ?>;

  payBtn?.addEventListener('click', async (e) => {
    e.preventDefault();
    payBtn.disabled = true; const original = payBtn.textContent; payBtn.textContent='Processing…';
    const {error, paymentIntent} = await stripe.confirmPayment({elements,confirmParams:{return_url:window.location.href},redirect:'if_required'});
    if(error){ alert(error.message || 'Payment failed. Try again.'); payBtn.disabled=false; payBtn.textContent=original; return; }
    if(paymentIntent && paymentIntent.status==='succeeded'){ document.getElementById('payment_intent_id').value = paymentIntent.id; finalizeForm.submit(); }
    else{ alert('Payment status: '+(paymentIntent?.status||'unknown')); payBtn.disabled=false; payBtn.textContent=original; }
  });
</script>
<?php endif; ?>
</body>
</html>

