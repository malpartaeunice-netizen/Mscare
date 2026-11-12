<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();

if (current_patient()) { header('Location: patient-dashboard.php'); exit; }

$error = '';
$old = ['last_name' => '', 'first_name' => '', 'middle_name' => '', 'age' => '', 'gender' => 'Female', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($old) as $k) { $old[$k] = trim((string)($_POST[$k] ?? $old[$k])); }

    $last   = mb_strtoupper($old['last_name']);
    $first  = mb_strtoupper($old['first_name']);
    $middle = mb_strtoupper($old['middle_name']);
    $age    = (int)$old['age'];
    $gender = in_array($old['gender'], ['Female', 'Male', 'Prefer not to say'], true) ? $old['gender'] : 'Prefer not to say';
    $email  = strtolower($old['email']);
    $phone  = $old['phone'];
    $pass   = (string)($_POST['password'] ?? '');
    $pass2  = (string)($_POST['password2'] ?? '');
    $nameRx = "/^[\p{L}][\p{L} .'\-]*$/u";

    if ($last === '' || $first === '' || $email === '' || $phone === '' || $pass === '') {
        $error = 'Please fill out all required fields.';
    } elseif (!preg_match($nameRx, $last) || !preg_match($nameRx, $first) || ($middle !== '' && !preg_match($nameRx, $middle))) {
        $error = 'Names can only contain letters, spaces, hyphens, apostrophes and periods.';
    } elseif ($age < 1 || $age > 120) {
        $error = 'Please enter a valid age.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $error = 'Please enter a valid mobile number.';
    } elseif (($pwErr = password_problem($pass)) !== null) {
        $error = $pwErr;
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } elseif (empty($_POST['consent'])) {
        $error = 'Please agree to the records consent to continue.';
    } else {
        try {
            $pdo = db();
            $chk = $pdo->prepare("SELECT id, email_verified FROM patients WHERE LOWER(email) = :em");
            $chk->execute([':em' => $email]);
            $existing = $chk->fetch();
            if ($existing && $existing['email_verified']) {
                $error = 'An account with that email already exists. Try logging in instead.';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                if ($existing) {
                    // Earlier sign-up never verified this email: let the new attempt replace it.
                    $up = $pdo->prepare("UPDATE patients SET first_name=:fn, last_name=:ln, middle_name=:mn, phone=:ph, age=:ag,
                                         gender=:gn, password_hash=:pw WHERE id=:id RETURNING *");
                    $up->execute([':fn' => $first, ':ln' => $last, ':mn' => $middle, ':ph' => $phone, ':ag' => $age,
                                  ':gn' => $gender, ':pw' => $hash, ':id' => $existing['id']]);
                    $new = $up->fetch();
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO patients (first_name, last_name, middle_name, email, phone, age, gender, password_hash, is_demo, status, email_verified)
                        VALUES (:fn, :ln, :mn, :em, :ph, :ag, :gn, :pw, FALSE, 'Active', FALSE) RETURNING *");
                    $ins->execute([':fn' => $first, ':ln' => $last, ':mn' => $middle, ':em' => $email, ':ph' => $phone,
                                   ':ag' => $age, ':gn' => $gender, ':pw' => $hash]);
                    $new = $ins->fetch();
                }
                [$sent, $sendErr] = send_patient_code($new, 'register');
                session_regenerate_id(true);
                $_SESSION['verify_pid'] = (int)$new['id'];
                if ($sent) {
                    flash('We sent a 6-digit code to ' . mask_email($email) . '. Enter it below to verify your email.');
                } else {
                    flash('Your account was created, but we could not send the code (' . (MSC_DEBUG ? $sendErr : 'email problem') . '). Press "Resend code" to try again.', 'error');
                }
                header('Location: verify-email.php');
                exit;
            }
        } catch (PDOException $ex) {
            error_log('MSCare DB (register): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'We could not create your account. Please try again.');
        }
    }
}

$page = 'login'; $title = 'Create account';
$body_class = 'fit-page'; $no_footer = true;   // whole page fits the screen, no scrolling
include __DIR__ . '/includes/header.php';
?>
<section class="auth">
  <div class="container">
    <div class="auth-card wide">
      <aside class="auth-side" style="--auth-img:url('../<?= e($images['services_hero']) ?>')">
        <a class="brand" href="index.php"><?= icon($services[0][2], 26) ?><span><?= e($clinic['short']) ?></span></a>
      </aside>

      <div class="auth-main">
        <a class="auth-back" href="index.php"><?= icon($ui['back'], 16) ?> Back</a>
        <h2>Patient registration</h2>
        <p class="sub">Fill in your details to get started. Fields marked <span class="req">*</span> are required.</p>

        <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form method="post" action="register.php" novalidate>
          <div class="form-grid">
            <div class="field">
              <label for="last_name">Last name <span class="req">*</span></label>
              <input id="last_name" name="last_name" type="text" required data-uppercase value="<?= e($old['last_name']) ?>" placeholder="DELA CRUZ" autocomplete="family-name">
            </div>
            <div class="field">
              <label for="first_name">First name <span class="req">*</span></label>
              <input id="first_name" name="first_name" type="text" required data-uppercase value="<?= e($old['first_name']) ?>" placeholder="JUAN" autocomplete="given-name">
            </div>
            <div class="field">
              <label for="middle_name">Middle name</label>
              <input id="middle_name" name="middle_name" type="text" data-uppercase value="<?= e($old['middle_name']) ?>" placeholder="SANTOS">
            </div>
            <div class="field">
              <label for="age">Age <span class="req">*</span></label>
              <input id="age" name="age" type="number" min="1" max="120" required value="<?= e($old['age']) ?>" placeholder="e.g. 28">
            </div>
            <div class="field">
              <label for="gender">Gender</label>
              <select id="gender" name="gender">
                <?php foreach (['Female', 'Male', 'Prefer not to say'] as $g): ?>
                  <option<?= $old['gender'] === $g ? ' selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="phone">Mobile number <span class="req">*</span></label>
              <span class="input-icon"><?= icon($ui['phone'], 17) ?>
                <input id="phone" name="phone" type="tel" required value="<?= e($old['phone']) ?>" placeholder="09XX XXX XXXX" autocomplete="tel">
              </span>
            </div>
            <div class="field full">
              <label for="email">Email address <span class="req">*</span></label>
              <span class="input-icon"><?= icon($ui['mail'], 17) ?>
                <input id="email" name="email" type="email" required value="<?= e($old['email']) ?>" placeholder="you@email.com" autocomplete="email">
              </span>
            </div>
            <div class="field">
              <label for="password">Password <span class="req">*</span></label>
              <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
                <input id="password" name="password" type="password" required autocomplete="new-password" placeholder="At least 8 characters" data-pw-check>
                <button type="button" class="pass-toggle" data-toggle-pass="password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
              </span>
            </div>
            <div class="field">
              <label for="password2">Confirm password <span class="req">*</span></label>
              <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
                <input id="password2" name="password2" type="password" required autocomplete="new-password" placeholder="Re-enter password">
                <button type="button" class="pass-toggle" data-toggle-pass="password2" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
              </span>
            </div>
          </div>
          <label class="consent-check">
            <input type="checkbox" name="consent" value="1" required checked>
            <span>I agree to MSCare storing my dental and contact information for treatment purposes.</span>
          </label>
          <button type="submit" class="btn btn-primary btn-pill btn-block">Create account <?= icon($ui['arrow'], 18) ?></button>
        </form>
        <p class="auth-switch">Already registered? <a href="login.php">Log in</a></p>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
