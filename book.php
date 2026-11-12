<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/auth.php';
// Booking now requires a patient account: guests must log in (or register) first.
if (!current_patient()) {
    header('Location: login.php?next=patient-book.php');
    exit;
}
// Signed-in patients book from their portal (their details are already on file).
if (current_patient() && !isset($_GET['done'])) { header('Location: patient-book.php'); exit; }
$today = date('Y-m-d');

// Times already requested (one dentist, so a date + time can only be held once)
$taken = [];
try {
    $q = db()->prepare("SELECT appointment_date::text AS d, to_char(appointment_time, 'HH12:MI AM') AS t
                        FROM appointments WHERE appointment_date >= :today AND status <> 'Cancelled'");
    $q->execute([':today' => $today]);
    foreach ($q as $r) { $taken[$r['d']][] = $r['t']; }
} catch (PDOException $ex) {
    error_log('MSCare DB (load slots): ' . $ex->getMessage());
}

$error = '';
$old = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['branch', 'service', 'date', 'time', 'first_name', 'last_name', 'phone', 'email', 'current_patient', 'comments'];
    $f = [];
    foreach ($fields as $k) { $f[$k] = trim((string)($_POST[$k] ?? '')); }

    $serviceNames = array_column($services, 0);
    $phoneDigits = preg_replace('/[\s\-()]/', '', $f['phone']);
    $d = DateTime::createFromFormat('Y-m-d', $f['date']);
    $dateOk = $d && $d->format('Y-m-d') === $f['date'];

    if (!isset($branches[$f['branch']])) {
        $error = 'Choose a branch.';
    } elseif (!in_array($f['service'], $serviceNames, true)) {
        $error = 'Choose a service.';
    } elseif (!$dateOk || $f['date'] < $today) {
        $error = 'Choose a valid date.';
    } elseif (!in_array($d->format('D'), $branches[$f['branch']]['days'], true)) {
        $error = $branches[$f['branch']]['name'] . ' is not open on that day.';
    } elseif (!in_array($f['time'], $slots, true)) {
        $error = 'Choose a valid time.';
    } elseif (in_array($f['time'], $taken[$f['date']] ?? [], true)) {
        $error = 'That time was just requested by someone else. Please choose another time.';
    } elseif ($f['date'] === $today && strtotime($f['date'] . ' ' . $f['time']) <= time()) {
        $error = 'That time has already passed. Please choose a later time.';
    } elseif (str_len($f['first_name']) < 2 || str_len($f['last_name']) < 2) {
        $error = 'Enter your first and last name.';
    } elseif (!preg_match('/^(\+63|63|0)?9\d{9}$/', $phoneDigits)) {
        $error = 'Enter a valid mobile number.';
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!in_array($f['current_patient'], ['yes', 'no'], true)) {
        $error = 'Tell us if you are a current patient.';
    } elseif (str_len($f['comments']) > 500) {
        $error = 'Comments must be 500 characters or fewer.';
    }

    if ($error === '') {
        $ref = 'MSC-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $ins = db()->prepare(
                "INSERT INTO appointments
                    (ref, branch_id, service_id, appointment_date, appointment_time,
                     first_name, last_name, phone, email, is_current_patient, comments)
                 VALUES
                    (:ref,
                     (SELECT id FROM branches WHERE code = :branch),
                     (SELECT id FROM services WHERE name = :service),
                     :date, :time, :first, :last, :phone, :email, :current, :comments)"
            );
            $ins->bindValue(':ref', $ref);
            $ins->bindValue(':branch', $f['branch']);
            $ins->bindValue(':service', $f['service']);
            $ins->bindValue(':date', $f['date']);
            $ins->bindValue(':time', date('H:i', strtotime($f['time'])));
            $ins->bindValue(':first', $f['first_name']);
            $ins->bindValue(':last', $f['last_name']);
            $ins->bindValue(':phone', $phoneDigits);
            $ins->bindValue(':email', $f['email']);
            $ins->bindValue(':current', $f['current_patient'] === 'yes', PDO::PARAM_BOOL);
            $ins->bindValue(':comments', $f['comments']);
            try { $ins->execute(); }
            catch (PDOException $ex1) {
                if (!is_sequence_conflict($ex1)) throw $ex1;
                fix_sequences(db());              // id counter was behind the data - repair it and retry once
                $ins->execute();
            }
            try {
                require_once __DIR__ . '/includes/appt_mail.php';
                $newId = (int)db()->query("SELECT id FROM appointments WHERE ref = " . db()->quote($ref))->fetchColumn();
                if ($newId) { appt_email(db(), $newId, 'received'); appt_email(db(), $newId, 'new_booking_alert'); }
            } catch (Throwable $mx) { error_log('MSCare booking email: ' . $mx->getMessage()); }
            header('Location: book.php?done=' . $ref);
            exit;
        } catch (PDOException $ex) {
            if (is_slot_conflict($ex)) {   // unique_violation: someone took the slot first
                $error = 'That time was just requested by someone else. Please choose another time.';
            } else {
                error_log('MSCare DB (insert appointment): ' . $ex->getMessage());
                $error = db_fail_text($ex, 'We could not save your request. Please call us instead.');
            }
        }
    }
    $old = $f;
}

