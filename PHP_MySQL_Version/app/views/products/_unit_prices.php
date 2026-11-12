<?php
/**
 * Owner decision D-339 (12 Nov 2026, owner confirmed twice; mockup screen 2): the read-only
 * card "Prices in other units" — per thickness, per CBM / per SQM / per SQFT, worked out
 * from the product's prices (UnitPrices). Nothing is saved. Each price set is shown only to
 * people with the permission for it (see ProductController::show()).
 * Node twin: views/products/_unit_prices.njk.
 */
use App\Helpers\View;
if (!empty($unitPrices) && $unitPrices['sets'] !== []):
    $__f = static fn (?float $v): string => $v !== null ? number_format($v, 2) : '—';
?>
<div class="section unit-prices-card">
  <h2>Prices in other units — <?= View::e((string) $product['name']) ?></h2>
  <p class="muted small">Worked out from this product's prices for each thickness (1 SQM = thickness × 1 m²; 1 SQM = 10.7639 SQFT). Read-only, nothing is saved. Per piece needs a size, so it is shown on the order line, not here. Ton and RMT follow the same rules as the price check (D-270).</p>
  <?php foreach ($unitPrices['sets'] as $__i => $__set): ?>
    <details<?= $__i === 0 ? ' open' : '' ?>>
      <summary><b><?= View::e($__set['label']) ?></b> <span class="muted small">(<?= View::e($__set['currency']) ?>)</span></summary>
      <div class="table-scroll">
      <table class="list">
        <thead><tr><th>Thickness</th><th class="num">per CBM</th><th class="num">per SQM</th><th class="num">per SQFT</th><th class="num">Source</th></tr></thead>
        <tbody>
        <?php foreach ($unitPrices['rows'] as $__r): ?>
          <tr>
            <td><?= View::e((string) $__r['label']) ?></td>
            <?php if ($__r['missing'] !== null): ?>
              <td colspan="4" class="muted small"><?= View::e((string) $__r['missing']) ?></td>
            <?php else: $__p = $__r['prices'][$__set['key']] ?? ['CBM' => null, 'SQM' => null, 'SQFT' => null]; ?>
              <td class="num"><?= $__f($__p['CBM']) ?></td>
              <td class="num"><?= $__f($__p['SQM']) ?></td>
              <td class="num"><?= $__f($__p['SQFT']) ?></td>
              <td class="num"><?= $__r['typed'] ? '<span class="tag typed">typed price wins (D-335)</span>' : '<span class="tag">worked out</span>' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>
