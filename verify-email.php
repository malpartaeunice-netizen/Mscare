<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();

if (current_patient()) { header('Location: patient-dashboard.php'); exit; }
$pid = (int)($_SESSION['verify_pid'] ?? 0);
if (!$pid) { header('Location: login.php'); exit; }

$error = ''; $info = '';
try {
    $st = db()->prepare("SELECT * FROM patients WHERE id = :i");
    $st->execute([':i' => $pid]);
    $p = $st->fetch();
} catch (PDOException $ex) { $p = null; $error = db_fail_text($ex, 'Database error.'); }
if (!$p) { unset($_SESSION['verify_pid']); header('Location: login.php'); exit; }
if (!empty($p['email_verified'])) { unset($_SESSION['verify_pid']); flash('Your email is already verified. Please log in.'); header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';
    try {
        if ($action === 'resend') {
            $wait = otp_wait_seconds($pid, 'register');
            if ($wait > 0) {
                // too soon: the live "Resend code in Ns" countdown on the page already shows the wait
            } else {
                [$ok, $err] = send_patient_code($p, 'register');
                if ($ok) $info = 'A new code was sent to ' . mask_email($p['email']) . '.';
                else $error = 'We could not send the email. ' . (MSC_DEBUG ? $err : 'Please try again later.');
            }
        } else {
            $res = check_patient_code($pid, 'register', $_POST['code'] ?? '');
            if ($res === true) {
                db()->prepare("UPDATE patients SET email_verified = TRUE, email_verified_at = now() WHERE id = :i")->execute([':i' => $pid]);
                unset($_SESSION['verify_pid']);
                session_regenerate_id(true);
                $p['email_verified'] = true;
                login_patient_session($p);
                flash('Email verified! Welcome to MSCare, ' . $p['first_name'] . '. Please complete your dental record to unlock booking.');
                header('Location: patient-record.php');
                exit;
            }
            $error = $res;
        }
    } catch (PDOException $ex) {
        error_log('MSCare DB (verify): ' . $ex->getMessage());
        $error = db_fail_text($ex, 'Something went wrong. Please try again.');
    }
}
$flash = take_flash();
$expIn  = otp_expires_in($pid, 'register');
$waitIn = otp_wait_seconds($pid, 'register');
$page = 'login'; $title = 'Verify your email';
include __DIR__ . '/includes/header.php';
?>
<section class="auth">
  <div class="container">
    <div class="auth-card">
      <aside class="auth-side" style="--auth-img:url('../<?= e($images['services_hero']) ?>')">
        <a class="brand" href="index.php"><?= icon($services[0][2], 26) ?><span><?= e($clinic['short']) ?></span></a>
      </aside>
      <div class="auth-main">
        <a class="auth-back" href="login.php"><?= icon($ui['back'], 16) ?> Back</a>
        <h2>Verify your email</h2>
        <?php if ($flash): ?><div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'bad' : 'ok' ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($info): ?><div class="alert ok" role="status"><?= e($info) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>
        <p id="codeTimer" class="sub" style="margin:0 0 10px;font-weight:600" data-left="<?= (int)$expIn ?>"></p>
        <form method="post" action="verify-email.php" novalidate>
          <input type="hidden" name="action" value="verify">
          <div class="field">
            <label for="code">Verification code</label>
            <input id="code" name="code" type="text" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="123456" required autofocus style="font-size:1.6rem;letter-spacing:.5rem;text-align:center">
          </div>
          <button type="submit" class="btn btn-primary btn-pill btn-block">Verify &amp; continue <?= icon($ui['arrow'], 18) ?></button>
        </form>
        <form method="post" action="verify-email.php" style="margin-top:14px">
          <input type="hidden" name="action" value="resend">
          <p class="auth-switch">Didn’t get it? <button type="submit" id="resendBtn" data-wait="<?= (int)$waitIn ?>" class="btn-link" style="background:none;border:0;color:inherit;font-weight:600;text-decoration:underline;cursor:pointer;font:inherit">Resend code</button></p>
        </form>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/otp_timer.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
