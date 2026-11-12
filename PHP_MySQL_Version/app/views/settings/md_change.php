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
    <?php /* The choice travels in the button's own address (kept since D-350 made app.js carry a pressed button's name/value too). */ ?>
    <?= Csrf::field() ?>
    <div class="md-change-line">
      <label><input type="checkbox" name="apply_orders" value="1"<?= $nOrders > 0 ? ' checked' : ' disabled' ?>>
        <strong><?= $nOrders ?></strong> open order<?= $nOrders === 1 ? '' : 's' ?> whose kept company details still show the old value</label>
      <?php if ($nOrders > 0): ?>
        <?php /* D-350: only the count shows; the list of numbers opens on request. */ ?>
        <details class="md-change-list" id="md-change-orders-list"><summary>Show the orders</summary><span class="muted small"><?= htmlspecialchars(implode(', ', array_map(static fn (array $o): string => $o['ref'], $preview['orders']))) ?></span></details>
      <?php endif; ?>
    </div>
    <div class="md-change-line">
      <label><input type="checkbox" name="apply_documents" value="1"<?= $nDocs > 0 ? ' checked' : ' disabled' ?>>
        <strong><?= $nDocs ?></strong> document<?= $nDocs === 1 ? '' : 's' ?> in Draft / In review whose kept signatory is the company fallback and still shows the old value</label>
      <?php if ($nDocs > 0): ?>
        <details class="md-change-list" id="md-change-documents-list"><summary>Show the documents</summary><span class="muted small"><?= htmlspecialchars(implode(', ', array_map(static fn (array $d): string => $d['ref'], $preview['documents']))) ?></span></details>
      <?php endif; ?>
    </div>

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
