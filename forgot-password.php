<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();
if (current_patient()) { header('Location: patient-dashboard.php'); exit; }

$error = ''; $email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $st = db()->prepare("SELECT * FROM patients WHERE LOWER(email) = :em AND status = 'Active' LIMIT 1");
            $st->execute([':em' => $email]);
            $p = $st->fetch();
            $sendErr = '';
            if ($p) {
                $wait = otp_wait_seconds((int)$p['id'], 'password_reset');
                if ($wait > 0) {
                    // a code was sent a moment ago: go to the code page, where the live countdown is shown
                } else {
                    [$ok, $sendErr] = send_patient_code($p, 'password_reset');
                    if (!$ok) $error = 'We could not send the email. ' . (MSC_DEBUG ? $sendErr : 'Please try again later.');
                }
            }
            if (!$error) {
                // Same response whether or not the email exists, so nobody can probe for registered emails.
                $_SESSION['reset_email'] = $email;
                flash('If that email is registered, we sent a 6-digit code to it.');
                header('Location: reset-password.php');
                exit;
            }
        } catch (PDOException $ex) {
            error_log('MSCare DB (forgot): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'Something went wrong. Please try again.');
        }
    }
}
$page = 'login'; $title = 'Forgot password';
include __DIR__ . '/includes/header.php';
?>
<section class="auth">
  <div class="container">
    <div class="auth-card">
      <aside class="auth-side" style="--auth-img:url('../<?= e($images['about_hero']) ?>')">
        <a class="brand" href="index.php"><?= icon($services[0][2], 26) ?><span><?= e($clinic['short']) ?></span></a>
        <div><h1>Forgot your password?</h1></div>
      </aside>
      <div class="auth-main">
        <a class="auth-back" href="login.php"><?= icon($ui['back'], 16) ?> Back</a>
        <h2>Reset your password</h2>
        <p class="sub">Type the email you registered with.</p>
        <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="forgot-password.php" novalidate>
          <div class="field">
            <label for="email">Email</label>
            <span class="input-icon"><?= icon($ui['mail'], 17) ?>
              <input id="email" name="email" type="email" value="<?= e($email) ?>" placeholder="you@email.com" autocomplete="email" required autofocus>
            </span>
          </div>
          <button type="submit" class="btn btn-primary btn-pill btn-block">Send verification code <?= icon($ui['send'], 18) ?></button>
        </form>
        <p class="auth-switch">Remembered it? <a href="login.php">Log in</a></p>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
