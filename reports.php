<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/revenue.php';
require_once __DIR__ . '/includes/dash_helpers.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

// ---- which year? (defaults to this year) ----
$this_year = (int)date('Y');
$year = (int)($_GET['year'] ?? $this_year);
if ($year < 2000 || $year > $this_year) $year = $this_year;

$db_error = null;
$years = [$this_year, $this_year - 1];
$R = null;
try {
    $pdo = db();
    $years = rev_years($pdo, $branch_id);
    if (!in_array($year, $years, true)) $year = $this_year;
    $R = rev_year_report($pdo, $branch_id, $year);
} catch (PDOException $ex) {
    $db_error = $ex->getMessage();
}
if ($R === null) $R = ['year' => $year, 'rev' => array_fill(1, 12, 0), 'paid' => array_fill(1, 12, 0), 'appts' => array_fill(1, 12, 0), 'done' => array_fill(1, 12, 0),
    'prev_rev' => array_fill(1, 12, 0), 'prev_paid' => array_fill(1, 12, 0), 'prev_appts' => array_fill(1, 12, 0), 'prev_done' => array_fill(1, 12, 0),
    'status' => array_fill(1, 12, []), 'methods' => array_fill_keys(REV_METHODS, 0.0), 'treatments' => [], 'patients' => [], 'visits' => array_fill(1, 12, 0),
    'new_patients' => array_fill(1, 12, 0), 'records' => []];

// ---- downloads: ?export=excel (everything) or ?export=monthly|patients|appointments|treatments ----
$export = $_GET['export'] ?? '';
if ($export !== '' && !$db_error) {
    $which = in_array($export, ['monthly', 'patients', 'appointments', 'treatments'], true) ? $export : 'all';
    $file = 'mscare-' . ($which === 'all' ? 'revenue-report' : $which . '-report') . '-' . $year . '.xlsx';
    rev_send_xlsx($file, rev_report_sheets($R, $which, $cinfo['label']));
}

// ---- headline numbers ----
$total_rev   = array_sum($R['rev']);        $prev_rev   = array_sum($R['prev_rev']);
$total_appts = array_sum($R['appts']);      $prev_appts = array_sum($R['prev_appts']);
$total_done  = array_sum($R['done']);       $prev_done  = array_sum($R['prev_done']);
$total_paid  = array_sum($R['paid']);       $prev_paid  = array_sum($R['prev_paid']);
$cmp = 'vs. ' . ($year - 1);
$d_rev = dash_delta($total_rev, $prev_rev);     $d_appts = dash_delta($total_appts, $prev_appts);
$d_done = dash_delta($total_done, $prev_done);  $d_paid = dash_delta($total_paid, $prev_paid);

$n_elapsed = rev_elapsed_months($year);
$mn = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$mfull = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$has_prev = array_sum($R['prev_rev']) > 0;
$chart = ['labels' => $mn, 'rev' => array_values($R['rev']), 'prev' => array_values($R['prev_rev']), 'hasPrev' => $has_prev, 'year' => $year];

$method_colors = ['Cash' => '#139C93', 'Card' => '#3B82F6', 'GCash' => '#F5A93B', 'Bank Transfer' => '#A78BFA'];
$top_tx = array_slice($R['treatments'], 0, 5, true);

// monthly summary rows (growth = vs. the month before; January compares with last December)
$summary = [];
for ($m = 1; $m <= $n_elapsed; $m++) {
    $prev = $m > 1 ? $R['rev'][$m - 1] : $R['prev_rev'][12];
    $summary[] = ['name' => $mfull[$m - 1], 'appts' => $R['appts'][$m], 'rev' => $R['rev'][$m], 'g' => dash_delta($R['rev'][$m], $prev)];
}

