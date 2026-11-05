<?php
use App\Helpers\Csrf;
use App\Helpers\View;

/**
 * Owner decision D-245 (4 Nov 2026), P1: Procurement's market prices for one
 * product — supplier prices only (₹), dated, with history. No selling prices,
 * margins or floors are on this page.
 */
$__today = date('Y-m-d');
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>">&larr; Back to <?= View::e($product['name']) ?></a></p>
  <h1>Market Prices — <?= View::e($product['name']) ?></h1>
  <p class="muted">Supplier and market prices for this product, newest first, with the ones <strong>used for pricing</strong> on top. A price is per the product's unit, or <strong>per CBM for thick stone (<?= (int) ($cbmFrom ?? 50) ?> mm and above)</strong> — each kind has its own price used for pricing (D-255). Record a new price as a new row so the history stays; a price older than <?= (int) $staleDays ?> days, or past its "valid until" date, is marked. Changing the price used for pricing changes new quotes only — orders with an issued Proforma Invoice keep their prices.</p>

  <table class="list">
    <tr><th>Used for pricing</th><th>Supplier</th><th>Per</th><th class="num">Price (₹)</th><th>Quoted on</th><th>Valid until</th><th>Notes</th><?php if ($canRecord): ?><th></th><?php endif; ?></tr>
    <?php if ($rows === []): ?><tr><td colspan="8" class="muted">No supplier prices recorded yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php $__cbm = ($r['price_basis'] ?? 'unit') === 'cbm'; $__used = $__cbm ? !empty($r['is_cbm_primary']) : !empty($r['is_primary']); ?>
        <td><?= $__used ? '<span class="badge badge-active">● Yes</span>' : '<span class="muted">○</span>' ?></td>
        <td><?= View::e($r['supplier_name']) ?><?= !empty($r['location']) ? '<br><small class="muted">' . View::e($r['location']) . '</small>' : '' ?></td>
        <td><?= $__cbm ? '<span class="badge">CBM — thick stone</span>' : View::e((string) ($product['reference_unit'] ?? 'unit')) ?></td>
        <td class="num"><?= $r['effective_fob'] === null ? 'To Be Confirmed' : number_format((float) $r['effective_fob'], 2) ?><?= $r['fob_source'] === 'computed' ? '<br><small class="muted">worked out from cost components</small>' : '' ?></td>
        <td><?= View::e($r['price_date'] ?? '—') ?><?php if ($r['staleness'] === 'stale'): ?> <span class="badge badge-warning">stale</span><?php endif; ?></td>
        <td><?php if (!empty($r['valid_until'])): ?><?= View::e((string) $r['valid_until']) ?><?php if ($r['staleness'] === 'expired'): ?> <span class="badge badge-danger">expired</span><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= View::e($r['notes'] ?? '') ?></td>
        <?php if ($canRecord): ?>
          <td>
            <?php if (!$__used): ?>
              <form method="post" action="/products/suppliers/<?= (int) $r['id'] ?>/use-for-pricing" style="display:inline">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm">Use for pricing</button>
              </form>
            <?php endif; ?>
            <?php if ($r['fob_source'] === 'direct'): ?>
            <details style="display:inline-block">
              <summary class="btn-sm" style="display:inline-block;cursor:pointer">Correct</summary>
              <div class="card-nested">
                <form method="post" action="/products/suppliers/<?= (int) $r['id'] ?>/market-price">
                  <?= Csrf::field() ?>
                  <label>Price (₹) *<input type="number" name="price_inr" min="0.01" step="0.01" required value="<?= View::e((string) $r['fob_value']) ?>" placeholder="e.g. 80000.00"></label>
                  <label>Location<input type="text" name="location" maxlength="150" value="<?= View::e($r['location'] ?? '') ?>" placeholder="e.g. Ongole, Andhra Pradesh"></label>
                  <label>Quoted on *<input type="date" name="quoted_on" required max="<?= $__today ?>" value="<?= View::e((string) ($r['quoted_on'] ?? $r['price_date'] ?? '')) ?>"></label>
                  <label>Valid until<input type="date" name="valid_until" value="<?= View::e((string) ($r['valid_until'] ?? '')) ?>"></label>
                  <label>Notes<textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Ex-works, Grade A, min 2 blocks"><?= View::e($r['notes'] ?? '') ?></textarea></label>
                  <button type="submit" class="btn-sm">Save</button>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($canRecord): ?>
    <h2>Add a price</h2>
    <form method="post" action="/products/<?= (int) $product['id'] ?>/market-prices" class="field-grid">
      <?= Csrf::field() ?>
      <label>Supplier *<input type="text" name="supplier_name" maxlength="255" required placeholder="e.g. Sri Balaji Granites"></label>
      <label>Location<input type="text" name="location" maxlength="150" placeholder="e.g. Ongole, Andhra Pradesh"></label>
      <label>Price (₹) *<input type="number" name="price_inr" min="0.01" step="0.01" required placeholder="e.g. 80000.00"></label>
      <label>Price per *
        <select name="price_basis" required>
          <option value="unit">The product's unit (<?= View::e((string) ($product['reference_unit'] ?? 'unit')) ?>) — below <?= (int) ($cbmFrom ?? 50) ?> mm</option>
          <option value="cbm">CBM — thick stone, <?= (int) ($cbmFrom ?? 50) ?> mm and above</option>
        </select></label>
      <label>Quoted on *<input type="date" name="quoted_on" required max="<?= $__today ?>" value="<?= $__today ?>"></label>
      <label>Valid until<input type="date" name="valid_until"></label>
      <label class="full">Notes<textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Ex-works, Grade A, min 2 blocks"></textarea></label>
      <label class="inline"><input type="checkbox" name="used_for_pricing" value="1"> Use this price for pricing</label>
      <div><button type="submit" class="btn-sm btn-accent">Add price</button></div>
    </form>

    <?php if ($quotes !== []): ?>
      <h2>Copy from a supplier quote</h2>
      <p class="muted small">Quotes recorded in Procurement for orders of this product.</p>
      <table class="list">
        <tr><th>Supplier</th><th class="num">Price (₹)</th><th>Recorded</th><th>Valid until</th><th>Reference</th><th></th></tr>
        <?php foreach ($quotes as $q): ?>
          <tr>
            <td><?= View::e($q['supplier_legal_name']) ?></td>
            <td class="num"><?= number_format((float) $q['unit_price_inr'], 2) ?><?= !empty($q['unit']) ? ' <small class="muted">per ' . View::e($q['unit']) . '</small>' : '' ?></td>
            <td><?= View::e(substr((string) $q['recorded_at'], 0, 10)) ?></td>
            <td><?= View::e((string) ($q['valid_until'] ?? '—')) ?></td>
            <td><?= View::e((string) ($q['quote_reference'] ?? '')) ?></td>
            <td>
              <form method="post" action="/products/<?= (int) $product['id'] ?>/market-prices" style="display:inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="purchase_quote_id" value="<?= (int) $q['id'] ?>">
                <button type="submit" class="btn-sm">Copy as a market price</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
