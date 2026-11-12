<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
$stmt->execute([':id' => $pid]);
$p = $stmt->fetch();
if (!$p) { unset($_SESSION['mscare_patient']); redirect_to('login.php'); }
$p_saved = $p;   // what is in the database (the card shows this; the form may hold unsaved typing)

$last_visit = null;
try {
    $lv = $pdo->prepare("
        SELECT to_char(max(d), 'Mon DD, YYYY') FROM (
            SELECT max(treatment_date) AS d FROM treatments WHERE patient_id = :pid
            UNION ALL
            SELECT last_dental_visit FROM patient_records WHERE patient_id = :pid2
        ) x");
    $lv->execute([':pid' => $pid, ':pid2' => $pid]);
    $last_visit = $lv->fetchColumn() ?: null;
} catch (PDOException $ex) { $last_visit = null; }

$error = '';
$pwError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    // ---- change password (needs the current password) ----
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $con = (string)($_POST['confirm_password'] ?? '');
    if ($cur === '' || $new === '' || $con === '') {
        $pwError = 'Please fill in your current password, the new password and its confirmation.';
    } elseif (!password_verify($cur, (string)$p['password_hash'])) {
        $pwError = 'Your current password is incorrect.';
    } elseif (($pwErr = password_problem($new)) !== null) {
        $pwError = str_replace('Password', 'The new password', $pwErr);
    } elseif ($new === $cur) {
        $pwError = 'The new password must be different from your current password.';
    } elseif ($new !== $con) {
        $pwError = 'The new password and its confirmation do not match.';
    } else {
        try {
            $pdo->prepare("UPDATE patients SET password_hash = :h, updated_at = now() WHERE id = :id")
                ->execute([':h' => password_hash($new, PASSWORD_BCRYPT), ':id' => $pid]);
            flash('Your password was changed.');
            redirect_to('patient-profile.php');
        } catch (PDOException $ex) {
            error_log('MSCare DB (change password): ' . $ex->getMessage());
            $pwError = db_fail_text($ex, 'We could not change your password. Please try again.');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first  = mb_strtoupper(trim((string)($_POST['first_name'] ?? '')));
    $middle = mb_strtoupper(trim((string)($_POST['middle_name'] ?? '')));
    $last   = mb_strtoupper(trim((string)($_POST['last_name'] ?? '')));
    $age    = (int)($_POST['age'] ?? 0);
    $email  = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone  = trim((string)($_POST['phone'] ?? ''));
    $gender = trim((string)($_POST['gender'] ?? 'Prefer not to say'));
    $nameRx = "/^[\p{L}][\p{L} .'\-]*$/u";

    if ($first === '' || $last === '') {
        $error = 'Please enter your first and last name.';
    } elseif (!preg_match($nameRx, $first) || !preg_match($nameRx, $last) || ($middle !== '' && !preg_match($nameRx, $middle))) {
        $error = 'Names can only contain letters, spaces, hyphens, apostrophes and periods.';
    } elseif ($age < 1 || $age > 120) {
        $error = 'Please enter a valid age.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $error = 'Please enter a valid mobile number.';
    } elseif (!in_array($gender, ['Female', 'Male', 'Prefer not to say'], true)) {
        $error = 'Please choose a gender.';
    } else {
        try {
            $dup = $pdo->prepare("SELECT id FROM patients WHERE LOWER(email) = :em AND id <> :id");
            $dup->execute([':em' => $email, ':id' => $pid]);
            if ($dup->fetch()) {
                $error = 'Another account already uses that email.';
            } else {
                $pdo->prepare("UPDATE patients SET first_name=:fn, middle_name=:mn, last_name=:ln,
                               age=:ag, email=:em, phone=:ph, gender=:gn, updated_at=now() WHERE id=:id")
                    ->execute([':fn' => $first, ':mn' => $middle, ':ln' => $last, ':ag' => $age,
                               ':em' => $email, ':ph' => $phone, ':gn' => $gender, ':id' => $pid]);
                if (in_array($gender, ['Female', 'Male'], true)) {
                    $pdo->prepare("UPDATE patient_records SET sex = :gn WHERE patient_id = :id")->execute([':gn' => $gender, ':id' => $pid]);
                }
                refresh_patient_session($pid);
                flash('Profile updated.');
                redirect_to('patient-profile.php');
            }
        } catch (PDOException $ex) {
            error_log('MSCare DB (profile): ' . $ex->getMessage());
            $error = db_fail_text($ex, 'We could not save your profile. Please try again.');
        }
    }
    // keep what the person typed
    $p = array_merge($p, ['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'age' => $age,
                          'email' => $email, 'phone' => $phone, 'gender' => $gender]);
}
$gate = booking_status($pid);

$ptitle = 'My Profile';
$psub   = '';
$pactive = 'profile';
include __DIR__ . '/includes/portal_head.php';
?>
<div class="profile-grid">
  <div style="display:grid;gap:20px;min-width:0">
  <div class="p-card pf-card">
    <div class="p-card-head"><h2>Personal details</h2>
      <button type="button" class="btn btn-outline btn-pill btn-sm" id="edOpen">Edit</button>
    </div>
    <dl class="kv">
      <div><dt>First name</dt><dd><?= e($p_saved['first_name']) ?></dd></div>
      <div><dt>Middle name</dt><dd><?= e($p_saved['middle_name'] ?: '—') ?></dd></div>
      <div><dt>Last name</dt><dd><?= e($p_saved['last_name']) ?></dd></div>
      <div><dt>Age</dt><dd><?= (int)$p_saved['age'] ?></dd></div>
      <div><dt>Gender</dt><dd><?= e($p_saved['gender']) ?></dd></div>
      <div><dt>Email</dt><dd><?= e($p_saved['email']) ?></dd></div>
      <div><dt>Mobile number</dt><dd><?= e($p_saved['phone']) ?></dd></div>
    </dl>
  </div>
  </div>

  <div class="p-card profile-card">
    <div class="big-avatar"><?= e($patient['initials']) ?></div>
    <h3><?= e($patient['full_name']) ?></h3>
    <p class="meta"><?= e($patient['email']) ?></p>
    <dl class="kv">
      <div><dt>Last visit</dt><dd><?= e($last_visit ?: 'No visits yet') ?></dd></div>
      <div><dt>Medical history</dt><dd><?= $gate['med_done'] ? 'Completed' : 'Not yet' ?></dd></div>
      <div><dt>Informed consent</dt><dd><?= e(ucfirst($gate['consent'] === 'none' ? 'not signed' : $gate['consent'])) ?></dd></div>
      <div><dt>Booking</dt><dd><?= $gate['can_book'] ? 'Unlocked' : 'Locked' ?></dd></div>
    </dl>
    <button type="button" class="btn btn-outline btn-pill btn-block" id="pwOpen" style="margin-top:18px;"><?= icon($ui['lock'], 16) ?> Change password</button>
  </div>
</div>

<!-- EDIT PERSONAL DETAILS (small pop-up) -->
<div class="pwm-back<?= $error ? '' : ' hidden' ?>" id="edModal" role="dialog" aria-modal="true" aria-labelledby="edTitle">
  <div class="pwm pwm-wide">
    <button type="button" class="pwm-x" id="edClose" aria-label="Close">&times;</button>
    <h2 id="edTitle">Edit personal details</h2>
    <?php if ($error): ?>
      <div class="alert bad" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="patient-profile.php" novalidate>
      <div class="form-grid">
        <div class="field third"><label for="first_name">First name</label><input id="first_name" name="first_name" required data-uppercase value="<?= e($p['first_name']) ?>"></div>
        <div class="field third"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" data-uppercase value="<?= e($p['middle_name']) ?>"></div>
        <div class="field third"><label for="last_name">Last name</label><input id="last_name" name="last_name" required data-uppercase value="<?= e($p['last_name']) ?>"></div>
        <div class="field"><label for="age">Age</label><input id="age" name="age" type="number" min="1" max="120" required value="<?= (int)$p['age'] ?>"></div>
        <div class="field"><label for="gender">Gender</label>
          <select id="gender" name="gender">
            <?php foreach (['Female', 'Male', 'Prefer not to say'] as $g): ?>
              <option<?= $p['gender'] === $g ? ' selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required value="<?= e($p['email']) ?>"></div>
        <div class="field"><label for="phone">Mobile number</label><input id="phone" name="phone" type="tel" required value="<?= e($p['phone']) ?>"></div>
      </div>
      <div class="pwm-actions">
        <button type="button" class="btn btn-outline btn-pill" id="edCancel">Cancel</button>
        <button type="submit" class="btn btn-primary btn-pill">Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- CHANGE PASSWORD (small pop-up) -->
<div class="pwm-back<?= $pwError ? '' : ' hidden' ?>" id="pwModal" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
  <div class="pwm">
    <button type="button" class="pwm-x" id="pwClose" aria-label="Close">&times;</button>
    <h2 id="pwTitle">Change password</h2>
    <?php if ($pwError): ?>
      <div class="alert bad" role="alert"><?= e($pwError) ?></div>
    <?php endif; ?>
    <form method="post" action="patient-profile.php" novalidate>
      <input type="hidden" name="action" value="password">
      <div class="field"><label for="current_password">Current password</label>
        <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
          <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
          <button type="button" class="pass-toggle" data-toggle-pass="current_password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
        </span></div>
      <div class="field"><label for="new_password">New password</label>
        <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
          <input id="new_password" name="new_password" type="password" autocomplete="new-password" placeholder="At least 8 characters" required data-pw-check>
          <button type="button" class="pass-toggle" data-toggle-pass="new_password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
        </span></div>
      <div class="field"><label for="confirm_password">Confirm new password</label>
        <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
          <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Re-enter new password" required>
          <button type="button" class="pass-toggle" data-toggle-pass="confirm_password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
        </span></div>
      <div class="pwm-actions">
        <button type="button" class="btn btn-outline btn-pill" id="pwCancel">Cancel</button>
        <button type="submit" class="btn btn-primary btn-pill">Change password</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('edModal'), openBtn = document.getElementById('edOpen');
  if (!modal || !openBtn) return;
  function open() { modal.classList.remove('hidden'); var f = document.getElementById('first_name'); if (f) f.focus(); }
  function close() { modal.classList.add('hidden'); openBtn.focus(); }
  openBtn.addEventListener('click', open);
  document.getElementById('edClose').addEventListener('click', close);
  document.getElementById('edCancel').addEventListener('click', close);
  modal.addEventListener('mousedown', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
})();
</script>

<script>
(function () {
  var modal = document.getElementById('pwModal'), openBtn = document.getElementById('pwOpen');
  if (!modal || !openBtn) return;
  function open() {
    modal.classList.remove('hidden');
    window.dispatchEvent(new Event('resize'));          // re-fit the password rules row
    var f = document.getElementById('current_password'); if (f) f.focus();
  }
  function close() { modal.classList.add('hidden'); openBtn.focus(); }
  openBtn.addEventListener('click', open);
  document.getElementById('pwClose').addEventListener('click', close);
  document.getElementById('pwCancel').addEventListener('click', close);
  modal.addEventListener('mousedown', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
  if (!modal.classList.contains('hidden') || location.hash === '#change-password') open();
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
