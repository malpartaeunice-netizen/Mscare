<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

if (empty($_SESSION['messages_csrf'])) $_SESSION['messages_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['messages_csrf'];

/* ---------- helpers ---------- */

/** Adds the "who sent it" column the first time (older databases). Safe to run any time. */
function msg_ensure_schema(PDO $pdo) {
    if (!empty($_SESSION['msg_chat_ok'])) return;
    try {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS sender varchar(10) NOT NULL DEFAULT 'patient'");
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS is_ai boolean NOT NULL DEFAULT false");
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS needs_human boolean NOT NULL DEFAULT false");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_messages_email ON contact_messages (lower(email))");
        $_SESSION['msg_chat_ok'] = 1;
    } catch (Throwable $ex) { error_log('MSCare msg schema: ' . $ex->getMessage()); }
}

/** A conversation = every message of one person at this clinic (same email, or same mobile when no email). */
function msg_conv(PDO $pdo, $mid, $branch_id) {
    $q = $pdo->prepare("SELECT name, mobile, COALESCE(email, '') AS email FROM contact_messages WHERE id = :id AND branch_id = :b");
    $q->execute([':id' => (int)$mid, ':b' => $branch_id]);
    return $q->fetch() ?: null;
}
function msg_cond(array $c, $alias = 'm') {
    return $c['email'] !== ''
        ? ["$alias.branch_id = :b AND lower($alias.email) = lower(:ce)", [':ce' => $c['email']]]
        : ["$alias.branch_id = :b AND COALESCE($alias.email, '') = '' AND $alias.mobile = :cm", [':cm' => $c['mobile']]];
}
function msg_initials($name) {
    $p = preg_split('/\s+/', trim((string)$name)); $s = '';
    foreach (array_slice($p, 0, 2) as $w) $s .= mb_strtoupper(mb_substr($w, 0, 1));
    return $s !== '' ? $s : '?';
}
function msg_json($data) { header('Content-Type: application/json'); echo json_encode($data); exit; }

$filter = ($_GET['f'] ?? '') === 'unread' ? 'unread' : 'all';
$chat_id = (int)($_GET['chat'] ?? $_GET['focus'] ?? 0);   // any message id of the conversation (the bell uses ?focus=)
$db_error = null;
$ajax = !empty($_POST['ajax']) || !empty($_GET['poll']);

try { msg_ensure_schema(db()); } catch (Throwable $ex) { $db_error = $ex->getMessage(); }

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $back = 'messages.php' . ($chat_id ? '?chat=' . $chat_id : '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Session expired. Please refresh the page.');
        $pdo = db();
        if ($act === 'read_all') {
            $pdo->prepare("UPDATE contact_messages SET is_read = true WHERE branch_id = :b AND sender = 'patient'")->execute([':b' => $branch_id]);
            flash('All messages marked as read.');
            redirect_to('messages.php');
        }
        $chat_id = (int)($_POST['chat'] ?? 0);
        $back = 'messages.php?chat=' . $chat_id;
        $conv = msg_conv($pdo, $chat_id, $branch_id);
        if (!$conv) throw new RuntimeException('Conversation not found.');
        [$cw, $cp] = msg_cond($conv);

        if ($act === 'send') {
            $text = trim((string)($_POST['message'] ?? ''));
            if ($text === '') throw new RuntimeException('Type a message first.');
            if (mb_strlen($text) > 1000) throw new RuntimeException('Please keep the message under 1000 characters.');
            $ins = $pdo->prepare("INSERT INTO contact_messages (name, mobile, email, branch_id, message, sender, is_read, replied_by_admin_id)
                                  VALUES (:n, :mo, :em, :b, :msg, 'admin', false, :a)
                                  RETURNING id, to_char(created_at, 'FMMon DD, YYYY') AS day_label, to_char(created_at, 'FMHH12:MI AM') AS time_label");
            $ins->execute([':n' => $conv['name'], ':mo' => $conv['mobile'], ':em' => $conv['email'] !== '' ? $conv['email'] : null,
                           ':b' => $branch_id, ':msg' => $text, ':a' => admin_id()]);
            $row = $ins->fetch();
            // answering a conversation also means the clinic has read it
            $pdo->prepare("UPDATE contact_messages m SET is_read = true WHERE $cw AND m.sender = 'patient' AND NOT m.is_read")->execute($cp + [':b' => $branch_id]);
            if ($ajax) msg_json(['ok' => true, 'row' => ['id' => (int)$row['id'], 'sender' => 'admin', 'message' => $text,
                                'day' => $row['day_label'], 'time' => $row['time_label'], 'admin' => $admin['display_name']]]);
            redirect_to($back . '#end');
        } elseif ($act === 'unread') {
            $pdo->prepare("UPDATE contact_messages SET is_read = false WHERE id = (SELECT max(m.id) FROM contact_messages m WHERE $cw AND m.sender = 'patient')")->execute($cp + [':b' => $branch_id]);
            flash('Marked as unread.');
            redirect_to('messages.php');
        }
    } catch (Throwable $e) {
        if ($ajax) msg_json(['ok' => false, 'error' => $e->getMessage()]);
        flash($e->getMessage(), 'error');
        redirect_to($back);
    }
    redirect_to('messages.php');
}

