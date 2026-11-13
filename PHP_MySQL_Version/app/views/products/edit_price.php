<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ThicknessPrices;

/**
 * Owner decision D-359 (13 Nov 2026, plan rule 8, mockup edit_price_new.png): the simple
 * "Edit price" form — one supplier's price for one product. New price (big), per unit, for
 * which thickness (default: the base thickness), quoted on, valid until, "More (optional)":
 * CHA + transport per container, packing and loading, notes. Right: the other thicknesses
 * worked out (old vs new, "Type it yourself" per thickness) and the selling price after
 * saving (MD / ED only). One Save; the page stays; a green message with Undo and
 * "See price history". The figures come from the server (SupplierOffers::preview — the
 * existing ThicknessPrices / PricingService maths). Node twin: edit_price.njk.
 */
$__id = (int) $row['id'];
$__q = $from !== 'product' ? '?from=' . $from : '';
$__amt = static fn (?float $v): string => $v === null ? '—' : '₹' . ThicknessPrices::amount($v);
$__validUntil = (string) ($old['valid_until'] ?? ((string) ($row['valid_until'] ?? '') >= $today ? (string) ($row['valid_until'] ?? '') : ''));
$__cha = (string) ($old['cha_transport_per_container'] ?? ($row['cha_transport_per_container'] !== null ? rtrim(rtrim(number_format((float) $row['cha_transport_per_container'], 2, '.', ''), '0'), '.') : ''));
$__incl = array_key_exists('includes_packing_loading', $old) ? (string) $old['includes_packing_loading'] === '1' : (int) ($row['includes_packing_loading'] ?? 1) === 1;
$__prodCha = $product['cha_transport_per_container'] ?? null;
$__sell = $preview['selling'] ?? null;
?>
<div class="card page-wide">
  <p class="muted small"><a href="<?= View::e($backUrl) ?>">&larr; Back</a></p>

  <?php if ($saved !== null): ?>
    <div class="alert alert-success edit-price-saved" role="status">
      <strong>Saved.</strong> <?= View::e((string) $saved) ?>
      <?php if ($undoToken !== null): ?>
        <form method="post" action="/products/suppliers/<?= $__id ?>/edit-price/undo" class="inline-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="undo_token" value="<?= View::e((string) $undoToken) ?>">
          <input type="hidden" name="from" value="<?= View::e($from) ?>">
          <button type="submit" class="btn-link">Undo</button>
        </form> ·
      <?php endif; ?>
      <a href="/products/suppliers/<?= $__id ?>/price#history">See price history</a>
    </div>
    <p class="small muted">The old price is kept in the price history. Orders and quotations already issued are not changed (price lock D-342).</p>
  <?php endif; ?>

  <div class="edit-price-panel">
    <a class="edit-price-close" href="<?= View::e($backUrl) ?>" aria-label="Close">✕ close</a>
    <h1>Edit price — <?= View::e((string) $row['supplier_name']) ?> · <?= View::e((string) $product['name']) ?></h1>
    <p class="muted">Today: <?= $base !== null ? View::e($__amt($base['inr']) . ' ' . $base['label']) : 'no price yet' ?><?= !empty($row['quoted_on']) ? ', quoted ' . View::e(date('j M Y', (int) strtotime((string) $row['quoted_on']))) : '' ?><?= !\App\Services\SupplierOffers::usable($row) ? ' <span class="badge badge-warning">offer or supplier inactive — not used</span>' : '' ?></p>

    <form method="post" action="/products/suppliers/<?= $__id ?>/edit-price" class="edit-price-form" data-edit-price
          data-preview-url="/products/suppliers/<?= $__id ?>/edit-price/preview" data-was="<?= $base !== null ? View::e((string) $base['inr']) : '' ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="from" value="<?= View::e($from) ?>">
      <div class="edit-price-cols">
        <div>
          <div class="edit-price-main">
            <label>New price (₹) *<input type="text" inputmode="decimal" name="price" required class="edit-price-big" data-max-decimals="2" data-max-value="999999999999.99"
                   placeholder="e.g. 185000" value="<?= View::e((string) ($old['price'] ?? ($base !== null ? rtrim(rtrim(number_format($base['inr'], 2, '.', ''), '0'), '.') : ''))) ?>" data-edit-price-input></label>
            <?php if ($grid): ?>
              <?php $__unit = (string) ($old['unit'] ?? ''); $__unit = $fromMm === ThicknessPrices::CBM || (isset($old['thickness_mm']) && (string) $old['thickness_mm'] === (string) ThicknessPrices::CBM) ? 'CBM' : (in_array($__unit, ['m2', 'SQFT'], true) ? $__unit : 'm2'); ?>
              <label>per *<select name="unit" required data-edit-price-unit-select data-sqft-unit>
                <option value="m2"<?= $__unit === 'm2' ? ' selected' : '' ?>>SQM</option>
                <option value="SQFT" data-sqft="1"<?= $__unit === 'SQFT' ? ' selected' : '' ?>>SQFT</option>
                <option value="CBM"<?= $__unit === 'CBM' ? ' selected' : '' ?>>CBM</option>
              </select></label>
              <span class="edit-price-per">for</span>
              <label>Thickness *<select name="thickness_mm" required data-edit-price-thickness data-sqft-thickness>
                <?php foreach ($thicknesses as $t): ?>
                  <option value="<?= (int) $t['mm'] ?>" data-unit="<?= View::e($t['unit']) ?>"<?= (int) $t['mm'] === (int) $rules['base_mm'] ? ' data-base="1"' : '' ?><?= (int) ($old['thickness_mm'] ?? $fromMm) === (int) $t['mm'] ? ' selected' : '' ?>><?= View::e($t['label']) ?><?= (int) $t['mm'] === (int) $fromMm ? ' (base)' : '' ?></option>
                <?php endforeach; ?>
              </select></label>
            <?php else: ?>
              <?php if ($sqmUnit !== null): $__unit = (string) ($old['unit'] ?? '') === 'SQFT' ? 'SQFT' : $sqmUnit; ?>
                <label>per *<select name="unit" required data-edit-price-unit-select data-sqft-unit>
                  <option value="<?= View::e($sqmUnit) ?>"<?= $__unit !== 'SQFT' ? ' selected' : '' ?>>SQM</option>
                  <option value="SQFT" data-sqft="1"<?= $__unit === 'SQFT' ? ' selected' : '' ?>>SQFT</option>
                </select></label>
              <?php else: ?>
                <span class="edit-price-per">per <strong><?= View::e($unitLabel) ?></strong></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <p class="sqft-note" data-sqft-note hidden></p>
          <p class="small muted" data-edit-price-change><?= $base !== null ? 'Was ' . View::e($__amt($base['inr'])) : '' ?></p>
          <div class="field-grid">
            <label>Quoted on *<input type="date" name="quoted_on" required max="<?= View::e($today) ?>" value="<?= View::e((string) ($old['quoted_on'] ?? $today)) ?>"></label>
            <label>Valid until<input type="date" name="valid_until" value="<?= View::e($__validUntil) ?>"></label>
          </div>
          <details class="edit-price-more"<?= $__cha !== '' || !$__incl || !empty($row['notes']) ? ' open' : '' ?>>
            <summary><strong>More (optional)</strong> — CHA + transport, packing, notes</summary>
            <div class="field-grid">
              <label>CHA + transport per container (₹)<input type="text" inputmode="decimal" name="cha_transport_per_container" data-max-decimals="2" value="<?= View::e($__cha) ?>"
                     placeholder="<?= $__prodCha !== null ? 'product default ' . View::e(ThicknessPrices::amount((float) $__prodCha)) : 'e.g. 100000' ?>"></label>
              <label class="full checkbox-label"><input type="hidden" name="includes_packing_loading" value="0"><input type="checkbox" name="includes_packing_loading" value="1"<?= $__incl ? ' checked' : '' ?>> Price includes packing and loading</label>
              <label class="full">Notes<textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Ex-works, Grade A, min 2 blocks"><?= View::e((string) ($old['notes'] ?? ($row['notes'] ?? ''))) ?></textarea></label>
            </div>
          </details>
          <div class="edit-price-actions">
            <button type="submit" class="btn-accent">Save</button>
            <a class="btn-secondary" href="<?= View::e($backUrl) ?>">Cancel</a>
          </div>
        </div>

        <div>
          <?php if ($grid): ?>
            <h2 class="edit-price-h">Other thicknesses — worked out for you</h2>
            <table class="list edit-price-calc">
              <tr><th>Thickness</th><th class="num">Price</th><th class="num">Was</th><th></th></tr>
              <?php foreach ($preview['lines'] as $l): ?>
                <tr data-edit-price-row="<?= (int) $l['mm'] ?>"<?= $l['from'] ? ' class="edit-price-from"' : '' ?>>
                  <td><?= View::e($l['label']) ?></td>
                  <td class="num"><span data-edit-price-new><?= $l['price'] !== null ? View::e($__amt((float) $l['price']) . ' / ' . $l['unit']) : '—' ?></span>
                    <span class="badge" data-edit-price-how><?= $l['from'] ? 'entered' : '' ?></span></td>
                  <td class="num muted"><?= $l['was'] !== null ? View::e($__amt((float) $l['was'])) : '—' ?></td>
                  <td>
                    <button type="button" class="btn-link small" data-edit-price-own-toggle<?= $l['from'] ? ' hidden' : '' ?>>Type it yourself</button>
                    <label class="edit-price-own" hidden>Your price for <?= View::e($l['label']) ?><input type="text" inputmode="decimal" name="own[<?= (int) $l['mm'] ?>]" data-max-decimals="2"
                           placeholder="e.g. <?= $l['price'] !== null ? View::e(rtrim(rtrim(number_format((float) $l['price'], 2, '.', ''), '0'), '.')) : '4500' ?>" value="<?= View::e((string) ($old['own'][$l['mm']] ?? '')) ?>" data-edit-price-own></label>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
            <p class="small muted">Want a different price for one thickness? <strong>Type it yourself</strong> — that row then stays as you typed it.</p>
          <?php endif; ?>
          <?php if ($canSeeSelling): ?>
            <p class="edit-price-selling" data-edit-price-selling>
              <?php if ($__sell !== null && $__sell['applies'] && ($__sell['min'] ?? null) !== null): ?>
                <strong>Selling price after saving:</strong> minimum USD <?= number_format((float) $__sell['min'], 2) ?> · quote USD <?= number_format((float) $__sell['quote'], 2) ?> / <?= View::e(\App\Services\LineUnits::label((string) $__sell['unit']) ?: (string) $__sell['unit']) ?>
                <span class="muted">(was <?= number_format((float) $__sell['was_min'], 2) ?> · <?= number_format((float) $__sell['was_quote'], 2) ?>)</span>
              <?php elseif ($__sell !== null): ?>
                <span class="muted"><?= View::e((string) ($__sell['reason'] ?? '')) ?></span>
              <?php endif; ?>
            </p>
          <?php endif; ?>
          <p class="small muted" data-edit-price-error role="alert"></p>
        </div>
      </div>
    </form>
  </div>
</div>
