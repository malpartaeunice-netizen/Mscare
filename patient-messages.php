<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

// CSRF token for this form
if (empty($_SESSION['pmsg_csrf'])) { $_SESSION['pmsg_csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['pmsg_csrf'];

// Messages now carry who sent them ('patient' or 'admin') so the clinic can reply inside the chat.
if (empty($_SESSION['pmsg_chat_ok'])) {
    try {
        db()->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS sender varchar(10) NOT NULL DEFAULT 'patient'");
        db()->exec("CREATE INDEX IF NOT EXISTS ix_messages_email ON contact_messages (lower(email))");
        db()->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS is_ai boolean NOT NULL DEFAULT false");
        db()->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS needs_human boolean NOT NULL DEFAULT false");
        $_SESSION['pmsg_chat_ok'] = 1;
    } catch (PDOException $ex) { error_log('MSCare DB (chat schema): ' . $ex->getMessage()); }
}

// Live updates: the page asks every few seconds whether the clinic has replied.
if (!empty($_GET['poll'])) {
    header('Content-Type: application/json');
    try {
        $q = db()->prepare("SELECT m.id, m.message, m.is_ai, to_char(m.created_at, 'FMMon DD, YYYY') AS day_label,
                                   to_char(m.created_at, 'FMHH12:MI AM') AS time_label
                            FROM contact_messages m
                            WHERE LOWER(m.email) = LOWER(:em) AND m.sender = 'admin' AND m.id > :after
                              AND m.branch_id = (SELECT id FROM branches WHERE code = :clinic)
                            ORDER BY m.created_at ASC, m.id ASC");
        $q->execute([':em' => $patient['email'], ':after' => (int)($_GET['after'] ?? 0), ':clinic' => (string)($_GET['clinic'] ?? '')]);
        $rows = $q->fetchAll();
        if ($rows) db()->prepare("UPDATE contact_messages SET is_read = TRUE WHERE LOWER(email) = LOWER(:em) AND sender = 'admin' AND NOT is_read
                                    AND branch_id = (SELECT id FROM branches WHERE code = :clinic)")
                       ->execute([':em' => $patient['email'], ':clinic' => (string)($_GET['clinic'] ?? '')]);
        echo json_encode(['ok' => true, 'rows' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'message' => $r['message'], 'ai' => !empty($r['is_ai']), 'day' => $r['day_label'], 'time' => $r['time_label']];
        }, $rows)]);
    } catch (PDOException $ex) { echo json_encode(['ok' => false]); }
    exit;
}

// The clinic is the branch the patient chose when registering (fallback: branch of their latest appointment).
$my_branch = null; $db_error = null;
try {
    $q = db()->prepare("SELECT b.id, b.code, b.name FROM patients p JOIN branches b ON b.id = p.branch_id WHERE p.id = :p");
    $q->execute([':p' => $pid]);
    $my_branch = $q->fetch() ?: null;
    if (!$my_branch) {
        $q = db()->prepare("SELECT b.id, b.code, b.name FROM appointments a JOIN branches b ON b.id = a.branch_id
                            WHERE a.patient_id = :p ORDER BY a.created_at DESC LIMIT 1");
        $q->execute([':p' => $pid]);
        $my_branch = $q->fetch() ?: null;
    }
} catch (PDOException $ex) {
    error_log('MSCare DB (patient branch): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your messages right now.');
}

// Every conversation belongs to ONE branch. The patient picks which clinic to talk to; the message is stored
// with that branch_id, so only that branch's admin sees it (and only that branch's replies show up here).
$all_branches = [];
try { $all_branches = db()->query("SELECT id, code, name, city FROM branches ORDER BY id")->fetchAll(); } catch (PDOException $ex) { error_log('MSCare DB (branches): ' . $ex->getMessage()); }
$cur = null;
$want = (string)($_POST['clinic'] ?? $_GET['clinic'] ?? '');
foreach ($all_branches as $i => $b) { $all_branches[$i]['label'] = 'Clinic ' . ($i + 1) . ' - ' . $b['city']; if ($want !== '' && $b['code'] === $want) $cur = $all_branches[$i]; }
if (!$cur && $my_branch) { foreach ($all_branches as $b) { if ((int)$b['id'] === (int)$my_branch['id']) { $cur = $b; break; } } }
if (!$cur && $all_branches) $cur = $all_branches[0];

$errors = [];
$draft = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft = trim($_POST['message'] ?? '');

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Your session expired. Please try again.';
    }
    if ($draft === '')                  $errors['form'] = 'Type a message first.';
    elseif (str_len($draft) > 1000)     $errors['form'] = 'Please keep your message under 1000 characters.';
    if (!$cur) $errors['form'] = 'Choose a clinic to message.';

    if (!$errors) {
        try {
            $ins = db()->prepare(
                "INSERT INTO contact_messages (name, mobile, email, branch_id, message)
                 VALUES (:name, :mobile, :email, :bid, :message)
                 RETURNING id"
            );
            $ins->execute([
                ':name'    => mb_substr($patient['full_name'], 0, 100),
                ':mobile'  => mb_substr($patient['phone'], 0, 20),
                ':email'   => $patient['email'],
                ':bid'     => (int)$cur['id'],
                ':message' => $draft,
            ]);
            $new_id = (int)$ins->fetchColumn();
            // AI assistant: answers common questions, hands the rest over to the clinic admin
            require_once __DIR__ . '/includes/ai_chat.php';
            ai_autoreply(db(), $patient, (int)$cur['id'], $new_id, $draft);
            redirect_to('patient-messages.php?clinic=' . urlencode($cur['code']) . '#end');
        } catch (PDOException $ex) {
            error_log('MSCare DB (patient message): ' . $ex->getMessage());
            $errors['form'] = db_fail_text($ex, 'We could not send your message. Please call us instead.');
        }
    }
}

