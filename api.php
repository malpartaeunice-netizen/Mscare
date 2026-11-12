<?php
// MSCare - small JSON API for actions used by the patient pages.
// Every action requires a real signed-in patient session; there is no demo
// patient fallback (that fallback used to show another patient's name/data).
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$admin = current_admin();
$patient = current_patient();
$pid = $patient ? patient_id() : 0;
$aid = $admin ? admin_id() : 0;
$isAdmin = (bool)$admin;
$adminBranch = $admin ? (int)($admin['branch_id'] ?? 0) : 0;

if (!$patient && !$admin) {
    respond(['ok' => false, 'error' => 'Your session has expired. Please log in again.'], 401);
}

// A patient the clinic has deactivated is signed out immediately, even mid-session.
if ($patient && !$admin) {
    try {
        $stS = db()->prepare("SELECT status FROM patients WHERE id = :id");
        $stS->execute([':id' => $pid]);
        $curStatus = $stS->fetchColumn();
        if ($curStatus !== false && $curStatus !== 'Active') {
            unset($_SESSION['mscare_patient']);
            respond(['ok' => false, 'error' => 'Your account is inactive. Please contact the clinic.'], 403);
        }
    } catch (PDOException $ex) { /* DB hiccup: let the request continue */ }
}
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$action = (string)($data['action'] ?? $_GET['action'] ?? '');