/* ---------- chatroom data ---------- */
$conv = null; $thread = []; $portal_account = false; $last_id = 0;
if ($chat_id) {
    try {
        $pdo = db();
        $conv = msg_conv($pdo, $chat_id, $branch_id);
        if ($conv) {
            [$cw, $cp] = msg_cond($conv);
            $cp[':b'] = $branch_id;
            // opening the chatroom = the clinic has seen the patient's messages
            $pdo->prepare("UPDATE contact_messages m SET is_read = true WHERE $cw AND m.sender = 'patient' AND NOT m.is_read")->execute($cp);
            $after = (int)($_GET['after'] ?? 0);
            $sel = "SELECT m.id, m.sender, m.is_ai, m.message, m.replied_at,
                           to_char(m.created_at, 'FMMon DD, YYYY') AS day_label,
                           to_char(m.created_at, 'FMHH12:MI AM')   AS time_label,
                           to_char(m.replied_at, 'FMMon DD, YYYY') AS replied_on,
                           a.display_name AS admin_name, m.created_at
                    FROM contact_messages m LEFT JOIN admins a ON a.id = m.replied_by_admin_id WHERE $cw";
            if (!empty($_GET['poll'])) {
                $q = $pdo->prepare("$sel AND m.id > :after ORDER BY m.created_at ASC, m.id ASC");
                $q->execute($cp + [':after' => $after]);
                $rows = [];
                foreach ($q->fetchAll() as $r) $rows[] = ['id' => (int)$r['id'], 'sender' => $r['sender'], 'message' => $r['message'],
                                                          'day' => $r['day_label'], 'time' => $r['time_label'], 'admin' => $r['admin_name'], 'ai' => !empty($r['is_ai'])];
                msg_json(['ok' => true, 'rows' => $rows]);
            }
            $q = $pdo->prepare("SELECT * FROM ($sel ORDER BY m.created_at DESC, m.id DESC LIMIT 300) t ORDER BY created_at ASC, id ASC");
            $q->execute($cp);
            $thread = $q->fetchAll();
            foreach ($thread as $r) $last_id = max($last_id, (int)$r['id']);
            if ($conv['email'] !== '') {
                $q = $pdo->prepare("SELECT 1 FROM patients WHERE lower(email) = lower(:e) LIMIT 1");
                $q->execute([':e' => $conv['email']]);
                $portal_account = (bool)$q->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        if (!empty($_GET['poll'])) msg_json(['ok' => false]);
        $db_error = $e->getMessage();
    }
}

/* ---------- inbox data (one row per person) ---------- */
$inbox = []; $n_unread = 0;
if (!$conv) {
    try {
        $st = db()->prepare("
            WITH m AS (
              SELECT x.*, CASE WHEN COALESCE(x.email, '') <> '' THEN 'e:' || lower(x.email) ELSE 't:' || x.mobile END AS ckey
              FROM contact_messages x WHERE x.branch_id = :b)
            SELECT * FROM (
              SELECT DISTINCT ON (m.ckey) m.id, m.name, m.mobile, COALESCE(m.email, '') AS email, m.message, m.sender, m.is_ai, m.created_at,
                     (SELECT count(*) FROM m m3 WHERE m3.ckey = m.ckey AND m3.sender = 'patient' AND m3.needs_human AND NOT m3.is_read) AS needs,
                     (SELECT count(*) FROM m m2 WHERE m2.ckey = m.ckey AND m2.sender = 'patient' AND NOT m2.is_read) AS unread,
                     CASE WHEN m.created_at::date = current_date THEN to_char(m.created_at, 'FMHH12:MI AM')
                          WHEN m.created_at::date > current_date - 7 THEN to_char(m.created_at, 'Dy')
                          ELSE to_char(m.created_at, 'FMMon DD') END AS short_time
              FROM m ORDER BY m.ckey, m.created_at DESC, m.id DESC) t
            ORDER BY created_at DESC");
        $st->execute([':b' => $branch_id]);
        $inbox = $st->fetchAll();
        foreach ($inbox as $r) if ((int)$r['unread'] > 0) $n_unread++;
        if ($filter === 'unread') $inbox = array_values(array_filter($inbox, function ($r) { return (int)$r['unread'] > 0; }));
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
    }
}
$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261107a">
</head>
<body data-guard="admin">
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item active" href="messages.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Messages</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
      <a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Revenue &amp; Reports</span></a>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= htmlspecialchars($admin['display_name']) ?></div>
          <div class="who-role"><?= htmlspecialchars($cinfo['place']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Messages</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:14px;padding:12px 16px;<?= ($flash['type'] ?? '') === 'error' ? 'color:var(--coral);' : '' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
      <?php if ($db_error): ?><div class="card" style="margin-bottom:14px;padding:12px 16px;color:var(--coral);">Could not load messages: <?= e($db_error) ?></div><?php endif; ?>

<?php if (!$conv): /* ================= INBOX ================= */ ?>
      <div class="card inbox">
        <div class="toolbar">
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-ghost' ?>" href="messages.php">All</a>
            <a class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-ghost' ?>" href="messages.php?f=unread">Unread (<?= $n_unread ?>)</a>
          </div>
          <?php if ($n_unread > 0): ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="read_all">
            <button class="btn btn-sm btn-ghost" type="submit">Mark all as read</button>
          </form>
          <?php endif; ?>
        </div>
        <div class="search-box" style="margin-bottom:10px;max-width:360px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <input id="msgSearch" type="search" placeholder="Search patients or messages" aria-label="Search conversations" autocomplete="off">
        </div>
        <div id="inboxList" class="inbox-list">
          <?php if (!$inbox): ?>
            <div class="inbox-empty"><?= $filter === 'unread' ? 'No unread conversations.' : 'No messages yet.' ?></div>
          <?php else: foreach ($inbox as $r): $u = (int)$r['unread']; ?>
            <a class="inbox-row<?= $u ? ' unread' : '' ?>" href="messages.php?chat=<?= (int)$r['id'] ?>"
               data-search="<?= e(strtolower($r['name'] . ' ' . $r['mobile'] . ' ' . $r['email'] . ' ' . $r['message'])) ?>">
              <div class="m-avatar"><?= e(msg_initials($r['name'])) ?></div>
              <div class="inbox-main">
                <div class="inbox-top"><span class="inbox-name"><?= e($r['name']) ?></span><span class="inbox-time"><?= e($r['short_time']) ?></span></div>
                <div class="inbox-prev">
                  <span class="inbox-text"><?= $r['sender'] === 'admin' ? (!empty($r['is_ai']) ? 'AI: ' : 'You: ') : '' ?><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $r['message']), 0, 90, '…')) ?></span>
                  <?php if ((int)$r['needs'] > 0): ?><span style="background:#fde8e4;color:#c0392b;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700;white-space:nowrap;margin-right:6px;">Needs reply</span><?php endif; ?>
                  <?php if ($u): ?><span class="inbox-badge"><?= $u > 99 ? '99+' : $u ?></span><?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>

<?php else: /* ================= CHATROOM ================= */
  $tel = preg_replace('/[^0-9+]/', '', $conv['mobile']); ?>
      <div class="card mchat" id="mchat" data-last="<?= (int)$last_id ?>" data-chat="<?= (int)$chat_id ?>" data-csrf="<?= e($csrf) ?>">
        <div class="mchat-head">
          <a class="mchat-back" href="messages.php" aria-label="Back to inbox"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg></a>
          <div class="m-avatar"><?= e(msg_initials($conv['name'])) ?></div>
          <div class="mchat-who">
            <strong><?= e($conv['name']) ?></strong>
            <span><?= e($conv['mobile']) ?><?= $conv['email'] !== '' ? ' · ' . e($conv['email']) : '' ?></span>
          </div>
          <div class="mchat-actions">
            <?php if ($tel !== ''): ?><a class="btn btn-sm btn-ghost" href="tel:<?= e($tel) ?>">Call</a><?php endif; ?>
            <?php if ($conv['email'] !== ''): ?><a class="btn btn-sm btn-ghost" href="mailto:<?= e($conv['email']) ?>?subject=<?= rawurlencode('Re: Your message to MSCare Dental Clinic') ?>">Email</a><?php endif; ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="chat" value="<?= (int)$chat_id ?>">
              <button class="btn btn-sm btn-ghost" name="action" value="unread" type="submit">Mark unread</button>
            </form>
          </div>
        </div>
        <?php if (!$portal_account): ?>
          <div class="mchat-warn">This sender has no patient-portal account<?= $conv['email'] === '' ? ' (no email given)' : '' ?>, so they won&rsquo;t see replies typed here. Use Call or Email instead.</div>
        <?php endif; ?>
        <div class="mchat-thread" id="mThread">
          <?php $lastDay = ''; foreach ($thread as $m):
            if ($m['day_label'] !== $lastDay): $lastDay = $m['day_label']; ?>
              <div class="mchat-day" data-day="<?= e($lastDay) ?>"><span><?= e($lastDay) ?></span></div>
            <?php endif;
            $mine = $m['sender'] === 'admin'; ?>
            <div class="mrow <?= $mine ? 'mine' : 'theirs' ?>" data-id="<?= (int)$m['id'] ?>">
              <div class="mbubble"><?= e($m['message']) ?><time><?= $mine && !empty($m['is_ai']) ? 'MSCare AI · ' : ($mine && $m['admin_name'] ? e($m['admin_name']) . ' · ' : '') ?><?= e($m['time_label']) ?></time></div>
            </div>
            <?php if (!$mine && $m['replied_at']): ?>
              <div class="mchat-note">Marked as replied on <?= e($m['replied_on']) ?> (phone/email)</div>
            <?php endif; ?>
          <?php endforeach; ?>
          <div id="end"></div>
        </div>
        <form class="mchat-composer" id="mForm" method="post" action="messages.php?chat=<?= (int)$chat_id ?>" novalidate>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="chat" value="<?= (int)$chat_id ?>"><input type="hidden" name="action" value="send">
          <textarea name="message" id="mText" rows="1" maxlength="1000" placeholder="Type a reply…" aria-label="Reply" required></textarea>
          <button type="submit" class="mchat-send" aria-label="Send"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
        </form>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  /* ---- inbox: search + auto-refresh ---- */
  var list = document.getElementById('inboxList'), q = document.getElementById('msgSearch');
  function applyFilter() {
    if (!list || !q) return;
    var t = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    list.querySelectorAll('.inbox-row').forEach(function (r) {
      r.style.display = t.every(function (w) { return (r.dataset.search || '').indexOf(w) > -1; }) ? '' : 'none';
    });
  }
  if (list && q) {
    q.addEventListener('input', applyFilter);
    setInterval(function () {
      if (document.hidden) return;
      fetch(location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) {
        var d = new DOMParser().parseFromString(h, 'text/html'), n = d.getElementById('inboxList');
        if (n) { list.innerHTML = n.innerHTML; applyFilter(); }
      }).catch(function () {});
    }, 8000);
  }

  /* ---- chatroom ---- */
  var box = document.getElementById('mchat'); if (!box) return;
  var th = document.getElementById('mThread'), form = document.getElementById('mForm'), ta = document.getElementById('mText');
  var last = parseInt(box.dataset.last, 10) || 0, chat = box.dataset.chat, busy = false;
  function bottom() { th.scrollTop = th.scrollHeight; }
  function lastDay() { var d = th.querySelectorAll('.mchat-day'); return d.length ? d[d.length - 1].dataset.day : ''; }
  function add(r) {
    if (r.id <= last) return; last = r.id;
    if (r.day && r.day !== lastDay()) {
      var dv = document.createElement('div'); dv.className = 'mchat-day'; dv.dataset.day = r.day;
      var sp = document.createElement('span'); sp.textContent = r.day; dv.appendChild(sp); th.insertBefore(dv, document.getElementById('end'));
    }
    var mine = r.sender === 'admin', row = document.createElement('div');
    row.className = 'mrow ' + (mine ? 'mine' : 'theirs'); row.dataset.id = r.id;
    var b = document.createElement('div'); b.className = 'mbubble'; b.textContent = r.message;
    var t = document.createElement('time'); t.textContent = (mine && r.ai ? 'MSCare AI · ' : (mine && r.admin ? r.admin + ' · ' : '')) + r.time;
    b.appendChild(t); row.appendChild(b); th.insertBefore(row, document.getElementById('end'));
  }
  function fit() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; }
  bottom(); fit(); ta.addEventListener('input', fit);
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (ta.value.trim()) form.requestSubmit(); }
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || !ta.value.trim()) return; busy = true;
    var fd = new FormData(form); fd.append('ajax', '1');
    fetch('messages.php?chat=' + chat, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.ok) { add(j.row); ta.value = ''; fit(); bottom(); } else { (window.msToast ? msToast(j.error || 'Could not send.', 'error') : alert(j.error || 'Could not send.')); }
      }).catch(function () { (window.msToast ? msToast('Could not send. Check your connection.', 'error') : alert('Could not send. Check your connection.')); })
      .then(function () { busy = false; ta.focus(); });
  });
  setInterval(function () {
    if (document.hidden) return;
    fetch('messages.php?chat=' + chat + '&poll=1&after=' + last, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok || !j.rows.length) return;
        var near = th.scrollHeight - th.scrollTop - th.clientHeight < 120;
        j.rows.forEach(add); if (near) bottom();
      }).catch(function () {});
  }, 4000);
})();
</script>
<script src="dialog.js?v=20261027"></script>
</body>
</html>
