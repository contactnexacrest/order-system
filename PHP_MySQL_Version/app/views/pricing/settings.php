<?php
use App\Helpers\Csrf;
use App\Helpers\View;

/** Owner decision D-245 (4 Nov 2026), P1: the global pricing rules. MD changes them; ED may look. */
$__ro = $canManage ? '' : ' disabled';
?>
<div class="card">
  <h1>Pricing Settings</h1>
  <p class="muted">The global rules every product's price is built from (FOB, per unit, in USD). A product can override any of them on its own page. Only the MD can change these; every change is recorded in the audit log (who, when, before → after). New quotes use the new values; orders with an issued Proforma Invoice keep their prices. Never shown to clients, associates or sales staff.</p>

  <div class="alert alert-info">
    <strong>How a price is built:</strong> cost = supplier price (₹) ÷ pricing exchange rate + other costs to FOB ·
    minimum price = cost ÷ (1 − margin %) · staff floor = minimum price + team-incentive reserve ·
    associate floor = staff floor ÷ (1 − commission %) · quote price = floor × (1 + buffer %).
    Team incentive program is <strong><?= $programOn ? 'on' : 'off' ?></strong><?= $programOn ? '' : ' — no reserve is added to prices' ?>.
  </div>

  <form method="post" action="/pricing/settings">
    <?= Csrf::field() ?>
    <fieldset><legend>Margin and costs</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['margin_pct']) ?> *<small class="muted">Margin on the selling price, not markup: 20% on a cost of USD 1,000 gives USD 1,250. Nobody can quote below the minimum price.</small></span>
        <input type="number" name="margin_pct" min="0" max="90" step="0.01" required value="<?= View::e($settings['margin_pct']) ?>" placeholder="e.g. 20.00"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['other_cost_pct']) ?> *<small class="muted">Packing, inland transport to port, CHA, inspection, documents and bank charges not already in the supplier price. A product can set its own % or USD amount.</small></span>
        <input type="number" name="other_cost_pct" min="0" max="100" step="0.01" required value="<?= View::e($settings['other_cost_pct']) ?>" placeholder="e.g. 2.50"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['pricing_fx_rate']) ?> *<small class="muted">Turns supplier prices in ₹ into USD. Keep it a little below the market rate as a cushion.</small></span>
        <input type="number" name="pricing_fx_rate" min="0.0001" step="0.0001" required value="<?= View::e($settings['pricing_fx_rate']) ?>" placeholder="e.g. 82.0000"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['buffer_pct']) ?> *<small class="muted">Room to negotiate: the quote price is the floor plus this %.</small></span>
        <input type="number" name="buffer_pct" min="0" max="100" step="0.01" required value="<?= View::e($settings['buffer_pct']) ?>" placeholder="e.g. 3.00"<?= $__ro ?>></label>
    </fieldset>

    <fieldset><legend>Team incentive reserve</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['incentive_mode']) ?> *<small class="muted">Added to the minimum price while the team incentive program is on.</small></span>
        <select name="incentive_mode" required<?= $__ro ?>>
          <option value="pct"<?= $settings['incentive_mode'] === 'pct' ? ' selected' : '' ?>>% of profit (minimum price − cost)</option>
          <option value="usd"<?= $settings['incentive_mode'] === 'usd' ? ' selected' : '' ?>>Fixed USD amount per unit</option>
        </select></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['incentive_value']) ?> *<small class="muted">e.g. 15 (% of profit) or 1.00 (USD per unit).</small></span>
        <input type="number" name="incentive_value" min="0" step="0.0001" required value="<?= View::e($settings['incentive_value']) ?>" placeholder="e.g. 15"<?= $__ro ?>></label>
    </fieldset>

    <fieldset><legend>Supplier prices</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['stale_days']) ?> *<small class="muted">A supplier price older than this is marked "stale" on the product list; update it before quoting.</small></span>
        <input type="number" name="stale_days" min="1" max="3650" step="1" required value="<?= View::e($settings['stale_days']) ?>" placeholder="e.g. 60"<?= $__ro ?>></label>
    </fieldset>

    <fieldset><legend>Order lines</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['require_product_pick']) ?> <small class="muted">On: a goods line on a USD order must be picked from Products (so its price is checked against the floor); a goods line typed by hand is refused. Service and charge lines can still be typed. Off: goods lines may be typed by hand and are not price-checked.</small></span>
        <select name="require_product_pick"<?= $__ro ?>>
          <option value="1"<?= (string) $settings['require_product_pick'] === '1' ? ' selected' : '' ?>>On — goods lines must come from Products</option>
          <option value="0"<?= (string) $settings['require_product_pick'] !== '1' ? ' selected' : '' ?>>Off — goods lines may be typed by hand</option>
        </select></label>
    </fieldset>

    <?php if ($canManage): ?><button type="submit">Save settings</button><?php else: ?><p class="muted small">Only the MD can change these settings.</p><?php endif; ?>
  </form>

  <h2>Who can do what</h2>
  <table class="list">
    <tr><th>Permission</th><th>Default roles</th></tr>
    <tr><td>Record supplier / market prices and costs to FOB</td><td>Procurement (Executive, Manager, Director), MD</td></tr>
    <tr><td>See the full price build-up</td><td>MD, ED</td></tr>
    <tr><td>Change these settings, product overrides, commission levels and ladder</td><td>MD only</td></tr>
  </table>
  <p><a class="btn-sm" href="/pricing/commission-levels">Associate commission levels and ladder</a></p>
</div>
