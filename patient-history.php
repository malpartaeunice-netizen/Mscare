<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$treatments = []; $teeth = []; $db_error = null;
try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT t.id, t.treatment_date, t.procedure_name, t.tooth, t.dentist_name, t.cost, t.notes, b.name AS branch_name
        FROM treatments t JOIN branches b ON b.id = t.branch_id
        WHERE t.patient_id = :pid ORDER BY t.treatment_date DESC");
    $stmt->execute([':pid' => $pid]);
    $treatments = $stmt->fetchAll();

    $t = $pdo->prepare("SELECT tooth_number, condition_code FROM dental_charts WHERE patient_id = :pid");
    $t->execute([':pid' => $pid]);
    foreach ($t->fetchAll() as $row) { $teeth[(int)$row['tooth_number']] = $row['condition_code']; }
} catch (PDOException $ex) {
    error_log('MSCare DB (history): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your treatment history right now.');
}

// dentist's chart codes -> colour group (same codes the clinic uses in the admin chart)
$codeInfo = [
    'D' => ['Decayed (caries)', 'cavity'], 'I' => ['Caries indicated for extraction', 'cavity'],
    'M' => ['Missing due to caries', 'missing'], 'MO' => ['Missing due to other causes', 'missing'],
    'X' => ['Extraction due to caries', 'missing'], 'XO' => ['Extraction due to other causes', 'missing'],
    'Cm' => ['Congenitally missing', 'missing'],
    'F' => ['Filled', 'filling'], 'A' => ['Amalgam filling', 'filling'], 'In' => ['Inlay', 'filling'], 'FX' => ['Fixed cure composite', 'filling'],
    'J' => ['Jacket crown', 'crown'], 'A-B' => ['Abutment', 'crown'], 'P' => ['Pontic', 'crown'], 'Rm' => ['Removable denture', 'crown'],
    'RF' => ['Root fragment', 'root'], 'RC' => ['Root canal treatment', 'root'],
    'Im' => ['Impacted tooth', 'healthy'], '✓' => ['Present tooth', 'healthy'], 'Sp' => ['Supernumerary', 'healthy'],
];
function tooth_btn($n, $teeth, $codeInfo) {
    $code = $teeth[$n] ?? '';
    $info = $code !== '' ? ($codeInfo[$code] ?? [$code, 'healthy']) : ['Healthy', 'healthy'];
    $cls  = $code !== '' ? 'st-' . $info[1] : 'st-healthy';
    return '<div class="tooth ' . $cls . '" title="Tooth #' . $n . ' — ' . e($info[0]) . '"><span class="tn">' . $n . '</span><span class="tc">' . e($code) . '</span></div>';
}

$ptitle = 'Treatment History';
$psub   = '';
$pactive = 'history';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<div class="p-card" style="margin-bottom:20px;">
  <div class="p-card-head"><h2>Treatments</h2><span class="meta"><?= count($treatments) ?> on record</span></div>
  <div class="table-wrap treat-scroll"><table class="p-table stack">
    <thead><tr><th>Date</th><th>Procedure</th><th>Tooth</th><th>Dentist</th><th>Clinical notes</th><th>Cost</th></tr></thead>
    <tbody>
    <?php if (!$treatments): ?>
      <tr><td colspan="6"><div class="p-empty">No treatment records on file yet.</div></td></tr>
    <?php else: foreach ($treatments as $t): ?>
      <tr>
        <td data-label="Date" class="nowrap"><?= date('M j, Y', strtotime($t['treatment_date'])) ?></td>
        <td data-label="Procedure" class="strong"><?= e($t['procedure_name']) ?></td>
        <td data-label="Tooth"><?= e($t['tooth']) ?></td>
        <td data-label="Dentist"><?= e($t['dentist_name']) ?></td>
        <td data-label="Notes" class="muted"><?= e($t['notes'] ?: 'Completed successfully.') ?></td>
        <td data-label="Cost" class="money">₱<?= number_format((float)$t['cost'], 2) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="p-card">
  <div class="p-card-head"><h2>My dental chart &mdash; intraoral examination</h2></div>
  <div class="dc-head">
    <span><b>Name:</b> <?= e($patient['full_name']) ?></span>
    <span><b>Age:</b> <?= e($patient['age']) ?></span>
    <span><b>Gender:</b> <?= e($patient['gender']) ?></span>
    <span><b>As of:</b> <?= date('M j, Y') ?></span>
  </div>
  <div class="dc-arch">
    <div class="d-label">PERMANENT TEETH &middot; UPPER</div>
    <div class="dc-row">
      <div class="half r"><?php for ($n = 18; $n >= 11; $n--) echo tooth_btn($n, $teeth, $codeInfo); ?></div>
      <div class="half l"><?php for ($n = 21; $n <= 28; $n++) echo tooth_btn($n, $teeth, $codeInfo); ?></div>
    </div>
    <div class="dc-hr"></div>
    <div class="d-label">PERMANENT TEETH &middot; LOWER</div>
    <div class="dc-row">
      <div class="half r"><?php for ($n = 48; $n >= 41; $n--) echo tooth_btn($n, $teeth, $codeInfo); ?></div>
      <div class="half l"><?php for ($n = 31; $n <= 38; $n++) echo tooth_btn($n, $teeth, $codeInfo); ?></div>
    </div>
  </div>
  <div class="dc-legend">
    <span><i style="background:#AFC2CC;"></i>Healthy</span>
    <span><i style="background:#E7A93C;"></i>Cavity</span>
    <span><i style="background:#2A6FA0;"></i>Filling</span>
    <span><i style="background:#7E6BC9;"></i>Crown / prosthetic</span>
    <span><i style="background:#E5484D;"></i>Root canal / fragment</span>
    <span><i style="background:#8A4A3A;"></i>Missing / extraction</span>
  </div>
  <?php if ($teeth): ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
