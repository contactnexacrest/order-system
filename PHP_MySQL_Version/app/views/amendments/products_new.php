<?php
/**
 * Owner decision D-342 (12 Nov 2026, owner confirmed twice; mockup screen 1):
 * New amendment — Products & prices. The lines are edited inside the amendment
 * draft; the order itself changes only when the amendment is signed and activated.
 * Node twin: views/amendments/products_new.njk.
 */
use App\Helpers\Csrf;
use App\Services\LineTable;
use App\Services\LineUnits;
use App\Services\PriceLock;
use App\Services\ProductsAmendmentService as PA;

$ccy = (string) ($order['currency_code'] ?? '');
$byLine = [];
foreach (($draft['lines'] ?? []) as $__d) {
    if ($__d['order_product_id'] !== null) {
        $byLine[(int) $__d['order_product_id']] = $__d;
    }
}
$__in = static function (int $id, string $field, $fallback) use ($post): string {
    $v = $post['line'][$id][$field] ?? null;
    return htmlspecialchars($v !== null ? (string) $v : ($fallback === null ? '' : (string) $fallback));
};
$__num = static fn ($v): string => $v === null || $v === '' ? '' : LineUnits::num((float) $v);
$__qty = static fn ($v, ?string $unit): string => $v === null ? '—' : number_format((float) $v, LineTable::quantityDecimals($unit) ?? 3);
$__was = static fn (string $old, string $new): string => $old !== $new ? '<span class="amd-old">' . htmlspecialchars($old) . '</span> <span class="amd-new">' . htmlspecialchars($new) . '</span>' : htmlspecialchars($new);
?>
<div class="card page-wide amendment-products">
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>/amendments">&larr; Back to Amendments</a></p>
  <h1>New amendment — Products &amp; prices · Order <?= htmlspecialchars((string) $order['order_reference']) ?></h1>
  <p class="muted">Prices fixed by the approved <?= htmlspecialchars(PriceLock::fixedByLabel($order)) ?> of <?= htmlspecialchars(PriceLock::fixedDate($order)) ?><?= $seesRate && PriceLock::fixedRate($order) !== null ? ' at ₹' . number_format((float) PriceLock::fixedRate($order), 2) . ' per USD' : '' ?>. Change only what the buyer agreed; the order itself changes only when this amendment is signed and activated.</p>

  <?php if ($errors !== []): ?>
    <div class="notice notice-error" role="alert"><?php foreach ($errors as $__e): ?><p><?= htmlspecialchars($__e) ?></p><?php endforeach; ?></div>
  <?php endif; ?>

  <form method="post" action="/orders/<?= (int) $order['id'] ?>/amendments/products" id="products-amendment-form">
    <?= Csrf::field() ?>
    <div class="table-scroll">
    <table class="list amd-lines">
      <thead><tr><th>#</th><th>Product</th><th>L × W × H (cm)</th><th class="num">No. of pcs</th><th class="num">Qty</th><th class="num">Unit price (<?= htmlspecialchars($ccy) ?>)</th><th class="num">Line total (<?= htmlspecialchars($ccy) ?>)</th><th>Change</th><th>Remove</th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): $id = (int) $l['id']; $no = (int) $l['line_no']; $d = $byLine[$id] ?? null;
          $unit = (string) ($l['unit'] ?? '');
          $byPieces = empty($l['as_per_annexure']) && LineTable::needsPieces($unit, LineUnits::size($l['piece_length_cm'] ?? null), LineUnits::size($l['piece_width_cm'] ?? null), LineUnits::size($l['piece_thickness_cm'] ?? null));
          $kind = $d['change_kind'] ?? 'none';
          $rowClass = $kind === 'remove' ? 'amd-del' : ($kind === 'change' ? 'amd-chg' : '');
          $fixed = $l['fixed_price']; ?>
        <tr class="<?= $rowClass ?>">
          <td><?= $no ?></td>
          <td><?= htmlspecialchars((string) $l['description']) ?></td>
          <td class="amd-size">
            <?php if (!empty($l['as_per_annexure'])): ?>
              <span class="muted">As per annexure</span>
            <?php else: ?>
              <input type="text" inputmode="decimal" name="line[<?= $id ?>][length_cm]" value="<?= $__in($id, 'length_cm', $__num($l['piece_length_cm'] ?? null)) ?>" placeholder="e.g. 180" data-max-decimals="2" aria-label="Line <?= $no ?> length (cm)"> ×
              <input type="text" inputmode="decimal" name="line[<?= $id ?>][width_cm]" value="<?= $__in($id, 'width_cm', $__num($l['piece_width_cm'] ?? null)) ?>" placeholder="e.g. 80" data-max-decimals="2" aria-label="Line <?= $no ?> width (cm)"> ×
              <input type="text" inputmode="decimal" name="line[<?= $id ?>][thickness_cm]" value="<?= $__in($id, 'thickness_cm', $__num($l['piece_thickness_cm'] ?? null)) ?>" placeholder="e.g. 13" data-max-decimals="2" aria-label="Line <?= $no ?> thickness (cm)">
            <?php endif; ?>
          </td>
          <td class="num"><input type="text" inputmode="numeric" name="line[<?= $id ?>][pieces]" value="<?= $__in($id, 'pieces', $l['pieces_count'] ?? null) ?>" placeholder="e.g. 25" data-pieces-count maxlength="7" aria-label="Line <?= $no ?> No. of pieces"></td>
          <td class="num">
            <?php if ($byPieces): ?>
              <?= $d !== null ? $__was($__qty($d['old_quantity'], $unit), $__qty($d['new_quantity'], $unit)) : htmlspecialchars($__qty($l['quantity_is_tbc'] ? null : $l['quantity'], $unit)) ?> <span class="muted small"><?= htmlspecialchars($unit) ?></span>
              <br><small class="muted">worked out from the pieces</small>
            <?php else: ?>
              <input type="text" inputmode="decimal" name="line[<?= $id ?>][quantity]" value="<?= $__in($id, 'quantity', $l['quantity_is_tbc'] ? '' : ($l['quantity'] !== null ? rtrim(rtrim((string) $l['quantity'], '0'), '.') : '')) ?>" placeholder="e.g. 40" data-max-decimals="3" aria-label="Line <?= $no ?> quantity (<?= htmlspecialchars($unit) ?>)"> <span class="muted small"><?= htmlspecialchars($unit) ?></span>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($d !== null && !empty($d['price_lowered'])): ?><span class="amd-old"><?= PA::money((float) $d['old_unit_price']) ?></span><?php endif; ?>
            <input type="text" inputmode="decimal" name="line[<?= $id ?>][unit_price]" value="<?= $__in($id, 'unit_price', $l['unit_price'] !== null ? number_format((float) $l['unit_price'], 2, '.', '') : '') ?>" placeholder="e.g. 1950.00" data-max-decimals="2" required
              <?php if ($fixed !== null): ?>data-max-price="<?= number_format((float) $fixed, 2, '.', '') ?>" data-max-price-message="<?= htmlspecialchars(PA::priceUpMessage($no, $ccy, (float) $fixed, PriceLock::fixedByLabel($order))) ?>"<?php endif; ?>
              aria-label="Line <?= $no ?> unit price (<?= htmlspecialchars($ccy) ?> per <?= htmlspecialchars($unit) ?>)">
          </td>
          <td class="num"><?= $d !== null && $kind !== 'remove' ? $__was(PA::money($d['old_line_total']), PA::money($d['new_line_total'])) : PA::money($l['fob_value'] !== null ? (float) $l['fob_value'] : null) ?></td>
          <td><?= $d === null ? '' : htmlspecialchars(implode(', ', array_map(static fn ($k) => PA::CHANGE_LABELS[$k], $d['changes'] ?: ['none']))) ?></td>
          <td><label class="checkbox-row"><input type="checkbox" name="line[<?= $id ?>][remove]" value="1"<?= !empty($post['line'][$id]['remove']) ? ' checked' : '' ?>> Remove</label></td>
        </tr>
      <?php endforeach; ?>
      <?php for ($k = 0; $k < PA::NEW_LINE_SLOTS; $k++): $nin = $post['new'][$k] ?? [];
          $nd = null;
          foreach (($draft['lines'] ?? []) as $__d) { if (($__d['slot'] ?? null) === $k) { $nd = $__d; } }
          $nv = static fn (string $f): string => htmlspecialchars((string) ($nin[$f] ?? '')); ?>
        <tr class="amd-add-row<?= $nd !== null ? ' amd-add' : '' ?>">
          <td><?= $nd !== null ? (int) $nd['line_no'] : '+' ?></td>
          <td>
            <select name="new[<?= $k ?>][source_line_id]" aria-label="New line <?= $k + 1 ?>: product">
              <option value="">Add a line — choose…</option>
              <?php foreach ($lines as $l): ?>
                <option value="<?= (int) $l['id'] ?>"<?= (string) ($nin['source_line_id'] ?? '') === (string) $l['id'] ? ' selected' : '' ?>>Same product as line <?= (int) $l['line_no'] ?> — <?= htmlspecialchars(mb_strimwidth((string) $l['description'], 0, 50, '…')) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="amd-size">
            <input type="text" inputmode="decimal" name="new[<?= $k ?>][length_cm]" value="<?= $nv('length_cm') ?>" placeholder="e.g. 100" data-max-decimals="2" aria-label="New line <?= $k + 1 ?> length (cm)"> ×
            <input type="text" inputmode="decimal" name="new[<?= $k ?>][width_cm]" value="<?= $nv('width_cm') ?>" placeholder="e.g. 50" data-max-decimals="2" aria-label="New line <?= $k + 1 ?> width (cm)"> ×
            <input type="text" inputmode="decimal" name="new[<?= $k ?>][thickness_cm]" value="<?= $nv('thickness_cm') ?>" placeholder="e.g. 13" data-max-decimals="2" aria-label="New line <?= $k + 1 ?> thickness (cm)">
          </td>
          <td class="num"><input type="text" inputmode="numeric" name="new[<?= $k ?>][pieces]" value="<?= $nv('pieces') ?>" placeholder="e.g. 20" data-pieces-count maxlength="7" aria-label="New line <?= $k + 1 ?> No. of pieces"></td>
          <td class="num"><input type="text" inputmode="decimal" name="new[<?= $k ?>][quantity]" value="<?= $nv('quantity') ?>" placeholder="e.g. 1.300" data-max-decimals="3" aria-label="New line <?= $k + 1 ?> quantity"><?php if ($nd !== null && $nd['new_quantity'] !== null): ?><br><small class="muted">= <?= htmlspecialchars($__qty($nd['new_quantity'], (string) $nd['unit'])) ?> <?= htmlspecialchars((string) $nd['unit']) ?></small><?php endif; ?></td>
          <td class="num"><input type="text" inputmode="decimal" name="new[<?= $k ?>][unit_price]" value="<?= $nv('unit_price') ?>" placeholder="e.g. 1950.00" data-max-decimals="2" aria-label="New line <?= $k + 1 ?> unit price (<?= htmlspecialchars($ccy) ?>)"></td>
          <td class="num"><?= $nd !== null ? PA::money($nd['new_line_total']) : '' ?></td>
          <td><?= $nd !== null ? PA::CHANGE_LABELS['add'] : '' ?></td>
          <td></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small">With Length, Width (and Thickness for CBM) given, the quantity is worked out from the No. of pieces; otherwise type the quantity. A new line copies the product of the line you choose, with its own size, pieces, quantity and price.</p>

    <?php if ($draft !== null && $draft['errors'] === []): $t = $draft['totals']; ?>
      <div class="notice notice-info amd-totals">
        <strong>Order total:</strong> <span class="amd-old"><?= htmlspecialchars($ccy) ?> <?= PA::money((float) $t['old_total']) ?></span>
        <span class="amd-new"><?= htmlspecialchars($ccy) ?> <?= PA::money((float) $t['new_total']) ?></span> (<?= htmlspecialchars(PA::signedDifference($t)) ?>)
        · Advance <?= htmlspecialchars(rtrim(rtrim(number_format((float) $t['advance_pct'], 2, '.', ''), '0'), '.')) ?>%: <span class="amd-old"><?= PA::money((float) $t['old_advance']) ?></span> <span class="amd-new"><?= PA::money((float) $t['new_advance']) ?></span>
        — <?= htmlspecialchars(PA::advanceNote($t)) ?>
      </div>
    <?php endif; ?>

    <label class="full">Reason for the amendment *
      <textarea name="reason" required minlength="10" maxlength="500" rows="2" placeholder="e.g. Buyer asked for 5 more slabs of 180 × 80 on 14 Nov (e-mail); discount on line 3 agreed by MD" style="width:100%"><?= htmlspecialchars($reason) ?></textarea>
    </label>
    <p>
      <button type="submit" formaction="/orders/<?= (int) $order['id'] ?>/amendments/products?step=check" class="btn-secondary" data-step="check">Check the changes</button>
      <button type="submit" class="btn-primary" data-step="send" data-loading-text="Sending…">Send to MD for approval</button>
      <a class="btn-sm btn-secondary" href="/orders/<?= (int) $order['id'] ?>/amendments">Cancel</a>
    </p>
    <p class="muted small">A price can only go DOWN, and only with MD approval; a higher price is refused ("the buyer accepted the <?= htmlspecialchars(PriceLock::fixedByLabel($order)) ?> at <?= htmlspecialchars($ccy) ?> …"). New lines get their own price.</p>
  </form>
</div>
