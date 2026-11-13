<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ThicknessPrices;

/**
 * Owner decision D-245 (4 Nov 2026), P1: Procurement's market prices for one
 * product — supplier prices only (₹), dated, with history. No selling prices,
 * margins or floors are on this page.
 *
 * D-300 (point 12, 11 Nov 2026): one supplier price holds every thickness (grid),
 * packing and loading, and CHA + transport per container. D-303 (point 15): the
 * supplier is picked from the Suppliers directory. D-335 (12 Nov 2026): one price card per
 * supplier price for every thickness (typed / calculated / no price, the base) and
 * "Add / update a price". Node twin: market_prices.njk.
 */
$__today = date('Y-m-d');
$__unit = (string) ($product['reference_unit'] ?? '') ?: 'unit';
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>">&larr; Back to <?= View::e($product['name']) ?></a></p>
  <h1>Market Prices — <?= View::e($product['name']) ?></h1>
  <p class="muted">Supplier prices for this product (₹), newest first, with the ones <strong>used for pricing</strong> on top.<?php if ($rules['grid']): ?> One price card per supplier holds <strong>every thickness</strong>. A price you <strong>typed</strong> always wins — no rule changes it; the <strong>base</strong> you chose (◉) is what the product's price and order lines without a thickness use; the price rules only <strong>work out</strong> the thicknesses you tick while adding a price.<?php endif; ?> An <strong>exact price for a special combination</strong> (e.g. "30 mm · Leather") is used as it is for the options it names. Record a new price as a new row so the history stays; a price older than <?= (int) $staleDays ?> days, or past its "valid until" date, is marked. Changing the price used for pricing changes new quotes only — orders with an issued Proforma Invoice keep their prices.</p>
  <?php if ($rules['grid']): ?><p class="small muted">Rules for <?= View::e((string) $rules['category_name']) ?><?= $rules['own'] !== [] ? ' <span class="badge badge-warning">own rule</span>' : '' ?>: <?= View::e(\App\Services\PriceRules::summary($rules)) ?><?php if ($canChangeRules && $rules['category_id'] !== null): ?> <a class="btn-sm" href="/products/categories/<?= (int) $rules['category_id'] ?>?tab=rules">Change these rules</a><?php endif; ?></p><?php endif; ?>

  <table class="list">
    <tr><th>Used for pricing</th><th>Supplier</th><th>Per</th><th class="num">Price (₹)</th><th class="num">FOB cost (₹)</th><th>Quoted on</th><th>Valid until</th><th>Notes</th><th></th></tr>
    <?php if ($rows === []): ?><tr><td colspan="9" class="muted">No supplier prices recorded yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php $__basis = (string) ($r['price_basis'] ?? 'unit'); $__cbm = $__basis === 'cbm'; $__combo = $__basis === 'combo'; $__gridRow = $__basis === 'grid';
          $__used = $__combo ? !empty($r['combo_in_use']) : ($__cbm ? !empty($r['is_cbm_primary']) : !empty($r['is_primary'])); ?>
        <td><?= $__used ? '<span class="badge badge-active">● ' . ($__combo ? 'Exact' : ($__gridRow ? 'All thicknesses' : 'Base')) . '</span>' : '<span class="muted">○</span>' ?></td>
        <td><?= View::e($r['supplier_name']) ?><?= empty($r['supplier_id']) ? ' <span class="badge badge-warning" title="Typed before the Suppliers directory link — link it on Products › Link these prices">not linked</span>' : '' ?><?= !empty($r['location']) ? '<br><small class="muted">' . View::e($r['location']) . '</small>' : '' ?></td>
        <td><?php if ($__combo): ?><span class="badge badge-purple">Exact: <?= View::e((string) $r['combo_text']) ?></span> <small class="muted">per <?= ($r['combo_per'] ?? 'unit') === 'cbm' ? 'CBM' : View::e($__unit) ?></small>
          <?php elseif ($__gridRow): ?>every thickness<?php else: ?><?= $__cbm ? '<span class="badge">CBM — thick stone</span>' : View::e($__unit) ?><?php endif; ?></td>
        <td class="num">
          <?php if ($__gridRow && $r['card'] !== null): ?>
            <span class="nowrap">Base: <?= View::e($r['card']['base_label']) ?> <?= $r['card']['base_price'] !== null ? number_format((float) $r['card']['base_price'], 2) . ' <small class="muted">/' . View::e($r['card']['base_unit']) . '</small>' : '<small class="muted">no price</small>' ?></span>
            <br><a class="small" href="#price-card-<?= (int) $r['id'] ?>">every thickness ↓</a>
          <?php else: ?>
            <?= $r['effective_fob'] === null ? 'To Be Confirmed' : number_format((float) $r['effective_fob'], 2) ?>
          <?php endif; ?>
          <?= empty($r['includes_packing_loading']) ? '<br><small class="muted">packing and loading not included</small>' : '' ?>
        </td>
        <td class="num"><?php if ($r['fob'] !== null): ?><?= number_format((float) $r['fob']['total'], 2) ?><br><small class="muted">per <?= View::e(\App\Services\LineUnits::label((string) $r['fob']['unit']) ?: $__unit) ?><?= $r['fob']['cha'] > 0 ? ' · incl. CHA + transport ' . number_format((float) $r['fob']['cha'], 2) : '' ?></small><?php else: ?>—<?php endif; ?></td>
        <td><?= View::e($r['price_date'] ?? '—') ?><?php if ($r['staleness'] === 'stale'): ?> <span class="badge badge-warning">stale</span><?php endif; ?></td>
        <td><?php if (!empty($r['valid_until'])): ?><?= View::e((string) $r['valid_until']) ?><?php if ($r['staleness'] === 'expired'): ?> <span class="badge badge-danger">expired</span><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= View::e($r['notes'] ?? '') ?></td>
        <td>
          <?php if ($canRecord && (!$__used || $__combo)): ?>
            <form method="post" action="/products/suppliers/<?= (int) $r['id'] ?>/use-for-pricing" style="display:inline">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm"><?= $__combo && $__used ? 'Stop using' : 'Use for pricing' ?></button>
            </form>
          <?php endif; ?>
          <?php if ($r['fob_source'] === 'direct'): ?><a class="btn-sm" href="/products/suppliers/<?= (int) $r['id'] ?>/price"><?= $canRecord ? 'Edit' : 'View' ?></a><?php endif; ?>
          <?php // D-359: the simple "Edit price" form (one price, the other thicknesses worked out, Undo). ?>
          <?php if ($canRecord && $r['fob_source'] === 'direct'): ?><a class="btn-sm btn-accent" href="/products/suppliers/<?= (int) $r['id'] ?>/edit-price">Edit price</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php // D-335: one price card per supplier price for every thickness. ?>
  <?php foreach ($rows as $r): if ($r['card'] === null) { continue; } $__pc = ['row' => $r, 'card' => $r['card'], 'grid' => $r['grid'], 'returnTo' => '']; ?>
    <?php include __DIR__ . '/_price_card.php'; ?>
  <?php endforeach; ?>

  <?php if ($canRecord): ?>
    <h2 id="add-price">Add a price<?= $rules['grid'] ? ' from another supplier' : '' ?></h2>
    <?php $__pf = $prefill ?? null; ?>
    <?php if (($__pf['purchase_quote_id'] ?? null) !== null): ?><p class="small muted">Filled in from the supplier quote — check the price and add it.</p><?php endif; ?>
    <form method="post" action="/products/<?= (int) $product['id'] ?>/market-prices" class="field-grid">
      <?= Csrf::field() ?>
      <?php if (($__pf['purchase_quote_id'] ?? null) !== null): ?><input type="hidden" name="purchase_quote_id" value="<?= (int) $__pf['purchase_quote_id'] ?>"><?php endif; ?>
      <?php $__pickName = $__pf['supplier_name'] ?? ''; $__pickId = $__pf['supplier_id'] ?? 0; $__pickLocation = ''; include __DIR__ . '/_supplier_pick.php'; ?>
      <label>Quoted on *<input type="date" name="quoted_on" required max="<?= $__today ?>" value="<?= View::e($__pf['quoted_on'] ?? $__today) ?>"></label>
      <label>Valid until<input type="date" name="valid_until" value="<?= View::e($__pf['valid_until'] ?? '') ?>"></label>
      <label>Price for *
        <select name="price_basis" required>
          <option value="">Choose…</option>
          <?php if ($rules['grid']): ?>
            <option value="grid">Every thickness — the prices below</option>
          <?php else: ?>
            <option value="unit">The product's unit (<?= View::e($__unit) ?>)</option>
            <option value="cbm"<?= ($prefillBasis ?? null) === 'cbm' ? ' selected' : '' ?>>Thick stone, <?= (int) ($cbmFrom ?? 50) ?> mm and above (per CBM)</option>
          <?php endif; ?>
          <?php if (($offered ?? []) !== []): ?><option value="combo">A special combination (exact price)</option><?php endif; ?>
        </select>
        <small class="muted">Kept only for special combinations, e.g. "30 mm · Leather" quoted as one exact price<?= $rules['grid'] ? '; ordinary thickness prices always go in the grid' : '' ?>.</small></label>
      <?php if ($rules['grid']): ?>
        <fieldset class="full" data-show-when="price_basis=grid"><legend class="legend-sentence">The price the supplier quoted — type one thickness and tick the others to work out from it. It becomes the base for orders.</legend>
          <?php $__af = ['grid' => null, 'card' => null, 'dateField' => '', 'pickMm' => ($prefillBasis ?? null) === 'cbm' ? ThicknessPrices::CBM : null]; include __DIR__ . '/_price_add.php'; ?>
        </fieldset>
        <label data-show-when="price_basis=combo">Exact price (₹) *<input type="text" inputmode="decimal" name="price_inr" data-max-decimals="2" data-max-value="999999999999.99" data-required-when="price_basis=combo" placeholder="e.g. 4900.00" value="<?= View::e($__pf['price_inr'] ?? '') ?>" disabled></label>
      <?php else: ?>
        <label>Price (₹) *<input type="text" inputmode="decimal" name="price_inr" required data-max-decimals="2" data-max-value="999999999999.99" placeholder="e.g. 80000.00" value="<?= View::e($__pf['price_inr'] ?? '') ?>"></label>
      <?php endif; ?>
      <?php if (($offered ?? []) !== []): ?>
      <fieldset class="full" data-show-when="price_basis=combo"><legend class="legend-sentence">The special combination <small class="muted">(leave "any" for what the price does not fix)</small></legend>
        <div class="field-grid">
        <?php foreach ($offered as $__sp): ?>
          <label><?= View::e($__sp['name']) ?>
            <select name="combo[<?= (int) $__sp['id'] ?>]">
              <option value="">any</option>
              <?php foreach ($__sp['options'] as $__o): ?><option value="<?= (int) $__o['id'] ?>"><?= View::e($__o['name']) ?></option><?php endforeach; ?>
            </select></label>
        <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endif; ?>
      <?php $__row = null; include __DIR__ . '/_cha_transport.php'; ?>
      <label class="full">Notes<textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Ex-works, Grade A, min 2 blocks"></textarea></label>
      <label class="inline"><input type="checkbox" name="used_for_pricing" value="1"> Use this price for pricing</label>
      <div><button type="submit" class="btn-sm btn-accent">Save price</button></div>
    </form>
    <?php if ($canQuickAdd): $__quickProductId = (int) $product['id']; include __DIR__ . '/_supplier_quick_add.php'; endif; ?>

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
              <?php if (!$rules['grid']): ?>
              <form method="post" action="/products/<?= (int) $product['id'] ?>/market-prices" style="display:inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="purchase_quote_id" value="<?= (int) $q['id'] ?>">
                <button type="submit" class="btn-sm">Copy as a market price</button>
              </form>
              <?php endif; ?>
              <a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/market-prices?from_quote=<?= (int) $q['id'] ?>#add-price"><?= $rules['grid'] ? 'Copy into the form' : 'Copy for a special combination' ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
