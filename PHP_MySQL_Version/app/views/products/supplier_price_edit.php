<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ThicknessPrices;

/**
 * Owner decision D-300 (point 12, 11 Nov 2026): one supplier price — every thickness
 * (TYPED or CALCULATED, "↺ use calculated", recalculated on screen), packing and
 * loading, CHA + transport per container, what goes into the FOB cost, and the price
 * history (who, when, old → new). D-303: the supplier from the Suppliers directory.
 * Node twin: views/products/supplier_price_edit.njk.
 */
$__today = date('Y-m-d');
$__unit = (string) ($product['reference_unit'] ?? '') ?: 'unit';
$__ro = $canRecord ? '' : ' disabled';
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>/market-prices">&larr; Market prices — <?= View::e($product['name']) ?></a></p>
  <h1>Supplier price — <?= View::e($product['name']) ?></h1>
  <form method="post" action="/products/suppliers/<?= (int) $row['id'] ?>/price" class="field-grid">
    <?= Csrf::field() ?>
    <?php $__pickName = (string) $row['supplier_name']; $__pickId = (int) ($row['supplier_id'] ?? 0); $__pickLocation = (string) ($row['location'] ?? ''); include __DIR__ . '/_supplier_pick.php'; ?>
    <label>Quoted on *<input type="date" name="quoted_on" required max="<?= $__today ?>" value="<?= View::e((string) ($row['quoted_on'] ?? substr((string) $row['updated_at'], 0, 10))) ?>"<?= $__ro ?>></label>
    <label>Valid until<input type="date" name="valid_until" value="<?= View::e((string) ($row['valid_until'] ?? '')) ?>"<?= $__ro ?>></label>
    <?php if ($grid !== null): ?>
      <fieldset class="full"><legend class="legend-sentence">Prices by thickness (₹) — enter any one; the others are worked out. Change any of them if the supplier quoted it.</legend>
        <?php $__grid = $grid; include __DIR__ . '/_thickness_grid.php'; ?>
      </fieldset>
    <?php else: ?>
      <label>Price (₹) per <?= View::e(($row['price_basis'] ?? '') === 'cbm' || ($row['combo_per'] ?? '') === 'cbm' ? 'CBM' : $__unit) ?> *<input type="text" inputmode="decimal" name="price_inr" required data-max-decimals="2" data-max-value="999999999999.99" value="<?= View::e((string) $row['fob_value']) ?>" placeholder="e.g. 80000.00"<?= $__ro ?>></label>
    <?php endif; ?>
    <?php $__row = $row; include __DIR__ . '/_cha_transport.php'; ?>
    <label class="full">Notes<textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Ex-works, Grade A, min 2 blocks"<?= $__ro ?>><?= View::e((string) ($row['notes'] ?? '')) ?></textarea></label>
    <?php if ($canRecord): ?><div><button type="submit" class="btn-sm btn-accent">Save price</button></div><?php endif; ?>
  </form>

  <?php if ($fob !== null): ?>
    <h2>What goes into the cost per <?= View::e(\App\Services\LineUnits::label((string) $fob['unit']) ?: $__unit) ?> <small class="muted">(<?= $fob['mm'] !== null ? View::e(ThicknessPrices::fmt((float) $fob['mm'])) . ' mm' : 'base' ?>)</small></h2>
    <table class="list" style="max-width:640px">
      <tr><td>Supplier price<?= empty($row['includes_packing_loading']) ? '' : ' (includes packing and loading)' ?></td><td class="num"><?= number_format((float) $fob['supplier'], 2) ?></td></tr>
      <tr><td>CHA + transport per unit</td><td class="num"><?= number_format((float) $fob['cha'], 2) ?></td></tr>
      <?php if ((float) $fob['packing'] > 0): ?><tr><td>Packing and loading (product's costs)</td><td class="num"><?= number_format((float) $fob['packing'], 2) ?></td></tr><?php endif; ?>
      <tr><td><strong>FOB cost (₹)</strong></td><td class="num"><strong><?= number_format((float) $fob['total'], 2) ?></strong></td></tr>
    </table>
    <p class="small muted">The selling price is then worked out from the FOB cost as usual.<?= $fob['note'] !== null ? ' ' . View::e((string) $fob['note']) : '' ?></p>
  <?php endif; ?>

  <h2>Price history</h2>
  <table class="list">
    <tr><th>When</th><th>Who</th><th>What</th><th>Old</th><th>New</th></tr>
    <?php foreach ($history as $h): ?>
      <tr><td><?= View::e(\App\Helpers\Dates::short(substr((string) $h['changed_at'], 0, 10))) ?> <?= View::e(substr((string) $h['changed_at'], 11, 5)) ?></td><td><?= View::e((string) ($h['changed_by_name'] ?? '—')) ?></td>
        <td><?= View::e((string) $h['what']) ?></td><td><?= View::e((string) ($h['old_value'] ?? '—')) ?></td><td><?= View::e((string) ($h['new_value'] ?? '—')) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($history === []): ?><tr><td colspan="5" class="muted">No changes since this price was recorded.</td></tr><?php endif; ?>
  </table>
</div>
