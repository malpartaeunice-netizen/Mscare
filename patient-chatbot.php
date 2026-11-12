<?php
// MSCare chatbot (opens as a popup from the floating "Chatbot" button in the patient portal).
// Only the ready-made questions in config.php ($bot_faqs) can be asked. Gemini AI writes the answer;
// if Gemini is off or fails, the ready-made answer is shown instead. Nothing is saved - there is no
// patient <-> admin chat anymore.
require_once __DIR__ . '/includes/auth.php';
require_patient();
require_once __DIR__ . '/includes/ai_chat.php';

if (empty($_SESSION['bot_csrf'])) { $_SESSION['bot_csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['bot_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $i = (int)($_POST['faq'] ?? -1);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? '')) || !isset($bot_faqs[$i])) {
        echo json_encode(['ok' => false, 'error' => 'Please choose one of the questions.']); exit;
    }
    if (!isset($_SESSION['bot_cache'][$i])) {                       // ask Gemini once per question per login
        $reply = $bot_faqs[$i][1];                                  // fallback: the ready-made answer
        if (ai_enabled()) {
            $ans = gemini_ask([], $bot_faqs[$i][0]);
            if ($ans && !empty($ans['can_answer']) && trim((string)$ans['reply']) !== '') $reply = trim($ans['reply']);
        }
        $_SESSION['bot_cache'][$i] = $reply;
    }
    echo json_encode(['ok' => true, 'reply' => $_SESSION['bot_cache'][$i]]); exit;
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chatbot | <?= e($clinic['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="assets/portal.css?v=<?= filemtime(__DIR__ . '/assets/portal.css') ?>">
<style>
/* chatbot is always white (inline so an old cached portal.css can't bring the mint background back) */
html,body,body.chat-embed{background:#fff !important;}
body.chat-embed .chat,body.chat-embed .chat-head,body.chat-embed .chat-thread,body.chat-embed .faq-tray{background:#fff !important;}
body.chat-embed .bubble.them{background:#EAF2F8 !important;border:1px solid #C9D8E4 !important;color:#0B2A40;}
body.chat-embed .bubble.typing{background:#EAF2F8 !important;}
</style>
</head><body class="chat-embed">
<div class="chat">
  <div class="chat-head">
    <div class="chat-avatar">MS</div>
    <div><strong>MSCare Chatbot</strong><span class="chat-status"><i></i>Online</span></div>
  </div>
  <div class="chat-thread" id="thread">
    <div class="bubble-row them"><div class="bubble them">Hello! Welcome to MSCare Dental Clinic. &#128075;<br>How can I assist you today? You can select one of the frequently asked questions below.</div></div>
  </div>
  <div class="faq-tray" id="faqBox">
    <div class="faq-tray-title">Frequently asked questions</div>
    <?php foreach ($bot_faqs as $i => $f): ?>
      <button type="button" class="faq-btn" data-i="<?= (int)$i ?>"><span><?= e($f[0]) ?></span><?= icon('M9 6l6 6-6 6', 16) ?></button>
    <?php endforeach; ?>
    <p class="faq-note">Need something else? Call us at <a href="tel:<?= e($clinic['phone_tel']) ?>"><?= e($clinic['phone']) ?></a>.</p>
  </div>
</div>
<script>
(function () {
  var t = document.getElementById('thread'), tray = document.getElementById('faqBox');
  var csrf = <?= json_encode($csrf) ?>, busy = false;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function wait(ms) { return new Promise(function (r) { setTimeout(r, reduce ? 0 : ms); }); }
  function stamp(mine) { return (mine ? '' : 'MSCare Chatbot \u00b7 ') + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  function toEnd() { t.scrollTo({ top: t.scrollHeight, behavior: reduce ? 'auto' : 'smooth' }); }
  function addTime(b, mine) { var tm = document.createElement('time'); tm.textContent = stamp(mine); b.appendChild(tm); }

  function bubble(text, mine) {
    var row = document.createElement('div'); row.className = 'bubble-row ' + (mine ? 'me' : 'them');
    var b = document.createElement('div'); b.className = 'bubble' + (mine ? '' : ' them');
    var span = document.createElement('span'); span.className = 'msg-text'; span.textContent = text; b.appendChild(span);
    addTime(b, mine); row.appendChild(b); t.appendChild(row); toEnd(); return row;
  }
  function typing() {
    var row = document.createElement('div'); row.className = 'bubble-row them';
    var b = document.createElement('div'); b.className = 'bubble them typing';
    b.innerHTML = '<span class="typing-dots"><i></i><i></i><i></i></span>';
    row.appendChild(b); t.appendChild(row); toEnd(); return row;
  }
  // turns the typing bubble into the answer and "types" it out word by word
  function reveal(row, text) {
    var b = row.firstChild; b.classList.remove('typing'); b.innerHTML = '';
    var span = document.createElement('span'); span.className = 'msg-text'; b.appendChild(span);
    if (reduce) { span.textContent = text; addTime(b, false); toEnd(); return Promise.resolve(); }
    var words = text.split(/(\s+)/), i = 0;
    return new Promise(function (done) {
      (function step() {
        var n = 0;
        while (i < words.length && n < 2) { span.textContent += words[i++]; if (words[i - 1].trim()) n++; }
        t.scrollTop = t.scrollHeight;
        if (i < words.length) { setTimeout(step, 28); } else { addTime(b, false); toEnd(); done(); }
      })();
    });
  }
  function setBusy(on, btn) {
    busy = on; tray.classList.toggle('is-busy', on);
    tray.querySelectorAll('.faq-btn').forEach(function (x) { x.classList.toggle('is-picked', on && x === btn); });
  }

  tray.querySelectorAll('.faq-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (busy) return; setBusy(true, btn);
      bubble(btn.querySelector('span').textContent, true);
      var fd = new FormData(); fd.append('faq', btn.dataset.i); fd.append('csrf', csrf);
      var req = fetch('patient-chatbot.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { return j.ok ? j.reply : (j.error || 'Something went wrong. Please try again.'); })
        .catch(function () { return 'Connection problem. Please try again.'; });
      wait(450).then(function () {
        var dots = typing();
        return Promise.all([req, wait(900)]).then(function (res) { return reveal(dots, res[0]); });
      }).then(function () { setBusy(false); });
    });
  });
})();
</script>
</body></html>
