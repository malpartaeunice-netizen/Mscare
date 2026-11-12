<?php $page = 'about'; $title = 'About Us'; include __DIR__ . '/includes/header.php'; ?>

<section class="page-hero photo" style="--hero-img:url('../<?= e($images['about_hero']) ?>')">
  <div class="container">
    <div class="eyebrow">About us</div>
    <h1><?= e($about['hero_title']) ?></h1>
  </div>
</section>

<section class="section">
  <div class="container profile">
    <img src="<?= e($images['dentist']) ?>" alt="Dr. Marilou S. Cariño">
    <div class="profile-text">
      <div class="eyebrow eyebrow-dark">Our dentist</div>
      <h2>Dr. Marilou S. Cariño</h2>
      <?php foreach ($about['dentist_paragraphs'] as $p): ?>
        <p><?= e($p) ?></p>
      <?php endforeach; ?>
      <ul class="checklist">
        <?php foreach ($about['dentist_points'] as $pt): ?>
          <li><?= e($pt) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<section class="section section-tint">
  <div class="container profile reverse">
    <img src="<?= e($images['assistant']) ?>" alt="Nina Clemente">
    <div class="profile-text">
      <div class="eyebrow eyebrow-dark">Dentist’s assistant</div>
      <h2>Nina Clemente</h2>
      <?php foreach ($about['assistant_paragraphs'] as $p): ?>
        <p><?= e($p) ?></p>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head"><h2>Our clinics</h2></div>
    <div class="grid grid-2">
      <?php foreach ($branches as $b): ?>
        <article class="clinic-card tone-border-<?= e($b['tone']) ?>">
          <h3><?= e($b['name']) ?></h3>
          <p><?= e($b['address']) ?></p>
          <p class="meta">Open <?= e($b['days_text']) ?></p>
          <p class="meta"><?= e($clinic['phone']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
