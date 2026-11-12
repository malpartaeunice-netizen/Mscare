<?php
require_once __DIR__ . '/config.php';

$page = 'contact'; $title = 'Contact';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="eyebrow">Contact us</div>
    <h1>We’re here to help</h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="branch-grid">
      <?php foreach ($branches as $key => $b): ?>
        <article class="branch-card">
          <div class="branch-map">
            <iframe title="Map of <?= e($b['full']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?= e(map_src($b['address'])) ?>"></iframe>
          </div>
          <div class="branch-body">
            <div class="eyebrow eyebrow-dark"><?= e($b['city']) ?> branch</div>
            <h2><?= e($b['full']) ?></h2>
            <div class="info-row">
              <?= icon($ui['pin'], 20) ?>
              <div><?php foreach ($b['lines'] as $line): ?><?= e($line) ?><br><?php endforeach; ?></div>
            </div>
            <div class="info-row">
              <?= icon($ui['phone'], 20) ?>
              <div><a class="phone" href="tel:<?= e($clinic['phone_tel']) ?>"><?= e($clinic['phone']) ?></a> <span class="tag">Mobile</span></div>
            </div>
            <div class="info-row">
              <?= icon($ui['clock'], 20) ?>
              <div>Open <?= e($b['days_text']) ?>.<br>Please call the branch to confirm clinic hours.</div>
            </div>
            <div class="btn-row branch-actions">
              <a class="btn btn-primary btn-pill" href="book.php?branch=<?= e($key) ?>">Book at <?= e($b['city']) ?></a>
              <a class="btn btn-outline btn-pill" href="<?= e(directions_url($b['address'])) ?>" target="_blank" rel="noopener"><?= icon($ui['compass'], 16) ?> Directions</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
