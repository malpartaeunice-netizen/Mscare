<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$next_appt = null; $upcoming_count = 0; $completed_count = 0;
$upcoming_list = []; $recent_treatments = [];
$gate = ['med_done' => false, 'consent' => 'none', 'can_book' => false];
$db_error = null;

try {
    $pdo = db();
    $base = "FROM appointments a
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             WHERE a.patient_id = :pid AND a.status IN ('Pending', 'Confirmed') AND a.appointment_date >= CURRENT_DATE
             ORDER BY a.appointment_date ASC, a.appointment_time ASC";
    $stmt = $pdo->prepare("SELECT a.id, a.ref, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
                                  s.name AS service_name, b.name AS branch_name, b.address AS branch_address, a.status $base LIMIT 5");
    $stmt->execute([':pid' => $pid]);
    $upcoming_list = $stmt->fetchAll();
    $next_appt = $upcoming_list[0] ?? null;

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE patient_id = :pid AND status IN ('Pending', 'Confirmed') AND appointment_date >= CURRENT_DATE");
    $stmt->execute([':pid' => $pid]);
    $upcoming_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE patient_id = :pid AND status = 'Completed'");
    $stmt->execute([':pid' => $pid]);
    $completed_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT procedure_name, tooth, treatment_date, to_char(treatment_date, 'Mon DD') AS date_formatted
                           FROM treatments WHERE patient_id = :pid ORDER BY treatment_date DESC LIMIT 5");
    $stmt->execute([':pid' => $pid]);
    $recent_treatments = $stmt->fetchAll();

    $gate = booking_status($pid);
} catch (PDOException $ex) {
    error_log('MSCare DB (dashboard): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your information right now.');
}

$badge = ['Confirmed' => 'b-confirmed', 'Pending' => 'b-pending', 'Completed' => 'b-completed', 'Cancelled' => 'b-cancelled', 'No-show' => 'b-noshow'];

$ptitle = 'Hello, ' . ucwords(strtolower($patient['first_name'])) . '.';
$psub   = '';   // no subtitle under the greeting
// Dashboard background. To change it, replace the file images/dashboard-bg.jpg with your own picture
// (same file name, JPG, ideally 1920x1080 or wider, no text on it). Set to '' to turn the background off.
$pbg    = 'images/dashboard-bg.jpg';
$pactive = 'dashboard';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<?php if (!$gate['can_book']): ?>
<?php endif; ?>

<div class="p-grid" style="margin-bottom:20px;">
  <?php if ($next_appt): $ts = strtotime($next_appt['appointment_date']); ?>
    <div class="visit-card">
      <div>
        <p class="visit-eyebrow">Your next visit</p>
        <div class="visit-main">
          <div class="date-tile"><span class="m"><?= date('M', $ts) ?></span><span class="d"><?= date('j', $ts) ?></span><span class="w"><?= date('D', $ts) ?></span></div>
          <div>
            <h3><?= e($next_appt['service_name']) ?></h3>
            <p><?= date('l, F j', $ts) ?> &middot; <?= e($next_appt['time_formatted']) ?></p>
          </div>
        </div>
      </div>
      <div class="visit-where"><?= icon($ui['pin'], 18) ?><span><?= e($next_appt['branch_name']) ?><br><?= e($next_appt['branch_address']) ?></span></div>
      <div class="btn-row">
        <a class="btn btn-primary btn-pill btn-sm" href="patient-appointments.php">View details</a>
        <?php if ($gate['can_book']): ?><a class="btn btn-outline-light btn-pill btn-sm" href="patient-book.php">Book another</a><?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="visit-card">
      <div>
        <p class="visit-eyebrow">No upcoming visits</p>
        <h3>You&rsquo;re all caught up</h3>
        <p>Book your next dental visit whenever you&rsquo;re ready.</p>
      </div>
      <div class="btn-row">
        <?php if ($gate['can_book']): ?>
          <a class="btn btn-primary btn-pill btn-sm" href="patient-book.php"><?= icon($ui['plus'], 16) ?> Book appointment</a>
        <?php else: ?>
          <a class="btn btn-primary btn-pill btn-sm" href="patient-record.php"><?= icon($ui['file'], 16) ?> Complete my record</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
