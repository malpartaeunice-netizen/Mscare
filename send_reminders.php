<?php
// MSCare Admin - AUTOMATIC email reminders. Run from the command line / scheduler every 15-30 minutes:
//     php C:\xampp\htdocs\mscareadmin\send_reminders.php
// For each clinic: if Settings > Notifications > "Appointment reminders" is ON, every Pending/Confirmed
// appointment starting within "Send reminder (hours before)" gets ONE email (+ a portal bell message
// if the patient has an account). Already-reminded appointments are skipped.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line / Task Scheduler.\n"); }

require_once __DIR__ . '/includes/reminders.php';

$pdo = db();
ensure_reminder_schema($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (branch_id smallint NOT NULL, setting_key varchar(60) NOT NULL, setting_value text NOT NULL DEFAULT '', updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (branch_id, setting_key))");

function rs_setting(PDO $pdo, $branch, $key, $default) {
    $st = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE branch_id = :b AND setting_key = :k");
    $st->execute([':b' => $branch, ':k' => $key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

$sent = 0; $failed = 0;
foreach ($pdo->query("SELECT id FROM branches ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $bid) {
    if (rs_setting($pdo, $bid, 'n_reminder', '1') !== '1') continue;
    $hours = max(1, min(168, (int)rs_setting($pdo, $bid, 'reminder_hours', '24')));

    $st = $pdo->prepare("
        SELECT a.id, a.patient_id, s.name AS service_name, a.appointment_date, a.appointment_time
          FROM appointments a JOIN services s ON s.id = a.service_id
         WHERE a.branch_id = :b
           AND a.status IN ('Pending', 'Confirmed')
           AND a.email_reminded_at IS NULL
           AND (a.appointment_date + a.appointment_time) >  now()
           AND (a.appointment_date + a.appointment_time) <= now() + make_interval(hours => :h)
         ORDER BY a.appointment_date, a.appointment_time");
    $st->execute([':b' => $bid, ':h' => $hours]);

    foreach ($st->fetchAll() as $a) {
        [$ok, $err] = send_reminder_email($pdo, $a['id']);
        if ($ok) {
            $sent++;
            if ($a['patient_id']) {
                $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message) VALUES (:p, :a, 'Appointment reminder', :m)")
                    ->execute([':p' => $a['patient_id'], ':a' => $a['id'],
                        ':m' => 'Reminder: ' . $a['service_name'] . ' on ' . date('M j, Y', strtotime($a['appointment_date'])) . ' at ' . date('g:i A', strtotime($a['appointment_time'])) . '. Please arrive 10 minutes early.']);
            }
            echo "[" . date('Y-m-d H:i') . "] emailed appointment #{$a['id']}\n";
        } else {
            $failed++;
            echo "[" . date('Y-m-d H:i') . "] FAILED #{$a['id']}: $err\n";
            if (stripos($err, 'not set up') !== false) break 2;   // no sender configured - stop early
        }
    }
}
echo "Done. Sent $sent, failed $failed.\n";
