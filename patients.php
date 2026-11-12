<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$patients = [];
$db_error = null;

// CSRF token for the activate / deactivate buttons
if (empty($_SESSION['patients_csrf'])) $_SESSION['patients_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['patients_csrf'];

// ---- activate / deactivate a patient account ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        flash('Your session expired. Please try again.', 'error');
    } else {
        $pid = (int)($_POST['patient_id'] ?? 0);
        $new = (($_POST['to'] ?? '') === 'Active') ? 'Active' : 'Inactive';
        try {
            $pdo = db();
            // The admin already only sees their own branch's patients in the list, so look the
            // patient up by id alone (the old branch re-check could wrongly say "not found").
            $st = $pdo->prepare("SELECT first_name, last_name, status FROM patients WHERE id = :id");
            $st->execute([':id' => $pid]);
            $row = $st->fetch();
            if (!$row) {
                flash('That patient was not found.', 'error');
            } else {
                $up = $pdo->prepare("UPDATE patients SET status = :s, updated_at = now() WHERE id = :id");
                $up->execute([':s' => $new, ':id' => $pid]);
                $full = trim($row['first_name'] . ' ' . $row['last_name']);
                if ($up->rowCount() < 1) {
                    flash('Nothing was changed for ' . $full . '. Please try again.', 'error');
                } else {
                    try {   // best-effort audit entry
                        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
                            id bigserial PRIMARY KEY, admin_id integer, branch_id smallint,
                            action varchar(80) NOT NULL, detail text NOT NULL DEFAULT '', created_at timestamptz NOT NULL DEFAULT now())");
                    } catch (PDOException $ex) { /* ignore */ }
                    try {
                        $pdo->prepare("INSERT INTO admin_audit_logs (admin_id, branch_id, action, detail) VALUES (:a, :b, :x, :d)")
                            ->execute([':a' => $admin['id'], ':b' => $branch_id, ':x' => 'Patient account ' . ($new === 'Active' ? 'activated' : 'deactivated'), ':d' => $full]);
                    } catch (PDOException $ex) { /* ignore */ }
                    flash($full . ' is now ' . strtolower($new) . '.' . ($new === 'Inactive' ? ' They can no longer sign in.' : ''));
                }
            }
        } catch (PDOException $ex) {
            flash('Could not update the patient: ' . $ex->getMessage(), 'error');
        }
    }
    header('Location: patients.php');
    exit;
}
$flash = take_flash();

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.id, p.first_name, p.middle_name, p.last_name, concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name) AS full_name,
               p.gender, p.email, p.phone, p.age, p.status,
               UPPER(SUBSTRING(p.first_name FROM 1 FOR 1) || SUBSTRING(p.last_name FROM 1 FOR 1)) AS initials,
               (SELECT to_char(max(treatment_date), 'Mon DD, YYYY') FROM treatments WHERE patient_id = p.id) AS last_visit
        FROM patients p
        WHERE (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b))
        ORDER BY p.id ASC
    ");
    $stmt->execute([':b' => $branch_id]);
    $patients = $stmt->fetchAll();
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patients | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body data-guard="admin">
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item active" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
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
      <div><h2>Patients</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content tbl-fit">
      <div class="card">
        <div class="toolbar">
          <div class="search-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="patientSearch" placeholder="Search patients by name or email" oninput="filterPatients()">
          </div>
          <select class="p-status-filter" id="statusFilter" onchange="filterPatients()" aria-label="Filter by account status">
            <option value="">All accounts</option>
            <option value="active">Active only</option>
            <option value="inactive">Inactive / suspended</option>
          </select>
          <span style="font-size:12.5px;color:var(--muted);"><?= count($patients) ?> registered patients</span>
        </div>
        <div class="tbl-scroll">
        <table id="patientsTable">
          <thead>
            <tr><th>Patient</th><th>Contact</th><th>Age</th><th>Last Visit</th><th>Status</th><th>Record &amp; Chart</th><th>Account</th></tr>
          </thead>
          <tbody>
            <?php if (empty($patients)): ?>
              <tr>
                <td colspan="7" style="text-align:center;color:var(--muted);padding:40px;">
                  No registered patients found in database.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($patients as $p): ?>
                <?php $is_act = ($p['status'] === 'Active'); ?>
                <tr class="row-hover p-row<?= $is_act ? '' : ' p-inactive' ?>" data-status="<?= $is_act ? 'active' : 'inactive' ?>" data-search="<?= strtolower(htmlspecialchars($p['full_name'] . ' ' . $p['email'])) ?>">
                  <td>
                    <div class="row-people">
                      <div class="avatar-sm"><?= htmlspecialchars($p['initials']) ?></div>
                      <div>
                        <div class="name"><?= htmlspecialchars($p['full_name']) ?></div>
                        <div class="sub"><?= htmlspecialchars($p['gender']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <?= htmlspecialchars($p['email']) ?><br>
                    <span style="color:var(--muted);font-size:11.5px;"><?= htmlspecialchars($p['phone']) ?></span>
                  </td>
                  <td><?= htmlspecialchars($p['age']) ?></td>
                  <td><?= $p['last_visit'] ?: '—' ?></td>
                  <td><span class="badge <?= $is_act ? 'b-confirmed' : 'b-cancelled' ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                  <td>
                    <div style="display:flex;gap:6px;">
                      <a class="icon-btn js-pview" href="patient-record.php?patient=<?= (int)$p['id'] ?>" data-src="patient-record.php?patient=<?= (int)$p['id'] ?>&amp;embed=1" data-title="Dental Record &mdash; <?= htmlspecialchars($p['full_name'], ENT_QUOTES) ?>" title="View Dental Record">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                      </a>
                      <a class="icon-btn js-pview" href="chart.php?patient=<?= (int)$p['id'] ?>" data-src="chart.php?patient=<?= (int)$p['id'] ?>&amp;embed=1" data-title="Dental Chart &mdash; <?= htmlspecialchars($p['full_name'], ENT_QUOTES) ?>" title="Open Dental Chart">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                      </a>
                    </div>
                  </td>
                  <td>
                    <form method="post" class="tm-toggle" data-title="<?= $is_act ? 'Deactivate patient?' : 'Activate patient?' ?>" data-ok="<?= $is_act ? 'Deactivate' : 'Activate' ?>"<?= $is_act ? '' : ' data-danger="0"' ?> data-confirm="<?= $is_act ? 'Deactivate ' . htmlspecialchars($p['full_name'], ENT_QUOTES) . '? They will no longer be able to sign in to the patient portal.' : 'Activate ' . htmlspecialchars($p['full_name'], ENT_QUOTES) . '? They will be able to sign in to the patient portal again.' ?>">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="patient_id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="to" value="<?= $is_act ? 'Inactive' : 'Active' ?>">
                      <button type="submit" class="btn-xs<?= $is_act ? ' btn-xs-danger' : '' ?>"><?= $is_act ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- popup: dental record / dental chart (same pages, shown without the sidebar) -->
<div class="dm-back" id="pviewModal" role="dialog" aria-modal="true" aria-labelledby="pviewT" hidden>
  <div class="dm dm-wide">
    <div class="dm-head"><h3 id="pviewT"></h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
    <iframe id="pviewFrame" class="dm-frame" title="Patient details"></iframe>
  </div>
</div>

<?php if (!empty($flash)): ?>
<script>window.addEventListener('DOMContentLoaded',function(){var t=document.createElement('div');t.className='toast';t.textContent=<?= json_encode($flash['message']) ?>;<?php if (($flash['type'] ?? '') === 'error'): ?>t.style.background='#b3261e';t.style.color='#fff';<?php endif; ?>document.body.appendChild(t);setTimeout(function(){t.remove();},<?= ($flash['type'] ?? '') === 'error' ? 12000 : 3200 ?>);});</script>
<?php endif; ?>
<script src="dialog.js?v=20261110"></script>
<script>
function filterPatients() {
  const query = (document.getElementById('patientSearch').value || '').trim().toLowerCase();
  const st = document.getElementById('statusFilter').value;
  document.querySelectorAll('.p-row').forEach(row => {
    const s = row.dataset.search || '';
    const okText = s.includes(query);
    const okStatus = !st || row.dataset.status === st;
    row.style.display = (okText && okStatus) ? '' : 'none';
  });
}
document.addEventListener('submit', function (ev) {
  var f = ev.target.closest ? ev.target.closest('form.tm-toggle') : null;
  if (!f || f.dataset.ok === '1') return;
  var msg = f.getAttribute('data-confirm');
  if (!msg) return;
  ev.preventDefault();
  if (!window.msConfirm) { if (window.confirm(msg)) { f.dataset.ok = '1'; f.submit(); } return; }
  msConfirm({
    title: f.getAttribute('data-title') || 'Deactivate account?',
    message: msg,
    confirmText: f.getAttribute('data-ok') || 'Deactivate',
    cancelText: 'Cancel',
    danger: f.getAttribute('data-danger') !== '0', icon: f.getAttribute('data-danger') === '0' ? '' : 'warn'
  }).then(function (yes) { if (yes) { f.dataset.ok = '1'; f.submit(); } });
});

(function () {
  var back = document.getElementById('pviewModal'), frame = document.getElementById('pviewFrame'), title = document.getElementById('pviewT');
  function close() { back.classList.remove('open'); back.hidden = true; frame.src = 'about:blank'; document.body.classList.remove('dm-lock'); }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('.js-pview');
    if (a) { e.preventDefault(); title.textContent = a.dataset.title; frame.src = a.dataset.src; back.hidden = false; back.classList.add('open'); document.body.classList.add('dm-lock'); return; }
    if (!back.hidden && (e.target === back || e.target.closest('#pviewModal [data-close]'))) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !back.hidden) close(); });
})();
</script>
</body>
</html>
