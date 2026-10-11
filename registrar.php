<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$user_stmt = mysqli_prepare($con, "SELECT student_id, first_name, last_name, email, phone, role, education_level FROM users WHERE student_id = ?");
$user = null;

if ($user_stmt) {
    mysqli_stmt_bind_param($user_stmt, "s", $student_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user = mysqli_fetch_assoc($user_result);
    mysqli_stmt_close($user_stmt);
}

$current_role = $_SESSION['user_role'] ?? ($user['role'] ?? 'student');
$education_level = $user['education_level'] ?? ($_SESSION['education_level'] ?? null);
if (!in_array($education_level, ['SHS', 'College'], true)) {
  header('Location: homepage.php?choose_level=1');
  exit();
}


$services_query = mysqli_prepare($con, "SELECT service_name, estimated_duration_min, is_visible, available_from, available_until FROM services WHERE office_code = 'registrar' AND target_level IN ('Both', ?) AND is_visible = 1 AND (available_from IS NULL OR available_from <= NOW()) AND (available_until IS NULL OR available_until >= NOW()) ORDER BY id ASC");
$services = [];
if ($services_query) {
  mysqli_stmt_bind_param($services_query, 's', $education_level);
  mysqli_stmt_execute($services_query);
  $services_result = mysqli_stmt_get_result($services_query);
  while ($s = mysqli_fetch_assoc($services_result)) {
        $services[] = $s;
    }
  mysqli_stmt_close($services_query);
}
function student_service_state($service) {
    $now = time();
    if (empty($service['is_visible']) || (!empty($service['available_until']) && strtotime($service['available_until']) < $now)) return 'unavailable';
    if (!empty($service['available_from']) && strtotime($service['available_from']) > $now) return 'scheduled';
    return 'available';
}
if (empty($services)) {
    $services = [
        ['service_name' => 'Enrollment Verification', 'estimated_duration_min' => 15],
        ['service_name' => 'Transcript Request (TOR)', 'estimated_duration_min' => 20],
        ['service_name' => 'Student Records Update', 'estimated_duration_min' => 15],
        ['service_name' => 'Certificate of Enrollment (COE)', 'estimated_duration_min' => 10],
        ['service_name' => 'Certificate of Good Moral Character', 'estimated_duration_min' => 10],
        ['service_name' => 'Honorable Dismissal', 'estimated_duration_min' => 20],
    ];
    foreach ($services as &$fallback_service) {
        $fallback_service['is_visible'] = 1;
        $fallback_service['available_from'] = null;
        $fallback_service['available_until'] = null;
    }
    unset($fallback_service);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registrar Office Appointment - ACLC</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="registrar.css">
  <link rel="stylesheet" href="popup.css">
  <script src="popup.js?v=<?php echo filemtime(__DIR__ . '/popup.js'); ?>"></script>
</head>

<body>
  <header class="home-header">
    <div class="school-name">
      <img src="img/image.png" alt="ACLC Logo" class="ama-logo" onerror="this.style.display='none'">
      <div class="school-text">
        <h1>ACLC College of Daet Appointment</h1>
        <p class="school-info">2F Guinhawa Bldg. J. Lukban St. Daet, Camarines Norte, Daet, Philippines, 4600</p>
      </div>
    </div>

    <div class="header-user-actions">
      <a href="homepage.php" class="home-link"><i class="fas fa-home"></i> Home</a>
      <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
        <i class="fas fa-moon"></i>
      </button>
      <button type="button" class="profile-trigger" id="profileTrigger" aria-label="View profile">
        <?php
          $user_initials = strtoupper(substr(trim($_SESSION['user_name'] ?? 'Student'), 0, 2));
          echo htmlspecialchars($user_initials);
        ?>
      </button>
      <a href="logout.php" class="logout-button">Logout</a>
    </div>
  </header>

  <div class="profile-modal" id="profileModal" aria-hidden="true">
    <div class="profile-modal-content">
      <button type="button" class="close-profile" id="closeProfile" aria-label="Close profile">&times;</button>
      <div class="profile-modal-header">
        <img src="https://ui-avatars.com/api/?name=<?php echo rawurlencode($_SESSION['user_name'] ?? 'Student'); ?>&background=003366&color=fff&size=200" alt="Profile avatar">
        <h3><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Student'); ?></h3>
      </div>
      <div class="profile-details">
        <div>
          <span>Student ID</span>
          <strong><?php echo htmlspecialchars($user['student_id'] ?? $student_id); ?></strong>
        </div>
        <div>
          <span>Email</span>
          <strong><?php echo htmlspecialchars($_SESSION['user_email'] ?? ($user['email'] ?? 'N/A')); ?></strong>
        </div>
        <div>
          <span>Mobile Phone</span>
          <strong><?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?></strong>
        </div>
        <div>
          <span>Role</span>
          <strong><?php echo htmlspecialchars(ucfirst($current_role)); ?></strong>
        </div>
                <div>
                  <span>Education Level</span>
                  <strong><?php echo $education_level === 'SHS' ? 'Senior High School' : 'College'; ?></strong>
                </div>
      </div>
              <button type="button" class="change-level-button" onclick="window.location.href='homepage.php?change_level=1'">Change Level</button>
    </div>
  </div>

  <section class="services">
    <div class="services-heading">
      <p class="section-kicker">Registrar office</p>
      <h2>Services available</h2>
      <p class="section-description">Choose the document or student record request you need.</p>
    </div>
    <div class="service-list" id="registrarServiceList">
      <?php foreach ($services as $srv): ?>
        <div class="service-card">
          <span class="service-card-icon" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
          <span><?php echo htmlspecialchars($srv['service_name']); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="booking">
    <div class="booking-heading">
      <p class="section-kicker">Plan your visit</p>
      <h2><i class="fas fa-calendar-days" aria-hidden="true"></i> Schedule an appointment</h2>
    </div>
    <div class="sched-notice">
      <i class="fas fa-circle-info" aria-hidden="true"></i>
      <strong>Registrar Schedule Control:</strong> Select your preferred day and time. The Registrar will review, assign, or adjust your official slot. Check your student portal for appointment updates.
    </div>
    <form method="post" action="book_appointment.php" id="bookingForm">
      <label>Select Registrar Service(s): <span>Appointments are available from 7:00 AM to 4:00 PM.</span></label>
      <div class="services-checkbox-group" id="servicesCheckboxGroup">
        <?php foreach ($services as $idx => $srv): ?>
          <label class="service-checkbox-item" for="svc_<?php echo $idx; ?>">
            <?php $service_state = student_service_state($srv); $service_disabled = $service_state === 'unavailable'; ?>
            <input type="checkbox" id="svc_<?php echo $idx; ?>" name="services[]" value="<?php echo htmlspecialchars($srv['service_name']); ?>" data-visible="<?php echo !empty($srv['is_visible']) ? '1' : '0'; ?>" data-from="<?php echo htmlspecialchars($srv['available_from'] ?? ''); ?>" data-until="<?php echo htmlspecialchars($srv['available_until'] ?? ''); ?>" <?php echo $service_disabled ? 'disabled' : ''; ?>>
            <span class="service-checkbox-label">
              <i class="fas fa-file-alt"></i>
              <?php echo htmlspecialchars($srv['service_name']); ?>
            </span>
            <span class="service-state-badge state-<?php echo $service_state; ?>" data-service-state><?php echo ucfirst($service_state); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="selected-services-summary" id="selectedServicesSummary" style="display:none;">
        <span><i class="fas fa-check-circle" style="color:#059669;"></i> Selected:</span>
        <div class="selected-services-pills" id="selectedServicesPills"></div>
      </div>

      <div class="week-calendar-toolbar"><button type="button" id="bookingPrevWeek">← Previous week</button><strong id="bookingWeekLabel"></strong><button type="button" id="bookingNextWeek">Next week →</button></div>
      <div class="booking-calendar-scroll"><div id="bookingWeeklyCalendar" class="booking-week-grid" aria-label="Weekly appointment availability"></div></div>
      <input type="hidden" name="date" id="date" value="<?php echo date('Y-m-d'); ?>">

      <input type="hidden" name="slot_id" id="selectedSlotId" value="">
      <input type="hidden" name="time" id="selectedTimeValue" value="" required>

     
      <div class="slots-picker-area" style="display:none">
        <div class="slots-picker-label">
          <span><i class="fas fa-clock" style="color: var(--accent-color);"></i> Select Open Time Slot:</span>
          <span id="slotsLoadingStatus" style="font-size: 0.8rem; color: var(--text-secondary);"></span>
        </div>
        <div id="slotsChipsGrid" class="slots-grid-chips">
      </div>


        <div id="noSlotsMessage" class="slots-empty-notice" style="display: none;">
          <strong><i class="fas fa-exclamation-triangle"></i> No Open Slots for this Date</strong>
          <p>The Registrar Office has not published availability for this day. Please choose another date or check back later.</p>
        </div>
      </div>

      <input type="hidden" name="office" value="registrar">
      <button type="submit" id="submitBookingBtn" disabled>Select at least one service and a time slot</button>
    </form>
  </section>

  <script>
    document.addEventListener('DOMContentLoaded', function () {

    const themeToggle = document.getElementById('themeToggle');
      if (themeToggle) {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.body.setAttribute('data-theme', savedTheme);
        const icon = themeToggle.querySelector('i');
        if (icon) {
          icon.classList.toggle('fa-sun', savedTheme === 'dark');
          icon.classList.toggle('fa-moon', savedTheme !== 'dark');
        }

        themeToggle.addEventListener('click', () => {
          const currentTheme = document.body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
          document.body.setAttribute('data-theme', currentTheme);
          localStorage.setItem('theme', currentTheme);
          if (icon) {
            icon.classList.toggle('fa-sun', currentTheme === 'dark');
            icon.classList.toggle('fa-moon', currentTheme !== 'dark');
          }
        });
      }

      const profileTrigger = document.getElementById('profileTrigger');
      const profileModal = document.getElementById('profileModal');
      const closeProfile = document.getElementById('closeProfile');

      if (profileTrigger && profileModal && closeProfile) {
        profileTrigger.addEventListener('click', () => {
          profileModal.classList.add('show');
          profileModal.setAttribute('aria-hidden', 'false');
        });
        closeProfile.addEventListener('click', () => {
          profileModal.classList.remove('show');
          profileModal.setAttribute('aria-hidden', 'true');
        });
        profileModal.addEventListener('click', (e) => {
          if (e.target === profileModal) {
            profileModal.classList.remove('show');
            profileModal.setAttribute('aria-hidden', 'true');
          }
        });
      }

      const dateInput = document.getElementById('date');
      const slotsGrid = document.getElementById('slotsChipsGrid');
      const noSlotsMsg = document.getElementById('noSlotsMessage');
      const statusSpan = document.getElementById('slotsLoadingStatus');
      const slotIdInput = document.getElementById('selectedSlotId');
      const timeValInput = document.getElementById('selectedTimeValue');
      const submitBtn = document.getElementById('submitBookingBtn');

      // -----------------------------------------------
      // Multi-service checkbox logic
      // -----------------------------------------------
      const checkboxGroup = document.getElementById('servicesCheckboxGroup');
      const summary = document.getElementById('selectedServicesSummary');
      const pillsContainer = document.getElementById('selectedServicesPills');
      let selectedServices = [];
      let slotSelected = false;

      function updateServiceSummary() {
        const checked = Array.from(checkboxGroup.querySelectorAll('input[type=checkbox]:checked'));
        selectedServices = checked.map(cb => cb.value);

        if (selectedServices.length > 0) {
          summary.style.display = 'flex';
          pillsContainer.replaceChildren();
          selectedServices.forEach(function (serviceName) { const pill = document.createElement('span'); pill.className = 'service-pill'; pill.textContent = serviceName; pillsContainer.appendChild(pill); });
        } else {
          summary.style.display = 'none';
          pillsContainer.innerHTML = '';
        }
        updateSubmitButton();
      }

      function updateSubmitButton() {
        if (selectedServices.length > 0 && slotSelected) {
          submitBtn.disabled = false;
          const count = selectedServices.length;
          submitBtn.textContent = `Confirm Registrar Appointment (${count} service${count > 1 ? 's' : ''})`;
        } else if (selectedServices.length === 0) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Select at least one service and a time slot';
        } else {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Select an Open Time Slot to Confirm';
        }
      }

      function refreshServiceAvailability() {
        const hasTargetSlot = slotSelected && dateInput.value && timeValInput.value;
        const target = hasTargetSlot ? new Date(dateInput.value + 'T' + timeValInput.value + ':00') : new Date();
        checkboxGroup.querySelectorAll('input[type=checkbox]').forEach(function (input) {
          const from = input.dataset.from ? new Date(input.dataset.from.replace(' ', 'T')) : null;
          const until = input.dataset.until ? new Date(input.dataset.until.replace(' ', 'T')) : null;
          const visible = input.dataset.visible === '1';
          const allowed = visible && (!until || target <= until) && (!hasTargetSlot || !from || target >= from);
          input.disabled = !allowed;
          if (!allowed && input.checked) input.checked = false;
          const badge = input.closest('.service-checkbox-item').querySelector('[data-service-state]');
          let state = !visible || (until && target > until) ? 'unavailable' : (from && target < from ? 'scheduled' : 'available');
          if (badge) { badge.textContent = state.charAt(0).toUpperCase() + state.slice(1); badge.className = 'service-state-badge state-' + state; }
          input.closest('.service-checkbox-item').classList.toggle('service-disabled', !allowed);
        });
        updateServiceSummary();
      }

      if (checkboxGroup) checkboxGroup.addEventListener('change', function (event) {
        if (!event.target.matches('input[type=checkbox]')) return;
        event.target.closest('.service-checkbox-item').classList.toggle('checked', event.target.checked);
        refreshServiceAvailability();
      });

      function renderLiveServices(services) {
        const serviceList = document.getElementById('registrarServiceList');
        if (!serviceList || !checkboxGroup) return;
        const selectedNames = new Set(Array.from(checkboxGroup.querySelectorAll('input:checked')).map(input => input.value));
        serviceList.replaceChildren();
        checkboxGroup.replaceChildren();
        services.forEach(function (service) {
          const name = String(service.service_name || '');
          const state = !Number(service.is_visible) || (service.available_until && new Date(service.available_until.replace(' ', 'T')) < new Date()) ? 'unavailable' : (service.available_from && new Date(service.available_from.replace(' ', 'T')) > new Date() ? 'scheduled' : 'available');
          const card = document.createElement('div');
          card.className = 'service-card';
          const cardIcon = document.createElement('i'); cardIcon.className = 'fas fa-file-alt'; cardIcon.style.marginRight = '8px'; cardIcon.style.color = 'var(--accent-color)';
          card.append(cardIcon, document.createTextNode(name)); serviceList.appendChild(card);

          const index = 'live_' + String(service.id);
          const item = document.createElement('label'); item.className = 'service-checkbox-item'; item.htmlFor = 'svc_' + index;
          const input = document.createElement('input'); input.type = 'checkbox'; input.id = 'svc_' + index; input.name = 'services[]'; input.value = name;
          input.dataset.visible = String(Number(service.is_visible)); input.dataset.from = service.available_from || ''; input.dataset.until = service.available_until || '';
          input.checked = selectedNames.has(name);
          const label = document.createElement('span'); label.className = 'service-checkbox-label';
          const icon = document.createElement('i'); icon.className = 'fas fa-file-alt'; label.append(icon, document.createTextNode(' ' + name));
          const badge = document.createElement('span'); badge.className = 'service-state-badge state-' + state; badge.dataset.serviceState = ''; badge.textContent = state.charAt(0).toUpperCase() + state.slice(1);
          item.append(input, label, badge); checkboxGroup.appendChild(item);
        });
        refreshServiceAvailability();
      }

      function loadSlotsForDate(selectedDate) {
        if (!selectedDate) return;

        statusSpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking open slots...';
        slotsGrid.innerHTML = '';
        noSlotsMsg.style.display = 'none';
        slotIdInput.value = '';
        timeValInput.value = '';
        slotSelected = false;
        updateSubmitButton();

        fetch(`api_slots.php?office=registrar&date=${encodeURIComponent(selectedDate)}`)
          .then(res => res.json())
          .then(data => {
            statusSpan.textContent = '';
            if (data.success && data.slots && data.slots.length > 0) {
              let availableCount = 0;

              data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'slot-btn-chip';
                btn.dataset.slotId = slot.id;
                btn.dataset.time = slot.start_time;

                if (!slot.is_available) {
                  btn.disabled = true;
                  btn.classList.add('slot-full');
                  btn.innerHTML = `
                    <span>${slot.label}</span>
                    <span class="slot-seats-badge">${slot.status === 'closed' ? 'CLOSED' : 'FULL'}</span>
                  `;
                } else {
                  availableCount++;
                  btn.innerHTML = `
                    <span>${slot.label}</span>
                    <span class="slot-seats-badge">${slot.seats_remaining} seat${slot.seats_remaining > 1 ? 's' : ''} left</span>
                  `;

                  btn.addEventListener('click', function () {
                    document.querySelectorAll('.slot-btn-chip').forEach(c => c.classList.remove('selected'));
                    btn.classList.add('selected');
                    slotIdInput.value = slot.id;
                    timeValInput.value = slot.start_time;
                    slotSelected = true;
                    updateSubmitButton();
                  });
                }

                slotsGrid.appendChild(btn);
              });

              if (availableCount === 0) {
                noSlotsMsg.style.display = 'block';
                noSlotsMsg.querySelector('p').textContent = 'All published slots for this date are currently full or closed. Please select another date.';
              }
            } else {
              noSlotsMsg.style.display = 'block';
            }
          })
          .catch(err => {
            statusSpan.textContent = 'Error checking slots.';
            noSlotsMsg.style.display = 'block';
          });
      }

      const weeklyGrid = document.getElementById('bookingWeeklyCalendar');
      const weekLabel = document.getElementById('bookingWeekLabel');
      let visibleWeek = new Date(dateInput.value + 'T12:00:00');
      visibleWeek.setDate(visibleWeek.getDate() - ((visibleWeek.getDay() + 6) % 7));
      function isoDay(date) { return date.getFullYear() + '-' + String(date.getMonth()+1).padStart(2,'0') + '-' + String(date.getDate()).padStart(2,'0'); }
      function drawWeeklyCalendar(preserveSelection) {
        if (!weeklyGrid) return;
        if (!preserveSelection) { slotSelected = false; slotIdInput.value = ''; timeValInput.value = ''; refreshServiceAvailability(); }
        const dates = Array.from({length:5}, (_,i) => { const d=new Date(visibleWeek); d.setDate(d.getDate()+i); return d; });
        weekLabel.textContent = dates[0].toLocaleDateString(undefined,{month:'short',day:'numeric'}) + ' – ' + dates[4].toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});
        weeklyGrid.innerHTML = '<div class="booking-grid-header">Time</div>' + dates.map(d=>'<div class="booking-grid-header">'+d.toLocaleDateString(undefined,{weekday:'short',month:'numeric',day:'numeric'})+'</div>').join('');
        Promise.all(dates.map(d=>fetch('api_slots.php?office=registrar&date='+encodeURIComponent(isoDay(d)),{cache:'no-store'}).then(r=>r.json()).catch(()=>({slots:[]})))).then(results=>{
          results.forEach((result,index)=>{
            const header=weeklyGrid.querySelectorAll('.booking-grid-header')[index+1];
            if(header && result.day_capacity !== null && result.day_capacity !== undefined){
              header.textContent += result.day_seats_remaining > 0 ? ' · '+result.day_seats_remaining+' daily seats' : ' · Daily limit reached';
              if(result.day_seats_remaining === 0)header.classList.add('booking-day-full');
            }
          });
          const byTime=results.map(result=>{const map={};(result.slots||[]).forEach(slot=>map[slot.start_time]=slot);return map;});
          if (preserveSelection && slotSelected) {
            const now = new Date();
            const stillOpen = results.some((result,index) => (result.slots||[]).some(slot => String(slot.id) === String(slotIdInput.value) && slot.is_available && (isoDay(dates[index]) > isoDay(now) || (isoDay(dates[index]) === isoDay(now) && slot.start_time > String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0')))));
            if (!stillOpen) { slotSelected = false; slotIdInput.value = ''; timeValInput.value = ''; refreshServiceAvailability(); }
          }
          const coveredUntil=dates.map(()=>0);
          for(let minute=420,rowIndex=0;minute<=960;minute+=15,rowIndex++){
            const hh=String(Math.floor(minute/60)).padStart(2,'0'),mm=String(minute%60).padStart(2,'0'),time=hh+':'+mm;
            const shown=new Date(2000,0,1,Math.floor(minute/60),minute%60).toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});
            const label=document.createElement('div');label.className='booking-grid-time';label.textContent=shown;label.style.gridColumn='1';label.style.gridRow=String(rowIndex+2);weeklyGrid.appendChild(label);
            dates.forEach((day,index)=>{
              if(rowIndex<coveredUntil[index])return;
              const slot=byTime[index][time];
              if(slot){
                const now=new Date();const today=isoDay(now);const currentTime=String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');const inFuture=isoDay(day)>today||(isoDay(day)===today&&slot.start_time>currentTime);const open=slot.is_available&&inFuture;
                const cell=document.createElement('button');cell.type='button';cell.className='booking-grid-slot '+(open?'is-open':'is-closed')+(slotSelected&&String(slot.id)===String(slotIdInput.value)?' is-selected':'');cell.disabled=!open;
                const duration=Math.max(15,((Number(slot.end_time.slice(0,2))*60+Number(slot.end_time.slice(3,5)))-minute));const span=Math.max(1,Math.ceil(duration/15));coveredUntil[index]=rowIndex+span;cell.style.gridColumn=String(index+2);cell.style.gridRow=(rowIndex+2)+' / span '+span;
                cell.textContent=open?slot.start_formatted+' · '+slot.seats_remaining+' open':(!inFuture?'Past':(slot.status==='closed'?'Closed':'Full'));
                cell.title=slot.label+' ('+slot.status+')';
                if(open)cell.addEventListener('click',function(){weeklyGrid.querySelectorAll('.booking-grid-slot').forEach(b=>b.classList.remove('is-selected'));cell.classList.add('is-selected');dateInput.value=isoDay(day);slotIdInput.value=slot.id;timeValInput.value=slot.start_time;slotSelected=true;refreshServiceAvailability();});
                weeklyGrid.appendChild(cell);
              }else{const empty=document.createElement('div');empty.className='booking-grid-empty';empty.textContent=(minute>=720&&minute<780)?'Lunch':'—';empty.style.gridColumn=String(index+2);empty.style.gridRow=String(rowIndex+2);weeklyGrid.appendChild(empty);}
            });
          }
        });
      }
      document.getElementById('bookingPrevWeek').addEventListener('click',function(){visibleWeek.setDate(visibleWeek.getDate()-7);drawWeeklyCalendar();});
      document.getElementById('bookingNextWeek').addEventListener('click',function(){visibleWeek.setDate(visibleWeek.getDate()+7);drawWeeklyCalendar();});
      drawWeeklyCalendar();

      function pollRegistrarChanges() {
        if (document.hidden) return;
        fetch('api_student_services.php', { cache: 'no-store', headers: { Accept: 'application/json' } })
          .then(response => { if (!response.ok) throw new Error('Schedule sync unavailable'); return response.json(); })
          .then(data => { if (data.success && Array.isArray(data.services)) renderLiveServices(data.services); })
          .catch(() => {});
        drawWeeklyCalendar(true);
      }
      window.setInterval(pollRegistrarChanges, 12000);

      if (dateInput) {
        dateInput.addEventListener('change', function () {
          loadSlotsForDate(this.value);
        });

       
        loadSlotsForDate(dateInput.value);
      }

      const bookingForm = document.getElementById('bookingForm');
      if (bookingForm) {
        bookingForm.addEventListener('submit', function (event) {
          event.preventDefault();
          submitBtn.disabled = true;
          submitBtn.textContent = 'Submitting appointment...';
          fetch('book_appointment.php', { method: 'POST', body: new FormData(bookingForm), headers: { Accept: 'application/json' } })
            .then(res => res.json())
            .then(data => {
              if (!data.success) throw new Error(data.message || 'Unable to book appointment.');
              AppPopup.show(data.message, 'success');
              bookingForm.reset();
              selectedServices = []; slotSelected = false;
              drawWeeklyCalendar();
              loadSlotsForDate(dateInput.value);
              updateSubmitButton();
            })
            .catch(err => { AppPopup.show(err.message || 'Unable to book appointment. Please try again.', 'error'); updateSubmitButton(); });
        });
      }
    });
  </script>
</body>

</html>
