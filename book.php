<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit();
}

/* ---------- DB ---------- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="localhost"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="maid_system";
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Saved addresses ---------- */
$addresses = [];
try{
  $stmt = $conn->prepare("SELECT id, label, address_text, home_type, home_sqft, is_default
                          FROM user_addresses
                          WHERE user_id=? ORDER BY is_default DESC, id DESC");
  $stmt->bind_param("i",$uid);
  $stmt->execute();
  $addresses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}catch(Throwable $e){
  $addresses = [];
}

/* ---------- Primary address from profile ---------- */
$primary = null;
try{
  $stmt = $conn->prepare("SELECT 
      COALESCE(address_line, '') AS address_line,
      COALESCE(home_type, '')   AS home_type,
      COALESCE(home_sqft, NULL) AS home_sqft
    FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param("i",$uid);
  $stmt->execute();
  $primary = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();

  if ($primary && trim((string)$primary['address_line'])==='') $primary = null;
}catch(Throwable $e){
  $primary = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Book a Service - NeinMaid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="book.css">
  <style>
    .hidden{display:none}
    .row{margin:10px 0}
    .note{color:#6b7280;font-size:12px;margin-top:4px}
    .flex{display:flex;gap:10px;align-items:flex-start}
    .dropdown,.input,textarea{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:12px}
    .services{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
    .service{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;text-align:center;cursor:pointer}
    .service.active{outline:3px solid rgba(99,91,255,.2);border-color:#635bff}
    .summary{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-top:10px}
    .summary .line{display:flex;justify-content:space-between;margin:6px 0}
    .summary .total{font-weight:800}
    .nav-btn,.btn{border-radius:12px;padding:10px 14px;border:1px solid #e5e7eb;background:#fff;cursor:pointer}
    .btn{background:#635bff;color:#fff;border:0}
    .brand{display:flex;align-items:center;gap:8px}
    .brand img{width:28px;height:28px}
    .header{background:#fff;border-bottom:1px solid #e5e7eb}
    .container{max-width:1000px;margin:18px auto;padding:0 12px}
    .footer{padding:18px;text-align:center;color:#6b7280;border-top:1px solid #e5e7eb;background:#fff;margin-top:24px}

    /* simple calendar look (if not in book.css already) */
    .calendar{overflow-x:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-top:10px}
    .calendar table{width:100%;border-collapse:collapse}
    .calendar th{font-size:12px;color:#64748b;padding:10px 6px;border-bottom:1px solid #e5e7eb}
    .calendar td{width:14.285%;height:44px;text-align:center;cursor:pointer}
    .calendar td.selected{background:#eef2ff;border-top:1px solid #c7d2fe;border-bottom:1px solid #c7d2fe}
    .calendar td.disabled{color:#cbd5e1;cursor:not-allowed}
    .section-title{margin:10px 0 6px}
  </style>
</head>
<body>

<header class="header">
  <nav class="nav" aria-label="Primary">
    <div class="brand">
      <img src="maid.png" alt="NeinMaid logo">
      <div class="brand-name">NeinMaid</div>
    </div>
  </nav>
</header>

<div class="container">
  <form id="bookingForm" action="confirm_booking.php" method="post" novalidate>
    <h2 class="section-title">Select Service</h2>

    <!-- Services -->
    <input type="hidden" name="service" id="serviceInput" required>
    <div class="services" id="serviceList">
      <div class="service" data-service="Standard House Cleaning"      data-hours="2" data-rate="40">🏠<br>STANDARD HOUSE CLEANING</div>
      <div class="service" data-service="Office & Commercial Cleaning" data-hours="2" data-rate="45">💼<br>OFFICE & COMMERCIAL CLEANING</div>
      <div class="service" data-service="Spring / Deep Cleaning"       data-hours="3" data-rate="50">🧽<br>SPRING / DEEP CLEANING</div>
      <div class="service" data-service="Move In/Out Cleaning"         data-hours="4" data-rate="55">🚚<br>MOVE IN/OUT CLEANING</div>
      <div class="service" data-service="Custom Cleaning Plans"        data-hours="0" data-rate="45">🧹<br>CUSTOM CLEANING PLANS</div>
    </div>

    <!-- Custom plan fields -->
    <div id="customFields" class="hidden">
      <div class="row flex">
        <div style="flex:1;min-width:200px">
          <label for="custom_hours">Estimated hours</label>
          <input class="input" type="text" id="custom_hours" name="custom_hours" placeholder="e.g. 3" inputmode="decimal">
        </div>
        <div style="flex:1;min-width:200px">
          <label for="custom_budget">Preferred budget (RM)</label>
          <input class="input" type="text" id="custom_budget" name="custom_budget" placeholder="optional e.g. 150" inputmode="decimal">
        </div>
      </div>
      <div class="row">
        <label for="custom_details">Custom details</label>
        <textarea class="input" id="custom_details" name="custom_details" rows="3" placeholder="Describe areas, rooms, any priorities..."></textarea>
      </div>
    </div>

    <!-- Address -->
    <h2 class="section-title">Address</h2>

    <div class="row">
      <label for="property_name">Place / Property Name (optional)</label>
      <input class="input" id="property_name" name="property_name" placeholder="e.g. Setia Sky Residence, Block B, Unit 12-08">

      <div class="note" style="margin-top:6px">Or pick a saved address (auto-fills fields):</div>
      <select id="saved_address" class="dropdown" style="margin-top:6px">
        <option value="">Choose saved address…</option>

        <?php if ($primary): ?>
          <optgroup label="Primary from Profile">
            <option
              value="__PRIMARY__"
              data-text="<?php echo h($primary['address_line']); ?>"
              data-type="<?php echo h((string)$primary['home_type']); ?>"
              data-sqft="<?php echo h((string)$primary['home_sqft']); ?>"
            >
              <?php
                $label = 'Primary Address';
                $subs  = [];
                if (!empty($primary['home_type'])) $subs[] = $primary['home_type'];
                if (!empty($primary['home_sqft'])) $subs[] = $primary['home_sqft'].' sqft';
                if ($subs) $label .= ' • '.implode(' • ',$subs);
                echo h($label);
              ?>
            </option>
          </optgroup>
        <?php endif; ?>

        <optgroup label="My Saved Addresses" id="mySavedGroup">
        <?php if (!empty($addresses)): ?>
            <?php foreach ($addresses as $a): ?>
              <option
                value="<?php echo (int)$a['id']; ?>"
                data-text="<?php echo h($a['address_text']); ?>"
                data-type="<?php echo h((string)$a['home_type']); ?>"
                data-sqft="<?php echo h((string)$a['home_sqft']); ?>"
              >
                <?php
                  $line = $a['label'];
                  if ((int)$a['is_default']===1) $line .= " • DEFAULT";
                  $subs = [];
                  if (!empty($a['home_type'])) $subs[] = $a['home_type'];
                  if (!empty($a['home_sqft'])) $subs[] = $a['home_sqft'].' sqft';
                  if ($subs) $line .= ' ('.implode(' • ',$subs).')';
                  echo h($line);
                ?>
              </option>
            <?php endforeach; ?>
        <?php endif; ?>
        </optgroup>
      </select>

      <div style="margin-top:8px">
        <?php if (empty($addresses) && !$primary): ?>
          <div class="note" style="margin-top:6px">Tip: Save addresses in <a href="user_addresses.php">My Addresses</a> or set a Primary in <a href="user_profile.php">Profile</a>.</div>
        <?php else: ?>
          <div class="note" style="margin-top:6px">Manage: <a href="user_profile.php">Profile</a> • <a href="user_addresses.php">My Addresses</a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Area under Address -->
    <div class="row">
      <label for="area">Select Area</label>
      <select class="dropdown" name="area" id="area" required>
        <option value="">Select Area</option>
        <option data-fee="0">Georgetown</option>
        <option data-fee="5">Jelutong</option>
        <option data-fee="5">Tanjung Tokong</option>
        <option data-fee="5">Air Itam</option>
        <option data-fee="5">Gelugor</option>
        <option data-fee="10">Bayan Lepas</option>
        <option data-fee="15">Balik Pulau</option>
        <option data-fee="20">Butterworth</option>
        <option data-fee="20">Bukit Mertajam</option>
        <option data-fee="20">Perai</option>
        <option data-fee="20">Nibong Tebal</option>
        <option data-fee="20">Seberang Jaya</option>
      </select>
    </div>

    <div class="row flex">
      <div style="flex:1;min-width:220px">
        <label for="property_type">Type of Place</label>
        <select class="dropdown" id="property_type" name="property_type" required>
          <option value="">Select type…</option>
          <option>Apartment / Condo</option>
          <option>Terrace</option>
          <option>Semi-D</option>
          <option>Bungalow</option>
          <option>Shoplot</option>
          <option>Office / Commercial</option>
          <option>Other</option>
        </select>
        <div class="note">Affects access/complexity surcharge.</div>
      </div>
      <div style="flex:1;min-width:180px">
        <label for="property_sqft">Size (sq ft)</label>
        <input class="input" id="property_sqft" name="property_sqft" type="number" min="0" step="1" placeholder="e.g. 1200" required>
        <div class="note">Tiered surcharge by size.</div>
      </div>
    </div>

    <!-- Tools option -->
    <div class="row">
      <label class="flex" style="gap:10px;align-items:center">
        <input type="checkbox" id="need_tools" name="need_tools" value="1">
        Require cleaner to bring tools & chemicals (+RM25 flat)
      </label>
      <div class="note">If unchecked, you provide vacuum, mop, chemicals, etc.</div>
    </div>

    <!-- Date & Time -->
    <h2 class="section-title">Select Date & Time</h2>

    <div class="row cal-meta" style="display:flex;gap:10px;flex-wrap:wrap">
      <div style="flex:1;min-width:260px">
        <label for="month">Month</label>
        <select class="dropdown" id="month"></select>
      </div>
      <div style="flex:1;min-width:180px">
        <label for="year">Year</label>
        <select class="dropdown" id="year"></select>
      </div>
      <div style="flex:1;min-width:260px">
        <label>Booking Window</label>
        <div class="note" id="windowNote"></div>
      </div>
    </div>

    <div class="calendar">
      <table id="calendarTable" aria-label="Choose a booking date">
        <thead><tr><th>SUN</th><th>MON</th><th>TUE</th><th>WED</th><th>THU</th><th>FRI</th><th>SAT</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
    <input type="hidden" name="date" id="dateISO" required>

    <div class="row">
      <label for="time_slot">Time Slot</label>
      <select class="dropdown" id="time_slot" name="time_slot" required></select>
      <p class="note">Only 6 slots per day (2-hour gaps).</p>
    </div>

    <!-- Summary -->
    <div class="summary" id="summaryBox" style="display:none">
      <div class="line"><span>Duration</span><strong id="sumHours">—</strong></div>
      <div class="line"><span>Service Rate</span><strong id="sumRate">—</strong></div>
      <div class="line"><span>Travel Fee</span><strong id="sumFee">—</strong></div>
      <div class="line"><span>Property Type Surcharge</span><strong id="sumTypeFee">RM 0.00</strong></div>
      <div class="line"><span>Size Surcharge</span><strong id="sumSizeFee">RM 0.00</strong></div>
      <div class="line"><span>Tools & Chemicals</span><strong id="sumToolsFee">RM 0.00</strong></div>
      <hr>
      <div class="line total"><span>Total Estimate</span><strong id="sumTotal">—</strong></div>
    </div>

    <!-- Actions -->
    <div class="row" style="display:flex;justify-content:space-between;align-items:center;margin-top:18px">
      <button class="nav-btn back-dashboard" type="button" onclick="location.href='user_dashboard.php'">⬅ Back to Dashboard</button>
      <button class="btn" type="submit">Continue Booking</button>
    </div>

    <!-- Hidden mirrors for confirm page -->
    <input type="hidden" name="h_property_name" id="h_property_name">
    <input type="hidden" name="h_property_type" id="h_property_type">
    <input type="hidden" name="h_property_sqft" id="h_property_sqft">
    <input type="hidden" name="h_need_tools" id="h_need_tools">
  </form>
</div>

<div class="footer"><p>© 2025 NeinMaid • <a href="#">Neinmaidservice.com</a></p></div>

<!-- ============ PAGE JS ============ -->
<script>
  /* --- Pricing helpers --- */
  const serviceEls   = document.querySelectorAll('.service');
  const serviceInput = document.getElementById('serviceInput');
  const customFields = document.getElementById('customFields');
  const customHours  = document.getElementById('custom_hours');
  const customBudget = document.getElementById('custom_budget');
  const customDetails= document.getElementById('custom_details');

  const areaSelect   = document.getElementById('area');

  const propName = document.getElementById('property_name');
  const propType = document.getElementById('property_type');
  const propSqft = document.getElementById('property_sqft');
  const needTools = document.getElementById('need_tools');

  const savedSel = document.getElementById('saved_address');
  const mySavedGroup = document.getElementById('mySavedGroup');

  // Hidden mirrors
  const hName = document.getElementById('h_property_name');
  const hType = document.getElementById('h_property_type');
  const hSqft = document.getElementById('h_property_sqft');
  const hTools= document.getElementById('h_need_tools');

  const sumBox     = document.getElementById('summaryBox');
  const sumHours   = document.getElementById('sumHours');
  const sumRate    = document.getElementById('sumRate');
  const sumFee     = document.getElementById('sumFee');
  const sumTypeFee = document.getElementById('sumTypeFee');
  const sumSizeFee = document.getElementById('sumSizeFee');
  const sumToolsFee= document.getElementById('sumToolsFee');
  const sumTotal   = document.getElementById('sumTotal');

  let selectedHours = 0, selectedRate = 0, travelFee = 0;

  const typeFees = {
    'Apartment / Condo': 10, 'Terrace': 15, 'Semi-D': 25, 'Bungalow': 40,
    'Shoplot': 20, 'Office / Commercial': 30, 'Other': 0
  };
  function sizeSurcharge(sf){
    sf = Number(sf||0);
    if (sf<=0) return 0;
    if (sf<=800) return 0;
    if (sf<=1200) return 20;
    if (sf<=1800) return 40;
    if (sf<=2500) return 70;
    return 100;
  }
  const TOOLS_FEE = 25;
  function money(n){ return 'RM ' + (Number(n||0)).toFixed(2); }

  /* ---------- Address reset helper ---------- */
  function resetAddressSelection() {
    if (savedSel) {
      savedSel.selectedIndex = 0;   // placeholder: "Choose saved address…"
      savedSel.value = "";
      savedSel.dispatchEvent(new Event('change'));
    }
    // clear auto-filled fields
    propName.value = "";
    propType.selectedIndex = 0;     // "Select type…"
    propSqft.value = "";
    areaSelect.selectedIndex = 0;   // "Select Area"
    needTools.checked = false;
    travelFee = 0;
    updateSummary();
  }

  /* ---------- Service click ---------- */
  serviceEls.forEach(el=>{
    el.addEventListener('click',()=>{
      serviceEls.forEach(s=>s.classList.remove('active'));
      el.classList.add('active');

      serviceInput.value = el.dataset.service;
      selectedHours = parseFloat(el.dataset.hours) || 0;
      selectedRate  = parseFloat(el.dataset.rate) || 0;

      const isCustom = el.dataset.service === 'Custom Cleaning Plans';
      customFields.classList.toggle('hidden', !isCustom);
      if (!isCustom) {
        customHours.value = '';
        customBudget.value = '';
        // customDetails.value = '';
      }

      // reset saved address + fields whenever service changes
      resetAddressSelection();

      updateSummary();
    });
  });

  areaSelect.addEventListener('change',()=>{
    travelFee = parseFloat(areaSelect.options[areaSelect.selectedIndex]?.dataset.fee || 0);
    updateSummary();
  });

  [customHours, propType, propSqft, needTools].forEach(el=>{
    if(el) el.addEventListener('input', updateSummary);
    if(el) el.addEventListener('change', updateSummary);
  });

  function updateSummary(){
    if(!serviceInput.value) return;
    const customH = customFields.classList.contains('hidden') ? NaN : parseFloat(customHours.value || '0');
    const hours = (!isNaN(customH) && customH > 0) ? customH : selectedHours;

    const base = (hours * (selectedRate || 0));
    const typeFee = typeFees[propType.value] || 0;
    const sizeFee = sizeSurcharge(propSqft.value);
    const toolsFee= needTools.checked ? TOOLS_FEE : 0;

    sumHours.textContent = hours || '—';
    sumRate.textContent  = selectedRate ? money(selectedRate) + ' / hr' : '—';
    sumFee.textContent   = money(travelFee || 0);
    sumTypeFee.textContent = money(typeFee);
    sumSizeFee.textContent = money(sizeFee);
    sumToolsFee.textContent= money(toolsFee);

    const total = base + (travelFee||0) + typeFee + sizeFee + toolsFee;
    sumTotal.textContent = (isFinite(total) && total>0) ? money(total) : '—';
    sumBox.style.display = 'block';
  }

  /* --- Saved address auto-fill --- */
  const AREA_LIST = ["Georgetown","Jelutong","Tanjung Tokong","Air Itam","Gelugor","Bayan Lepas","Balik Pulau","Butterworth","Bukit Mertajam","Perai","Nibong Tebal","Seberang Jaya"];

  function tryAutoSelectAreaFromText(text){
    if(!text) return false;
    const t = text.toLowerCase();
    for(const name of AREA_LIST){
      if(t.includes(name.toLowerCase())){
        for(let i=0;i<areaSelect.options.length;i++){
          if(areaSelect.options[i].text.toLowerCase() === name.toLowerCase()){
            areaSelect.selectedIndex = i;
            travelFee = parseFloat(areaSelect.options[i].dataset.fee || 0);
            updateSummary();
            return true;
          }
        }
      }
    }
    return false;
  }

  if(savedSel){
    savedSel.addEventListener('change', ()=>{
      const opt = savedSel.options[savedSel.selectedIndex];
      if(!opt || !opt.value) return;

      const text = opt.getAttribute('data-text') || '';
      const type = opt.getAttribute('data-type') || '';
      const sqft = opt.getAttribute('data-sqft') || '';

      propName.value = text;

      if(type){
        const map = {
          "Apartment":"Apartment / Condo","Condominium":"Apartment / Condo","Condo":"Apartment / Condo",
          "Apartment / Condo":"Apartment / Condo","Terrace":"Terrace","Semi-D":"Semi-D","Bungalow":"Bungalow",
          "Shoplot":"Shoplot","Office":"Office / Commercial","Commercial":"Office / Commercial","Office / Commercial":"Office / Commercial","Other":"Other"
        };
        const normalized = map[type] || type;
        for(let i=0;i<propType.options.length;i++){
          if(propType.options[i].text.toLowerCase() === normalized.toLowerCase()){
            propType.selectedIndex = i; break;
          }
        }
      }

      if(sqft){ propSqft.value = sqft; }

      tryAutoSelectAreaFromText(text);
      updateSummary();
      document.getElementById('time_slot').focus();
    });
  }

  /* --- Calendar 1–30 day window (TOMORROW .. +30 days) --- */
  const monthSelect = document.getElementById("month");
  const yearSelect  = document.getElementById("year");
  const calendarTableBody = document.getElementById("calendarTable").querySelector("tbody");
  const months = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  const dateISOInput = document.getElementById('dateISO');
  const windowNote   = document.getElementById('windowNote');

  let selectedCell = null;
  function atMidnight(d){ return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }

  const today   = new Date();
  // 🔁 CHANGED: was +3, now +1 to allow "tomorrow"
  const minDate = atMidnight(new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1));
  const maxDate = atMidnight(new Date(today.getFullYear(), today.getMonth(), today.getDate() + 30));

  windowNote.textContent = `You can book from ${minDate.toLocaleDateString()} to ${maxDate.toLocaleDateString()}.`;

  yearSelect.innerHTML = "";
  for (let y = minDate.getFullYear(); y <= maxDate.getFullYear(); y++) {
    const o = document.createElement('option'); o.value = y; o.textContent = y; yearSelect.add(o);
  }

  function refreshMonthOptions(){
    monthSelect.innerHTML = "";
    const y = parseInt(yearSelect.value, 10);
    const startM = (y === minDate.getFullYear()) ? minDate.getMonth() : 0;
    const endM   = (y === maxDate.getFullYear()) ? maxDate.getMonth() : 11;
    for (let m = startM; m <= endM; m++) {
      const o = document.createElement('option'); o.value = m; o.textContent = months[m]; monthSelect.add(o);
    }
  }
  function pad(n){ return n < 10 ? '0' + n : n; }

  function generateCalendar(month, year){
    calendarTableBody.innerHTML = '';
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    let date = 1;

    for (let i = 0; i < 6; i++) {
      const row = document.createElement('tr');

      for (let j = 0; j < 7; j++) {
        const cell = document.createElement('td');

        if (i === 0 && j < firstDay) {
          cell.textContent = '';
        } else if (date > daysInMonth) {
          cell.textContent = '';
        } else {
          const cellDate = new Date(year, month, date);
          const yStr = String(cellDate.getFullYear());
          const mStr = pad(cellDate.getMonth() + 1);
          const dStr = pad(cellDate.getDate());

          const cellMid  = atMidnight(cellDate);
          cell.textContent = cellDate.getDate();

          const inWindow = cellMid >= minDate && cellMid <= maxDate;

          if (!inWindow) {
            cell.classList.add('disabled');
          } else {
            cell.addEventListener('click', () => {
              if (selectedCell) selectedCell.classList.remove('selected');
              cell.classList.add('selected');
              selectedCell = cell;
              dateISOInput.value = `${yStr}-${mStr}-${dStr}`;
            });
          }
          date++;
        }
        row.appendChild(cell);
      }
      calendarTableBody.appendChild(row);
    }
  }

  yearSelect.value = String(minDate.getFullYear());
  refreshMonthOptions();
  monthSelect.value = String(minDate.getMonth());
  generateCalendar(parseInt(monthSelect.value, 10), parseInt(yearSelect.value, 10));

  monthSelect.addEventListener('change', () => {
    generateCalendar(parseInt(monthSelect.value, 10), parseInt(yearSelect.value, 10));
  });
  yearSelect.addEventListener('change', () => {
    refreshMonthOptions();
    const currentMonth = parseInt(monthSelect.value || minDate.getMonth(), 10);
    if (parseInt(yearSelect.value,10) === minDate.getFullYear() && currentMonth < minDate.getMonth()) {
      monthSelect.value = String(minDate.getMonth());
    }
    if (parseInt(yearSelect.value,10) === maxDate.getFullYear() && currentMonth > maxDate.getMonth()) {
      monthSelect.value = String(maxDate.getMonth());
    }
    generateCalendar(parseInt(monthSelect.value, 10), parseInt(yearSelect.value, 10));
  });

  /* --- Time slots --- */
  const timeSelect = document.getElementById('time_slot');
  const sixSlots = ["08:00 AM","10:00 AM","12:00 PM","02:00 PM","04:00 PM","06:00 PM"];
  function populateTimeSlots() {
    timeSelect.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = ''; ph.textContent = 'Select a time slot'; ph.disabled = true; ph.selected = true;
    timeSelect.add(ph);
    sixSlots.forEach(s => { const opt=document.createElement('option'); opt.value=s; opt.textContent=s; timeSelect.add(opt); });
  }
  populateTimeSlots();

  /* --- Validate & mirror --- */
  document.getElementById('bookingForm').addEventListener('submit', e => {
    if (!serviceInput.value) { alert('Please select a service.'); e.preventDefault(); return; }
    if (!areaSelect.value)   { alert('Please select an area.'); e.preventDefault(); return; }
    if (!propType.value)     { alert('Please select the type of place.'); e.preventDefault(); return; }
    if (!propSqft.value || Number(propSqft.value) <= 0) { alert('Please enter the size (sq ft).'); e.preventDefault(); return; }
    if (!document.getElementById('dateISO').value) { alert('Please select a date.'); e.preventDefault(); return; }
    if (!timeSelect.value)   { alert('Please select a time slot.'); e.preventDefault(); return; }

    hName.value  = propName.value.trim();
    hType.value  = propType.value;
    hSqft.value  = propSqft.value;
    hTools.value = needTools.checked ? '1' : '0';

    const extra = `\n\n[Property]\nType: ${propType.value}\nSize: ${propSqft.value} sqft\nName: ${propName.value||'-'}\nTools: ${needTools.checked?'Required (+RM25)':'Not required'}`;
    if (!customDetails.value.includes('[Property]')) {
      customDetails.value = (customDetails.value||'') + extra;
    }

    // 🔁 CHANGED: allow TOMORROW (1 day) instead of +3 days
    const [y,m,d] = document.getElementById('dateISO').value.split('-').map(Number);
    const picked = new Date(y, m - 1, d).setHours(0,0,0,0);
    const todayMid = new Date().setHours(0,0,0,0);
    const min = todayMid + 1*86400000; // 1 day = tomorrow
    const max = todayMid + 30*86400000;
    if (picked < min || picked > max) {
      alert(`Please choose a date within the booking window shown.`);
      e.preventDefault();
    }
  });
</script>
</body>
</html>
