<?php $page = 'services'; $title = 'Services'; include __DIR__ . '/includes/header.php'; ?>

<section class="page-hero photo" style="--hero-img:url('../<?= e($images['services_hero']) ?>')">
  <div class="container">
    <div class="eyebrow">Services</div>
    <h1>Our services</h1>
  </div>
</section>

<section class="section svc-page">
  <div class="container">
    <div class="filter-bar">
      <div class="chips" id="svcChips">
        <button type="button" class="chip on" data-cat="">All services</button>
        <?php foreach ($serviceCategories as $cat): ?>
          <button type="button" class="chip" data-cat="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></button>
        <?php endforeach; ?>
      </div>
      <label class="search">
        <?= icon($ui['search'], 20) ?>
        <input type="search" id="svcSearch" placeholder="Search e.g. braces, crowns, gums" aria-label="Search services" autocomplete="off">
      </label>
    </div>
    <p class="count" id="svcCount" aria-live="polite"><?= count($services) ?> services</p>

    <div class="grid grid-3" id="svcGrid">
      <?php foreach ($services as [$name, $desc, $path, $tone, $img, $cat]): ?>
        <article class="svc-card" data-text="<?= e(strtolower($name . ' ' . $desc . ' ' . ($serviceAlt[$name] ?? ''))) ?>" data-cat="<?= e(strtolower($cat)) ?>">
          <a class="svc-media" href="book.php?service=<?= rawurlencode($name) ?>" tabindex="-1" aria-hidden="true">
            <img src="<?= e($img) ?>" alt="" loading="lazy">
          </a>
          <div class="svc-body">
            <div class="svc-cat"><?= e($cat) ?></div>
            <h3><?= e($name) ?></h3>
            <?php if (!empty($serviceAlt[$name])): ?><div class="svc-alt"><?= e($serviceAlt[$name]) ?></div><?php endif; ?>
            <p><?= e($desc) ?></p>
            <a class="svc-link" href="book.php?service=<?= rawurlencode($name) ?>">Book this service <?= icon($ui['arrow'], 16) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="note" id="svcEmpty" hidden>No services match your search. Try another word.</p>
  </div>
</section>

<section class="section section-tint">
  <div class="container split">
    <div>
      <h2>Where to get each service</h2>
    </div>
    <div class="hero-card light">
      <?php include __DIR__ . '/includes/weekstrip.php'; ?>
      <ul class="legend">
        <?php foreach ($branches as $b): ?>
          <li><span class="dot dot-<?= e($b['tone']) ?>"></span><?= e($b['name']) ?>: <?= e($b['days_short']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<section class="cta">
  <div class="container cta-inner">
    <h2>Ready for your next visit?</h2>
    <div class="btn-row">
      <a class="btn btn-outline-light" href="tel:<?= e($clinic['phone_tel']) ?>">Call <?= e($clinic['phone']) ?></a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