try {
    $pdo = db();

    // ---------- Admin: fetch notifications ----------
    if ($action === 'fetch_admin_notifications') {
        if (!$admin) respond(['ok' => false, 'error' => 'Admin session required.'], 403);
        $rows = $pdo->prepare("SELECT id, title, message, is_read, created_at, patient_id, appointment_id FROM notifications WHERE (admin_id IS NULL OR admin_id = :aid) AND (branch_id IS NULL OR :ab = 0 OR branch_id = :ab) ORDER BY created_at DESC, id DESC LIMIT 50");
        $rows->execute([':aid' => $aid, ':ab' => $adminBranch]);
        $uc = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (admin_id IS NULL OR admin_id = :aid) AND (branch_id IS NULL OR :ab = 0 OR branch_id = :ab) AND is_read = FALSE");
        $uc->execute([':aid' => $aid, ':ab' => $adminBranch]);
        $unread = (int)$uc->fetchColumn();
        respond(['ok' => true, 'notifications' => $rows->fetchAll(), 'unread' => $unread]);
    }

    // ---------- Admin: mark notification(s) read ----------
    if ($action === 'mark_admin_notifications_read') {
        if (!$admin) respond(['ok' => false, 'error' => 'Admin session required.'], 403);
        $nid = (int)($data['id'] ?? 0);
        if ($nid > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND (admin_id IS NULL OR admin_id = :aid) AND (branch_id IS NULL OR :ab = 0 OR branch_id = :ab)");
            $stmt->execute([':id' => $nid, ':aid' => $aid, ':ab' => $adminBranch]);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE (admin_id IS NULL OR admin_id = :aid) AND (branch_id IS NULL OR :ab = 0 OR branch_id = :ab) AND is_read = FALSE");
            $stmt->execute([':aid' => $aid, ':ab' => $adminBranch]);
        }
        respond(['ok' => true]);
    }

    // ---------- Patient: cancel appointment ----------
    if ($action === 'cancel_appointment') {
        if (!$patient) respond(['ok' => false, 'error' => 'Patient session required.'], 403);
        $apptId = (int)($data['id'] ?? 0);
        if ($apptId <= 0) respond(['ok' => false, 'error' => 'Invalid appointment.']);

        $reason  = trim((string)($data['reason'] ?? ''));
        $details = trim((string)($data['details'] ?? ''));
        if ($reason === '') respond(['ok' => false, 'error' => 'Please choose a reason for cancellation.']);
        $reason  = function_exists('mb_substr') ? mb_substr($reason, 0, 80)   : substr($reason, 0, 80);
        $details = function_exists('mb_substr') ? mb_substr($details, 0, 200) : substr($details, 0, 200);
        // Must keep the "Cancelled by patient" prefix: the admin notification bell looks for it.
        $note = 'Cancelled by patient via portal: ' . $reason . ($details !== '' ? ' - ' . $details : '');

        $stmt = $pdo->prepare("
            UPDATE appointments
               SET status = 'Cancelled', admin_notes = :note, updated_at = now()
                WHERE id = :aid AND patient_id = :pid AND status IN ('Pending', 'Confirmed')
        ");
        $stmt->execute([':aid' => $apptId, ':pid' => $pid, ':note' => $note]);
        if ($stmt->rowCount() === 0) {
            respond(['ok' => false, 'error' => 'That appointment can no longer be cancelled.']);
        }
        try { require_once __DIR__ . '/includes/appt_mail.php'; appt_email($pdo, $apptId, 'patient_cancelled'); } catch (Throwable $mx) { error_log('MSCare cancel email: ' . $mx->getMessage()); }
        respond(['ok' => true]);
    }



    // ---------- Patient: reschedule appointment ----------
    if ($action === 'reschedule_appointment') {
        if (!$patient) respond(['ok' => false, 'error' => 'Patient session required.'], 403);
        $apptId = (int)($data['id'] ?? 0);
        $date   = trim((string)($data['date'] ?? ''));
        $time   = trim((string)($data['time'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));
        $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200);
        if ($apptId <= 0 || $date === '' || $time === '') respond(['ok' => false, 'error' => 'Please choose both a new date and time.']);

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) respond(['ok' => false, 'error' => 'Please choose a valid date.']);
        if ($date < date('Y-m-d')) respond(['ok' => false, 'error' => 'You cannot reschedule to a past date.']);
        if (!in_array($time, $slots, true)) respond(['ok' => false, 'error' => 'Please choose a valid time slot.']);
        $time_sql = date('H:i', strtotime($time));
        if ($date === date('Y-m-d') && $time_sql <= date('H:i')) {
            respond(['ok' => false, 'error' => 'That time has already passed today. Please choose a later time.']);
        }

        $cur = $pdo->prepare("SELECT a.status, a.branch_id, a.appointment_date, a.appointment_time, b.name AS branch_name, b.open_days
                                FROM appointments a JOIN branches b ON b.id = a.branch_id
                               WHERE a.id = :id AND a.patient_id = :pid");
        $cur->execute([':id' => $apptId, ':pid' => $pid]);
        $old = $cur->fetch();
        if (!$old) respond(['ok' => false, 'error' => 'Appointment not found.']);
        if (!in_array($old['status'], ['Pending', 'Confirmed'], true)) {
            respond(['ok' => false, 'error' => 'That appointment can no longer be rescheduled.']);
        }
        $days = pg_days($old['open_days']);
        if ($days && !in_array($dt->format('D'), $days, true)) {
            respond(['ok' => false, 'error' => $old['branch_name'] . ' is only open on ' . implode(', ', $days) . '. Please pick one of those days.']);
        }
        if ($old['appointment_date'] === $date && date('H:i', strtotime($old['appointment_time'])) === $time_sql) {
            respond(['ok' => false, 'error' => 'That is the same schedule. Please pick a different date or time.']);
        }

        $old_text = date('M j, Y', strtotime($old['appointment_date'])) . ' ' . date('g:i A', strtotime($old['appointment_time']));
        $new_text = date('M j, Y', strtotime($date)) . ' ' . date('g:i A', strtotime($time_sql));
        // Must keep the "Rescheduled by patient" prefix: the admin notification bell looks for it.
        $note = 'Rescheduled by patient via portal from ' . $old_text . ' to ' . $new_text . ($reason !== '' ? ': ' . $reason : '');

        try {
            // Goes back to Pending so the clinic can confirm the new schedule.
            $stmt = $pdo->prepare("UPDATE appointments
                                      SET appointment_date = :d, appointment_time = :t, status = 'Pending',
                                          admin_notes = :note, updated_at = now()
                                    WHERE id = :id AND patient_id = :pid AND status IN ('Pending', 'Confirmed')");
            $stmt->execute([':d' => $date, ':t' => $time_sql, ':note' => $note, ':id' => $apptId, ':pid' => $pid]);
        } catch (PDOException $ex) {
            if (is_slot_conflict($ex)) respond(['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.']);
            throw $ex;
        }
        if ($stmt->rowCount() === 0) respond(['ok' => false, 'error' => 'That appointment can no longer be rescheduled.']);

        // Show it as a fresh item in the clinic's notification bell again.
        try { $pdo->prepare("DELETE FROM admin_notif_reads WHERE notif_key = :k")->execute([':k' => 'p' . $apptId]); }
        catch (Throwable $nx) { /* table is created by the admin site on first use */ }
        // Patient's own notification bell.
        try {
            $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message) VALUES (:p, :a, :t, :m)")
                ->execute([':p' => $pid, ':a' => $apptId, ':t' => 'Reschedule Request Sent',
                           ':m' => 'Your appointment was moved from ' . $old_text . ' to ' . $new_text . '. The clinic will confirm the new schedule shortly.']);
        } catch (Throwable $nx) { error_log('MSCare reschedule notification: ' . $nx->getMessage()); }
        try {
            require_once __DIR__ . '/includes/appt_mail.php';
            appt_email($pdo, $apptId, 'patient_rescheduled_ack');
            appt_email($pdo, $apptId, 'patient_rescheduled');
        } catch (Throwable $mx) { error_log('MSCare reschedule email: ' . $mx->getMessage()); }
        respond(['ok' => true]);
    }




    // ---------- Patient: mark notification(s) read ----------
    if ($action === 'mark_notifications_read') {
        if (!$patient) respond(['ok' => false, 'error' => 'Patient session required.'], 403);
        $nid = (int)($data['id'] ?? 0);
        if ($nid > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND patient_id = :pid");
            $stmt->execute([':id' => $nid, ':pid' => $pid]);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE patient_id = :pid AND is_read = FALSE");
            $stmt->execute([':pid' => $pid]);
        }
        respond(['ok' => true]);
    }

    // Booking is done through patient-book.php (full validation lives there).

    respond(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (PDOException $ex) {
    if (is_slot_conflict($ex)) {
        respond(['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.']);
    }
    error_log('MSCare DB (api): ' . $ex->getMessage());
    respond(['ok' => false, 'error' => db_fail_text($ex, 'Something went wrong. Please try again.')], 500);
}
