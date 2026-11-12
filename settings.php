<?php
// MSCare Admin - Settings hub (Clinic Profile, Appointment Preferences, Notifications,
// Account & Security, Services & Pricing, Privacy & System).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bell.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

// ---- sections shown in the menu (same order as the design) -------------------------
$SECTIONS = [
    'clinic'        => ['Clinic Profile',          '', 'M4 21V6l8-3v18M12 9h8v12M4 21h16M8 9h.01M8 13h.01M8 17h.01M16 13h.01M16 17h.01'],
    'appointments'  => ['Appointment Preferences', '', 'M8 3v3M16 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v6M4 6v13a1 1 0 0 0 1 1h6M17 22a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM17 14.5V17l1.5 1'],
    'notifications' => ['Notifications',           '', 'M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10.3 20a2 2 0 0 0 3.4 0'],
    'email'         => ['Email Sender',            '', 'M3 6h18v12H3zM3 7l9 6 9-6'],
    'account'       => ['Account & Security',      '', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c0-3.9 3.1-7 7-7 1.4 0 2.7.4 3.8 1.1M18 15.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM18 13v1.5M18 20.5V22M21 14.5l-1.3.8M16.3 19.7 15 20.5M21 20.5l-1.3-.8M16.3 15.3 15 14.5'],
    'services'      => ['Services & Pricing',      '', 'M6 3v6a4 4 0 0 0 8 0V3M10 13v3a5 5 0 0 0 10 0v-2M20 11a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM4 3h4M12 3h4'],
    'privacy'       => ['Privacy & System',        '', 'M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6zM9 12l2 2 4-4'],
    'team'          => ['Team & Accounts',         '', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c.8-4 3.8-6 8-6s7.2 2 8 6'],
];
$sec = (string)($_GET['s'] ?? '');
if (!isset($SECTIONS[$sec])) $sec = '';

function icon_path($d, $size = 20) {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

// ---- storage: created on first visit so no manual migration is needed -------------
$db_error = null;
$pdo = null;
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (
        branch_id smallint NOT NULL,
        setting_key varchar(60) NOT NULL,
        setting_value text NOT NULL DEFAULT '',
        updated_at timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (branch_id, setting_key))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
        id bigserial PRIMARY KEY,
        admin_id integer,
        branch_id smallint,
        action varchar(80) NOT NULL,
        detail text NOT NULL DEFAULT '',
        created_at timestamptz NOT NULL DEFAULT now())");
} catch (PDOException $ex) {
    $db_error = $ex->getMessage();
}

$DEFAULTS = [
    'open_time' => '09:00', 'close_time' => '17:00',
    'slot_minutes' => '30', 'max_per_day' => '12', 'advance_days' => '60', 'cancel_hours' => '24', 'auto_confirm' => '0',
    'n_booking_alert' => '1', 'n_cancel_alert' => '1', 'n_patient_confirm' => '1', 'n_reminder' => '1', 'reminder_hours' => '24', 'alert_email' => '',
    'clinic_logo' => '', 'p_mask_records' => '1', 'p_session_minutes' => '30', 'p_backup_freq' => 'weekly',
];

function get_settings($pdo, $bid, $defaults) {
    $out = $defaults;
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM clinic_settings WHERE branch_id = :b");
    $st->execute([':b' => $bid]);
    foreach ($st->fetchAll() as $r) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}
function put_setting($pdo, $bid, $key, $val) {
    $st = $pdo->prepare("INSERT INTO clinic_settings (branch_id, setting_key, setting_value, updated_at)
                         VALUES (:b, :k, :v, now())
                         ON CONFLICT (branch_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()");
    $st->execute([':b' => $bid, ':k' => $key, ':v' => (string)$val]);
}
function audit($pdo, $admin_id, $bid, $action, $detail = '') {
    $st = $pdo->prepare("INSERT INTO admin_audit_logs (admin_id, branch_id, action, detail) VALUES (:a, :b, :x, :d)");
    $st->execute([':a' => $admin_id, ':b' => $bid, ':x' => $action, ':d' => $detail]);
}
function initials_of($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '' && strtolower($part) !== 'dr.') $letters .= mb_substr($part, 0, 1);
    }
    return mb_strtoupper(mb_substr($letters, 0, 2));
}
function pg_days_arr($v) {
    if (is_array($v)) return $v;
    $v = trim((string)$v, '{}');
    return $v === '' ? [] : array_map('trim', explode(',', $v));
}
function valid_time($t) { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t); }
function int_in($v, $min, $max, $fallback) {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    return ($n === false || $n < $min || $n > $max) ? $fallback : $n;
}