$done = (isset($_GET['done']) && preg_match('/^MSC-[A-F0-9]{6}$/', $_GET['done'])) ? $_GET['done'] : '';
$pre = (isset($_GET['branch']) && isset($branches[$_GET['branch']])) ? $_GET['branch'] : ($old['branch'] ?? '');

$preService = (isset($_GET['service']) && in_array($_GET['service'], array_column($services, 0), true)) ? $_GET['service'] : '';

$jsBranches = [];
foreach ($branches as $key => $b) {
    $jsBranches[$key] = ['name' => $b['full'], 'days' => $b['days'], 'daysText' => $b['days_text']];
}

$page = 'book'; $title = 'Book an appointment';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="eyebrow">Online appointment</div>
    <h1>Book an appointment</h1>
  </div>
</section>

<section class="section book">
  <div class="container book-grid">

    <?php if ($done): ?>
      <div class="card success">
        <div class="success-mark"><?= icon($ui['check'], 28) ?></div>
        <h2>Request received</h2>
        <p>Thank you. The clinic will review your request and contact you to confirm your schedule.</p>
        <p class="ref">Reference number: <strong><?= e($done) ?></strong></p>
        <div class="btn-row center">
          <a class="btn btn-primary btn-pill" href="index.php">Back</a>
          <a class="btn btn-outline btn-pill" href="book.php">Book another visit</a>
        </div>
      </div>
    <?php else: ?>

    <form class="card wizard" id="bookForm" method="post" action="book.php" novalidate>
      <ol class="steps">
        <li data-step="1" class="active"><button type="button" class="step-tab" disabled><span class="num">1</span><span class="lbl">Service</span></button></li>
        <li data-step="2"><button type="button" class="step-tab" disabled><span class="num">2</span><span class="lbl">Date &amp; time</span></button></li>
        <li data-step="3"><button type="button" class="step-tab" disabled><span class="num">3</span><span class="lbl">Your details</span></button></li>
        <li data-step="4"><button type="button" class="step-tab" disabled><span class="num">4</span><span class="lbl">Review</span></button></li>
      </ol>

      <div class="panel" data-panel="1">
        <h2>Where and what for?</h2>
        <div class="group">
          <span class="group-label">Preferred branch <span class="req">*</span></span>
          <div class="choice-grid">
            <?php foreach ($branches as $key => $b): ?>
              <label class="choice">
                <input type="radio" name="branch" value="<?= e($key) ?>"<?= $pre === $key ? ' checked' : '' ?>>
                <span class="choice-text">
                  <strong><?= e($b['city']) ?></strong>
                  <span class="choice-sub"><?= icon($ui['pin'], 15) ?> <?= e($b['lines'][0]) ?></span>
                  <span class="choice-sub"><?= icon($ui['calendar'], 15) ?> <?= e($b['days_short']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <span class="err" data-err="branch"></span>
        </div>
        <div class="field">
          <label for="service">Service required <span class="req">*</span></label>
          <select id="service" name="service">
            <option value="">Select a service</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= e($s[0]) ?>"<?= $preService === $s[0] ? ' selected' : '' ?>><?= e($s[0]) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Not sure? Choose “General Dentistry”.</span>
          <span class="err" data-err="service"></span>
        </div>
        <div class="actions">
          <span></span>
          <button type="button" class="btn btn-primary btn-pill" data-next>Continue <?= icon($ui['arrow'], 16) ?></button>
        </div>
      </div>

      <div class="panel" data-panel="2" hidden>
        <h2>When would you like to come in?</h2>
        <div class="group">
          <span class="group-label">Preferred date <span class="req">*</span></span>
          <div class="date-grid" id="dateGrid"></div>
          <span class="err" data-err="date"></span>
        </div>
        <div class="field narrow">
          <label for="altDate">Or pick another date <span class="opt">(optional)</span></label>
          <input type="date" id="altDate">
          <span class="hint">Today’s remaining open times can be requested too.</span>
          <span class="err" data-err="altdate"></span>
        </div>
        <div class="group">
          <span class="group-label">Preferred time <span class="req">*</span></span>
          <div class="time-grid" id="timeGrid"></div>
          <span class="hint">Fully booked times are greyed out. The clinic will confirm your schedule.</span>
          <span class="err" data-err="time"></span>
        </div>
        <input type="hidden" name="date" id="dateVal">
        <input type="hidden" name="time" id="timeVal">
        <div class="actions">
          <button type="button" class="btn-back" data-prev><?= icon($ui['back'], 16) ?> Back</button>
          <button type="button" class="btn btn-primary btn-pill" data-next>Continue <?= icon($ui['arrow'], 16) ?></button>
        </div>
      </div>

      <div class="panel" data-panel="3" hidden>
        <h2>How can we reach you?</h2>
        <div class="row">
          <div class="field">
            <label for="first_name">First name <span class="req">*</span></label>
            <input id="first_name" name="first_name" type="text" autocomplete="given-name" maxlength="60">
            <span class="err" data-err="first_name"></span>
          </div>
          <div class="field">
            <label for="last_name">Last name <span class="req">*</span></label>
            <input id="last_name" name="last_name" type="text" autocomplete="family-name" maxlength="60">
            <span class="err" data-err="last_name"></span>
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label for="phone">Phone <span class="req">*</span></label>
            <input id="phone" name="phone" type="tel" placeholder="+63 9XX XXX XXXX" autocomplete="tel" maxlength="20">
            <span class="hint">e.g. +639171234567, 09171234567 or 9171234567</span>
            <span class="err" data-err="phone"></span>
          </div>
          <div class="field">
            <label for="email">Email <span class="req">*</span></label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="120">
            <span class="err" data-err="email"></span>
          </div>
        </div>
        <div class="group">
          <span class="group-label">Are you a current patient?</span>
          <div class="pill-choices">
            <label class="pill-choice"><input type="radio" name="current_patient" value="yes"> Yes</label>
            <label class="pill-choice"><input type="radio" name="current_patient" value="no" checked> No</label>
          </div>
          <span class="err" data-err="current_patient"></span>
        </div>
        <div class="field">
          <label for="comments">Comments <span class="opt">(optional)</span></label>
          <textarea id="comments" name="comments" rows="4" maxlength="500" placeholder="Anything we should know — symptoms, concerns, or questions."></textarea>
        </div>
        <div class="actions">
          <button type="button" class="btn-back" data-prev><?= icon($ui['back'], 16) ?> Back</button>
          <button type="button" class="btn btn-primary btn-pill" data-next>Continue <?= icon($ui['arrow'], 16) ?></button>
        </div>
      </div>

      <div class="panel" data-panel="4" hidden>
        <h2>Review your request</h2>
        <div class="alert bad" id="formError" role="alert" hidden></div>
        <div class="review-list" id="review"></div>
        <p class="fineprint">This is a request, not a confirmed booking. Your details are used only by the clinic to arrange your visit.</p>
        <div class="actions">
          <button type="button" class="btn-back" data-prev><?= icon($ui['back'], 16) ?> Back</button>
          <button type="submit" class="btn btn-primary btn-pill"><?= icon($ui['send'], 16) ?> Submit request</button>
        </div>
      </div>
    </form>
    <?php endif; ?>

    <aside class="book-side">
      <div class="card patient-hint">
        <h3>Already a patient?</h3>
        <p>Log in to book faster, see your appointments and keep your dental record up to date.</p>
        <a class="btn btn-teal btn-pill btn-sm" href="login.php?next=patient-book.php"><?= icon($ui['user'], 16) ?> Patient log in</a>
        <p class="meta" style="margin:12px 0 0;">New here? <a href="register.php">Create an account</a></p>
      </div>
      <div class="call-card">
        <h3>Prefer to talk to us?</h3>
        <p>Call a branch directly for urgent concerns.</p>
        <?php foreach ($branches as $b): ?>
          <div class="call-branch"><?= e($b['city']) ?></div>
          <a class="call-phone" href="tel:<?= e($clinic['phone_tel']) ?>"><?= icon($ui['phone'], 18) ?> <?= e($clinic['phone']) ?></a>
        <?php endforeach; ?>
      </div>
    </aside>
  </div>
</section>

<?php if (!$done): ?>
<script>
window.MSC = <?= json_encode([
    'branches' => $jsBranches,
    'slots'    => $slots,
    'taken'    => (object)$taken,
    'old'      => $old,
    'error'    => $error,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/book.js"></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
