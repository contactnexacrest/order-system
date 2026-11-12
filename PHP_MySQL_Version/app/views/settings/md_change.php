<?php
use App\Helpers\Csrf;

/**
 * D-349 (owner, 12 Nov 2026, confirm 1 + confirm 2): Settings › Company — the
 * Managing Director's name / title changed. Shows what still carries the old
 * value and asks "Save and apply" or "Save setting only". Nothing is saved
 * until one of the two buttons is pressed.
 *
 * @var array<string,array{old:string,new:string}> $changes
 * @var array{orders:list<array>,documents:list<array>,not_changed_documents:int,not_changed_orders:int,founder_note:?string} $preview
 */
$labels = ['md_name' => "Managing Director's name", 'md_title' => "Managing Director's title"];
$nOrders = count($preview['orders']);
$nDocs = count($preview['documents']);
?>
<div class="card" id="md-change-panel">
  <h1>Managing Director's name or title changed</h1>
  <p class="muted"><a href="/settings">&larr; Company Settings</a> (going back saves nothing)</p>

  <?php foreach ($changes as $key => $c): ?>
    <p>The <?= htmlspecialchars($labels[$key]) ?> changed from "<strong><?= htmlspecialchars($c['old']) ?></strong>" to "<strong><?= htmlspecialchars($c['new']) ?></strong>".</p>
  <?php endforeach; ?>
  <p><strong>Apply it to:</strong></p>

  <form method="post" action="/settings/md-change">
    <?php /* The choice travels in the button's own address: app.js disables the pressed button while the form is sent, so a name/value on it would not arrive. */ ?>
    <?= Csrf::field() ?>
    <p>
      <label><input type="checkbox" name="apply_orders" value="1"<?= $nOrders > 0 ? ' checked' : ' disabled' ?>>
        <strong><?= $nOrders ?></strong> open order(s) whose kept company details still show the old value</label>
      <?php if ($nOrders > 0): ?>
        <br><span class="muted small"><?= htmlspecialchars(implode(', ', array_map(static fn (array $o): string => $o['ref'], $preview['orders']))) ?></span>
      <?php endif; ?>
    </p>
    <p>
      <label><input type="checkbox" name="apply_documents" value="1"<?= $nDocs > 0 ? ' checked' : ' disabled' ?>>
        <strong><?= $nDocs ?></strong> document(s) still in Draft / In review whose kept signatory is the company fallback and still shows the old value</label>
      <?php if ($nDocs > 0): ?>
        <br><span class="muted small"><?= htmlspecialchars(implode(', ', array_map(static fn (array $d): string => $d['ref'], $preview['documents']))) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($preview['founder_note'] !== null): ?>
      <div class="alert alert-info" id="md-change-founder-note">&#8505; <?= htmlspecialchars($preview['founder_note']) ?></div>
    <?php endif; ?>

    <p class="muted small" id="md-change-not-changed">Not changed: <?= (int) $preview['not_changed_documents'] ?> document(s) already approved or sent; <?= (int) $preview['not_changed_orders'] ?> closed and cancelled order(s).</p>

    <p style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="submit" formaction="/settings/md-change?choice=apply">Save and apply</button>
      <button type="submit" formaction="/settings/md-change?choice=setting_only" class="btn-secondary">Save setting only</button>
    </p>
    <p class="muted small">Either button saves the setting with the reason you gave. Every change is kept in the Audit Log (old &rarr; new, which orders, which documents).</p>
  </form>
</div>
