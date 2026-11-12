<?php
/**
 * Owner decision D-255 (5 Nov 2026): ask for a price for one product and
 * specification. Only this product's options are offered. Node twin: get_price.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
$__old = \App\Helpers\Flash::pullOld();
?>
<div class="card page-wide">
  <p class="muted small"><a href="/associate/catalogue">&larr; Catalogue</a></p>
  <h1>Get price — <?= View::e($product['name']) ?></h1>
  <p class="muted"><?= (int) $left ?> price requests left today. For a standard product you see the price at once. For anything else, our team sends you the price.</p>
  <form method="post" action="/associate/catalogue/<?= (int) $product['id'] ?>/price" class="field-grid">
    <?= Csrf::field() ?>
    <?php foreach ($specs as $s): ?>
      <label><?= View::e($s['name']) ?> *
        <select name="spec[<?= (int) $s['id'] ?>]" required>
          <?php foreach ($s['options'] as $o): ?>
            <?php $__sel = isset($__old['spec'][$s['id']]) ? (string) $__old['spec'][$s['id']] === (string) $o['id'] : $o['is_base']; ?>
            <option value="<?= (int) $o['id'] ?>"<?= $__sel ? ' selected' : '' ?>><?= View::e($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endforeach; ?>
    <?php if (!empty($access['price_requests'])): // D-261 ?>
    <label class="full inline"><input type="checkbox" name="other" value="1"<?= !empty($__old['other']) ? ' checked' : '' ?>> The buyer needs something not listed here (our team prices it within 24 hours)</label>
    <?php endif; ?>
    <label class="full">Notes for our team <small class="muted">(needed when something is not listed — at least 10 characters)</small>
      <textarea name="note" rows="2" maxlength="500" data-required-when="other=1" data-required-when-message="Tell our team what the buyer needs (at least 10 characters)." minlength="10" placeholder="e.g. Buyer needs 25 mm for a hotel lobby, 1 container"><?= View::e((string) ($__old['note'] ?? '')) ?></textarea></label>
    <div><button type="submit" class="btn-sm btn-accent">Get price</button></div>
  </form>
  <p class="small muted">Prices are FOB: goods loaded on the ship at the Indian port, without sea freight. Prices are per unit; for stone 50 mm or thicker they are per cubic metre (CBM). Your price is also shown per CBM, per SQM and per SQFT for the thickness you choose. The final price is on the proforma invoice. These prices are only for you — please do not share this page.</p>
</div>
