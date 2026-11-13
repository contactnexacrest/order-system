<?php
use App\Helpers\Csrf;
use App\Helpers\View;

/**
 * D-360: the "Offer active" switch for one offer ($__off = SupplierOffers::listRow, $__from =
 * "suppliers" | "supplier", $__canSwitch). Off = the price is not used for pricing, matching or
 * the lowest / highest supplier price. Node twin: _offer_switch.njk.
 */
?>
<?php if (!empty($__canSwitch)): ?>
<form method="post" action="/suppliers/offers/<?= (int) $__off['id'] ?>/active" class="offer-switch-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="from" value="<?= View::e($__from) ?>">
  <input type="hidden" name="active" value="<?= $__off['offer_active'] ? '0' : '1' ?>">
  <button type="submit" class="offer-switch<?= $__off['offer_active'] ? ' is-on' : '' ?>" role="switch" aria-checked="<?= $__off['offer_active'] ? 'true' : 'false' ?>"
          aria-label="Offer active: <?= View::e($__off['product_name']) ?>" title="<?= $__off['offer_active'] ? 'Switch this offer off' : 'Switch this offer on' ?>"><span class="offer-switch-knob"></span></button>
  <?php if (!$__off['offer_active']): ?><small class="muted">not offering now</small><?php endif; ?>
</form>
<?php else: ?>
<span class="badge <?= $__off['offer_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $__off['offer_active'] ? 'On' : 'Off' ?></span>
<?php endif; ?>
