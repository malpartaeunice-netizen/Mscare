<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$patients = [];
$selected_patient = null;
$teeth_data = [];
$db_error = null;

try {
    $pdo = db();
    $p_stmt = $pdo->query("SELECT id, concat_ws(' ', first_name, NULLIF(middle_name, ''), last_name) AS full_name, age, gender FROM patients WHERE status = 'Active' ORDER BY full_name ASC");
    $patients = $p_stmt->fetchAll();

    $sel_id = (int)($_GET['patient'] ?? ($patients[0]['id'] ?? 1));

    $p_query = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
    $p_query->execute([':id' => $sel_id]);
    $selected_patient = $p_query->fetch();

    $t_stmt = $pdo->prepare("SELECT tooth_number, condition_code, notes FROM dental_charts WHERE patient_id = :id");
    $t_stmt->execute([':id' => $sel_id]);
    foreach ($t_stmt->fetchAll() as $row) {
        $teeth_data[(int)$row['tooth_number']] = $row['condition_code'];
    }
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dental chart / odontogram | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<script src="dialog.js?v=20261027"></script>
</head>
<body data-guard="admin"<?= isset($_GET['embed']) ? ' class="embed"' : '' ?>>
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
      <a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Revenue &amp; Reports</span></a>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= htmlspecialchars($admin['display_name']) ?></div>
          <div class="who-role"><?= htmlspecialchars($cinfo['place']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Dental chart / odontogram</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content chart-content">
     <div class="card pick-card" style="margin-bottom:18px;">
       <div class="toolbar">
         <div class="search-box">
           <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
           <input id="chartPatientSearch" placeholder="Search patients by name" oninput="filterChartPatients(this.value)">
         </div>
         <span style="font-size:12.5px;color:var(--muted);"><?= count($patients) ?> registered patients</span>
       </div>
       <table id="chartPatientsTable" style="display:none;">
         <thead><tr><th>Patient</th><th>Age</th><th>Gender</th><th>Chart</th></tr></thead>
         <tbody>
           <?php if (empty($patients)): ?>
             <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:40px;">No registered patients found.</td></tr>
           <?php else: foreach ($patients as $p): ?>
             <tr class="row-hover chart-p-row" data-search="<?= strtolower(htmlspecialchars($p['full_name'])) ?>">
               <td>
                 <div class="row-people">
                   <div class="avatar-sm"><?= htmlspecialchars(strtoupper(substr($p['full_name'],0,2))) ?></div>
                   <div class="name"><?= htmlspecialchars($p['full_name']) ?></div>
                 </div>
               </td>
               <td><?= (int)$p['age'] ?></td>
               <td><?= htmlspecialchars($p['gender']) ?></td>
               <td><a class="icon-btn" href="chart.php?patient=<?= (int)$p['id'] ?>" title="Open Dental Chart"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></td>
             </tr>
           <?php endforeach; endif; ?>
         </tbody>
       </table>
     </div>
      <?php if (!$selected_patient): ?>
        <div class="card"><p style="text-align:center;color:var(--muted);padding:40px;">No patient selected.</p></div>
      <?php else: ?>
        <div class="chart-layout">
          <div class="card">
            <div class="dchart" id="dchart" data-editable="true">
              <div class="dc-head">
                <span><b>Name:</b> <?= htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']) ?></span>
                <span><b>Age:</b> <?= htmlspecialchars($selected_patient['age']) ?></span>
                <span><b>Gender:</b> <?= htmlspecialchars($selected_patient['gender']) ?></span>
                <span><b>Date:</b> <?= date('M j, Y') ?></span>
              </div>
              <div class="dc-arch">
                <div class="d-label">PERMANENT TEETH (UPPER)</div>
                <div class="dc-row">
                  <div class="half r">
                    <?php for ($t = 18; $t >= 11; $t--): ?>
                      <button type="button" class="tooth <?= isset($teeth_data[$t]) ? 'st-c' : '' ?>" data-n="<?= $t ?>" onclick="flagTooth(<?= (int)$selected_patient['id'] ?>, <?= $t ?>)">
                        <span class="tn"><?= $t ?></span><span class="tc"><?= $teeth_data[$t] ?? '' ?></span>
                      </button>
                    <?php endfor; ?>
                  </div>
                  <div class="half l">
                    <?php for ($t = 21; $t <= 28; $t++): ?>
                      <button type="button" class="tooth <?= isset($teeth_data[$t]) ? 'st-c' : '' ?>" data-n="<?= $t ?>" onclick="flagTooth(<?= (int)$selected_patient['id'] ?>, <?= $t ?>)">
                        <span class="tn"><?= $t ?></span><span class="tc"><?= $teeth_data[$t] ?? '' ?></span>
                      </button>
                    <?php endfor; ?>
                  </div>
                </div>

                <div class="dc-hr" style="margin:14px 0;border-top:1px dashed #cbd5e1;"></div>

                <div class="d-label">PERMANENT TEETH (LOWER)</div>
                <div class="dc-row">
                  <div class="half r">
                    <?php for ($t = 48; $t >= 41; $t--): ?>
                      <button type="button" class="tooth <?= isset($teeth_data[$t]) ? 'st-c' : '' ?>" data-n="<?= $t ?>" onclick="flagTooth(<?= (int)$selected_patient['id'] ?>, <?= $t ?>)">
                        <span class="tn"><?= $t ?></span><span class="tc"><?= $teeth_data[$t] ?? '' ?></span>
                      </button>
                    <?php endfor; ?>
                  </div>
                  <div class="half l">
                    <?php for ($t = 31; $t <= 38; $t++): ?>
                      <button type="button" class="tooth <?= isset($teeth_data[$t]) ? 'st-c' : '' ?>" data-n="<?= $t ?>" onclick="flagTooth(<?= (int)$selected_patient['id'] ?>, <?= $t ?>)">
                        <span class="tn"><?= $t ?></span><span class="tc"><?= $teeth_data[$t] ?? '' ?></span>
                      </button>
                    <?php endfor; ?>
                  </div>
                </div>
              </div>
              <div class="dc-legend" style="margin-top:20px;">
                <span><i style="background:#AFC2CC;"></i>Healthy</span>
                <span><i style="background:#E7A93C;"></i>Cavity (C)</span>
                <span><i style="background:#2A6FA0;"></i>Filling (F)</span>
                <span><i style="background:#7E6BC9;"></i>Crown (CR)</span>
                <span><i style="background:#E5484D;"></i>Root Canal (RT)</span>
                <span><i style="background:#8A4A3A;"></i>Missing (M)</span>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function filterChartPatients(q) {
  const query = (q || '').trim().toLowerCase();
  const table = document.getElementById('chartPatientsTable');
  if (table) table.style.display = query ? '' : 'none';
  document.querySelectorAll('.chart-p-row').forEach(function (row) {
    const s = row.dataset.search || '';
    row.style.display = (query && s.includes(query)) ? '' : 'none';
  });
}
async function flagTooth(patientId, toothNum) {
  const code = await msChoice({
    title: 'Tooth #' + toothNum,
    message: 'Choose the condition to record for this tooth.',
    options: [
      { value: 'C',    label: 'Cavity' },
      { value: 'F',    label: 'Filled' },
      { value: 'CR',   label: 'Crown' },
      { value: 'RT',   label: 'Root canal' },
      { value: 'M',    label: 'Missing' },
      { value: 'NONE', label: 'Clear', hint: 'Remove the recorded condition' }
    ],
    value: 'C', confirmText: 'Save'
  });
  if (code === null) return;

  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'save_tooth',
        patient_id: patientId,
        tooth_number: toothNum,
        condition_code: code.trim().toUpperCase()
      })
    });
    const d = await res.json();
    if (d.ok) {
      location.reload();
    } else {
      msToast('Error: ' + (d.error || 'Failed to update tooth'), 'error');
    }
  } catch (e) {
    msToast('Network error: ' + e.message, 'error');
  }
}
</script>
</body>
</html>
