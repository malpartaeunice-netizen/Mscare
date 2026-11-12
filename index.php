<?php $page = 'home'; $title = 'Home'; include __DIR__ . '/includes/header.php'; ?>

<section class="home-hero">
  <div class="container home-hero-grid">
    <div class="home-hero-copy">
      <div class="eyebrow eyebrow-dark">Makati · Manila</div>
      <h1>Every smile, looked after with care.</h1>
      <div class="btn-row">
        <a class="btn btn-outline btn-pill btn-lg" href="services.php">View our services <?= icon($ui['arrow'], 18) ?></a>
      </div>
    </div>
    <figure class="hero-photo">
      <img src="<?= e($images['home']) ?>" alt="MSC Dental Clinic entrance">
    </figure>
  </div>
</section>

<section class="stats" aria-label="MSCare at a glance">
  <div class="container stats-grid">
    <div class="stat"><strong>2 clinics</strong><span>Makati &amp; Manila</span></div>
    <div class="stat"><strong><?= count($services) ?> services</strong><span>From check-ups to braces</span></div>
    <div class="stat"><strong>1 dentist</strong><span>Dr. Cariño at both clinics</span></div>
    <div class="stat"><strong>Mon to Sat</strong><span>Makati Mon, Wed, Fri &amp; Sat · Manila Tue &amp; Thu</span></div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head">
      <h2>What we can do for you</h2>
      <a class="link-arrow" href="services.php">View all services</a>
    </div>
    <div class="grid grid-4">
      <?php foreach (array_slice($services, 0, 4) as [$name, , $path, $tone]): ?>
        <article class="card service">
          <div class="icon tone-<?= $tone ?>"><?= icon($path) ?></div>
          <h3><?= e($name) ?></h3>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-tint">
  <div class="container split">
    <div>
      <a class="btn btn-teal btn-pill" href="about.php">Meet the team</a>
    </div>
    <div class="clinic-stack">
      <?php foreach ($branches as $b): ?>
        <article class="clinic-card tone-border-<?= e($b['tone']) ?>">
          <h3><?= e($b['name']) ?></h3>
          <p><?= e($b['address']) ?></p>
          <p class="meta">Open <?= e($b['days_text']) ?></p>
        </article>
      <?php endforeach; ?>
      <div class="hero-card light">
        <?php include __DIR__ . '/includes/weekstrip.php'; ?>
      </div>
    </div>
  </div>
</section>

<section class="cta">
  <div class="container cta-inner">
    <h2>Ready for your next visit?</h2>
    <div class="btn-row">
      <a class="btn btn-primary" href="tel:<?= e($clinic['phone_tel']) ?>">Call <?= e($clinic['phone']) ?></a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