// CSRF token (per session)
if (empty($_SESSION['settings_csrf'])) $_SESSION['settings_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['settings_csrf'];

$errors = [];
$DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ---- save handlers ----------------------------------------------------------------
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = (string)($_POST['section'] ?? $sec);
    if (!isset($SECTIONS[$sec])) $sec = '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            if ($sec === 'clinic') {
                $name = trim((string)($_POST['name'] ?? ''));
                $addr = trim((string)($_POST['address'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $days = array_values(array_intersect($DAYS, (array)($_POST['days'] ?? [])));
                $open = (string)($_POST['open_time'] ?? ''); $close = (string)($_POST['close_time'] ?? '');
                if ($name === '' || $addr === '') $errors[] = 'Clinic name and address are required.';
                if (!$days) $errors[] = 'Select at least one operating day.';
                if (!valid_time($open) || !valid_time($close) || $open >= $close) $errors[] = 'Opening time must be earlier than closing time.';
                $logoPath = null;
                if (!empty($_FILES['clinic_logo']['name']) && $_FILES['clinic_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $lf = $_FILES['clinic_logo'];
                    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $mime = ($lf['error'] === UPLOAD_ERR_OK && is_uploaded_file($lf['tmp_name'])) ? (new finfo(FILEINFO_MIME_TYPE))->file($lf['tmp_name']) : '';
                    if ($lf['error'] !== UPLOAD_ERR_OK) $errors[] = 'The logo could not be uploaded. Try a smaller image.';
                    elseif (!isset($ext[$mime])) $errors[] = 'Logo must be a PNG, JPG, WEBP or GIF image.';
                    elseif ($lf['size'] > 2 * 1024 * 1024) $errors[] = 'Logo must be 2 MB or smaller.';
                    else {
                        $dir = __DIR__ . '/uploads';
                        if (!is_dir($dir)) @mkdir($dir, 0775, true);
                        foreach (glob($dir . '/logo_' . (int)$branch_id . '.*') ?: [] as $old) @unlink($old);
                        $rel = 'uploads/logo_' . (int)$branch_id . '.' . $ext[$mime];
                        if (move_uploaded_file($lf['tmp_name'], __DIR__ . '/' . $rel)) $logoPath = $rel;
                        else $errors[] = 'Could not save the logo file. Check that the uploads folder is writable.';
                    }
                }
                if (!$errors) {
                    if ($logoPath !== null) put_setting($pdo, $branch_id, 'clinic_logo', $logoPath);
                    $st = $pdo->prepare("UPDATE branches SET name = :n, address = :a, phone = :p, open_days = :d WHERE id = :id");
                    $st->execute([':n' => $name, ':a' => $addr, ':p' => $phone, ':d' => '{' . implode(',', $days) . '}', ':id' => $branch_id]);
                    put_setting($pdo, $branch_id, 'open_time', $open);
                    put_setting($pdo, $branch_id, 'close_time', $close);
                    audit($pdo, $admin['id'], $branch_id, 'Clinic profile updated', $name);
                }
            } elseif ($sec === 'appointments') {
                put_setting($pdo, $branch_id, 'slot_minutes', int_in($_POST['slot_minutes'] ?? '', 10, 120, 30));
                put_setting($pdo, $branch_id, 'max_per_day', int_in($_POST['max_per_day'] ?? '', 1, 100, 12));
                put_setting($pdo, $branch_id, 'advance_days', int_in($_POST['advance_days'] ?? '', 1, 365, 60));
                put_setting($pdo, $branch_id, 'cancel_hours', int_in($_POST['cancel_hours'] ?? '', 0, 168, 24));
                put_setting($pdo, $branch_id, 'auto_confirm', !empty($_POST['auto_confirm']) ? '1' : '0');
                audit($pdo, $admin['id'], $branch_id, 'Appointment preferences updated');
            } elseif ($sec === 'notifications') {
                $email = trim((string)($_POST['alert_email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid alert email address.';
                if (!$errors) {
                    foreach (['n_booking_alert', 'n_cancel_alert', 'n_patient_confirm', 'n_reminder'] as $k) {
                        put_setting($pdo, $branch_id, $k, !empty($_POST[$k]) ? '1' : '0');
                    }
                    put_setting($pdo, $branch_id, 'reminder_hours', int_in($_POST['reminder_hours'] ?? '', 1, 168, 24));
                    put_setting($pdo, $branch_id, 'alert_email', $email);
                    audit($pdo, $admin['id'], $branch_id, 'Notification settings updated');
                }
            } elseif ($sec === 'email' && ($_POST['do'] ?? '') === 'otp') {
                // How long a verification / password-reset code stays valid (saved on its own - no sender password needed)
                $mins = int_in($_POST['otp_minutes'] ?? '', 1, 60, 10);
                put_setting($pdo, 0, 'otp_minutes', $mins);
                audit($pdo, $admin['id'], $branch_id, 'Code validity updated', $mins . ' minutes');
                flash('Saved. New verification codes are valid for ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.');
                redirect_to('settings.php?s=email');
            } elseif ($sec === 'email') {
                require_once __DIR__ . '/includes/smtp.php';
                $cur = get_settings($pdo, 0, ['smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_name' => 'MSCare Dental Clinic', 'smtp_from_email' => '']);
                $host = trim((string)($_POST['smtp_host'] ?? '')) ?: 'smtp.gmail.com';
                $port = int_in($_POST['smtp_port'] ?? '', 1, 65535, 587);
                $user = trim((string)($_POST['smtp_username'] ?? ''));
                $pass = str_replace(' ', '', (string)($_POST['smtp_password'] ?? ''));
                if ($pass === '') $pass = $cur['smtp_password'];          // blank = keep the saved password
                $fname = trim((string)($_POST['smtp_from_name'] ?? '')) ?: 'MSCare Dental Clinic';
                $testTo = trim((string)($_POST['test_to'] ?? ''));
                if (!filter_var($user, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter the sender email address (e.g. yourclinic@gmail.com).';
                if ($pass === '') $errors[] = 'Enter the sender app password.';
                if (strtolower($user) === 'yourclinic@gmail.com' || strtolower($pass) === 'abcdefghijklmnop') $errors[] = 'Those are sample values. Enter the real sender Gmail and its App Password.';
                elseif ($pass !== '' && !preg_match('/^[a-z0-9]{16}$/i', $pass) && substr($user, -10) === '@gmail.com') $errors[] = 'A Gmail App Password is exactly 16 characters (shown as 4 groups of 4). Your normal Gmail password will not work - create an App Password at myaccount.google.com/apppasswords.';
                if ($testTo !== '' && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) $errors[] = 'The test email address is not valid.';
                if (!$errors) {
                    put_setting($pdo, 0, 'smtp_host', $host);
                    put_setting($pdo, 0, 'smtp_port', $port);
                    put_setting($pdo, 0, 'smtp_username', $user);
                    put_setting($pdo, 0, 'smtp_password', $pass);
                    put_setting($pdo, 0, 'smtp_from_name', $fname);
                    audit($pdo, $admin['id'], $branch_id, 'Email sender updated', $user);
                    if ($testTo !== '') {
                        [$ok, $err] = smtp_send_with(['host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass, 'from_name' => $fname, 'from_email' => '', 'verify_ssl' => false],
                            $testTo, 'MSCare test email', '<p>It works! MSCare can now send verification codes.</p>', 'It works! MSCare can now send verification codes.');
                        if ($ok) { flash('Email sender saved. Test email sent to ' . $testTo . ' - check the inbox (and Spam).'); }
                        else     { flash('Saved, but the test email FAILED: ' . $err, 'error'); }
                        redirect_to('settings.php?s=email');
                    }
                }
            } elseif ($sec === 'account') {
                $act = (string)($_POST['do'] ?? '');
                if ($act === 'profile') {
                    $dn = trim((string)($_POST['display_name'] ?? ''));
                    if ($dn === '') $errors[] = 'Display name is required.';
                    if (!$errors) {
                        $ini = initials_of($dn) ?: 'AD';
                        $st = $pdo->prepare("UPDATE admins SET display_name = :n, initials = :i WHERE id = :id");
                        $st->execute([':n' => $dn, ':i' => $ini, ':id' => $admin['id']]);
                        $_SESSION['mscare_admin']['display_name'] = $dn;
                        $_SESSION['mscare_admin']['initials'] = $ini;
                        audit($pdo, $admin['id'], $branch_id, 'Admin profile updated');
                    }
                } elseif ($act === 'password') {
                    $cur = (string)($_POST['current_password'] ?? '');
                    $new = (string)($_POST['new_password'] ?? '');
                    $con = (string)($_POST['confirm_password'] ?? '');
                    $st = $pdo->prepare("SELECT password_hash FROM admins WHERE id = :id");
                    $st->execute([':id' => $admin['id']]);
                    $hash = (string)$st->fetchColumn();
                    if (!password_verify($cur, $hash)) $errors[] = 'Your current password is incorrect.';
                    elseif (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
                    elseif ($new !== $con) $errors[] = 'New password and confirmation do not match.';
                    if (!$errors) {
                        $st = $pdo->prepare("UPDATE admins SET password_hash = :h WHERE id = :id");
                        $st->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $admin['id']]);
                        audit($pdo, $admin['id'], $branch_id, 'Password changed');
                    }
                }
            } elseif ($sec === 'services') {
                $svc_cats = ['Basic Dental Services', 'Specialized Services'];
                if (($_POST['do'] ?? '') === 'add_service') {
                    // ---- add a new treatment / service ----
                    $nm = trim((string)($_POST['new_name'] ?? ''));
                    $ct = (string)($_POST['new_category'] ?? '');
                    $raw = str_replace(',', '', trim((string)($_POST['new_price'] ?? '0')));
                    if ($raw === '') $raw = '0';
                    if ($nm === '' || mb_strlen($nm) > 80) $errors[] = 'Enter a treatment name (up to 80 characters).';
                    if (!in_array($ct, $svc_cats, true)) $errors[] = 'Choose a category.';
                    if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 99999999) $errors[] = 'Price must be a valid amount (0 or more).';
                    if (!$errors) {
                        $dup = $pdo->prepare("SELECT 1 FROM services WHERE lower(name) = lower(:n)");
                        $dup->execute([':n' => $nm]);
                        if ($dup->fetchColumn()) $errors[] = '"' . $nm . '" already exists in the list.';
                    }
                    if (!$errors) {
                        $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM services")->fetchColumn();
                        $pdo->prepare("INSERT INTO services (name, category, sort_order, price, is_active) VALUES (:n, :c, :o, :p, TRUE)")
                            ->execute([':n' => $nm, ':c' => $ct, ':o' => $next, ':p' => number_format((float)$raw, 2, '.', '')]);
                        audit($pdo, $admin['id'], $branch_id, 'Service added', $nm . ' - PHP ' . number_format((float)$raw, 2));
                        flash('"' . $nm . '" added to Services & Pricing.');
                        redirect_to('settings.php?s=services');
                    }
                } else {
                    // ---- update prices / availability (one row via its own Save button, or all rows) ----
                    $prices = (array)($_POST['price'] ?? []);
                    $active = (array)($_POST['active'] ?? []);
                    $one = (int)($_POST['save_one'] ?? 0);
                    $ids = $pdo->query("SELECT id FROM services")->fetchAll(PDO::FETCH_COLUMN);
                    if ($one > 0) $ids = array_values(array_intersect($ids, [$one]));
                    $pdo->beginTransaction();
                    $up = $pdo->prepare("UPDATE services SET price = :p, is_active = :a WHERE id = :id");
                    foreach ($ids as $sid) {
                        $raw = str_replace(',', '', trim((string)($prices[$sid] ?? '0')));
                        if ($raw === '') $raw = '0';
                        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 99999999) { $errors[] = 'Prices must be valid amounts (0 or more).'; break; }
                        $up->execute([':p' => number_format((float)$raw, 2, '.', ''), ':a' => isset($active[$sid]) ? 'true' : 'false', ':id' => (int)$sid]);
                    }
                    if ($errors) { $pdo->rollBack(); } else {
                        $pdo->commit();
                        audit($pdo, $admin['id'], $branch_id, $one > 0 ? 'Service price updated' : 'Services & pricing updated', $one > 0 ? 'Service #' . $one : '');
                    }
                }
            } elseif ($sec === 'team') {
                $team_ok = null;
                if (isset($_POST['toggle_admin'])) {
                    // Activate / deactivate an admin account
                    $tid = (int)($_POST['admin_id'] ?? 0);
                    $to  = !empty($_POST['to']);
                    $st = $pdo->prepare("SELECT username, is_active FROM admins WHERE id = :id");
                    $st->execute([':id' => $tid]);
                    $row = $st->fetch();
                    if (!$row) $errors[] = 'That admin account was not found.';
                    elseif (!$to && $tid === (int)$admin['id']) $errors[] = 'You cannot deactivate your own account while signed in.';
                    elseif (!$to && $row['is_active']) {
                        $left = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE is_active = TRUE AND id <> " . $tid)->fetchColumn();
                        if ($left < 1) $errors[] = 'At least one admin account must stay active.';
                    }
                    if (!$errors) {
                        $pdo->prepare("UPDATE admins SET is_active = :a WHERE id = :id")
                            ->execute([':a' => $to ? 'true' : 'false', ':id' => $tid]);
                        audit($pdo, $admin['id'], $branch_id, 'Admin account ' . ($to ? 'activated' : 'deactivated'), '@' . $row['username']);
                        $team_ok = 'Admin @' . $row['username'] . ' is now ' . ($to ? 'active.' : 'inactive. They can no longer sign in.');
                    }
                } elseif (isset($_POST['toggle_dentist'])) {
                    // Activate / deactivate a dentist
                    $tid = (int)($_POST['dentist_id'] ?? 0);
                    $to  = !empty($_POST['to']);
                    $st = $pdo->prepare("SELECT name, is_active FROM dentists WHERE id = :id");
                    $st->execute([':id' => $tid]);
                    $row = $st->fetch();
                    if (!$row) $errors[] = 'That dentist was not found.';
                    elseif (!$to && $row['is_active']) {
                        $left = (int)$pdo->query("SELECT COUNT(*) FROM dentists WHERE is_active = TRUE AND id <> " . $tid)->fetchColumn();
                        if ($left < 1) $errors[] = 'At least one dentist must stay active.';
                    }
                    if (!$errors) {
                        $pdo->prepare("UPDATE dentists SET is_active = :a WHERE id = :id")
                            ->execute([':a' => $to ? 'true' : 'false', ':id' => $tid]);
                        audit($pdo, $admin['id'], $branch_id, 'Dentist ' . ($to ? 'activated' : 'deactivated'), $row['name']);
                        $team_ok = $row['name'] . ' is now ' . ($to ? 'active.' : 'inactive. They will no longer be assigned to new appointments.');
                    }
                } elseif (isset($_POST['create_admin'])) {
                    $un = trim((string)($_POST['admin_username'] ?? ''));
                    $pw = (string)($_POST['admin_password'] ?? '');
                    $dn = trim((string)($_POST['admin_name'] ?? ''));
                    $rl = 'admin'; // new accounts are always Admins
                    $bn = (int)($_POST['admin_branch'] ?? 0);
                    if ($un === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $un)) $errors[] = 'Username must be 3-50 letters, numbers, dots, dashes or underscores.';
                    if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
                    elseif (strlen($pw) > 72) $errors[] = 'Password must be 72 characters or fewer.';
                    elseif (!preg_match('/[A-Z]/', $pw)) $errors[] = 'Password needs at least one uppercase letter.';
                    elseif (!preg_match('/[0-9]/', $pw)) $errors[] = 'Password needs at least one number.';
                    elseif (!preg_match('/[^A-Za-z0-9]/', $pw)) $errors[] = 'Password needs at least one special character (for example ! @ # $ %).';
                    if ($dn === '') $errors[] = 'Display name is required.';
                                        if (!$errors) {
                        $st = $pdo->prepare("SELECT 1 FROM admins WHERE LOWER(username) = LOWER(:u)");
                        $st->execute([':u' => $un]);
                        if ($st->fetchColumn()) $errors[] = 'That username is already taken.';
                        $st = $pdo->prepare("SELECT 1 FROM branches WHERE id = :b");
                        $st->execute([':b' => $bn]);
                        if (!$st->fetchColumn()) $errors[] = 'Choose a valid branch.';
                    }
                    if (!$errors) {
                        $ins = $pdo->prepare("INSERT INTO admins (username, password_hash, branch_id, display_name, initials, role, is_active)
                                              VALUES (:u, :h, :b, :n, :i, :r, TRUE)");
                        $args = [':u' => $un, ':h' => password_hash($pw, PASSWORD_DEFAULT), ':b' => $bn, ':n' => $dn, ':i' => initials_of($dn) ?: 'AD', ':r' => $rl];
                        try { $ins->execute($args); }
                        catch (PDOException $ex) { if (is_sequence_conflict($ex)) { fix_sequences($pdo); $ins->execute($args); } else throw $ex; }
                        audit($pdo, $admin['id'], $branch_id, 'Admin account created', '@' . $un);
                        $team_ok = 'Admin account @' . $un . ' created.';
                    }
                } elseif (isset($_POST['add_dentist'])) {
                    $dn = trim((string)($_POST['dentist_name'] ?? ''));
                    if ($dn === '') $errors[] = 'Enter the dentist name.';
                    if (!$errors) {
                        $ins = $pdo->prepare("INSERT INTO dentists (name, is_active) VALUES (:n, TRUE)");
                        try { $ins->execute([':n' => $dn]); }
                        catch (PDOException $ex) { if (is_sequence_conflict($ex)) { fix_sequences($pdo); $ins->execute([':n' => $dn]); } else throw $ex; }
                        audit($pdo, $admin['id'], $branch_id, 'Dentist added', $dn);
                        $team_ok = $dn . ' added.';
                    }
                } elseif (isset($_POST['add_branch'])) {
                    $code = trim((string)($_POST['branch_code'] ?? ''));
                    $bnm  = trim((string)($_POST['branch_name'] ?? ''));
                    $city = trim((string)($_POST['branch_city'] ?? ''));
                    $addr = trim((string)($_POST['branch_address'] ?? ''));
                    $days = array_values(array_intersect($DAYS, (array)($_POST['branch_days'] ?? [])));
                    if ($code === '' || $bnm === '' || $city === '' || $addr === '') $errors[] = 'Fill in the branch code, name, city and address.';
                    if (!$days) $errors[] = 'Select at least one open day.';
                    if (!$errors) {
                        $st = $pdo->prepare("SELECT 1 FROM branches WHERE LOWER(code) = LOWER(:c)");
                        $st->execute([':c' => $code]);
                        if ($st->fetchColumn()) $errors[] = 'That branch code is already used.';
                    }
                    if (!$errors) {
                        $ins = $pdo->prepare("INSERT INTO branches (code, name, city, address, open_days) VALUES (:c, :n, :ci, :a, :d)");
                        $args = [':c' => $code, ':n' => $bnm, ':ci' => $city, ':a' => $addr, ':d' => '{' . implode(',', $days) . '}'];
                        try { $ins->execute($args); }
                        catch (PDOException $ex) { if (is_sequence_conflict($ex)) { fix_sequences($pdo); $ins->execute($args); } else throw $ex; }
                        audit($pdo, $admin['id'], $branch_id, 'Branch created', $bnm);
                        $team_ok = 'Branch ' . $bnm . ' created.';
                    }
                }
                if (!$errors && $team_ok) { flash($team_ok); redirect_to('settings.php?s=team'); }
            } elseif ($sec === 'privacy') {
                $freq = in_array($_POST['p_backup_freq'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['p_backup_freq'] : 'weekly';
                put_setting($pdo, $branch_id, 'p_mask_records', !empty($_POST['p_mask_records']) ? '1' : '0');
                put_setting($pdo, $branch_id, 'p_session_minutes', int_in($_POST['p_session_minutes'] ?? '', 5, 480, 30));
                put_setting($pdo, $branch_id, 'p_backup_freq', $freq);
                audit($pdo, $admin['id'], $branch_id, 'Privacy & system settings updated');
            }
            if (!$errors && $sec !== '' && $sec !== 'team') {
                flash($SECTIONS[$sec][0] . ' saved.');
                redirect_to('settings.php?s=' . $sec);
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not save: ' . $ex->getMessage();
        }
    }
}

// ---- load data for the current view ------------------------------------------------
$S = $DEFAULTS; $branch = null; $dentists = []; $services = []; $logs = [];
if ($pdo) {
    try {
        $S = get_settings($pdo, $branch_id, $DEFAULTS);
        $st = $pdo->prepare("SELECT * FROM branches WHERE id = :id");
        $st->execute([':id' => $branch_id]);
        $branch = $st->fetch() ?: null;
        if ($sec === 'clinic') {
            $dentists = $pdo->query("SELECT * FROM dentists WHERE is_active = TRUE ORDER BY name")->fetchAll();
        } elseif ($sec === 'services') {
            $services = $pdo->query("SELECT * FROM services ORDER BY category, sort_order, name")->fetchAll();
        } elseif ($sec === 'privacy') {
            $st = $pdo->prepare("SELECT l.*, a.display_name FROM admin_audit_logs l LEFT JOIN admins a ON a.id = l.admin_id
                                 WHERE l.branch_id = :b ORDER BY l.created_at DESC, l.id DESC LIMIT 25");
            $st->execute([':b' => $branch_id]);
            $logs = $st->fetchAll();
        }
    } catch (PDOException $ex) {
        $db_error = $ex->getMessage();
    }
}
$flash = take_flash();
function checked($v) { return $v === '1' ? ' checked' : ''; }
function switch_row($name, $title, $sub, $on) {
    return '<label class="set-switch"><span class="set-switch-text"><strong>' . e($title) . '</strong></span>'
         . '<input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '><span class="set-slider" aria-hidden="true"></span></label>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | MSCare Dental Clinic</title>
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
      <a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Revenue &amp; Reports</span></a>
      <a class="nav-item active" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1.5 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
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
      <div><h2>Settings</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= e($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= e($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content<?= $sec === '' ? ' set-fit' : '' ?>">
  <?php if (!empty($db_error)): ?><div class="login-error" style="display:block;margin-bottom:16px;"><?= e($db_error) ?></div><?php endif; ?>
  <?php foreach ($errors as $er): ?><div class="login-error" style="display:block;margin-bottom:16px;"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($sec === ''): ?>
  <div class="card set-card">
    <nav class="set-list" aria-label="Settings sections">
      <?php foreach ($SECTIONS as $key => [$label, $desc, $d]): ?>
        <a class="set-row" href="settings.php?s=<?= e($key) ?>">
          <span class="set-ico"><?= icon_path($d, 22) ?></span>
          <span class="set-text"><strong><?= e($label) ?></strong></span>
          <svg class="set-chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

<?php else: [$label, $desc, $d] = $SECTIONS[$sec]; ?>
  <a class="set-back" href="settings.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg> Back</a>
  <div class="card set-card set-panel">
    <div class="set-title">
      <span class="set-ico sm"><?= icon_path($d, 20) ?></span>
      <div><h3><?= e($label) ?></h3></div>
    </div>

  <?php if ($sec === 'clinic' && $branch): $open = pg_days_arr($branch['open_days'] ?? ''); ?>
    <form method="post" class="set-form" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="clinic">
      <div class="set-group"><div class="set-group-head"><h4>Clinic details</h4></div>
      <div class="form-grid">
        <div class="full"><label>Clinic name</label><input type="text" name="name" value="<?= e($_POST['name'] ?? $branch['name']) ?>" required></div>
        <div class="full"><label>Address</label><input type="text" name="address" value="<?= e($_POST['address'] ?? $branch['address']) ?>" required></div>
        <div><label>Contact number</label><input type="text" name="phone" value="<?= e($_POST['phone'] ?? $branch['phone']) ?>"></div>
        <div><label>City</label><input type="text" value="<?= e($branch['city']) ?>" disabled></div>
      </div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Operating hours</h4></div>
      <div class="form-grid">
        <div><label>Opening time</label><input type="time" name="open_time" value="<?= e($_POST['open_time'] ?? $S['open_time']) ?>" required></div>
        <div><label>Closing time</label><input type="time" name="close_time" value="<?= e($_POST['close_time'] ?? $S['close_time']) ?>" required></div>
        <div class="full">
          <label>Operating days</label>
          <div class="day-pick">
            <?php foreach ($DAYS as $dy): ?>
              <label class="day-chip"><input type="checkbox" name="days[]" value="<?= $dy ?>"<?= in_array($dy, $open, true) ? ' checked' : '' ?>><span><?= $dy ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Branding</h4></div>
      <div class="form-grid">
        <div class="full">
          <label>Clinic logo</label>
          <div class="logo-row">
            <span class="logo-box"><?php if (!empty($S['clinic_logo'])): ?><img src="<?= e($S['clinic_logo']) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $S['clinic_logo']) ?>" alt="Clinic logo" style="width:100%;height:100%;object-fit:contain;border-radius:14px;"><?php else: ?><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#1FA8A0" stroke-width="1.6"><circle cx="12" cy="12" r="8"/></svg><?php endif; ?></span>
            <input type="file" name="clinic_logo" accept="image/png,image/jpeg,image/webp,image/gif">
          </div>
        </div>
      </div>
      </div>
      <?php if ($dentists): ?>
        <div class="set-group"><div class="set-group-head"><h4>Dentist on duty</h4></div>
        <div class="set-dentists">
          <?php foreach ($dentists as $dn): ?>
            <div class="set-dentist"><span class="avatar"><?= e(initials_of($dn['name'])) ?></span><div><strong><?= e($dn['name']) ?></strong><small><?= e($dn['title']) ?><?= !empty($dn['phone']) ? ' · ' . e($dn['phone']) : '' ?></small></div></div>
          <?php endforeach; ?>
        </div>
        </div>
      <?php endif; ?>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'appointments'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="appointments">
      <div class="set-group"><div class="set-group-head"><h4>Scheduling</h4></div>
      <div class="form-grid">
        <div><label>Appointment length (minutes)</label><input type="number" name="slot_minutes" min="10" max="120" step="5" value="<?= e($S['slot_minutes']) ?>"></div>
        <div><label>Max appointments per day</label><input type="number" name="max_per_day" min="1" max="100" value="<?= e($S['max_per_day']) ?>"></div>
      </div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Booking rules</h4></div>
      <div class="form-grid">
        <div><label>Patients can book up to (days ahead)</label><input type="number" name="advance_days" min="1" max="365" value="<?= e($S['advance_days']) ?>"></div>
        <div><label>Cancel / reschedule cut-off (hours before)</label><input type="number" name="cancel_hours" min="0" max="168" value="<?= e($S['cancel_hours']) ?>"></div>
      </div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Confirmation</h4></div>
      <div class="set-switches"><?= switch_row('auto_confirm', 'Auto-confirm new bookings', 'Skip manual approval for new appointments.', $S['auto_confirm'] === '1') ?></div>
      </div>

      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'notifications'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="notifications">
      <div class="set-group"><div class="set-group-head"><h4>Clinic alerts</h4></div>
      <div class="set-switches">
        <?= switch_row('n_booking_alert', 'New booking alerts', 'Get notified when a patient books.', $S['n_booking_alert'] === '1') ?>
        <?= switch_row('n_cancel_alert', 'Cancellation alerts', 'Get notified when an appointment is cancelled.', $S['n_cancel_alert'] === '1') ?>
      </div>
      <div class="form-grid" style="margin-top:14px;">
        <div class="full"><label>Clinic alert email</label><input type="email" name="alert_email" placeholder="clinic@example.com" value="<?= e($_POST['alert_email'] ?? $S['alert_email']) ?>"></div>
      </div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Patient emails</h4></div>
      <div class="set-switches">
        <?= switch_row('n_patient_confirm', 'Patient confirmation emails', 'Email patients when their booking is confirmed.', $S['n_patient_confirm'] === '1') ?>
        <?= switch_row('n_reminder', 'Appointment reminders', 'Email patients before their appointment.', $S['n_reminder'] === '1') ?>
      </div>
      <div class="form-grid" style="margin-top:14px;">
        <div><label>Send reminder (hours before)</label><input type="number" name="reminder_hours" min="1" max="168" value="<?= e($_POST['reminder_hours'] ?? $S['reminder_hours']) ?>"></div>
      </div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

  <?php elseif ($sec === 'email'): ?>
    <?php $E = get_settings($pdo, 0, ['smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_name' => 'MSCare Dental Clinic']); ?>
    <form method="post" class="set-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="email">
      <div class="set-group"><div class="set-group-head"><h4>Sender account</h4></div>
      <div class="form-grid">
        <div><label>Sender email</label><input type="email" name="smtp_username" placeholder="yourclinic@gmail.com" value="<?= e($_POST['smtp_username'] ?? $E['smtp_username']) ?>" required></div>
        <div><label>App password <?= $E['smtp_password'] !== '' ? '(saved - leave blank to keep)' : '' ?></label><input type="password" name="smtp_password" placeholder="xxxx xxxx xxxx xxxx" autocomplete="new-password" <?= $E['smtp_password'] === '' ? 'required' : '' ?>></div>
        <div><label>Sender name</label><input type="text" name="smtp_from_name" value="<?= e($_POST['smtp_from_name'] ?? $E['smtp_from_name']) ?>"></div>
      </div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Mail server</h4></div>
      <div class="form-grid">
        <div><label>SMTP server</label><input type="text" name="smtp_host" value="<?= e($_POST['smtp_host'] ?? $E['smtp_host']) ?>"></div>
        <div><label>Port (587 or 465)</label><input type="number" name="smtp_port" min="1" max="65535" value="<?= e($_POST['smtp_port'] ?? $E['smtp_port']) ?>"></div>
      </div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save &amp; test</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>

    <?php $otpMin = (int)get_settings($pdo, 0, ['otp_minutes' => '10'])['otp_minutes']; if ($otpMin < 1 || $otpMin > 60) $otpMin = 10; ?>
    <form method="post" class="set-form" style="margin-top:28px;">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="email"><input type="hidden" name="do" value="otp">
      <div class="set-group"><div class="set-group-head"><h4>Verification code (OTP)</h4></div>
      <div class="form-grid">
        <div>
          <label>Code is valid for (minutes)</label>
          <input type="number" name="otp_minutes" min="1" max="60" step="1" value="<?= (int)($_POST['otp_minutes'] ?? $otpMin) ?>" required>
        </div>
      </div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save validity</button></div>
    </form>

  <?php elseif ($sec === 'account'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="account"><input type="hidden" name="do" value="profile">
      <div class="set-group"><div class="set-group-head"><h4>Admin profile</h4></div>
      <div class="form-grid">
        <div><label>Display name</label><input type="text" name="display_name" value="<?= e($_POST['display_name'] ?? $admin['display_name']) ?>" required></div>
        <div><label>Username</label><input type="text" value="<?= e($admin['username']) ?>" disabled></div>
        <div><label>Role</label><input type="text" value="<?= e(ucfirst($admin['role'])) ?> · <?= e($cinfo['label']) ?>" disabled></div>
      </div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save profile</button></div>
    </form>
    <form method="post" class="set-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="account"><input type="hidden" name="do" value="password">
      <div class="set-group"><div class="set-group-head"><h4>Change password</h4></div>
      <div class="form-grid">
        <div class="full"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
        <div><label>New password (min. 8 characters)</label><input type="password" name="new_password" autocomplete="new-password" minlength="8" required></div>
        <div><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></div>
      </div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Update password</button></div>
    </form>

  <?php elseif ($sec === 'services'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="services"><input type="hidden" name="do" value="add_service">
      <div class="set-group">
        <div class="set-group-head"><h4>Add new treatment</h4></div>
        <div class="svc-add">
          <div class="svc-add-name"><label>Treatment name</label><input type="text" name="new_name" maxlength="80" required placeholder="e.g. Dental Crown" value="<?= e($_POST['new_name'] ?? '') ?>"></div>
          <div><label>Category</label>
            <select name="new_category" required><?php foreach (['Basic Dental Services', 'Specialized Services'] as $c): ?><option<?= (($_POST['new_category'] ?? '') === $c) ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
          <div><label>Price (₱)</label><input type="text" inputmode="decimal" name="new_price" placeholder="0.00" value="<?= e($_POST['new_price'] ?? '') ?>"></div>
          <div><button class="btn btn-primary" type="submit">+ Add treatment</button></div>
        </div>
      </div>
    </form>

    <form method="post" class="set-form" id="svcForm">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="services">
      <button type="submit" class="svc-default" tabindex="-1" aria-hidden="true"></button>
      <?php if (!$services): ?>
        <p class="set-note">No services found in the database.</p>
      <?php else: $cat = null; foreach ($services as $sv): $sid = (int)$sv['id'];
          $pv = isset($_POST['price'][$sid]) ? (string)$_POST['price'][$sid] : number_format((float)$sv['price'], 2, '.', ''); ?>
        <?php if ($sv['category'] !== $cat): if ($cat !== null) echo '</div>'; $cat = $sv['category']; ?><div class="set-group"><div class="set-group-head"><h4><?= e($cat) ?></h4></div><?php endif; ?>
        <div class="svc-row">
          <span class="svc-name"><?= e($sv['name']) ?></span>
          <label class="svc-price" title="Click to edit"><span>₱</span><input type="text" inputmode="decimal" name="price[<?= $sid ?>]" value="<?= e($pv) ?>" data-orig="<?= e(number_format((float)$sv['price'], 2, '.', '')) ?>" aria-label="Price for <?= e($sv['name']) ?>"><svg class="svc-pen" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></label>
          <label class="svc-active"><input type="checkbox" name="active[<?= $sid ?>]" value="1"<?= !empty($sv['is_active']) ? ' checked' : '' ?>> Active</label>
          <button type="submit" class="btn-xs svc-save" name="save_one" value="<?= $sid ?>">Save</button>
        </div>
      <?php endforeach; echo '</div>'; endif; ?>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save all</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>
    <script>
    (function(){
      var f=document.getElementById('svcForm'); if(!f) return;
      f.querySelectorAll('.svc-price input').forEach(function(i){
        i.addEventListener('focus',function(){i.select();});
        i.addEventListener('input',function(){i.closest('.svc-row').classList.toggle('dirty', i.value.trim()!==i.dataset.orig);});
        if(i.value.trim()!==i.dataset.orig) i.closest('.svc-row').classList.add('dirty');
      });
      f.querySelectorAll('.svc-active input').forEach(function(c){c.addEventListener('change',function(){c.closest('.svc-row').classList.add('dirty');});});
    })();
    </script>

  <?php elseif ($sec === 'privacy'): ?>
    <form method="post" class="set-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="privacy">
      <div class="set-group"><div class="set-group-head"><h4>Patient data</h4></div>
      <div class="set-switches"><?= switch_row('p_mask_records', 'Mask sensitive details', 'Hide parts of contact and medical details on screen.', $S['p_mask_records'] === '1') ?></div>
      </div>
      <div class="set-group"><div class="set-group-head"><h4>Session &amp; backup</h4></div>
      <div class="form-grid">
        <div><label>Auto log-out after (minutes idle)</label><input type="number" name="p_session_minutes" min="5" max="480" value="<?= e($S['p_session_minutes']) ?>"></div>
        <div><label>Backup schedule</label>
          <select name="p_backup_freq"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $v): ?><option value="<?= $k ?>"<?= $S['p_backup_freq'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      </div>
      </div>
      <div class="set-actions"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="settings.php">Cancel</a></div>
    </form>
    <div class="set-group"><div class="set-group-head"><h4>Audit log</h4></div>
    <?php if (!$logs): ?>
      <p class="set-note">No activity recorded yet. Changes made in Settings will appear here.</p>
    <?php else: ?>
      <div class="set-table"><table>
        <thead><tr><th>When</th><th>Admin</th><th>Action</th></tr></thead>
        <tbody><?php foreach ($logs as $lg): ?>
          <tr><td><?= e(date('M j, Y g:i A', strtotime($lg['created_at']))) ?></td><td><?= e($lg['display_name'] ?? 'Unknown') ?></td><td><?= e($lg['action']) ?><?= $lg['detail'] !== '' ? ' <span class="set-note">— ' . e($lg['detail']) . '</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    </div>
  <?php elseif ($sec === 'team'): ?>
    <?php
      $tm_admins = []; $tm_dentists = []; $tm_branches = [];
      try {
          $tm_admins = $pdo->query("SELECT a.id, a.username, a.display_name, a.initials, a.role, a.is_active, a.last_login_at, b.name AS branch_name
                                      FROM admins a LEFT JOIN branches b ON b.id = a.branch_id ORDER BY a.branch_id NULLS LAST, a.id")->fetchAll();
          $tm_dentists = $pdo->query("SELECT id, name, title, is_active FROM dentists ORDER BY is_active DESC, name")->fetchAll();
          $tm_branches = $pdo->query("SELECT id, name FROM branches ORDER BY id")->fetchAll();
      } catch (PDOException $ex) { error_log('MSCare settings team: ' . $ex->getMessage()); }
      $role_label = ['admin' => 'Admin', 'staff' => 'Staff', 'superadmin' => 'Super Admin'];
    ?>

    <!-- Current team -->
    <div class="tm-block">
      <div class="tm-head"><div><h4>Admin accounts</h4></div><span class="tm-count"><?= count($tm_admins) ?></span></div>
      <?php if (!$tm_admins): ?>
        <p class="set-note">No accounts found.</p>
      <?php else: ?>
        <div class="tm-list">
          <?php foreach ($tm_admins as $ad): ?>
            <div class="tm-item">
              <div class="avatar-sm"><?= e($ad['initials'] ?: 'AD') ?></div>
              <div class="tm-main"><strong><?= e($ad['display_name']) ?></strong><small>@<?= e($ad['username']) ?> &middot; <?= e($ad['branch_name'] ?? 'All branches') ?><?= $ad['last_login_at'] ? ' &middot; last login ' . e(date('M j, g:i A', strtotime($ad['last_login_at']))) : '' ?></small></div>
              <span class="badge b-crown"><?= e($role_label[$ad['role']] ?? ucfirst($ad['role'])) ?></span>
              <span class="badge <?= $ad['is_active'] ? 'b-confirmed' : 'b-cancelled' ?>"><?= $ad['is_active'] ? 'Active' : 'Inactive' ?></span>
              <?php if ((int)$ad['id'] !== (int)$admin['id']): ?>
              <form method="post" class="tm-toggle" data-title="<?= $ad['is_active'] ? 'Deactivate admin account?' : 'Activate admin account?' ?>" data-ok="<?= $ad['is_active'] ? 'Deactivate' : 'Activate' ?>"<?= $ad['is_active'] ? '' : ' data-danger="0"' ?> data-confirm="<?= $ad['is_active'] ? 'Deactivate ' . e($ad['display_name']) . '? They will no longer be able to sign in.' : 'Activate ' . e($ad['display_name']) . '? They will be able to sign in again.' ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team">
                <input type="hidden" name="toggle_admin" value="1"><input type="hidden" name="admin_id" value="<?= (int)$ad['id'] ?>"><input type="hidden" name="to" value="<?= $ad['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="btn-xs"><?= $ad['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <?php else: ?><span class="tm-you">You</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="tm-block">
      <div class="tm-head"><div><h4>Dentists</h4></div><span class="tm-count"><?= count($tm_dentists) ?></span></div>
      <?php if ($tm_dentists): ?>
        <div class="tm-chips">
          <?php foreach ($tm_dentists as $dn): ?>
            <div class="tm-chip<?= $dn['is_active'] ? '' : ' off' ?>">
              <b><?= e($dn['name']) ?></b><small><?= e($dn['title']) ?> &middot; <?= $dn['is_active'] ? 'Active' : 'Inactive' ?></small>
              <form method="post" class="tm-toggle" data-title="<?= $dn['is_active'] ? 'Deactivate dentist?' : 'Activate dentist?' ?>" data-ok="<?= $dn['is_active'] ? 'Deactivate' : 'Activate' ?>"<?= $dn['is_active'] ? '' : ' data-danger="0"' ?> data-confirm="<?= $dn['is_active'] ? 'Deactivate ' . e($dn['name']) . '? They will no longer be assigned to new appointments.' : 'Activate ' . e($dn['name']) . '? They can be assigned to new appointments again.' ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team">
                <input type="hidden" name="toggle_dentist" value="1"><input type="hidden" name="dentist_id" value="<?= (int)$dn['id'] ?>"><input type="hidden" name="to" value="<?= $dn['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="btn-xs"><?= $dn['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Add forms -->
    <div class="tm-title">Add to your team</div>

    <form method="post" class="tm-block tm-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team"><input type="hidden" name="create_admin" value="1">
      <div class="tm-head"><div><h4>New admin account</h4></div></div>
      <div class="form-grid">
        <div><label>Username</label><input type="text" name="admin_username" required autocomplete="off" placeholder="e.g. admin3"></div>
        <div><label>Password</label><input type="password" name="admin_password" id="newAdminPw" required minlength="8" maxlength="72" autocomplete="new-password">
          <ul class="pw-rules" id="newAdminPwRules">
            <li data-r="len">8+ characters</li><li data-r="upper">Uppercase letter</li><li data-r="num">Number</li><li data-r="sp">Special character</li>
          </ul></div>
        <div><label>Display name</label><input type="text" name="admin_name" required placeholder="e.g. Clinic 3 Admin"></div>
        <div><label>Role</label><input type="text" value="Admin" disabled><input type="hidden" name="admin_role" value="admin"></div>
        <div class="full"><label>Branch</label><select name="admin_branch"><?php foreach ($tm_branches as $br): ?><option value="<?= (int)$br['id'] ?>"><?= e($br['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="tm-foot"><button class="btn btn-primary" type="submit">Create admin</button></div>
    </form>
    <style>
      .pw-rules{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:12px;color:var(--muted);}
      .pw-rules li{position:relative;padding-left:16px;}
      .pw-rules li::before{content:"\25CB";position:absolute;left:0;top:0;}
      .pw-rules li.ok{color:#22693f;}.pw-rules li.ok::before{content:"\2713";}
      .pw-rules li.bad{color:#B8452B;}.pw-rules li.bad::before{content:"\2715";}
    </style>
    <script>
    (function(){
      var i=document.getElementById('newAdminPw'); if(!i) return;
      var li=document.querySelectorAll('#newAdminPwRules li');
      var t={len:function(v){return v.length>=8&&v.length<=72},upper:function(v){return /[A-Z]/.test(v)},num:function(v){return /[0-9]/.test(v)},sp:function(v){return /[^A-Za-z0-9]/.test(v)}};
      function run(){var v=i.value,all=true;li.forEach(function(e){var ok=t[e.dataset.r](v);if(!ok)all=false;e.className=v===''?'':(ok?'ok':'bad');});i.setCustomValidity(all?'':'Password does not meet all the rules.');}
      i.addEventListener('input',run);run();
    })();
    </script>

    <form method="post" class="tm-block tm-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team"><input type="hidden" name="add_dentist" value="1">
      <div class="tm-head"><div><h4>New dentist</h4></div></div>
      <div class="form-grid">
        <div class="full"><label>Dentist name</label><input type="text" name="dentist_name" required placeholder="Dr. ..."></div>
      </div>
      <div class="tm-foot"><button class="btn btn-primary" type="submit">Add dentist</button></div>
    </form>

    <form method="post" class="tm-block tm-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team"><input type="hidden" name="add_branch" value="1">
      <div class="tm-head"><div><h4>New branch</h4></div></div>
      <div class="form-grid">
        <div><label>Branch code</label><input type="text" name="branch_code" required placeholder="c3"></div>
        <div><label>Branch name</label><input type="text" name="branch_name" required placeholder="Clinic 3 — ..."></div>
        <div><label>City</label><input type="text" name="branch_city" required></div>
        <div><label>Address</label><input type="text" name="branch_address" required></div>
        <div class="full"><label>Open days</label><div class="day-chips"><?php foreach ($DAYS as $dy): ?><label class="day-chip"><input type="checkbox" name="branch_days[]" value="<?= $dy ?>"><span><?= $dy ?></span></label><?php endforeach; ?></div></div>
      </div>
      <div class="tm-foot"><button class="btn btn-primary" type="submit">Create branch</button></div>
    </form>
  <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php if ($flash): ?>
<script>window.addEventListener('DOMContentLoaded',function(){var t=document.createElement('div');t.className='toast';t.textContent=<?= json_encode($flash['message']) ?>;document.body.appendChild(t);setTimeout(function(){t.remove();},3200);});</script>
<?php endif; ?>
  </div>
</div>
<script src="dialog.js?v=20261110"></script>
<script>
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
</script>
</body>
</html>
