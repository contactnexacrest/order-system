<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Reorder — <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted small"><a href="/client/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <p class="muted">
    We have copied the products from this order to help you start. Change, remove or add products
    below. This does not place a new order yet. Our team will check your request and confirm the new
    order with you.
  </p>

  <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/reorder">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Products</legend>
      <div id="product-rows">
        <?php $rows = !empty($products) ? $products : [['description' => '', 'dimensions' => '', 'finish' => '', 'quantity' => '', 'quantity_is_tbc' => false, 'unit' => '']]; ?>
        <?php foreach ($rows as $idx => $p): ?>
        <div class="product-row">
          <label>Description *<input type="text" name="product_description[<?= $idx ?>]" required value="<?= htmlspecialchars($p['description'] ?? '') ?>" placeholder="e.g. Granite Memorial Headstone"></label>
          <label>Dimensions<input type="text" name="product_dimensions[<?= $idx ?>]" value="<?= htmlspecialchars($p['dimensions'] ?? '') ?>" placeholder="e.g. 24x12x3 inches"></label>
          <label>Finish
            <select name="product_finish[<?= $idx ?>]">
              <?php $__finishVal = (string) ($p['finish'] ?? ''); ?>
              <option value="">—</option>
              <?php foreach ($surfaceFinishes as $o): ?>
                <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__finishVal === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Quantity<input type="text" name="product_quantity[<?= $idx ?>]" value="<?= htmlspecialchars((string) ($p['quantity'] ?? '')) ?>" placeholder="e.g. 100"></label>
          <label><input type="checkbox" name="product_quantity_tbc[<?= $idx ?>]" value="1" style="display:inline-block;width:auto;" <?= !empty($p['quantity_is_tbc']) ? 'checked' : '' ?>> Quantity not decided yet</label>
          <label>Unit
            <?php
              // Buyer-portal fixes (7 Nov 2026): the earlier order's unit is matched whatever its case or
              // spacing ("sqm" = "SQM"); a unit not in the list is never swapped for the default (PCS) —
              // the buyer is asked to pick, with the earlier unit shown.
              $__unitVal = trim((string) ($p['unit'] ?? ''));
              $__unitKey = static fn ($v): string => strtoupper((string) preg_replace('/\s+/', '', (string) $v));
              $__unitPick = null;
              foreach ($units as $o) {
                  if ($__unitVal !== '' && $__unitKey($o['option_value']) === $__unitKey($__unitVal)) {
                      $__unitPick = (string) $o['option_value'];
                      break;
                  }
              }
              $__unitUnknown = $__unitVal !== '' && $__unitPick === null;
            ?>
            <select name="product_unit[<?= $idx ?>]"<?= $__unitUnknown ? ' required' : '' ?>>
              <?php if ($__unitUnknown): ?><option value="" selected>Choose… (your earlier unit: <?= htmlspecialchars($__unitVal) ?>)</option><?php endif; ?>
              <?php foreach ($units as $o): ?>
                <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__unitPick === $o['option_value'] || ($__unitVal === '' && $o['is_default'])) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="remove-row" onclick="this.closest('.product-row').remove()">&times;</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="btn-row"><button type="button" class="btn-secondary btn-sm" id="add-product-row">+ Add product line</button></div>
      <p class="muted small">You do not need to give prices or customs codes (HS codes). Our team adds them when we check your request.</p>
    </fieldset>

    <fieldset>
      <legend>Anything else we should know?</legend>
      <textarea name="notes" rows="2" placeholder="e.g. same as before but 2 extra cartons, or a size change on one item" aria-label="Notes"></textarea>
    </fieldset>

    <button type="submit">Submit Reorder Request</button>
  </form>
</div>
<script>
(function () {
  var nextIndex = <?= count($rows) ?>;
  document.getElementById('add-product-row').addEventListener('click', function () {
    var rows = document.getElementById('product-rows');
    var clone = rows.firstElementChild.cloneNode(true);
    clone.querySelectorAll('input[type=text]').forEach(function (el) {
      el.value = '';
      el.name = el.name.replace(/\[\d+\]/, '[' + nextIndex + ']');
    });
    clone.querySelectorAll('input[type=checkbox]').forEach(function (el) {
      el.checked = false;
      el.name = el.name.replace(/\[\d+\]/, '[' + nextIndex + ']');
    });
    // 90-item backlog "product Finish dropdown": adding the Finish select
    // surfaced a pre-existing gap here — only input elements were
    // re-indexed, so a cloned row's <select> (Unit, and now Finish) kept
    // the first row's array index and collided with it in $_POST instead
    // of submitting as its own product line.
    clone.querySelectorAll('select').forEach(function (el) {
      el.name = el.name.replace(/\[\d+\]/, '[' + nextIndex + ']');
    });
    nextIndex++;
    rows.appendChild(clone);
  });
})();
</script>
