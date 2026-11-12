<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();

if (isset($_GET['logout'])) {
    unset($_SESSION['mscare_patient']);
    session_regenerate_id(true);
    flash('You have been logged out. See you at your next visit!');
    header('Location: login.php');
    exit;
}

$allowed_next = ['patient-dashboard.php', 'patient-book.php', 'patient-appointments.php',
                 'patient-history.php', 'patient-record.php', 'patient-profile.php'];
$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'patient-dashboard.php');
if (!in_array($next, $allowed_next, true)) $next = 'patient-dashboard.php';

if (current_patient()) { header('Location: ' . $next); exit; }

$error = '';
$info  = '';
$email = '';
$locked_email = '';   // set when the account is locked, to offer "Resend reactivation email"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = (string)($_POST['action'] ?? 'login');
    $email    = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($action === 'resend_unlock') {
        // Same reply whether or not the email exists / is locked, so nobody can probe for accounts.
        try {
            $st = db()->prepare("SELECT * FROM patients WHERE LOWER(email) = :em AND locked_at IS NOT NULL AND status = 'Active' LIMIT 1");
            $st->execute([':em' => $email]);
            $lp = $st->fetch();
            if ($lp && unlock_wait_seconds($lp) === 0) {
                [$ok, $err] = send_unlock_email($lp);
                if (!$ok) { $error = 'We could not send the email. ' . (MSC_DEBUG ? $err : 'Please try again later.'); $locked_email = $email; }
            }
            if (!$error) $info = 'If that account is locked, we sent a reactivation link to its email. (You can ask for a new one once a minute.)';
        } catch (PDOException $ex) {
            error_log('MSCare DB (resend unlock): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'Something went wrong. Please try again.');
        }
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $stmt = db()->prepare("SELECT * FROM patients WHERE LOWER(email) = :em LIMIT 1");
            $stmt->execute([':em' => $email]);
            $p = $stmt->fetch();

            if ($p && !empty($p['locked_at']) && $p['status'] === 'Active') {
                // Locked: the password is not even checked until the person reactivates.
                $error = 'Your account is locked after ' . MSC_MAX_LOGIN_ATTEMPTS . ' wrong password attempts. We emailed a reactivation link to ' . mask_email($p['email']) . ' - click it to reactivate your account.';
                $locked_email = $email;
            } elseif ($p && password_verify($password, $p['password_hash'])) {
                if ($p['status'] !== 'Active') {
                    $error = 'This account is inactive. Please contact the clinic to reactivate it.';
                } else {
                    if ((int)$p['failed_attempts'] > 0) {
                        db()->prepare("UPDATE patients SET failed_attempts = 0 WHERE id = :i")->execute([':i' => $p['id']]);
                    }
                    if (empty($p['email_verified'])) {
                        // Correct password but email never verified: send a fresh code and ask for it.
                        if (otp_wait_seconds((int)$p['id'], 'register') === 0) { send_patient_code($p, 'register'); }
                        session_regenerate_id(true);
                        $_SESSION['verify_pid'] = (int)$p['id'];
                        flash('Please verify your email first. We sent a code to ' . mask_email($p['email']) . '.');
                        header('Location: verify-email.php');
                        exit;
                    }
                    session_regenerate_id(true);
                    login_patient_session($p);
                    header('Location: ' . $next);
                    exit;
                }
            } elseif ($p && $p['status'] === 'Active') {
                // Wrong password on a real, active account: count it.
                $n = (int)$p['failed_attempts'] + 1;
                if ($n >= MSC_MAX_LOGIN_ATTEMPTS) {
                    db()->prepare("UPDATE patients SET failed_attempts = :n, locked_at = now() WHERE id = :i")
                        ->execute([':n' => $n, ':i' => $p['id']]);
                    [$ok, $err] = send_unlock_email($p);
                    $error = 'Too many wrong password attempts - your account is now locked. '
                           . ($ok ? 'We sent a reactivation link to ' . mask_email($p['email']) . '. Click it to reactivate your account.'
                                  : 'We could not send the reactivation email (' . (MSC_DEBUG ? $err : 'try again later') . '). Use "Resend" below, or reset your password with "Forgot password".');
                    $locked_email = $email;
                } else {
                    db()->prepare("UPDATE patients SET failed_attempts = :n WHERE id = :i")->execute([':n' => $n, ':i' => $p['id']]);
                    $left = MSC_MAX_LOGIN_ATTEMPTS - $n;
                    $error = 'Incorrect email or password. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left before your account is locked.';
                }
            } else {
                $error = 'Incorrect email or password. Please try again.';
            }
        } catch (PDOException $ex) {
            error_log('MSCare DB (login): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'We could not reach the database. Please try again in a moment.');
        }
    }
}

$page = 'login'; $title = 'Patient log in';
include __DIR__ . '/includes/header.php';
$flash = take_flash();
?>
<section class="auth">
  <div class="container">
    <div class="auth-card">
      <aside class="auth-side" style="--auth-img:url('../<?= e($images['about_hero']) ?>')">
        <a class="brand" href="index.php"><?= icon($services[0][2], 26) ?><span><?= e($clinic['short']) ?></span></a>
      </aside>

      <div class="auth-main">
        <a class="auth-back" href="index.php"><?= icon($ui['back'], 16) ?> Back</a>
        <h2>Welcome back</h2>

        <?php if ($flash): ?><div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'bad' : 'ok' ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($info): ?><div class="alert ok" role="status"><?= e($info) ?></div><?php endif; ?>
        <?php if ($locked_email !== ''): ?>
          <form method="post" action="login.php" style="margin:-4px 0 16px">
            <input type="hidden" name="action" value="resend_unlock">
            <input type="hidden" name="email" value="<?= e($locked_email) ?>">
            <button type="submit" class="btn btn-pill btn-block" style="background:#fff;border:1.5px solid var(--line,#d8e2e4);color:inherit">Resend reactivation email</button>
          </form>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
          <input type="hidden" name="next" value="<?= e($next) ?>">
          <div class="field">
            <label for="email">Email</label>
            <span class="input-icon"><?= icon($ui['mail'], 17) ?>
              <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" placeholder="you@email.com" required autofocus>
            </span>
          </div>
          <div class="field">
            <label for="password">Password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Your password" required>
              <button type="button" class="pass-toggle" data-toggle-pass="password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span>
          </div>
          <p style="text-align:right;margin:-6px 0 14px;font-size:.92rem"><a href="forgot-password.php">Forgot password?</a></p>
          <button type="submit" class="btn btn-primary btn-pill btn-block">Log in <?= icon($ui['arrow'], 18) ?></button>
        </form>

        <p class="auth-switch">New to MSCare? <a href="register.php">Create a patient account</a></p>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
