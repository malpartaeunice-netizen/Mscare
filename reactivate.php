<?php
// MSCare - opened from the "Reactivate my account" email. Unlocks an account that was
// locked after too many wrong passwords. The link works once and expires.
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    flash('That reactivation link is not valid.', 'error');
    header('Location: login.php');
    exit;
}

try {
    $st = db()->prepare("SELECT id FROM patients
                         WHERE reactivate_token = :h AND locked_at IS NOT NULL AND reactivate_expires > now() LIMIT 1");
    $st->execute([':h' => hash('sha256', $token)]);
    $id = $st->fetchColumn();
    if ($id) {
        db()->prepare("UPDATE patients SET locked_at = NULL, failed_attempts = 0, reactivate_token = NULL,
                       reactivate_expires = NULL, reactivate_sent_at = NULL WHERE id = :i")->execute([':i' => $id]);
        flash('Your account has been reactivated. You can now log in.');
    } else {
        flash('This reactivation link is invalid, already used, or has expired. Try logging in to get a new one.', 'error');
    }
} catch (PDOException $ex) {
    error_log('MSCare DB (reactivate): ' . $ex->getMessage());
    flash('Something went wrong. Please try again.', 'error');
}
header('Location: login.php');
exit;