$quick = [
    ['monthly',      'Monthly Revenue Report',   'Excel', 'red',   '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h6"/>'],
    ['patients',     'Patient Statistics Report', 'Excel', 'blue',  '<circle cx="9" cy="8" r="3"/><path d="M3 20c.8-3.4 3-5 6-5s5.2 1.6 6 5"/><path d="M17 8v6M14 11h6"/>'],
    ['appointments', 'Appointment Report',       'Excel', 'green', '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 15l2 2 4-4"/>'],
    ['treatments',   'Treatment Report',         'Excel', 'coral', '<path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/>'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Revenue &amp; Reports | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
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
      <a class="nav-item active" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Revenue &amp; Reports</span></a>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1.5 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= e($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= e($admin['display_name']) ?></div>
          <div class="who-role"><?= e($cinfo['place']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Revenue &amp; Reports</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= e($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= e($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content content-wide rv-page">
      <?php if (!empty($db_error)): ?><div class="login-error" style="display:block;"><?= e($db_error) ?></div><?php endif; ?>

      <!-- page header -->
      <div class="rv-head">
        <div class="rv-actions no-print">
          <form method="get" action="reports.php" class="rv-year">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            <select name="year" onchange="this.form.submit()" aria-label="Report year">
              <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y ?>"<?= $y === $year ? ' selected' : '' ?>><?= e($y === $this_year ? 'This Year (' . $y . ')' : ($y === $this_year - 1 ? 'Last Year (' . $y . ')' : (string)$y)) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <button type="button" class="rv-btn rv-btn-pdf" onclick="window.print()"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 14h6M9 18h4"/></svg>Export PDF</button>
          <a class="rv-btn rv-btn-xls" href="reports.php?year=<?= $year ?>&amp;export=excel"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9.5 12l5 6M14.5 12l-5 6"/></svg>Export Excel</a>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="rv-kpis">
        <div class="rv-kpi">
          <div class="rv-kpi-ico" style="background:#139C93;font:700 28px 'Inter',sans-serif;">₱</div>
          <div class="rv-kpi-body">
            <div class="rv-kpi-num">₱<?= number_format($total_rev) ?></div>
            <div class="rv-kpi-label">Total Revenue</div>
            <?= dash_delta_html($d_rev, $cmp) ?>
          </div>
        </div>
        <div class="rv-kpi">
          <div class="rv-kpi-ico" style="background:#1E9BC9;"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h2M12 14h2M16 14h.01M8 18h2M12 18h2"/></svg></div>
          <div class="rv-kpi-body">
            <div class="rv-kpi-num"><?= number_format($total_appts) ?></div>
            <div class="rv-kpi-label">Total Appointments</div>
            <?= dash_delta_html($d_appts, $cmp) ?>
          </div>
        </div>
        <div class="rv-kpi">
          <div class="rv-kpi-ico" style="background:#139C93;"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg></div>
          <div class="rv-kpi-body">
            <div class="rv-kpi-num"><?= number_format($total_done) ?></div>
            <div class="rv-kpi-label">Completed</div>
            <?= dash_delta_html($d_done, $cmp) ?>
          </div>
        </div>
        <div class="rv-kpi">
          <div class="rv-kpi-ico" style="background:#F5A93B;"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M16 14.5h2"/><path d="M5 6l11-2.5"/></svg></div>
          <div class="rv-kpi-body">
            <div class="rv-kpi-num">₱<?= number_format($total_paid) ?></div>
            <div class="rv-kpi-label">Collected Amount</div>
            <?= dash_delta_html($d_paid, $cmp) ?>
          </div>
        </div>
      </div>

      <!-- trend + payment methods -->
      <div class="rv-row2">
        <div class="card rv-card">
          <div class="section-head">
            <h4><svg class="h-ico" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 20V11M12 20V4M19 20v-6"/></svg>Monthly Revenue Trend</h4>
            <div class="chart-legend"><span><i style="background:#139C93"></i>Revenue (₱)</span><?php if ($has_prev): ?><span><i class="ln" style="background:#5CC8C0"></i>Last Year</span><?php endif; ?></div>
          </div>
          <div class="rev-chart" id="trendChart"></div>
        </div>

        <div class="card rv-card">
          <div class="section-head"><h4><svg class="h-ico" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/></svg>Revenue by Payment Method</h4></div>
          <div class="rv-donut-wrap">
            <div class="rv-donut">
              <svg viewBox="0 0 200 200" role="img" aria-label="Revenue by payment method">
                <circle cx="100" cy="100" r="70" fill="none" stroke="#EEF3F4" stroke-width="30"/>
                <?php
                  $circ = 2 * M_PI * 70; $acc = 0;
                  if ($total_rev > 0) foreach ($R['methods'] as $name => $amt) {
                      if ($amt <= 0) continue;
                      $len = $amt / $total_rev * $circ;
                      echo '<circle cx="100" cy="100" r="70" fill="none" stroke="' . $method_colors[$name] . '" stroke-width="30" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" stroke-dashoffset="' . round(-$acc, 2) . '" transform="rotate(-90 100 100)"><title>' . e($name) . ': ' . e(peso($amt)) . '</title></circle>';
                      $acc += $len;
                  }
                ?>
              </svg>
              <div class="rv-donut-center"><b>₱<?= number_format($total_rev) ?></b><span>Total Revenue</span></div>
            </div>
            <ul class="rv-donut-legend">
              <?php foreach ($R['methods'] as $name => $amt): ?>
                <li><i style="background:<?= $method_colors[$name] ?>"></i><span><?= e($name) ?></span><b><?= $total_rev > 0 ? number_format($amt / $total_rev * 100, 1) : '0.0' ?>%</b></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

      <!-- top treatments / monthly summary / quick reports -->
      <div class="rv-row3">
        <div class="card rv-card">
          <div class="section-head"><h4><svg class="h-ico" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>Top Treatments by Revenue</h4></div>
          <?php if (!$top_tx): ?>
            <div class="side-empty">No treatment records in <?= $year ?>.</div>
          <?php else: ?>
          <table class="rv-table">
            <thead><tr><th>Treatment</th><th>Revenue</th><th>Percentage</th></tr></thead>
            <tbody>
              <?php foreach ($top_tx as $name => $t): ?>
                <tr>
                  <td><span class="rv-tooth"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg></span><?= e($name) ?></td>
                  <td>₱<?= number_format($t['rev']) ?></td>
                  <td><?= $total_rev > 0 ? number_format($t['rev'] / $total_rev * 100, 1) : '0.0' ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
          <a class="rv-more" href="treatments.php">View Details <span>→</span></a>
        </div>

        <div class="card rv-card">
          <div class="section-head"><h4><svg class="h-ico" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 20V11M12 20V4M19 20v-6"/></svg>Monthly Summary</h4></div>
          <?php if (!$summary): ?>
            <div class="side-empty">No months to show for <?= $year ?> yet.</div>
          <?php else: ?>
          <div class="rv-sum" id="rvSum">
            <table class="rv-table">
              <thead><tr><th>Month</th><th>Appointments</th><th>Revenue</th><th>Growth</th></tr></thead>
              <tbody>
                <?php $cnt = count($summary); foreach ($summary as $i => $s): ?>
                  <tr<?= $i < $cnt - 5 ? ' class="rv-old"' : '' ?>>
                    <td><?= e($s['name']) ?></td>
                    <td><?= number_format($s['appts']) ?></td>
                    <td>₱<?= number_format($s['rev']) ?></td>
                    <td><?php if (!$s['g']): ?><span class="muted-dash">—</span><?php else: ?><span class="rv-growth <?= $s['g']['dir'] ?>"><?= $s['g']['dir'] === 'up' ? '↑' : ($s['g']['dir'] === 'down' ? '↓' : '→') ?> <?= e($s['g']['txt']) ?></span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($cnt > 5): ?><button type="button" class="rv-more link-btn" id="rvSumToggle" onclick="var s=document.getElementById('rvSum');s.classList.toggle('open');this.firstChild.nodeValue=s.classList.contains('open')?'Show Less ':'View Full Report ';">View Full Report <span>→</span></button><?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="card rv-card">
          <div class="section-head"><h4><svg class="h-ico" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h6"/></svg>Quick Reports</h4></div>
          <div class="rv-quick">
            <?php foreach ($quick as $q): ?>
              <div class="rv-q">
                <div class="rv-q-ico rv-q-<?= $q[3] ?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><?= $q[4] ?></svg></div>
                <div class="rv-q-text"><b><?= e($q[1]) ?></b><span><?= e($q[2]) ?> &bull; <?= $year ?></span></div>
                <a class="rv-dl no-print" href="reports.php?year=<?= $year ?>&amp;export=<?= e($q[0]) ?>">Download</a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var TREND = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
/* ---------- monthly revenue trend: bars = this year's revenue (labelled), line = last year ---------- */
(function () {
  var box = document.getElementById('trendChart');
  if (!box) return;
  var W = 760, H = 380, L = 56, R = 16, T = 34, B = 34;
  function niceMax(v) {
    if (v <= 0) return 4;
    var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 4 ? 4 : f <= 5 ? 5 : 10) * p;
  }
  function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { maximumFractionDigits: 0 }); }
  function short(n) {
    if (n >= 1000000) return '₱' + +(n / 1000000).toFixed(2) + 'M';
    if (n >= 1000) return '₱' + +(n / 1000).toFixed(1) + 'k';
    return '₱' + Math.round(n);
  }
  function draw() {
    var n = TREND.labels.length, iw = W - L - R, ih = H - T - B, gw = iw / n;
    var all = TREND.rev.concat(TREND.hasPrev ? TREND.prev : []);
    var max = niceMax(Math.max.apply(null, all.concat([0])));
    var y = function (v) { return T + ih - v / max * ih; };
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Monthly revenue trend">';
    for (var g = 0; g <= 4; g++) {
      var gy = y(max * g / 4);
      s += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + gy + '" y2="' + gy + '" stroke="#E1EAEC"' + (g ? ' stroke-dasharray="4 4"' : '') + '/>';
      s += '<text x="' + (L - 10) + '" y="' + (gy + 4) + '" text-anchor="end">' + short(max * g / 4) + '</text>';
    }
    var bw = Math.min(40, gw * 0.62), pts = [];
    for (var i = 0; i < n; i++) {
      var cx = L + gw * i + gw / 2, v = TREND.rev[i];
      if (v > 0) {
        s += '<rect x="' + (cx - bw / 2) + '" y="' + y(v) + '" width="' + bw + '" height="' + (T + ih - y(v)) + '" rx="3" fill="#139C93"/>';
        s += '<text class="vlbl" x="' + cx + '" y="' + (y(v) - 8) + '" text-anchor="middle">' + short(v) + '</text>';
      }
      s += '<text x="' + cx + '" y="' + (H - 10) + '" text-anchor="middle">' + TREND.labels[i] + '</text>';
      if (TREND.hasPrev) pts.push([cx, y(TREND.prev[i])]);
    }
    if (pts.length) {
      s += '<path d="M' + pts.map(function (p) { return p[0] + ' ' + p[1]; }).join(' L') + '" fill="none" stroke="#5CC8C0" stroke-width="2.2" stroke-linejoin="round"/>';
      pts.forEach(function (p) { s += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3.8" fill="#5CC8C0" stroke="#fff" stroke-width="1.6"/>'; });
    }
    for (var k = 0; k < n; k++) s += '<rect class="perf-hit" data-i="' + k + '" x="' + (L + gw * k) + '" y="' + T + '" width="' + gw + '" height="' + ih + '" fill="transparent"/>';
    s += '</svg><div class="rev-tip perf-tip" hidden></div>';
    box.innerHTML = s;
    var tip = box.querySelector('.perf-tip'), svg = box.querySelector('svg');
    function show(i) {
      var k = svg.getBoundingClientRect().width / W, cx = L + gw * i + gw / 2;
      tip.textContent = TREND.labels[i] + ' ' + TREND.year + '\nRevenue: ' + peso(TREND.rev[i]) + (TREND.hasPrev ? '\nLast year: ' + peso(TREND.prev[i]) : '');
      tip.style.left = (cx * k) + 'px'; tip.style.top = (T * k + 6) + 'px'; tip.hidden = false;
    }
    box.querySelectorAll('.perf-hit').forEach(function (r) {
      r.addEventListener('mouseenter', function () { show(+r.dataset.i); });
      r.addEventListener('click', function () { show(+r.dataset.i); });
    });
    svg.addEventListener('mouseleave', function () { tip.hidden = true; });
  }
  draw();
  window.addEventListener('resize', draw);
})();</script>
</body>
</html>
