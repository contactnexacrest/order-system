<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Reorder — <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted small"><a href="/client/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <p class="muted">
    This copies the product lines from this order as a starting point — edit, remove, or add lines
    below as needed for the repeat order. It doesn't create a new order right away: our team reviews
    every reorder request and will confirm it with you once it's set up.
  </p>

  <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/reorder">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Products</legend>
      <div id="product-rows">
        <?php $rows = !empty($products) ? $products : [['description' => '', 'dimensions' => '', 'finish' => '', 'quantity' => '', 'quantity_is_tbc' => false, 'unit' => '']]; ?>
        <?php foreach ($rows as $idx => $p): ?>
        <div class="product-row">
          <label>Description *<input type="text" name="product_description[<?= $idx ?>]" value="<?= htmlspecialchars($p['description'] ?? '') ?>"></label>
          <label>Dimensions<input type="text" name="product_dimensions[<?= $idx ?>]" value="<?= htmlspecialchars($p['dimensions'] ?? '') ?>"></label>
          <label>Finish<input type="text" name="product_finish[<?= $idx ?>]" value="<?= htmlspecialchars($p['finish'] ?? '') ?>"></label>
          <label>Qty<input type="text" name="product_quantity[<?= $idx ?>]" value="<?= htmlspecialchars((string) ($p['quantity'] ?? '')) ?>"></label>
          <label><input type="checkbox" name="product_quantity_tbc[<?= $idx ?>]" value="1" style="display:inline-block;width:auto;" <?= !empty($p['quantity_is_tbc']) ? 'checked' : '' ?>> Qty TBC</label>
          <label>Unit<input type="text" name="product_unit[<?= $idx ?>]" value="<?= htmlspecialchars($p['unit'] ?? '') ?>" placeholder="SQM/PCS"></label>
          <button type="button" class="remove-row" onclick="this.closest('.product-row').remove()">&times;</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="btn-row"><button type="button" class="btn-secondary btn-sm" id="add-product-row">+ Add product line</button></div>
      <p class="muted small">Pricing and HS codes aren't set here — our team confirms those when reviewing your request.</p>
    </fieldset>

    <fieldset>
      <legend>Anything else we should know?</legend>
      <textarea name="notes" rows="2" placeholder="e.g. same as before but 2 extra cartons, or a size change on one item"></textarea>
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
    nextIndex++;
    rows.appendChild(clone);
  });
})();
</script>
