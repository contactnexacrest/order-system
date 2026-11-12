<?php
/**
 * Owner decision D-273 (point 17, 11 Nov 2026): the order page's products as one
 * compact table — the options as labels under each description (D-271, point 13),
 * size, qty, unit, price, total, a price-check badge per line and Edit /
 * Duplicate / Delete buttons — with a summary bar above it, a totals row with
 * CBM and containers (8 CBM each), "+ Add product" always visible, and charges
 * and services in the same table style. Edit / "+ Add product" open a side panel
 * in four steps: Product | Options | Size and quantity | Price (with the
 * reference box), and "More" (description, Dimensions text, finish, line type,
 * HS / SAC code, category) closed until needed.
 *
 * Included by orders/show.php inside the Products card (all of show.php's
 * variables are in scope). Node twin: views/orders/_products_table.njk.
 */
use App\Services\LineUnits;
use App\Services\OrderLinePricing;

$__copiedId = (int) ($_GET['copied'] ?? 0);
$__goods = array_values(array_filter($products, static fn (array $p): bool => ($p['line_type'] ?? 'goods') === 'goods'));
$__other = array_values(array_filter($products, static fn (array $p): bool => ($p['line_type'] ?? 'goods') !== 'goods'));
$__summary = $sees['selling'] ? OrderLinePricing::summary($linePricing['lines']) : null;
$__totals = OrderLinePricing::totals($products, $linePricing['lines']);
$__productReasonRequired = $isLockedOrder || $isPostConfirmationOrder;
$__money = static fn ($v): string => $v !== null ? number_format((float) $v, 2) : 'To Be Confirmed';
// Owner decision D-339: an order with the detailed product table (new orders) asks for the No. of pieces.
$__detailed = ($order['line_table_layout'] ?? 'classic') === 'detailed';

