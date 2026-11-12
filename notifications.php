<?php
require_once __DIR__ . '/includes/auth.php';

$patient = current_patient();
$admin   = current_admin();
if (!$admin) {
    if ($patient) redirect_to('patient-appointments.php');
    redirect_to('login.php');
}

$pdo = db();
$rows = [];
if ($patient) {
    $pid = patient_id();
    $st = $pdo->prepare("SELECT id, title, message, is_read, created_at, appointment_id FROM notifications WHERE patient_id = :p ORDER BY created_at DESC, id DESC");
    $st->execute([':p' => $pid]);
    $rows = $st->fetchAll();
} else {
    $aid = admin_id();
    $ab  = (int)(current_admin()['branch_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, title, message, is_read, created_at, patient_id, appointment_id FROM notifications WHERE (admin_id IS NULL OR admin_id = :aid) AND (branch_id IS NULL OR :ab = 0 OR branch_id = :ab) ORDER BY created_at DESC, id DESC");
    $st->execute([':aid' => $aid, ':ab' => $ab]);
    $rows = $st->fetchAll();
}

$markAction = $admin ? 'mark_admin_notifications_read' : 'mark_notifications_read';

$page  = 'notifications';
$title = 'Notifications';
include __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container" style="max-width:820px;">
    <div class="p-card">
      <div class="p-card-head">
        <h2>All notifications</h2>
        <button type="button" class="btn btn-outline btn-sm btn-pill" id="markAllBtn">Mark all as read</button>
      </div>
      <?php if (!$rows): ?>
        <div class="p-notif-empty">No notifications yet. Updates about appointments, signatures, and messages will appear here.</div>
      <?php else: ?>
        <div class="notif-list">
          <?php foreach ($rows as $n): ?>
            <a class="p-notif-item<?= $n['is_read'] ? '' : ' unread' ?>" data-id="<?= (int)$n['id'] ?>" href="notifications.php">
              <strong><?= e($n['title']) ?></strong>
              <span><?= e($n['message']) ?></span>
              <small><?= e(time_ago_label($n['created_at'])) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<script>
(function(){
  var action = <?= json_encode($markAction) ?>;
  function mark(id){
    fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:action,id:id})}).catch(function(){});
  }
  document.querySelectorAll('.notif-list .p-notif-item').forEach(function(a){
    a.addEventListener('click', function(){ mark(+a.dataset.id); a.classList.remove('unread'); });
  });
  var btn = document.getElementById('markAllBtn');
  if (btn) btn.addEventListener('click', function(){
    fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:action})}).then(function(r){return r.json();}).then(function(d){
      if (d.ok) document.querySelectorAll('.notif-list .p-notif-item.unread').forEach(function(i){i.classList.remove('unread');});
    });
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
