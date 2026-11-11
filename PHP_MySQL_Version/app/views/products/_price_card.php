<?php
/**
 * Owner decision D-335 (12 Nov 2026): one price card per supplier price for every thickness —
 * Thickness · Price (₹) · Unit · How it was set (TYPED / CALCULATED from … / no price) · Since ·
 * FOB cost (₹) incl. CHA · Base for orders (◉ BASE). Below: the product price worked out from
 * the base, "+ Add / update a price" and the history.
 *
 * In: $__pc = ['row' => supplier-price row, 'card' => PricingController::priceCard(), 'grid' => values,
 *     'returnTo' => '' | 'edit'], $rules, $product, $canRecord, $canSeeBuild.
 * Node twin: views/products/_price_card.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ThicknessPrices;

$__pcRow = $__pc['row'];
$__pcCard = $__pc['card'];
$__pcId = (int) $__pcRow['id'];
$__pcMoney = static fn (float $v): string => number_format($v, 2);
?>
<div class="card-nested price-card" id="price-card-<?= $__pcId ?>">
  <h3><?= View::e((string) $__pcRow['supplier_name']) ?> — prices<?= !empty($__pcRow['is_primary']) ? ' <span class="badge badge-active">● used for pricing</span>' : '' ?></h3>
  <table class="list price-card-table">
    <tr><th>Thickness</th><th class="num">Price (₹)</th><th>Unit</th><th>How it was set</th><th>Since</th><th class="num">FOB cost (₹) incl. CHA</th><th>Base for orders</th></tr>
    <?php foreach ($__pcCard['lines'] as $__l): ?>
      <tr<?= $__l['is_base'] ? ' class="price-card-base"' : '' ?>>
        <td><?= $__l['is_base'] ? '<strong>' . View::e($__l['label']) . '</strong>' : View::e($__l['label']) ?></td>
        <td class="num"><?= $__l['price'] !== null ? $__pcMoney((float) $__l['price']) : '—' ?></td>
        <td><?= View::e($__l['unit']) ?></td>
        <td><?php if ($__l['how'] === 'typed'): ?><span class="badge badge-typed">TYPED</span><?php elseif ($__l['how'] === 'calculated'): ?><span class="badge badge-calculated">CALCULATED</span> <small class="muted"><?= View::e($__l['note']) ?></small><?php else: ?><span class="badge badge-inactive">no price</span><?php endif; ?></td>
        <td><?= $__l['since'] !== null ? View::e(\App\Helpers\Dates::short((string) $__l['since'])) : '' ?></td>
        <td class="num"><?php if ($__l['fob'] !== null): ?><?= $__l['is_base'] ? '<strong>' . $__pcMoney((float) $__l['fob']) . '</strong>' : $__pcMoney((float) $__l['fob']) ?><br><small class="muted"><?= $__pcMoney((float) $__l['price']) ?> + CHA <?= $__pcMoney((float) $__l['cha']) ?></small><?php else: ?>—<?php endif; ?></td>
        <td>
          <?php if ($__l['is_base']): ?>◉ <span class="badge badge-active">BASE</span>
          <?php elseif ($__l['price'] !== null && $canRecord): ?>
            <form method="post" action="/products/suppliers/<?= $__pcId ?>/base" style="display:inline">
              <?= Csrf::field() ?><input type="hidden" name="mm" value="<?= (int) $__l['mm'] ?>"><input type="hidden" name="return_to" value="<?= View::e((string) ($__pc['returnTo'] ?? '')) ?>">
              <button type="submit" class="btn-link" title="Make <?= View::e($__l['label']) ?> the base for orders">○ make base</button>
            </form>
          <?php else: ?>○<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php $__pp = $__pcCard['parts']; $__pb = $__pcCard['build']; ?>
  <p class="price-card-summary"><strong>Product price (from the base):</strong>
    <?php if ($__pp !== null): ?>₹<?= $__pcMoney((float) $__pp['total']) ?> per <?= View::e((string) $__pcCard['unit_label']) ?> · <?= View::e($__pcCard['base_label']) ?>
      <?php if ($__pb !== null && $canSeeBuild): ?>→ USD <?= $__pcMoney((float) $__pb['cost']) ?> at ₹<?= View::e(ThicknessPrices::fmt((float) $__pb['fx_rate'])) ?> · minimum (<?= View::e(ThicknessPrices::fmt((float) $__pb['margin_pct'])) ?> % margin) USD <?= $__pcMoney((float) $__pb['minimum']) ?> · quote (+<?= View::e(ThicknessPrices::fmt((float) $__pb['buffer_pct'])) ?> %) USD <?= $__pcMoney((float) $__pb['staff_quote']) ?><?php endif; ?>
    <?php else: ?>the base (<?= View::e($__pcCard['base_label']) ?>) has no price yet — an order line without a thickness goes to the MD.<?php endif; ?></p>
  <?php if ($__pcCard['cha_per_cbm'] !== null): ?><p class="small muted">CHA + transport ₹<?= $__pcMoney((float) $__pcCard['cha_per_container']) ?> per container ÷ <?= View::e(ThicknessPrices::fmt(round((float) $__pcCard['fits_cbm'], 2))) ?> CBM = ₹<?= $__pcMoney((float) $__pcCard['cha_per_cbm']) ?> per CBM (per m² = per CBM × the thickness).</p><?php endif; ?>
  <p class="small muted">The base is what a new order line without a thickness (or <?= (int) $rules['cbm_from'] ?> mm and above) uses. You choose it — the app never swaps it for a calculated price.<?= $__pcCard['auto'] ? ' This price was saved before 12 Nov 2026: a thickness without its own price is still worked out each time, until you add a price below.' : '' ?></p>
  <div class="price-card-actions">
    <?php if ($canRecord): ?>
    <details class="quick-add price-add">
      <summary class="btn-sm">+ Add / update a price</summary>
      <form method="post" action="/products/suppliers/<?= $__pcId ?>/thickness-price" class="card-nested">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= View::e((string) ($__pc['returnTo'] ?? '')) ?>">
        <h3>Add / update a price — <?= View::e((string) $__pcRow['supplier_name']) ?></h3>
        <?php $__af = ['grid' => $__pc['grid'], 'card' => $__pcCard, 'dateField' => 'price_quoted_on']; include __DIR__ . '/_price_add.php'; ?>
        <div><button type="submit" class="btn-sm btn-accent">Save price</button></div>
      </form>
    </details>
    <?php endif; ?>
    <?php if (($__pc['returnTo'] ?? '') !== 'edit'): ?><a class="btn-sm" href="/products/suppliers/<?= $__pcId ?>/price#history">History</a><?php endif; ?>
  </div>
</div>