// One table row (goods, or a charge / service line).
$__row = static function (array $p) use ($products, $linePricing, $sees, $seesPriceBuildup, $canEditProducts, $order, $__copiedId, $__money, $__productReasonRequired): void {
    $__lc = $linePricing['lines'][(int) $p['id']] ?? null;
    $__copyOf = \App\Repositories\OrderProductRepository::copiedFromLineNo($p, $products);
    $__chips = OrderLinePricing::optionChips($p);
    ?>
    <tr id="line-<?= (int) $p['id'] ?>" class="<?= (int) $p['id'] === $__copiedId ? 'line-copied' : '' ?>">
      <td><?= (int) $p['line_no'] ?></td>
      <td class="line-desc">
        <strong><?= htmlspecialchars((string) $p['description']) ?></strong><?php if ($__copyOf !== null): ?> <span class="muted">— copy of line <?= $__copyOf ?></span><?php endif; ?>
        <?php if (($p['line_type'] ?? 'goods') !== 'goods'): ?> <span class="muted small">(<?= htmlspecialchars(\App\Repositories\OrderProductRepository::LINE_TYPES[$p['line_type']] ?? '') ?>)</span><?php endif; ?>
        <?php if ($__chips !== []): ?>
          <div class="option-chips"><?php foreach ($__chips as $__chip): ?><span class="chip<?= $__chip['gone'] ? ' chip-gone' : '' ?>"><?= htmlspecialchars($__chip['name']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if (!empty($p['finish']) && $__chips === []): ?><div class="muted small"><?= htmlspecialchars((string) $p['finish']) ?></div><?php endif; ?>
      </td>
      <td><?= !empty($p['as_per_annexure']) ? '<span class="muted">As per annexure</span>' : htmlspecialchars((string) ($p['dimensions'] ?? '')) ?><?= !empty($p['pieces_count']) ? '<div class="muted small">' . number_format((int) $p['pieces_count']) . ' pcs</div>' : '' ?><?= !empty($p['size_note']) ? '<div class="muted small">' . htmlspecialchars((string) $p['size_note']) . '</div>' : '' ?></td>
      <td class="num"><?= $p['quantity_is_tbc'] ? 'To Be Confirmed' : htmlspecialchars((string) ($p['quantity'] ?? '')) ?></td>
      <td><?= htmlspecialchars((string) ($p['unit'] ?? '')) ?></td>
      <?php if ($sees['selling']): ?>
        <td class="num"><?= $__money($p['unit_price']) ?></td>
        <td class="num"><?= $__money($p['fob_value']) ?></td>
        <td class="line-check"><?php include __DIR__ . '/_line_price.php'; ?></td>
      <?php endif; ?>
      <?php if ($canEditProducts): ?>
        <td class="line-actions">
          <button type="button" class="btn-sm btn-secondary" data-open-panel="line-panel-<?= (int) $p['id'] ?>">Edit</button>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/products/<?= (int) $p['id'] ?>/duplicate" class="inline-form">
            <?= \App\Helpers\Csrf::field() ?>
            <?php if ($__productReasonRequired): ?><input type="text" name="reason" placeholder="Reason (required)" required minlength="10" aria-label="Reason"><?php endif; ?>
            <button type="submit" class="btn-sm btn-secondary" title="An exact copy right below — every option, the size, the product; the MD's approval is not copied">Duplicate</button>
          </form>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/products/<?= (int) $p['id'] ?>/delete" class="inline-form" data-confirm="Remove line <?= (int) $p['line_no'] ?>?">
            <?= \App\Helpers\Csrf::field() ?>
            <?php if ($__productReasonRequired): ?><input type="text" name="reason" placeholder="Reason (required)" required minlength="10" aria-label="Reason"><?php endif; ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
        </td>
      <?php endif; ?>
    </tr>
    <?php
};
$__head = static function (string $first) use ($sees, $canEditProducts, $order): void {
    ?>
    <thead><tr><th>#</th><th><?= $first ?></th><th>Size</th><th class="num">Qty</th><th>Unit</th><?php if ($sees['selling']): ?><th class="num">Price <?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?></th><th class="num">Total</th><th>Price check</th><?php endif; ?><?php if ($canEditProducts): ?><th></th><?php endif; ?></tr></thead>
    <?php
};
?>
<div class="products-head">
  <h3>Products (<?= count($__goods) ?> line<?= count($__goods) === 1 ? '' : 's' ?>)</h3>
  <?php if ($canEditProducts): ?><button type="button" class="btn-primary" data-open-panel="line-panel-new">+ Add product</button><?php endif; ?>
</div>
<?php // D-305 (point 19): on an order from an associate's client, staff see this order's figures — never the associate's name, level or %. ?>
<?php if (!empty($sees['selling']) && !empty($linePricing['ctx']['associate'])): ?>
  <p class="muted small for-this-order">ⓘ This client came through an associate, so this order's prices are higher.<?= !empty($seesPriceBuildup) ? ' Sold by ' . htmlspecialchars((string) $linePricing['ctx']['label']) . '.' : '' ?></p>
<?php endif; ?>
<?php if ($__summary !== null): ?>
  <p class="line-summary <?= $__summary['blocking'] ? 'notice notice-warning' : 'notice notice-success' ?>"><?= htmlspecialchars($__summary['text']) ?><?= $__summary['blocking'] ? ' <a href="#price-check">Show</a>' : '' ?></p>
<?php endif; ?>
<?php if (!empty($pricesFixed) && $canManageOrders): // Owner decision D-342 ?>
  <p class="notice notice-info price-lock-note" id="price-lock-note">🔒 <?= htmlspecialchars(\App\Services\PriceLock::message($order)) ?> <a href="/orders/<?= (int) $order['id'] ?>/amendments#products-amendment">Amendments ›</a></p>
<?php elseif ($isLockedOrder && $canEditProducts): ?>
  <p class="notice notice-warning">This order is LOCKED (closed or marked lost). Adding, editing, duplicating, or removing a product line will be logged as a locked-order edit override — a reason is required.</p>
<?php elseif ($isPostConfirmationOrder && $canEditProducts): ?>
  <p class="notice notice-warning">This order has already reached Order Confirmation. Adding, editing, duplicating, or removing a product line will be logged as a post-confirmation edit — a reason is required.</p>
<?php elseif ($isLockedOrder && $canManageOrders): ?>
  <p class="notice notice-error">This order is LOCKED (closed or marked lost) — editing or duplicating a product line now needs the "Override locked data" permission.</p>
<?php elseif ($isPostConfirmationOrder && $canManageOrders): ?>
  <p class="notice notice-error">This order has already reached Order Confirmation — editing or duplicating a product line now needs the "Edit an order after confirmation" permission.</p>
<?php endif; ?>
<div class="table-scroll">
<table class="list products-table">
  <?php $__head('Product and options'); ?>
  <tbody>
  <?php foreach ($__goods as $p): $__row($p); endforeach; ?>
  <?php if ($__goods === []): ?><tr><td colspan="9" class="muted">No product lines yet.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($__goods !== []): ?>
  <tfoot><tr class="totals-row">
    <td></td>
    <td colspan="4"><strong>Total<?= $__totals['cbm'] > 0 ? ' · about ' . number_format($__totals['cbm'], 1) . ' CBM · ' . $__totals['containers'] . ' container' . ($__totals['containers'] === 1 ? '' : 's') . ' (' . LineUnits::num(LineUnits::CONTAINER_CBM) . ' CBM each)' : '' ?></strong>
      <?php if ($__totals['uncounted'] > 0): ?><span class="muted small"> — <?= $__totals['uncounted'] ?> line<?= $__totals['uncounted'] === 1 ? '' : 's' ?> without a size or quantity not counted in CBM</span><?php endif; ?></td>
    <?php if ($sees['selling']): ?><td></td><td class="num"><strong><?= number_format($__totals['amount'], 2) ?></strong></td><td></td><?php endif; ?>
    <?php if ($canEditProducts): ?><td></td><?php endif; ?>
  </tr></tfoot>
  <?php endif; ?>
</table>
</div>
<?php if ($__other !== []): ?>
<h3>Charges and services</h3>
<div class="table-scroll">
<table class="list products-table">
  <?php $__head('Charge or service'); ?>
  <tbody><?php foreach ($__other as $p): $__row($p); endforeach; ?></tbody>
</table>
</div>
<?php endif; ?>

<?php if ($canEditProducts): ?>
  <?php foreach (array_merge($products, [null]) as $__pl): ?>
    <?php
    $__isNew = $__pl === null;
    $__p = $__pl ?? [];
    $__panelId = $__isNew ? 'line-panel-new' : 'line-panel-' . (int) $__p['id'];
    $__lc = $__isNew ? null : ($linePricing['lines'][(int) $__p['id']] ?? null);
    $__lcFloor = (!$__isNew && $sees['selling'] && $__lc !== null && $__lc['figures'] !== null && $__lc['status'] !== OrderLinePricing::APPROVED) ? number_format((float) $__lc['figures']['floor'], 2, '.', '') : null;
    $__size = static fn (string $k): string => !$__isNew && ($__p[$k] ?? null) !== null ? LineUnits::num((float) $__p[$k]) : '';
    // Owner decision D-339: the line's pieces summary ("This line: 0.1872 CBM per piece · 25 pcs = 4.680 CBM · …").
    $__pieceSummary = !$__isNew && $__detailed ? \App\Services\LineTable::summary($__p['unit'] ?? null, LineUnits::size($__p['piece_length_cm'] ?? null), LineUnits::size($__p['piece_width_cm'] ?? null),
        LineUnits::size($__p['piece_thickness_cm'] ?? null), isset($__p['pieces_count']) ? (int) $__p['pieces_count'] : null,
        $__p['unit_price'] !== null ? (float) $__p['unit_price'] : null, (string) ($order['currency_code'] ?? '')) : null;
    ?>
    <aside class="line-panel" id="<?= $__panelId ?>" hidden aria-label="<?= $__isNew ? 'Add product' : 'Line ' . (int) $__p['line_no'] . ' — edit' ?>">
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/products<?= $__isNew ? '' : '/' . (int) $__p['id'] ?>" class="line-form">
        <?= \App\Helpers\Csrf::field() ?>
        <div class="line-panel-head">
          <h3><?= $__isNew ? 'Add product' : 'Line ' . (int) $__p['line_no'] . ' — edit' ?></h3>
          <button type="button" class="btn-sm btn-secondary" data-close-panel aria-label="Close">Close</button>
        </div>
        <section class="line-step"><h4><span class="step-no">1</span> Product</h4>
          <?php $__pickerName = 'catalog_product_id'; $__pickerSelected = (int) ($__p['catalog_product_id'] ?? 0); $__pickerCurrency = (string) ($order['currency_code'] ?? ''); $__pickerKey = (string) ($linePricing['ctx']['key'] ?? 'staff'); $__pickerSpecSel = (string) ($__p['spec_selection'] ?? ''); $__pickerOrderId = (int) $order['id']; $__pickerNoSpecBox = true; include __DIR__ . '/_product_picker.php'; $__pickerNoSpecBox = false; ?>
          <?php if ($__isNew && !empty($sees['selling']) && !empty($linePricing['ctx']['associate'])): // Owner decision D-255: a line from the associate's price request ?>
            <label>From the associate's price request <small class="muted">(optional — fills the product, options, price and unit from a valid request; you can still change the price)</small>
              <input type="text" name="price_request_number" maxlength="20" pattern="[Pp][Rr]-[0-9]{2}-[0-9]{2}-[0-9]{4}" placeholder="e.g. PR-26-05-0042"></label>
          <?php endif; ?>
        </section>
        <section class="line-step"><h4><span class="step-no">2</span> Options</h4>
          <div class="spec-options spec-grid" aria-live="polite"></div>
          <p class="muted small spec-empty-hint">Pick a product to choose its options.</p>
        </section>
        <section class="line-step"><h4><span class="step-no">3</span> Size and quantity</h4>
          <div class="size-grid">
            <label>Length (cm)<input type="text" inputmode="decimal" name="piece_length_cm" data-piece-size="length" data-max-decimals="2" data-max-value="10000" value="<?= $__size('piece_length_cm') ?>" placeholder="e.g. 180"></label>
            <label>Width (cm)<input type="text" inputmode="decimal" name="piece_width_cm" data-piece-size="width" data-max-decimals="2" data-max-value="10000" value="<?= $__size('piece_width_cm') ?>" placeholder="e.g. 80"></label>
            <label>Thickness (cm)<input type="text" inputmode="decimal" name="piece_thickness_cm" data-piece-size="thickness" data-max-decimals="2" data-max-value="10000" value="<?= $__size('piece_thickness_cm') ?>" placeholder="e.g. 13"></label>
            <label>Unit
              <select name="unit" data-allowed-from="category_id">
                <?php $__unitVal = (string) ($__p['unit'] ?? ''); ?>
                <?php foreach ($units as $o): ?>
                  <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__isNew ? $o['is_default'] : $__unitVal === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php if ($__detailed): // Owner decision D-339: No. of pieces — with the size given, the Qty is worked out from it (app.js and the server). ?>
            <label>No. of pieces<input type="text" inputmode="numeric" name="pieces_count" data-pieces-count maxlength="7" value="<?= htmlspecialchars((string) ($__p['pieces_count'] ?? '')) ?>" placeholder="e.g. 25"></label>
            <?php endif; ?>
            <label>Qty<input type="text" name="quantity" data-max-decimals="3" data-max-value="99999999999.999" value="<?= htmlspecialchars((string) ($__p['quantity'] ?? '')) ?>" placeholder="e.g. 40"><?php if ($__detailed): ?><small class="muted qty-worked-out" aria-live="polite"></small><?php endif; ?></label>
            <label class="checkbox-row"><input type="checkbox" name="quantity_is_tbc" value="1" <?= !empty($__p['quantity_is_tbc']) ? 'checked' : '' ?>> Qty To Be Confirmed</label>
            <?php // Owner decision D-336: the size is given in the annexure; never with "Qty To Be Confirmed" (app.js and the server). ?>
            <input type="hidden" name="as_per_annexure_field" value="1">
            <label class="checkbox-row"><input type="checkbox" name="as_per_annexure" value="1" data-annexure-line <?= !empty($__p['as_per_annexure']) ? 'checked' : '' ?>> As per annexure</label>
          </div>
          <p class="muted small annexure-line-hint">"Qty To Be Confirmed" or "As per annexure" — only one of the two. As per annexure: the sizes go in Annexure A (order page › Annexure A); the Qty is still needed.</p>
          <p class="muted small">PCS needs length, width and thickness; RMT width and thickness; m² and sq ft the thickness (or the Thickness option). The thickness and the Thickness option follow each other.</p>
          <?php if ($__detailed): ?>
          <p class="muted small">With Length and Width (and Thickness for CBM) in CBM, SQM or SQFT, type the No. of pieces — the Qty is worked out from it. PCS: the Qty is the pieces. Without a size, type the Qty as before.</p>
          <p class="line-pieces-summary notice notice-info small" aria-live="polite" data-currency="<?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?>"<?= $__pieceSummary === null ? ' hidden' : '' ?>><?= htmlspecialchars((string) $__pieceSummary) ?></p>
          <label>Size note <small class="muted">(only when no size is given — printed under the line on the documents)</small><input type="text" name="size_note" maxlength="200" value="<?= htmlspecialchars((string) ($__p['size_note'] ?? '')) ?>" placeholder="e.g. Random sizes, as available"></label>
          <?php endif; ?>
        </section>
        <section class="line-step"><h4><span class="step-no">4</span> Price</h4>
          <div class="size-grid">
            <label>Unit price <?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?><input type="text" name="unit_price" data-max-decimals="2" data-max-value="999999999999.99" value="<?= htmlspecialchars((string) ($__p['unit_price'] ?? '')) ?>" placeholder="e.g. 495.00"<?= $__detailed ? ' data-floor-show data-floor-currency="' . htmlspecialchars((string) ($order['currency_code'] ?? '')) . '"' : '' ?> data-floor-message="<?= !empty($linePricing['ctx']['associate']) ? 'Below the lowest for this order' : 'Below your lowest price' ?>"<?= $__lcFloor !== null ? ' data-floor="' . $__lcFloor . '"' : '' ?>></label>
            <div class="line-total-box"><span class="muted small">Line total</span><output class="line-total"><?= !$__isNew && $__p['fob_value'] !== null ? number_format((float) $__p['fob_value'], 2) : '—' ?></output></div>
          </div>
          <div class="price-ref-box" aria-live="polite"><?php if ($__lc !== null && $sees['selling']): include __DIR__ . '/_line_price.php'; endif; ?></div>
        </section>
        <details class="line-more">
          <summary>More <small class="muted">(description text, Dimensions, finish, line type, HS / SAC code, category)</small></summary>
          <div class="kv-grid">
            <label>Description *<input type="text" name="description" value="<?= htmlspecialchars((string) ($__p['description'] ?? '')) ?>" required placeholder="e.g. Absolute black granite — Premium, Polished"></label>
            <label>Dimensions <small class="muted">(prints on the documents — filled from the three sizes, you can still edit it)</small><input type="text" name="dimensions" data-dims-auto="<?= !$__isNew && ($__p['dimensions'] ?? '') !== '' ? '0' : '1' ?>" value="<?= htmlspecialchars((string) ($__p['dimensions'] ?? '')) ?>" placeholder="e.g. 180 × 80 × 13 cm"></label>
            <label>Finish
              <select name="finish">
                <?php $__finishVal = (string) ($__p['finish'] ?? ''); ?>
                <option value="">—</option>
                <?php foreach ($surfaceFinishes as $o): ?>
                  <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__isNew ? $o['is_default'] : $__finishVal === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Line type
              <select name="line_type">
                <?php foreach (\App\Repositories\OrderProductRepository::LINE_TYPES as $__lt => $__ltLabel): ?><option value="<?= $__lt ?>" <?= ($__p['line_type'] ?? 'goods') === $__lt ? 'selected' : '' ?>><?= $__ltLabel ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>HS Code <small class="muted">(goods)</small><input type="text" name="hs_code" value="<?= htmlspecialchars((string) ($__p['hs_code'] ?? '')) ?>" list="hs_code_list_edit" data-in-list="hs_code_list_edit" data-in-list-message="Pick an HS code from the HS Code Master List." data-required-when="line_type=goods" data-required-when-message="Goods need an HS code." placeholder="e.g. 68022310"></label>
            <label>SAC Code <small class="muted">(services)</small><input type="text" name="sac_code" value="<?= htmlspecialchars((string) ($__p['sac_code'] ?? '')) ?>" pattern="99[0-9]{4}" maxlength="6" placeholder="e.g. 998719" title="6 digits starting 99" data-required-when="line_type=service" data-required-when-message="A service needs its SAC code."></label>
            <?php // Owner decision D-215 (5a): the line's product category; another category's own attributes. ?>
            <label>Category
              <select name="category_id">
                <?php foreach ($__productCategories as $__c): ?><option value="<?= (int) $__c['is_system'] === 1 ? '' : (int) $__c['id'] ?>" <?= (int) $__c['is_system'] !== 1 && (int) ($__p['category_id'] ?? 0) === (int) $__c['id'] ? 'selected' : '' ?><?= \App\Repositories\ProductCategoryRepository::lineOptionAttributes($__c) ?>><?= htmlspecialchars((string) $__c['name']) ?></option><?php endforeach; ?>
              </select>
            </label>
            <?php foreach ($__productCategories as $__c): if ((int) $__c['is_system'] === 1) { continue; } $__vals = (int) ($__p['category_id'] ?? 0) === (int) $__c['id'] ? (json_decode((string) ($__p['attribute_values'] ?? ''), true) ?: []) : []; ?>
            <fieldset class="category-attributes" data-show-when="category_id=<?= (int) $__c['id'] ?>"><legend><?= htmlspecialchars((string) $__c['name']) ?></legend>
              <?php foreach ($__c['attributes'] as $__label): ?><label><?= htmlspecialchars($__label) ?><input type="text" name="attr_<?= (int) $__c['id'] ?>[]" maxlength="100" value="<?= htmlspecialchars((string) ($__vals[$__label] ?? '')) ?>" placeholder="e.g. Black Galaxy"></label><?php endforeach; ?>
            </fieldset>
            <?php endforeach; ?>
          </div>
        </details>
        <?php if ($__productReasonRequired): ?>
          <label>Reason *<textarea name="reason" rows="2" required minlength="10" placeholder="<?= $__isNew ? 'Why is a new line being added?' : 'Why is this line changing?' ?>"></textarea></label>
        <?php endif; ?>
        <div class="btn-row">
          <button type="submit"><?= $__productReasonRequired ? ($__isNew ? 'Override & Add' : 'Override & Save') : ($__isNew ? 'Add product' : 'Save line') ?></button>
          <button type="button" class="btn-secondary" data-close-panel>Cancel</button>
        </div>
      </form>
    </aside>
  <?php endforeach; ?>
  <datalist id="hs_code_list_edit">
    <?php foreach ($hsCodes ?? [] as $hc): ?>
      <option value="<?= htmlspecialchars($hc['code']) ?>"><?= htmlspecialchars($hc['description'] ?? '') ?></option>
    <?php endforeach; ?>
  </datalist>
<?php endif; ?>
