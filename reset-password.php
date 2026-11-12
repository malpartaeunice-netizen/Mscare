<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();
if (current_patient()) { header('Location: patient-dashboard.php'); exit; }

$email = (string)($_SESSION['reset_email'] ?? '');
if ($email === '') { header('Location: forgot-password.php'); exit; }

$error = ''; $info = '';
try {
    $st = db()->prepare("SELECT * FROM patients WHERE LOWER(email) = :em AND status = 'Active' LIMIT 1");
    $st->execute([':em' => $email]);
    $p = $st->fetch();
} catch (PDOException $ex) { $p = false; $error = db_fail_text($ex, 'Database error.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'reset';
    try {
        if ($action === 'resend') {
            if ($p) {
                $wait = otp_wait_seconds((int)$p['id'], 'password_reset');
                if ($wait > 0) { /* live countdown on the page shows the wait */ }
                else {
                    [$ok, $err] = send_patient_code($p, 'password_reset');
                    if ($ok) $info = 'A new code was sent.'; else $error = 'We could not send the email. ' . (MSC_DEBUG ? $err : 'Please try again later.');
                }
            } else { $info = 'If that email is registered, a new code was sent.'; }
        } else {
            $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
            if (($pwErr = password_problem($pw)) !== null) $error = $pwErr;
            elseif ($pw !== $pw2) $error = 'Passwords do not match.';
            elseif (!$p) $error = 'Incorrect or expired code.';
            else {
                $res = check_patient_code((int)$p['id'], 'password_reset', $_POST['code'] ?? '');
                if ($res === true) {
                    // A reset code proves the person owns the email, so it also verifies it.
                    db()->prepare("UPDATE patients SET password_hash = :h, email_verified = TRUE,
                                   email_verified_at = COALESCE(email_verified_at, now()),
                                   failed_attempts = 0, locked_at = NULL, reactivate_token = NULL, reactivate_expires = NULL WHERE id = :i")
                        ->execute([':h' => password_hash($pw, PASSWORD_BCRYPT), ':i' => $p['id']]);
                    unset($_SESSION['reset_email']);
                    flash('Your password was changed. You can now log in.');
                    header('Location: login.php');
                    exit;
                }
                $error = $res;
            }
        }
    } catch (PDOException $ex) {
        error_log('MSCare DB (reset): ' . $ex->getMessage());
        $error = db_fail_text($ex, 'Something went wrong. Please try again.');
    }
}
$flash = take_flash();
$expIn  = $p ? otp_expires_in((int)$p['id'], 'password_reset') : 0;
$waitIn = $p ? otp_wait_seconds((int)$p['id'], 'password_reset') : 0;
$page = 'login'; $title = 'Reset password';
$body_class = 'fit-page'; $no_footer = true;   // whole page fits the screen, no scrolling
include __DIR__ . '/includes/header.php';
?>
<section class="auth">
  <div class="container">
    <div class="auth-card">
      <aside class="auth-side" style="--auth-img:url('../<?= e($images['about_hero']) ?>')">
        <a class="brand" href="index.php"><?= icon($services[0][2], 26) ?><span><?= e($clinic['short']) ?></span></a>
        <div><h1>Choose a new password.</h1></div>
      </aside>
      <div class="auth-main reset-main">
        <a class="auth-back" href="forgot-password.php"><?= icon($ui['back'], 16) ?> Back</a>
        <h2>Enter code &amp; new password</h2>
        <p class="sub">Code sent to <b><?= e(mask_email($email)) ?></b>.</p>
        <?php if ($flash): ?><div class="alert ok" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($info): ?><div class="alert ok" role="status"><?= e($info) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>
        <p id="codeTimer" class="sub" style="margin:0 0 6px;font-weight:600" data-left="<?= (int)$expIn ?>"></p>
        <form method="post" action="reset-password.php" novalidate>
          <input type="hidden" name="action" value="reset">
          <div class="field">
            <label for="code">Verification code</label>
            <input id="code" name="code" type="text" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="123456" required autofocus style="font-size:1.3rem;letter-spacing:.45rem;text-align:center;padding:6px 12px">
          </div>
          <div class="field">
            <label for="password">New password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="password" name="password" type="password" autocomplete="new-password" placeholder="At least 8 characters" required data-pw-check>
              <button type="button" class="pass-toggle" data-toggle-pass="password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span>
          </div>
          <div class="field">
            <label for="password2">Confirm new password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="password2" name="password2" type="password" autocomplete="new-password" placeholder="Re-enter password" required>
              <button type="button" class="pass-toggle" data-toggle-pass="password2" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span>
          </div>
          <button type="submit" class="btn btn-primary btn-pill btn-block">Change password <?= icon($ui['check'], 18) ?></button>
        </form>
        <form method="post" action="reset-password.php" style="margin-top:4px">
          <input type="hidden" name="action" value="resend">
          <p class="auth-switch">Didn’t get it? <button type="submit" id="resendBtn" data-wait="<?= (int)$waitIn ?>" style="background:none;border:0;color:inherit;font:inherit;font-weight:600;text-decoration:underline;cursor:pointer">Resend code</button></p>
        </form>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/otp_timer.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