// Conversation so far (matched by the patient's account email), oldest first
$thread = [];
try {
    // opening the chat = the patient has seen the clinic's replies
    db()->prepare("UPDATE contact_messages SET is_read = TRUE WHERE LOWER(email) = LOWER(:em) AND sender = 'admin' AND NOT is_read AND branch_id = :b")
        ->execute([':em' => $patient['email'], ':b' => (int)($cur['id'] ?? 0)]);
    $q = db()->prepare("
        SELECT * FROM (
          SELECT m.id, m.message, m.sender, m.is_ai, m.replied_at,
                 to_char(m.created_at, 'FMMon DD, YYYY') AS day_label,
                 to_char(m.created_at, 'HH12:MI AM')     AS time_label,
                 to_char(m.replied_at, 'FMMon DD, YYYY') AS replied_on,
                 m.created_at
          FROM contact_messages m WHERE LOWER(m.email) = LOWER(:em) AND m.branch_id = :b
          ORDER BY m.created_at DESC LIMIT 100) t
        ORDER BY created_at ASC, id ASC");
    $q->execute([':em' => $patient['email'], ':b' => (int)($cur['id'] ?? 0)]);
    $thread = $q->fetchAll();
} catch (PDOException $ex) {
    error_log('MSCare DB (patient messages list): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your messages right now.');
}

$ptitle  = 'Messages';
$psub    = '';
$pactive = 'messages';
include __DIR__ . '/includes/portal_head.php';
$clinic_label = $cur ? $cur['label'] : 'MSCare Dental Clinic';
?>

<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
  <div class="banner err" role="alert"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($errors['form']) ?></strong></div></div>
<?php endif; ?>

<div class="chat">
  <div class="chat-head">
    <div class="chat-avatar">MS</div>
    <div>
      <strong><?= e($clinic_label) ?></strong>
      <span>Replies from this clinic show up here</span>
    </div>
    <?php if (count($all_branches) > 1): ?>
      <select class="chat-branch" aria-label="Choose clinic" onchange="location.href='patient-messages.php?clinic=' + encodeURIComponent(this.value)">
        <?php foreach ($all_branches as $b): ?>
          <option value="<?= e($b['code']) ?>"<?= $cur && $cur['code'] === $b['code'] ? ' selected' : '' ?>><?= e($b['label']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

  <div class="chat-thread" id="thread">
    <?php if (!$thread): ?>
      <div class="chat-empty"><strong>No messages yet</strong>Say hello or ask the clinic a question below.</div>
    <?php else:
      $lastDay = '';
      foreach ($thread as $m):
        if ($m['day_label'] !== $lastDay): $lastDay = $m['day_label']; ?>
          <div class="chat-day"><span><?= e($lastDay) ?></span></div>
        <?php endif; ?>
        <?php $theirs = ($m['sender'] ?? 'patient') === 'admin'; ?>
        <div class="bubble-row <?= $theirs ? 'them' : 'me' ?>" data-id="<?= (int)$m['id'] ?>">
          <div class="bubble<?= $theirs ? ' them' : '' ?>"><?= e($m['message']) ?><time><?= $theirs ? (!empty($m['is_ai']) ? 'MSCare AI · ' : 'MSCare · ') : '' ?><?= e($m['time_label']) ?></time></div>
        </div>
        <?php if (!$theirs && $m['replied_at']): ?>
          <div class="chat-note"><?= icon($ui['check'], 14) ?> The clinic replied on <?= e($m['replied_on']) ?> (by phone or email)</div>
        <?php endif; ?>
    <?php endforeach; endif; ?>
    <div id="end" data-last="<?= $thread ? (int)end($thread)['id'] : 0 ?>"></div>
  </div>

  <form class="chat-composer" method="post" action="patient-messages.php" novalidate>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="clinic" value="<?= e($cur['code'] ?? '') ?>">
    <textarea name="message" id="chatText" rows="1" maxlength="1000" placeholder="Type a message…" aria-label="Message" required><?= e($draft) ?></textarea>
    <button type="submit" class="chat-send" aria-label="Send message"><?= icon($ui['send'], 20) ?></button>
  </form>
</div>

<script>
(function () {
  var t = document.getElementById('thread'); if (t) t.scrollTop = t.scrollHeight;
  var endEl = document.getElementById('end'), last = endEl ? parseInt(endEl.dataset.last, 10) || 0 : 0;
  // every row's id, so we ask only for the clinic's newer replies
  document.querySelectorAll('#thread .bubble-row[data-id]').forEach(function (r) { last = Math.max(last, parseInt(r.dataset.id, 10) || 0); });
  setInterval(function () {
    if (document.hidden || !t) return;
    fetch('patient-messages.php?poll=1&clinic=<?= urlencode($cur['code'] ?? '') ?>&after=' + last, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok || !j.rows.length) return;
      var empty = t.querySelector('.chat-empty'); if (empty) empty.remove();
      j.rows.forEach(function (m) {
        last = Math.max(last, m.id);
        var row = document.createElement('div'); row.className = 'bubble-row them'; row.dataset.id = m.id;
        var b = document.createElement('div'); b.className = 'bubble them'; b.textContent = m.message;
        var tm = document.createElement('time'); tm.textContent = (m.ai ? 'MSCare AI · ' : 'MSCare · ') + m.time; b.appendChild(tm);
        row.appendChild(b); t.insertBefore(row, endEl);
      });
      t.scrollTop = t.scrollHeight;
    }).catch(function () {});
  }, 5000);
  var ta = document.getElementById('chatText'); if (!ta) return;
  function fit() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; }
  ta.addEventListener('input', fit); fit();
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (ta.value.trim()) ta.form.submit(); }
  });
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
