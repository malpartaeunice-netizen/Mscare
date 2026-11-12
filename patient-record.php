<?php
require_once __DIR__ . '/includes/auth.php';

require_patient();
$patient = current_patient();
$pid = patient_id();

$pdo = db();
$p_stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
$p_stmt->execute([':id' => $pid]);
$p = $p_stmt->fetch();
if (!$p) {
    unset($_SESSION['mscare_patient']);
    redirect_to('login.php');
}
$patient = current_patient();

$r_stmt = $pdo->prepare("SELECT * FROM patient_records WHERE patient_id = :id");
$r_stmt->execute([':id' => $pid]);
$rec = $r_stmt->fetch() ?: [];

$m_stmt = $pdo->prepare("SELECT * FROM patient_medical_histories WHERE patient_id = :id");
$m_stmt->execute([':id' => $pid]);
$med = $m_stmt->fetch() ?: [];

$c_stmt = $pdo->prepare("SELECT * FROM patient_consents WHERE patient_id = :id");
$c_stmt->execute([':id' => $pid]);
$con = $c_stmt->fetch() ?: [];

$error = '';

function pg_text_array($values) {
    $clean = [];
    foreach ((array)$values as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $clean[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return '{' . implode(',', $clean) . '}';
}

function array_value($value) {
    if (is_array($value)) return $value;
    $value = trim((string)$value, '{}');
    if ($value === '') return [];
    return array_map(static function ($v) {
        return trim($v, '"');
    }, str_getcsv($value));
}

function post_upper($key) {
    return mb_strtoupper(trim((string)($_POST[$key] ?? '')));
}

function post_text($key) {
    return trim((string)($_POST[$key] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    try {
        if ($step === 'info') {
            $first  = post_upper('first');
            $middle = post_upper('middle');
            $last   = post_upper('last');
            $age    = (int)($_POST['age'] ?? 0);
            $sex    = post_text('sex');
            $email  = strtolower(post_text('email'));
            $cell   = post_text('cell');

            if ($last === '' || $first === '') {
                $error = 'Please enter your first and last name.';
            } elseif (!preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $last) || !preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $first)
                      || ($middle !== '' && !preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $middle))) {
                $error = 'Names can only contain letters, spaces, hyphens, apostrophes and periods.';
            } elseif ($age < 0 || $age > 120) {
                $error = 'Please enter a valid age.';
            } elseif (!in_array($sex, ['Female', 'Male'], true)) {
                $error = 'Please choose your sex.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $cell)) {
                $error = 'Please enter a valid cell / mobile number.';
            } else {
                $dup = $pdo->prepare("SELECT id FROM patients WHERE LOWER(email) = :em AND id <> :id");
                $dup->execute([':em' => $email, ':id' => $pid]);
                if ($dup->fetch()) {
                    $error = 'Another account already uses that email.';
                } else {
                    $pdo->beginTransaction();
                    $up = $pdo->prepare("UPDATE patients SET first_name=:fn, middle_name=:mn, last_name=:ln,
                                         age=:ag, gender=:gn, email=:em, phone=:ph, updated_at=now() WHERE id=:id");
                    $up->execute([':fn' => $first, ':mn' => $middle, ':ln' => $last,
                        ':ag' => $age, ':gn' => $sex, ':em' => $email, ':ph' => $cell, ':id' => $pid]);

                    $upsert = $pdo->prepare("
                        INSERT INTO patient_records
                            (patient_id, birthdate, sex, nickname, religion, nationality, home_address,
                             occupation, dental_insurance, effective_date, home_no, office_no, fax_no,
                             cell_no, email, guardian_name, guardian_occupation, prev_dentist,
                             last_dental_visit, reason_for_consultation, is_completed)
                        VALUES
                            (:pid, :birth, :sex, :nick, :rel, :nat, :addr, :occ, :ins, :eff,
                             :hno, :ono, :fax, :cno, :em, :gname, :gocc, :pdent, :ldv, :reason, TRUE)
                        ON CONFLICT (patient_id) DO UPDATE SET
                            birthdate = EXCLUDED.birthdate, sex = EXCLUDED.sex, nickname = EXCLUDED.nickname,
                            religion = EXCLUDED.religion, nationality = EXCLUDED.nationality,
                            home_address = EXCLUDED.home_address, occupation = EXCLUDED.occupation,
                            dental_insurance = EXCLUDED.dental_insurance, effective_date = EXCLUDED.effective_date,
                            home_no = EXCLUDED.home_no, office_no = EXCLUDED.office_no, fax_no = EXCLUDED.fax_no,
                            cell_no = EXCLUDED.cell_no, email = EXCLUDED.email,
                            guardian_name = EXCLUDED.guardian_name, guardian_occupation = EXCLUDED.guardian_occupation,
                            prev_dentist = EXCLUDED.prev_dentist, last_dental_visit = EXCLUDED.last_dental_visit,
                            reason_for_consultation = EXCLUDED.reason_for_consultation, is_completed = TRUE
                    ");
                    $upsert->execute([
                        ':pid' => $pid,
                        ':birth' => post_text('birth') ?: null,
                        ':sex' => $sex,
                        ':nick' => post_upper('nickname'),
                        ':rel' => post_upper('religion'),
                        ':nat' => post_upper('nationality'),
                        ':addr' => post_upper('address'),
                        ':occ' => post_upper('occupation'),
                        ':ins' => post_upper('insurance'),
                        ':eff' => post_text('effective') ?: null,
                        ':hno' => post_text('homeNo'),
                        ':ono' => post_text('officeNo'),
                        ':fax' => post_text('faxNo'),
                        ':cno' => $cell,
                        ':em' => $email,
                        ':gname' => post_upper('guardian'),
                        ':gocc' => post_upper('guardianOcc'),
                        ':pdent' => post_upper('prevDentist'),
                        ':ldv' => post_text('lastVisit') ?: null,
                        ':reason' => post_upper('reason'),
                    ]);
                    $pdo->commit();
                    refresh_patient_session($pid);
                    flash('Patient information saved.');
                    redirect_to('patient-record.php#info');
                }
            }
        } elseif ($step === 'medical') {
            $yn = [];
            foreach (['good', 'treat', 'ill', 'hosp', 'meds', 'tobacco', 'alcohol'] as $key) {
                $yn[$key] = ($_POST[$key] ?? '') === 'Yes' ? 'Yes' : (($_POST[$key] ?? '') === 'No' ? 'No' : '');
            }
            $female = ($_POST['sex'] ?? ($rec['sex'] ?? '')) === 'Female';
            foreach (['preg', 'nurse', 'pills'] as $key) {
                $yn[$key] = ($_POST[$key] ?? '') === 'Yes' ? 'Yes' : (($_POST[$key] ?? '') === 'No' ? 'No' : '');
            }

            $allergies = array_values(array_filter((array)($_POST['allergy'] ?? []), static fn($v) => $v !== ''));
            $conditions = array_values(array_filter((array)($_POST['cond'] ?? []), static fn($v) => $v !== ''));

            $required_yn = ['good', 'treat', 'ill', 'hosp', 'meds', 'tobacco', 'alcohol'];
            if ($female) {
                $required_yn = array_merge($required_yn, ['preg', 'nurse', 'pills']);
            }
            $missing_yn = false;
            foreach ($required_yn as $key) {
                if ($yn[$key] === '') { $missing_yn = true; break; }
            }

            if ($missing_yn) {
                $error = 'Please answer every Yes / No health question.';
            } elseif (!$allergies) {
                $error = 'Please tick your allergies, or tick None.';
            } elseif (in_array('Other', $allergies, true) && post_text('allergy_other') === '') {
                $error = 'Please specify your other allergy.';
            } elseif (!$conditions) {
                $error = 'Please tick your conditions, or tick None.';
            } elseif (in_array('Other', $conditions, true) && post_text('cond_other') === '') {
                $error = 'Please specify your other condition.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO patient_medical_histories
                        (patient_id, physician_name, physician_specialty, physician_office_no, physician_office_addr,
                         in_good_health, under_treatment, treatment_details, serious_illness_or_op, illness_details,
                         hospitalized, hospitalized_details, taking_medication, medication_details,
                         tobacco_use, alcohol_drug_use, allergies, allergy_other,
                         women_pregnant, women_nursing, women_birth_control,
                         blood_type, blood_pressure, conditions, condition_other, is_completed)
                    VALUES
                        (:pid, :pn, :ps, :pno, :paddr, :good, :treat, :treat_d, :ill, :ill_d,
                         :hosp, :hosp_d, :meds, :meds_d, :tob, :alc, :algs, :alg_other,
                         :preg, :nurse, :pills, :blood, :bp, :conds, :cond_other, TRUE)
                    ON CONFLICT (patient_id) DO UPDATE SET
                        physician_name = EXCLUDED.physician_name, physician_specialty = EXCLUDED.physician_specialty,
                        physician_office_no = EXCLUDED.physician_office_no, physician_office_addr = EXCLUDED.physician_office_addr,
                        in_good_health = EXCLUDED.in_good_health, under_treatment = EXCLUDED.under_treatment,
                        treatment_details = EXCLUDED.treatment_details, serious_illness_or_op = EXCLUDED.serious_illness_or_op,
                        illness_details = EXCLUDED.illness_details, hospitalized = EXCLUDED.hospitalized,
                        hospitalized_details = EXCLUDED.hospitalized_details, taking_medication = EXCLUDED.taking_medication,
                        medication_details = EXCLUDED.medication_details, tobacco_use = EXCLUDED.tobacco_use,
                        alcohol_drug_use = EXCLUDED.alcohol_drug_use, allergies = EXCLUDED.allergies,
                        allergy_other = EXCLUDED.allergy_other, women_pregnant = EXCLUDED.women_pregnant,
                        women_nursing = EXCLUDED.women_nursing, women_birth_control = EXCLUDED.women_birth_control,
                        blood_type = EXCLUDED.blood_type, blood_pressure = EXCLUDED.blood_pressure,
                        conditions = EXCLUDED.conditions, condition_other = EXCLUDED.condition_other,
                        is_completed = TRUE
                ");
                // PDO/pgsql binds PHP false as an empty string, which is not a
                // valid boolean literal, so pass explicit PostgreSQL booleans.
                $bool = static fn($v) => $v === 'Yes' ? 'true' : 'false';
                $stmt->execute([
                    ':pid' => $pid,
                    ':pn' => post_upper('phys'), ':ps' => post_upper('spec'),
                    ':pno' => post_text('physNo'), ':paddr' => post_upper('physAddr'),
                    ':good' => $bool($yn['good']), ':treat' => $bool($yn['treat']),
                    ':treat_d' => post_upper('treat_d'), ':ill' => $bool($yn['ill']),
                    ':ill_d' => post_upper('ill_d'), ':hosp' => $bool($yn['hosp']),
                    ':hosp_d' => post_upper('hosp_d'), ':meds' => $bool($yn['meds']),
                    ':meds_d' => post_upper('meds_d'), ':tob' => $bool($yn['tobacco']),
                    ':alc' => $bool($yn['alcohol']), ':algs' => pg_text_array($allergies),
                    ':alg_other' => post_upper('allergy_other'),
                    ':preg' => $female ? $bool($yn['preg']) : null,
                    ':nurse' => $female ? $bool($yn['nurse']) : null,
                    ':pills' => $female ? $bool($yn['pills']) : null,
                    ':blood' => post_text('blood'), ':bp' => post_upper('bp'),
                    ':conds' => pg_text_array($conditions), ':cond_other' => post_upper('cond_other'),
                ]);
                flash('Medical history saved. Please sign the informed consent.');
                redirect_to('patient-record.php#consent');
            }
        } elseif ($step === 'consent') {
            $med_done = (bool)($med['is_completed'] ?? false);
            if (!$med_done) {
                $error = 'Please complete your medical history before signing the consent.';
            } elseif (($con['status'] ?? '') === 'approved') {
                $error = 'Your informed consent is already approved. No further action is needed.';
            } elseif (empty($_POST['agree'])) {
                $error = 'Please tick the box to give your consent.';
            } else {
                $signature = (string)($con['signature_data'] ?? '');
                $new_signature = 0;

                if (!empty($_FILES['signature']['tmp_name']) && is_uploaded_file($_FILES['signature']['tmp_name'])) {
                    $file = $_FILES['signature'];
                    if ($file['size'] > 4 * 1024 * 1024) {
                        $error = 'The signature image must be smaller than 4 MB.';
                    } else {
                        $info = @getimagesize($file['tmp_name']);
                        $mime = $info['mime'] ?? '';
                        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
                        if (!in_array($mime, $allowed, true)) {
                            $error = 'Please upload a PNG, JPG or WEBP image of your signature.';
                        } else {
                            $signature = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
                            $new_signature = 1;
                        }
                    }
                }

                if ($error === '') {
                    if ($signature === '') {
                        $error = 'Please upload a picture of your signature.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO patient_consents
                                (patient_id, has_agreed, signature_data, signature_date, sig_tries_used, status)
                            VALUES (:pid, TRUE, :sig, CURRENT_DATE, 1, 'pending')
                            ON CONFLICT (patient_id) DO UPDATE SET
                                has_agreed = TRUE,
                                signature_data = EXCLUDED.signature_data,
                                signature_date = CURRENT_DATE,
                                sig_tries_used = patient_consents.sig_tries_used + :inc,
                                status = 'pending',
                                rejection_note = NULL,
                                reviewed_by_admin_id = NULL,
                                reviewed_at = NULL
                        ");
                        $stmt->execute([
                            ':pid' => $pid,
                            ':sig' => $signature,
                            ':inc' => $new_signature,
                        ]);
                        flash('Consent submitted. The clinic will review and approve your signature.');
                        redirect_to('patient-record.php#consent');
                    }
                }
            }
        }
    } catch (PDOException $ex) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('MSCare DB (record): ' . $ex->getMessage());
        $error = db_fail_text($ex, 'We could not save that. Please try again.');
    }
}

// Reload rows after a failed POST so the page shows current database values.
$r_stmt->execute([':id' => $pid]); $rec = $r_stmt->fetch() ?: [];
$m_stmt->execute([':id' => $pid]); $med = $m_stmt->fetch() ?: [];
$c_stmt->execute([':id' => $pid]); $con = $c_stmt->fetch() ?: [];

$allergies_saved = array_value($med['allergies'] ?? []);
$conditions_saved = array_value($med['conditions'] ?? []);
$consent_status = $con['status'] ?? 'none';
$med_done = !empty($med['is_completed']);
$ready = $med_done && $consent_status === 'approved';

$value = static fn($key, $default = '') => (string)($rec[$key] ?? $default);
$yn_value = static function ($key, $default = '') use ($med) {
    // Not answered yet (no saved record, or NULL column) -> nothing pre-selected.
    if (!array_key_exists($key, $med) || $med[$key] === null || $med[$key] === '') return '';
    return ($med[$key] === true || $med[$key] === 't' || $med[$key] === 'true' || $med[$key] === 1 || $med[$key] === '1') ? 'Yes' : 'No';
};
$family = [];
$family['good'] = $yn_value('in_good_health');
$family['treat'] = $yn_value('under_treatment');
$family['ill'] = $yn_value('serious_illness_or_op');
$family['hosp'] = $yn_value('hospitalized');
$family['meds'] = $yn_value('taking_medication');
$family['tobacco'] = $yn_value('tobacco_use');
$family['alcohol'] = $yn_value('alcohol_drug_use');
$family['preg'] = $yn_value('women_pregnant');
$family['nurse'] = $yn_value('women_nursing');
$family['pills'] = $yn_value('women_birth_control');

$sex_saved = $rec['sex'] ?? $p['gender'];
$date_value = static function ($v) {
    return $v ? substr((string)$v, 0, 10) : '';
};

$ptitle = 'My Dental Record';
$psub   = '';
$pactive = 'record';
include __DIR__ . '/includes/portal_head.php';
?>
      <div id="recRoot">
      <?php if ($error): ?>
        <div class="banner err" role="alert"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($error) ?></strong></div></div>
      <?php endif; ?>
      <?php if ($ready): ?>
      <?php else: ?>
        <div class="banner warn">
          <?= icon($ui['lock'], 20) ?>
          <div class="b-text">
            <strong>Booking is locked for now.</strong>
            <span>
              <?php if (!$med_done): ?>Please complete your medical history.
              <?php elseif ($consent_status === 'pending'): ?>Your signature is waiting for clinic approval.
              <?php elseif ($consent_status === 'rejected'): ?>Your signature was not approved. Please upload a new one.
              <?php else: ?>Please sign the informed consent.<?php endif; ?>
            </span>
          </div>
        </div>
      <?php endif; ?>

      <div class="p-card">
        <div class="rec-tabs">
          <button type="button" class="active" data-rec-tab="info">Patient information</button>
          <button type="button" data-rec-tab="medical">Medical history <span class="tab-flag <?= $med_done ? 'done' : '' ?>"></span></button>
          <button type="button" data-rec-tab="consent">Informed consent</button>
        </div>

        <!-- PATIENT INFORMATION -->
        <div data-rec-panel="info">
          <form id="infoForm" method="post" action="patient-record.php">
            <input type="hidden" name="step" value="info">
            <div class="rec-scroll">
            <p class="rec-eyebrow">Personal details</p>
            <div class="rec-grid">
              <div class=""><label for="r_last">Last name <span class="req">*</span></label><input id="r_last" name="last" type="text" required value="<?= e($p['last_name']) ?>" data-uppercase></div>
              <div class=""><label for="r_first">First name <span class="req">*</span></label><input id="r_first" name="first" type="text" required value="<?= e($p['first_name']) ?>" data-uppercase></div>
              <div class=""><label for="r_middle">Middle name</label><input id="r_middle" name="middle" type="text" value="<?= e($p['middle_name']) ?>" data-uppercase></div>
              <div class=""><label for="r_birth">Birthdate</label><input id="r_birth" name="birth" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($date_value($rec['birthdate'] ?? '')) ?>"></div>
              <div class=""><label for="r_age">Age</label><input id="r_age" name="age" type="number" min="0" max="120" value="<?= (int)$p['age'] ?>"></div>
              <div><label for="r_sex">Sex (M/F) <span class="req">*</span></label><select id="r_sex" name="sex" required><option value="">Select</option><option <?= $sex_saved === 'Female' ? 'selected' : '' ?>>Female</option><option <?= $sex_saved === 'Male' ? 'selected' : '' ?>>Male</option></select></div>
              <div class=""><label for="r_nickname">Nickname</label><input id="r_nickname" name="nickname" type="text" value="<?= e($rec['nickname'] ?? '') ?>" data-uppercase></div>
              <div class=""><label for="r_religion">Religion</label><input id="r_religion" name="religion" type="text" value="<?= e($rec['religion'] ?? '') ?>" data-uppercase></div>
              <div class=""><label for="r_nationality">Nationality</label><input id="r_nationality" name="nationality" type="text" value="<?= e($rec['nationality'] ?? '') ?>" data-uppercase></div>
            </div>
            <p class="rec-eyebrow rec-sub">Contact information</p>
            <div class="rec-grid">
              <div class="full"><label for="r_address">Home address</label><input id="r_address" name="address" type="text" value="<?= e($rec['home_address'] ?? '') ?>" data-uppercase></div>
              <div><label for="r_cell">Cell / mobile no. <span class="req">*</span></label><input id="r_cell" name="cell" type="tel" required value="<?= e($p['phone']) ?>"></div>
              <div class="span2"><label for="r_email">Email address <span class="req">*</span></label><input id="r_email" name="email" type="email" required value="<?= e($p['email']) ?>"></div>
              <div class=""><label for="r_homeNo">Home no.</label><input id="r_homeNo" name="homeNo" type="tel" value="<?= e($rec['home_no'] ?? '') ?>"></div>
              <div class=""><label for="r_officeNo">Office no.</label><input id="r_officeNo" name="officeNo" type="tel" value="<?= e($rec['office_no'] ?? '') ?>"></div>
              <div class=""><label for="r_faxNo">Fax no.</label><input id="r_faxNo" name="faxNo" type="tel" value="<?= e($rec['fax_no'] ?? '') ?>"></div>
            </div>
            <p class="rec-eyebrow rec-sub">Work &amp; insurance</p>
            <div class="rec-grid">
              <div class=""><label for="r_occupation">Occupation</label><input id="r_occupation" name="occupation" type="text" value="<?= e($rec['occupation'] ?? '') ?>" data-uppercase></div>
              <div class=""><label for="r_insurance">Dental insurance</label><input id="r_insurance" name="insurance" type="text" value="<?= e($rec['dental_insurance'] ?? '') ?>" data-uppercase></div>
              <div class=""><label for="r_effective">Effective date</label><input id="r_effective" name="effective" type="date" value="<?= e($date_value($rec['effective_date'] ?? '')) ?>"></div>
            </div>
            <p class="rec-eyebrow rec-sub <?= ((int)($p['age'] ?? 0) >= 18) ? 'hidden' : '' ?>" id="minorsLabel">For minors (under 18)</p>
            <div class="rec-grid <?= ((int)($p['age'] ?? 0) >= 18) ? 'hidden' : '' ?>" id="minorsBlock">
              <div class="span2"><label for="r_guardian">Parent / guardian name</label><input id="r_guardian" name="guardian" type="text" value="<?= e($rec['guardian_name'] ?? '') ?>" data-uppercase></div>
              <div><label for="r_guardianOcc">Parent / guardian occupation</label><input id="r_guardianOcc" name="guardianOcc" type="text" value="<?= e($rec['guardian_occupation'] ?? '') ?>" data-uppercase></div>
            </div>
            <p class="rec-eyebrow rec-sub">Dental history</p>
            <div class="rec-grid">
              <div class="span2"><label for="r_prevDentist">Previous dentist</label><input id="r_prevDentist" name="prevDentist" type="text" value="<?= e($rec['prev_dentist'] ?? '') ?>" data-uppercase></div>
              <div><label for="r_lastVisit">Last dental visit</label><input id="r_lastVisit" name="lastVisit" type="date" value="<?= e($date_value($rec['last_dental_visit'] ?? '')) ?>"></div>
              <div class="full"><label for="r_reason">Reason for consultation</label><input id="r_reason" name="reason" type="text" value="<?= e($rec['reason_for_consultation'] ?? '') ?>" data-uppercase></div>
            </div>
            </div>
            <div class="rec-actions"><button type="submit" class="btn btn-primary">Save &amp; continue</button></div>
          </form>
        </div>

        <!-- MEDICAL HISTORY -->
        <div data-rec-panel="medical" class="hidden">
          <form id="medForm" method="post" action="patient-record.php">
            <input type="hidden" name="step" value="medical">
            <input type="hidden" name="sex" value="<?= e($sex_saved) ?>">
            <div class="rec-scroll">
            <p class="rec-eyebrow">Physician information</p>
            <div class="rec-grid">
              <div class=""><label for="r_phys">Name of physician</label><input id="r_phys" name="phys" type="text" value="<?= e($med['physician_name'] ?? '') ?>" data-uppercase></div>
              <div class=""><label for="r_spec">Specialty</label><input id="r_spec" name="spec" type="text" value="<?= e($med['physician_specialty'] ?? '') ?>" data-uppercase></div>
              <div class=""><label for="r_physNo">Physician office no.</label><input id="r_physNo" name="physNo" type="tel" value="<?= e($med['physician_office_no'] ?? '') ?>"></div>
              <div class="full"><label for="r_physAddr">Physician office address</label><input id="r_physAddr" name="physAddr" type="text" value="<?= e($med['physician_office_addr'] ?? '') ?>" data-uppercase></div>
            </div>
            <p class="rec-eyebrow rec-sub">General health questions <span class="req">*</span> all required</p>
            <div class="yn-list">
              <?php
              $questions = [
                  'good' => 'Are you in good health?',
                  'treat' => 'Are you under medical treatment now?',
                  'ill' => 'Have you ever had a serious illness or surgical operation?',
                  'hosp' => 'Have you ever been hospitalized?',
                  'meds' => 'Are you taking any prescription or non-prescription medication?',
                  'tobacco' => 'Do you use tobacco products?',
                  'alcohol' => 'Do you use alcohol, cocaine or other dangerous drugs?',
              ];
              $follow = ['treat' => 'If yes, what is the condition being treated?', 'ill' => 'If yes, what illness or operation?', 'hosp' => 'If yes, when and why?', 'meds' => 'If yes, please specify'];
              foreach ($questions as $key => $label): ?>
                <div class="yn" data-yn="<?= $key ?>">
                  <div class="yn-row"><span class="yn-text"><?= e($label) ?></span><span class="yn-opts">
                    <label><input type="radio" name="<?= $key ?>" value="Yes" <?= $family[$key] === 'Yes' ? 'checked' : '' ?> required> Yes</label>
                    <label><input type="radio" name="<?= $key ?>" value="No" <?= $family[$key] === 'No' ? 'checked' : '' ?> required> No</label>
                  </span></div>
                  <?php if (isset($follow[$key])): ?>
                    <div class="yn-follow <?= $family[$key] === 'Yes' ? '' : 'hidden' ?>" data-follow="<?= $key ?>"><input class="follow-input" name="<?= $key ?>_d" type="text" value="<?= e($med[$key === 'treat' ? 'treatment_details' : ($key === 'ill' ? 'illness_details' : ($key === 'hosp' ? 'hospitalized_details' : 'medication_details'))] ?? '') ?>" data-uppercase placeholder="<?= e($follow[$key]) ?>"></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div id="femBlock" class="<?= $sex_saved === 'Female' ? '' : 'hidden' ?>">
              <p class="rec-eyebrow rec-sub">For women only <span class="req">*</span></p>
              <div class="yn-list">
                <?php foreach (['preg' => 'Are you pregnant?', 'nurse' => 'Are you nursing?', 'pills' => 'Are you taking birth control pills?'] as $key => $label): ?>
                  <div class="yn" data-yn="<?= $key ?>"><div class="yn-row"><span class="yn-text"><?= e($label) ?></span><span class="yn-opts">
                    <label><input type="radio" name="<?= $key ?>" value="Yes" <?= $family[$key] === 'Yes' ? 'checked' : '' ?> data-fem-req> Yes</label>
                    <label><input type="radio" name="<?= $key ?>" value="No" <?= $family[$key] === 'No' ? 'checked' : '' ?> data-fem-req> No</label>
                  </span></div></div>
                <?php endforeach; ?>
              </div>
            </div>
            <p class="rec-eyebrow rec-sub">Blood type &amp; blood pressure</p>
            <div class="rec-grid">
              <div><label for="r_blood">Blood type</label><select id="r_blood" name="blood">
                <option value="">Not sure</option>
                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                  <option <?= ($med['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                <?php endforeach; ?>
              </select></div>
              <div class=""><label for="r_bp">Blood pressure</label><input id="r_bp" name="bp" type="text" value="<?= e($med['blood_pressure'] ?? '') ?>" data-uppercase></div>
            </div>
            <p class="rec-eyebrow rec-sub">Are you allergic to any of the following? <span class="req">*</span></p>
            <div class="chk-grid" id="allergyBox">
              <?php foreach (['Local anesthetic (e.g. Lidocaine)', 'Penicillin / antibiotics', 'Sulfa drugs', 'Aspirin', 'Latex', 'Pollen / food', 'Other'] as $a): ?>
                <label class="chk"><input type="checkbox" name="allergy[]" value="<?= e($a) ?>" <?= in_array($a, $allergies_saved, true) ? 'checked' : '' ?>> <?= e($a) ?></label>
              <?php endforeach; ?>
              <input class="other-input<?= in_array('Other', $allergies_saved, true) ? '' : ' hidden' ?>" name="allergy_other" type="text" value="<?= e($med['allergy_other'] ?? '') ?>" data-uppercase placeholder="Please specify">
              <label class="chk none"><input type="checkbox" name="allergy[]" value="None" <?= in_array('None', $allergies_saved, true) ? 'checked' : '' ?>> None</label>
            </div>
            <p class="rec-eyebrow rec-sub">Do you have or have you had any of the following? <span class="req">*</span></p>
            <div class="chk-grid" id="condBox">
              <?php foreach (['High blood pressure', 'Low blood pressure', 'Heart disease', 'Heart murmur', 'Rheumatic fever', 'Stroke', 'Diabetes', 'Thyroid problem', 'Asthma', 'Epilepsy / convulsions', 'Hepatitis / liver disease', 'Kidney disease', 'Tuberculosis', 'Anemia', 'Bleeding problems', 'Cancer / tumor', 'HIV / AIDS', 'Other'] as $c): ?>
                <label class="chk"><input type="checkbox" name="cond[]" value="<?= e($c) ?>" <?= in_array($c, $conditions_saved, true) ? 'checked' : '' ?>> <?= e($c) ?></label>
              <?php endforeach; ?>
              <input class="other-input<?= in_array('Other', $conditions_saved, true) ? '' : ' hidden' ?>" name="cond_other" type="text" value="<?= e($med['condition_other'] ?? '') ?>" data-uppercase placeholder="Please specify">
              <label class="chk none"><input type="checkbox" name="cond[]" value="None" <?= in_array('None', $conditions_saved, true) ? 'checked' : '' ?>> None</label>
            </div>
            </div>
            <div class="rec-actions"><button type="submit" class="btn btn-primary">Save &amp; continue</button></div>
          </form>
        </div>

        <!-- INFORMED CONSENT -->
        <div data-rec-panel="consent" class="hidden">
          <form id="conForm" method="post" action="patient-record.php" enctype="multipart/form-data">
            <input type="hidden" name="step" value="consent">
            <div class="rec-scroll">
            <p class="rec-eyebrow">Informed consent</p>
            <div class="consent-text">
              <p>I understand that dental treatment, like any health care, carries some risk. By signing below I confirm that I have read and understood the following, and that my questions were answered.</p>
              <ol>
                <li><b>Treatment to be done.</b> I consent to the examination, diagnosis and treatment recommended by Dr. Marilou S. Cariño and the clinic staff.</li>
                <li><b>Drugs and medications.</b> Antibiotics, pain relievers and anesthetics can cause allergic reactions, swelling, drowsiness or stomach upset. I will tell the dentist about my allergies and the medicines I take.</li>
                <li><b>Changes in the treatment plan.</b> The plan may change if new findings appear during treatment. I agree to be informed and to approve any change.</li>
                <li><b>X-rays (radiographs).</b> I allow radiographs to be taken when needed for diagnosis.</li>
                <li><b>Removal of teeth.</b> I understand the risks of extraction, such as pain, swelling, bleeding, dry socket and injury to nearby teeth, and agree to follow after-care instructions.</li>
                <li><b>Fillings, crowns, bridges and dentures.</b> I understand these may need adjustment, can chip or loosen over time, and need proper care and regular check-ups.</li>
                <li><b>Root canal and gum treatment.</b> I understand that results cannot be guaranteed and that further treatment may sometimes be needed.</li>
                <li><b>Records and privacy.</b> I allow MSCare to keep my dental and medical records and use them for my care. My information is kept confidential in line with the Data Privacy Act of 2012.</li>
              </ol>
            </div>
            <label class="consent-agree"><input type="checkbox" name="agree" required><span>I have read and understood the information above and I consent to dental treatment.</span></label>
            <div class="rec-grid consent-sign">
              <div class="span2"><label>Signature (upload image) <span class="req">*</span></label>
                <input type="file" id="r_sigFile" name="signature" accept="image/png,image/jpeg,image/webp" <?= $consent_status === 'approved' ? 'disabled' : '' ?>>
                <?php if (!empty($con['signature_data'])): ?>
                  <div class="sig-preview" style="margin-top:10px;">
                    <img src="<?= e($con['signature_data']) ?>" alt="Your signature">
                  </div>
                <?php endif; ?>
              </div>
              <div><label for="r_signDate">Date</label><input id="r_signDate" type="text" readonly value="<?= date('M j, Y') ?>"></div>
            </div>
            <div class="sig-status <?= $consent_status === 'approved' ? 'ok' : ($consent_status === 'pending' ? 'wait' : ($consent_status === 'rejected' ? 'bad' : '')) ?>">
              <?php if ($consent_status === 'approved'): ?>
                Approved by the clinic<?= !empty($con['reviewed_at']) ? ' on ' . date('M j, Y', strtotime($con['reviewed_at'])) : '' ?>. You can now book an appointment.
              <?php elseif ($consent_status === 'pending'): ?>
                Submitted. Waiting for the clinic to check and approve your signature.
              <?php elseif ($consent_status === 'rejected'): ?>
                The clinic did not approve your signature<?= !empty($con['rejection_note']) ? ': ' . e($con['rejection_note']) : '' ?>. Please upload it again.
              <?php else: ?>
                No signature uploaded yet.
              <?php endif; ?>
            </div>
            </div>
            <?php if ($consent_status !== 'approved'): ?>
              <p class="hint-text">Upload a clear picture of your handwritten signature in dark ink on plain white paper (PNG, JPG or WEBP, up to 4 MB). The clinic will review it before your booking is unlocked.</p>
              <div class="rec-actions"><button type="submit" class="btn btn-primary" <?= $med_done ? '' : 'disabled' ?>>Sign &amp; submit</button></div>
            <?php endif; ?>
          </form>
        </div>
      </div>
      </div>

<script>
(function () {
  var root = document.getElementById('recRoot');
  if (!root) return;
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-rec-tab]'));
  var panels = Array.prototype.slice.call(root.querySelectorAll('[data-rec-panel]'));

  function show(name) {
    if (['info', 'medical', 'consent'].indexOf(name) < 0) name = 'info';
    tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.recTab === name); });
    panels.forEach(function (p) { p.classList.toggle('hidden', p.dataset.recPanel !== name); });
    try { history.replaceState(null, '', '#' + name); } catch (e) {}
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.recTab); }); });

  document.querySelectorAll('[data-uppercase]').forEach(function (el) {
    el.addEventListener('input', function () {
      var a = el.selectionStart, b = el.selectionEnd, u = el.value.toUpperCase();
      if (u !== el.value) { el.value = u; try { el.setSelectionRange(a, b); } catch (e) {} }
    });
  });

  document.querySelectorAll('#medForm input[type=radio]').forEach(function (r) {
    r.addEventListener('change', function () {
      var box = r.closest('.yn');
      var follow = box && box.querySelector('[data-follow]');
      if (follow) follow.classList.toggle('hidden', r.value !== 'Yes');
    });
  });

  document.querySelectorAll('#allergyBox input[type=checkbox], #condBox input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      if (cb.value !== 'None' && cb.checked) {
        var none = cb.closest('.chk-grid').querySelector('input[value="None"]');
        if (none) none.checked = false;
      }
      if (cb.value === 'None' && cb.checked) {
        cb.closest('.chk-grid').querySelectorAll('input[type=checkbox]').forEach(function (o) { if (o !== cb) o.checked = false; });
      }
    });
  });

  document.querySelectorAll('#allergyBox, #condBox').forEach(function (box) {
    var other = box.querySelector('input[type=checkbox][value="Other"]');
    var txt = box.querySelector('.other-input');
    if (!other || !txt) return;
    other.addEventListener('change', function () {
      txt.classList.toggle('hidden', !other.checked);
      if (other.checked) txt.focus(); else txt.value = '';
    });
    box.querySelectorAll('input[value="None"]').forEach(function (n) {
      n.addEventListener('change', function () {
        if (n.checked) { txt.classList.add('hidden'); txt.value = ''; }
      });
    });
  });

  function femReq(on) {
    document.querySelectorAll('#femBlock [data-fem-req]').forEach(function (r) { r.required = on; });
  }
  var femBlock = document.getElementById('femBlock');
  femReq(!!femBlock && !femBlock.classList.contains('hidden'));
  var sex = document.getElementById('r_sex');
  if (sex) sex.addEventListener('change', function () {
    if (femBlock) femBlock.classList.toggle('hidden', sex.value !== 'Female');
    femReq(sex.value === 'Female');
  });

  // Show the "For minors" (guardian) block only when age < 18
  var ageInput = document.getElementById('r_age');
  var minorsLabel = document.getElementById('minorsLabel');
  var minorsBlock = document.getElementById('minorsBlock');
  function toggleMinors() {
    var a = parseInt(ageInput && ageInput.value, 10);
    var isMinor = isNaN(a) || a < 18;
    if (minorsLabel) minorsLabel.classList.toggle('hidden', !isMinor);
    if (minorsBlock) minorsBlock.classList.toggle('hidden', !isMinor);
  }
  if (ageInput) ageInput.addEventListener('input', toggleMinors);
  if (ageInput) ageInput.addEventListener('change', toggleMinors);
  toggleMinors();

  var hash = (location.hash || '').replace('#', '');
  if (hash) show(hash);
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
