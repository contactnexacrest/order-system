<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\PricingService;

/** Owner decision D-245 (4 Nov 2026), P1: the global pricing rules. MD changes them; ED may look. */
$__ro = $canManage ? '' : ' disabled';
?>
<div class="card">
  <h1>Global pricing settings</h1>
  <p class="muted" data-testid="applies-note"><strong>Applies to every product unless a category or product changes it.</strong></p>
  <p class="muted">The global rules every product's price is built from (FOB, per unit, in USD). A product can override any of them on its own page. Only the MD can change these; every change is recorded in the audit log (who, when, before → after). New quotes use the new values; orders with an issued Proforma Invoice keep their prices. Never shown to clients, associates or sales staff.</p>

  <div class="alert alert-info">
    <strong>How a price is built:</strong> cost = supplier price (₹) ÷ pricing exchange rate + other costs to FOB ·
    minimum price = cost ÷ (1 − margin %) · staff floor = minimum price + team-incentive reserve ·
    associate floor = staff floor ÷ (1 − commission %) · quote price = floor × (1 + buffer %).
    Team incentive program is <strong><?= $programOn ? 'on' : 'off' ?></strong><?= $programOn ? '' : ' — no reserve is added to prices' ?>.
  </div>

  <?php // D-340: the fetch and the MD's decision are their own forms; their buttons sit in the box inside the settings form (form="…"). ?>
  <?php if ($canManage): ?>
    <form method="post" action="/pricing/fx-rates/fetch" id="fx-fetch-form"><?= Csrf::field() ?></form>
    <?php if ($fx['waiting'] !== null): ?><form method="post" action="/pricing/fx-rates/<?= (int) $fx['waiting']['id'] ?>/decide" id="fx-decide-form"><?= Csrf::field() ?></form><?php endif; ?>
  <?php endif; ?>

  <form method="post" action="/pricing/settings">
    <?= Csrf::field() ?>
    <fieldset><legend>Margin and Costs</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['margin_pct']) ?> *<small class="muted">Margin on the selling price, not markup: 20% on a cost of USD 1,000 gives USD 1,250. Nobody can quote below the minimum price.</small><span class="help-line">Example: cost USD 1,000 at 20% → minimum price USD 1,250 (1,000 ÷ 0.80). Nobody can sell below it.</span></span>
        <input type="number" name="margin_pct" min="0" max="90" step="0.01" required value="<?= View::e($settings['margin_pct']) ?>" placeholder="e.g. 15.00"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['other_cost_pct']) ?> *<small class="muted">Packing, inland transport to port, CHA, inspection, documents and bank charges not already in the supplier price. A product can set its own % or USD amount.</small><span class="help-line">Example: supplier price USD 975.61 + 2.5% = cost USD 1,000.00.</span></span>
        <input type="number" name="other_cost_pct" min="0" max="100" step="0.01" required value="<?= View::e($settings['other_cost_pct']) ?>" placeholder="e.g. 5.50"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['buffer_pct']) ?> *<small class="muted">Room to negotiate: the quote price is the floor plus this %.</small><span class="help-line">Example: lowest price USD 1,287.50 + 3% → quote price USD 1,326.13; the team may agree anywhere between the two.</span></span>
        <input type="number" name="buffer_pct" min="0" max="100" step="0.01" required value="<?= View::e($settings['buffer_pct']) ?>" placeholder="e.g. 3.00"<?= $__ro ?>></label>
    </fieldset>

    <?php // D-301 (point 4, 11 Nov 2026): Automatic (fetched daily) or Manual; pricing rate = market × (1 − cushion %). ?>
    <?php // D-340 (12 Nov 2026): the rate used right now, at the top; the field badges show which field is in use (app.js follows the mode drop-down). ?>
    <?php $__fxKey = $fx['mode'] === 'manual' ? 'manual' : ($fx['has_market'] ? 'auto-market' : 'auto-none'); $__fxHide = static fn (string $when): string => in_array($__fxKey, explode(' ', $when), true) ? '' : ' hidden'; ?>
    <fieldset id="exchange-rate-settings" data-fx-has-market="<?= $fx['has_market'] ? '1' : '0' ?>"><legend>Exchange Rate (USD → INR)</legend>
      <div class="fx-now fx-now-<?= View::e($fx['tone']) ?>" id="exchange-rate">
        <div class="fx-now-title">RATE USED FOR PRICES RIGHT NOW</div>
        <div class="fx-now-rate">₹<?= number_format((float) $fx['rate'], 2) ?> per USD <span class="fx-now-badge"><?= View::e($fx['badge']) ?></span></div>
        <?php if ($fx['state'] === 'auto'): ?>
          <div class="fx-now-facts">
            <div><span>Market rate</span><b>₹<?= number_format((float) $fx['market'], 2) ?></b></div>
            <div><span>Fetched</span><b><?= View::e((string) $fx['fetched']) ?></b></div>
            <div><span>Source</span><b><?= View::e((string) $fx['source']) ?></b></div>
            <div><span>Cushion</span><b><?= View::e((string) $fx['cushion_line']) ?></b></div>
          </div>
        <?php endif; ?>
        <?php foreach ($fx['warnings'] as $__w): ?><p class="fx-now-warning">⚠ <?= View::e($__w) ?></p><?php endforeach; ?>
        <?php if ($fx['fallback_hint'] !== null): ?><p class="fx-now-warning"><?= View::e($fx['fallback_hint']) ?></p><?php endif; ?>
        <?php if ($fx['compare'] !== null): ?><p class="fx-now-note"><?= View::e($fx['compare']) ?><?php if ($fx['above_note'] !== null): ?> <b>⚠ <?= View::e($fx['above_note']) ?></b><?php endif; ?></p><?php endif; ?>
        <?php if ($fx['manual_note'] !== null): ?><p class="fx-now-note"><?= View::e($fx['manual_note']) ?></p><?php endif; ?>
        <?php $fx['warnings'] = []; include __DIR__ . '/_fx_rate_line.php'; ?>
        <p class="fx-now-note">Prices on Quotations and Proforma Invoices already issued never change.</p>
        <?php if ($fx['waiting'] !== null): $__w = $fx['waiting']; ?>
          <div class="fx-now-waiting">
            <p><b>Waiting for the MD:</b> today's market rate ₹<?= number_format((float) $__w['market_rate'], 4) ?> (<?= View::e((string) $__w['source']) ?>, <?= View::e((string) $__w['rate_date']) ?>) moved <?= View::e(\App\Services\FxRateService::signed((float) $__w['change_pct'])) ?> — more than <?= View::e(rtrim(rtrim(number_format(\App\Services\FxRateService::JUMP_PCT, 1), '0'), '.')) ?>% in a day. It is not used for prices until approved.</p>
            <?php if ($canManage): ?>
              <div class="field-grid">
                <label>Reason *<input type="text" name="reason" form="fx-decide-form" required minlength="10" maxlength="255" placeholder="e.g. Checked with the bank — the move is real"></label>
                <div><button type="submit" form="fx-decide-form" name="decision" value="approve" class="btn-sm btn-success">Use this rate</button>
                  <button type="submit" form="fx-decide-form" name="decision" value="reject" class="btn-sm btn-secondary">Keep the rate in use</button></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="fx-now-actions">
          <?php if ($canManage): ?><button type="submit" form="fx-fetch-form" class="btn-sm">Fetch today's rate now</button><?php endif; ?>
          <a class="btn-sm" href="#fx-history">History (last 30 fetches)</a>
        </div>
      </div>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['fx_mode']) ?> *<small class="muted">Automatic: the market rate is fetched every day (Frankfurter — European Central Bank; backup open.er-api.com) and the cushion below is taken off. Manual: the rate typed below is used.</small><span class="help-line">Example: Automatic, market ₹88.40, cushion 2% → pricing rate ₹86.63.</span></span>
        <select name="fx_mode" required data-fx-mode<?= $__ro ?>>
          <option value="auto"<?= ($settings['fx_mode'] ?? 'auto') !== 'manual' ? ' selected' : '' ?>>Automatic — fetched every day</option>
          <option value="manual"<?= ($settings['fx_mode'] ?? 'auto') === 'manual' ? ' selected' : '' ?>>Manual — the rate typed below</option>
        </select></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['fx_cushion_pct']) ?> * <span class="fx-field-badge fx-field-badge-off" data-fx-when="manual"<?= $__fxHide('manual') ?>>not used in Manual</span><small class="muted">0 to 5 %, one decimal. Pricing rate = market rate × (1 − cushion %).</small><span class="help-line">Example: 2 → ₹88.40 × 0.98 = ₹86.63; 2.5 → ₹86.19.</span></span>
        <input type="number" name="fx_cushion_pct" min="0" max="5" step="0.1" required value="<?= View::e((string) ($settings['fx_cushion_pct'] ?? '2.0')) ?>" placeholder="e.g. 2.0" data-fx-muted-when="manual"<?= $__fxKey === 'manual' ? ' class="fx-unused"' : '' ?><?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label">Manual exchange rate (₹ per USD) * <span class="fx-field-badge fx-field-badge-off" data-fx-when="auto-market"<?= $__fxHide('auto-market') ?>>not used now — fall-back only</span><span class="fx-field-badge fx-field-badge-warn" data-fx-when="auto-none"<?= $__fxHide('auto-none') ?>>in use now</span><span class="fx-field-badge fx-field-badge-on" data-fx-when="manual"<?= $__fxHide('manual') ?>>in use now</span><small class="muted">Used in Manual mode, and in Automatic mode until the first rate has been fetched. Keep it a little below the market rate as a cushion.</small><span class="help-line">Example: market rate ₹83.25 → enter ₹82.00 (about 1.5% lower); ₹80,000 ÷ 82 = USD 975.61.</span></span>
        <input type="number" name="pricing_fx_rate" min="0.0001" step="0.0001" required value="<?= View::e($settings['pricing_fx_rate']) ?>" placeholder="e.g. 82.0000" data-fx-muted-when="auto-market"<?= $__fxKey === 'auto-market' ? ' class="fx-unused"' : '' ?><?= $__ro ?>></label>
      <details id="fx-history"><summary>History (last 30 fetches)</summary>
        <table class="list">
          <tr><th>Date</th><th class="num">Market rate (₹ per USD)</th><th>Source</th><th class="num">Change</th><th>Status</th><th>Note</th></tr>
          <?php foreach ($fxHistory as $__h): ?>
            <tr><td><?= View::e((string) $__h['rate_date']) ?></td>
              <td class="num"><?= $__h['market_rate'] !== null ? number_format((float) $__h['market_rate'], 4) : '—' ?></td>
              <td><?= View::e((string) ($__h['source'] ?? '—')) ?></td>
              <td class="num"><?= $__h['change_pct'] !== null ? View::e(\App\Services\FxRateService::signed((float) $__h['change_pct'])) : '—' ?></td>
              <td><?= ['in_use' => 'In use', 'waiting_md' => 'Waiting for the MD', 'rejected' => 'Not used (MD)', 'failed' => 'Fetch failed — last rate kept'][$__h['status']] ?? View::e((string) $__h['status']) ?></td>
              <td><?= View::e(trim((string) ($__h['note'] ?? '') . ($__h['decision_reason'] !== null ? ' ' . ($__h['decided_by_name'] ?? '') . ': ' . $__h['decision_reason'] : ''))) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($fxHistory === []): ?><tr><td colspan="6" class="muted">No rate fetched yet — the daily job fetches one every morning (see the README).</td></tr><?php endif; ?>
        </table>
      </details>
    </fieldset>

    <fieldset><legend>Team Incentive Reserve</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['incentive_mode']) ?> *<small class="muted">Added to the minimum price while the team incentive program is on.</small><span class="help-line">Example: % of profit — minimum USD 1,250, cost USD 1,000, 15% of the USD 250 profit = USD 37.50 reserved for the team.</span></span>
        <select name="incentive_mode" required<?= $__ro ?>>
          <option value="pct"<?= $settings['incentive_mode'] === 'pct' ? ' selected' : '' ?>>% of profit (minimum price − cost)</option>
          <option value="usd"<?= $settings['incentive_mode'] === 'usd' ? ' selected' : '' ?>>Fixed USD amount per unit</option>
        </select></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['incentive_value']) ?> *<small class="muted">e.g. 15 (% of profit) or 1.00 (USD per unit).</small><span class="help-line">Example: 15 → staff floor USD 1,250 + USD 37.50 = USD 1,287.50.</span></span>
        <input type="number" name="incentive_value" min="0" step="0.0001" required value="<?= View::e($settings['incentive_value']) ?>" placeholder="e.g. 15"<?= $__ro ?>></label>
    </fieldset>

    <fieldset><legend>Supplier Prices</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['stale_days']) ?> *<small class="muted">A supplier price older than this is marked "stale" on the product list; update it before quoting.</small><span class="help-line">Example: 60 → a price quoted on 1 Sep is stale from 31 Oct; "Pricing ready?" shows ✗ until it is renewed.</span></span>
        <input type="number" name="stale_days" min="1" max="3650" step="1" required value="<?= View::e($settings['stale_days']) ?>" placeholder="e.g. 60"<?= $__ro ?>></label>
    </fieldset>

    <?php // D-361 (13 Nov 2026, mockup p5): the global physical defaults — the last of the three levels. ?>
    <fieldset id="physical-defaults"><legend>Default physical values for every category and product</legend>
      <p class="muted small">Used when a category (Category price rules) or a product (Configure) has no value of its own.</p>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['weight_t_per_cbm']) ?> *<small class="muted">Stone weight, used for Ton prices and the container weight limit.</small><span class="help-line">Example: 2.70 → 8 CBM weigh 21.6 t.</span></span>
        <input type="number" name="weight_t_per_cbm" min="0.1" max="20" step="0.001" required value="<?= View::e((string) $settings['weight_t_per_cbm']) ?>" placeholder="e.g. 2.70"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['container_max_t']) ?> *<small class="muted">The most one container may weigh.</small><span class="help-line">Example: 27 → at 2.70 t per CBM a container holds at most 10 CBM by weight.</span></span>
        <input type="number" name="container_max_t" min="1" max="100" step="0.01" required value="<?= View::e((string) $settings['container_max_t']) ?>" placeholder="e.g. 27.00"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['container_cbm']) ?> *<small class="muted">What one container holds, used to spread CHA + transport per container.</small><span class="help-line">Example: 8 → ₹40,000 per container = ₹5,000 per CBM.</span></span>
        <input type="number" name="container_cbm" min="0.5" max="100" step="0.01" required value="<?= View::e((string) $settings['container_cbm']) ?>" placeholder="e.g. 8.00"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['thick_k_factor']) ?> *<small class="muted">50 mm and above is priced per CBM = the base thickness price per SQM × this factor.</small><span class="help-line">Example: 40 → ₹1,600 per SQM × 40 = ₹64,000 per CBM.</span></span>
        <input type="number" name="thick_k_factor" min="1" max="1000" step="0.001" required value="<?= View::e((string) $settings['thick_k_factor']) ?>" placeholder="e.g. 40"<?= $__ro ?>></label>
    </fieldset>

    <fieldset><legend>Order Lines</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['require_product_pick']) ?> <small class="muted">On: a goods line on a USD order must be picked from Products (so its price is checked against the floor); a goods line typed by hand is refused. Service and charge lines can still be typed. Off: goods lines may be typed by hand and are not price-checked.</small><span class="help-line">Example: on — typing "Granite slab" by hand on a USD order is refused; pick it from Products so its price is checked.</span></span>
        <select name="require_product_pick"<?= $__ro ?>>
          <option value="1"<?= (string) $settings['require_product_pick'] === '1' ? ' selected' : '' ?>>On — goods lines must come from Products</option>
          <option value="0"<?= (string) $settings['require_product_pick'] !== '1' ? ' selected' : '' ?>>Off — goods lines may be typed by hand</option>
        </select></label>
    </fieldset>

    <?php // Owner decision D-402 (21 Nov 2026): the calculated insurance on CIF orders. ?>
    <fieldset id="marine-insurance"><legend>Marine Insurance (CIF orders)</legend>
      <p class="muted small">Fills the Insurance amount on a CIF order: rate × cover % × CIF value (FOB + freight + insurance). Staff can change it on the order; the buyer is billed the actual premium by Debit Note. CFR and FOB orders have no insurance.</p>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['insurance_cover_pct']) ?> *<small class="muted">Incoterms 2020 minimum; LC banks expect 110 %.</small><span class="help-line">Example: 110 → a CIF value of EUR 5,468 is insured for EUR 6,015.</span></span>
        <input type="number" name="insurance_cover_pct" min="100" max="200" step="0.01" required value="<?= View::e((string) $settings['insurance_cover_pct']) ?>" placeholder="e.g. 110"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['insurance_rate_pct']) ?> *<small class="muted">ICC (A) all risks — used for categories without their own rate (Products › Categories).</small><span class="help-line">Example: 0.20 → EUR 12 on a CIF value of EUR 5,468 (rounded up).</span></span>
        <input type="number" name="insurance_rate_pct" min="0.0001" max="5" step="0.0001" required value="<?= View::e((string) $settings['insurance_rate_pct']) ?>" placeholder="e.g. 0.20"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['insurance_cover_wording']) ?> *<small class="muted">Printed on the Quotation and Proforma Invoice when the order's categories have no wording of their own.</small><span class="help-line">Example: ICC (A) all risks</span></span>
        <input type="text" name="insurance_cover_wording" maxlength="80" required value="<?= View::e((string) $settings['insurance_cover_wording']) ?>" placeholder="e.g. ICC (A) all risks"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['insurance_min_premium']) ?> <small class="muted">In the order's currency. Only if you buy a separate policy per shipment; leave empty for none.</small><span class="help-line">Example: 10 → a premium worked out at 6.40 becomes 10.</span></span>
        <input type="number" name="insurance_min_premium" min="0" max="1000000" step="0.01" value="<?= View::e((string) $settings['insurance_min_premium']) ?>" placeholder="e.g. 10 — leave empty for none"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['insurance_rounding']) ?> <small class="muted">How the worked-out premium is rounded.</small><span class="help-line">Example: 18.04 → 19.00 (up) or 18.04 (2 decimals).</span></span>
        <select name="insurance_rounding"<?= $__ro ?>>
          <option value="up"<?= (string) $settings['insurance_rounding'] !== '2dp' ? ' selected' : '' ?>>Round up to the next whole unit</option>
          <option value="2dp"<?= (string) $settings['insurance_rounding'] === '2dp' ? ' selected' : '' ?>>Round to 2 decimals</option>
        </select></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['insurance_add_gst']) ?> <small class="muted">The insurer charges 18 % GST to NexaCrest; exports under LUT can claim it back as input tax credit.</small><span class="help-line">Example: not added → 19.00; added → 22.00.</span></span>
        <select name="insurance_add_gst"<?= $__ro ?>>
          <option value="0"<?= (string) $settings['insurance_add_gst'] !== '1' ? ' selected' : '' ?>>Not added — NexaCrest's own cost (input tax credit)</option>
          <option value="1"<?= (string) $settings['insurance_add_gst'] === '1' ? ' selected' : '' ?>>Add 18 % to the buyer's figure</option>
        </select></label>
      <p class="muted small">Category rates (incl. breakage): <?php $__cr = []; foreach (\App\Services\MarineInsurance::categories() as $__c) { $__cr[] = View::e((string) $__c['name']) . ' ' . ($__c['insurance_rate_pct'] !== null ? View::e(rtrim(rtrim((string) $__c['insurance_rate_pct'], '0'), '.')) . ' %' : '— uses the default'); } echo implode(' · ', $__cr); ?>. <a href="/products/categories">Set per category</a></p>
    </fieldset>

    <fieldset><legend>Specifications and Associate Prices (D-255)</legend>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['cbm_from_mm']) ?> *<small class="muted">From this thickness the price is per CBM, from the product's per-CBM supplier price (Market prices). A product can set its own.</small><span class="help-line">Example: 50 → 20, 30 and 40 mm are priced per SQM; 50, 60, 80 mm per CBM (12.5 SQM in one CBM at 80 mm).</span></span>
        <input type="number" name="cbm_from_mm" min="1" max="2000" step="1" required value="<?= View::e($settings['cbm_from_mm']) ?>" placeholder="e.g. 50"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['price_request_daily_limit']) ?> *<small class="muted">Automatic price requests an associate may make in one day; non-standard requests to our team do not count. An associate can have their own limit.</small><span class="help-line">Example: 10 → the 11th automatic "Get price" that day is refused; a non-standard request still goes to staff.</span></span>
        <input type="number" name="price_request_daily_limit" min="0" max="1000" step="1" required value="<?= View::e($settings['price_request_daily_limit']) ?>" placeholder="e.g. 10"<?= $__ro ?>></label>
      <label class="setting-row"><span class="setting-label"><?= View::e($labels['price_request_valid_days']) ?> *<small class="muted">How long the price an associate gets stays valid.</small><span class="help-line">Example: 7 → a price given on 5 Nov is valid until 12 Nov; after that the associate asks again.</span></span>
        <input type="number" name="price_request_valid_days" min="1" max="365" step="1" required value="<?= View::e($settings['price_request_valid_days']) ?>" placeholder="e.g. 7"<?= $__ro ?>></label>
    </fieldset>

    <?php // D-410 (24 Nov 2026): the % of an associate's commission included in the client's price.
      $__ar = (string) $settings['associate_price_rule']; $__ar = isset(\App\Services\AssociateRules::PRICE_RULES[$__ar]) ? $__ar : 'fixed';
      $__ap = (float) $settings['associate_price_fixed_pct']; $__wi = $whatIf ?? null; ?>
    <fieldset id="associate-price-rule" data-testid="associate-price-rule-setting"><legend>Associate commission in the client's price (D-410)</legend>
      <p class="help-line">&#8505; An associate earns their full commission %. This setting decides how much of it is built into the price the client is quoted; any commission above it is paid from our margin. It applies to new prices only — orders with an approved Quotation or an issued Proforma Invoice keep their prices. Each associate can have their own choice (associate page › Commission).</p>
      <label class="inline"><input type="radio" name="associate_price_rule" value="starting"<?= $__ar === 'starting' ? ' checked' : '' ?><?= $__ro ?>> <strong>Starting rate</strong> (the first ladder step)</label>
      <p class="help-line" style="margin-left:1.6em"><?= View::e(\App\Services\AssociateRules::PRICE_RULE_TEXT['starting']) ?></p>
      <label class="inline"><input type="radio" name="associate_price_rule" value="fixed"<?= $__ar === 'fixed' ? ' checked' : '' ?><?= $__ro ?>> <strong>A fixed %</strong>
        <input type="number" name="associate_price_fixed_pct" min="0" max="50" step="0.01" value="<?= View::e(number_format($__ap, 2, '.', '')) ?>" placeholder="e.g. 5.00" aria-label="Fixed % included in the client's price" style="width:7em" data-required-when="associate_price_rule=fixed" data-required-when-message="Enter the % included in the client's price."<?= $__ro ?>> % (default)</label>
      <p class="help-line" style="margin-left:1.6em"><?= View::e(\App\Services\AssociateRules::PRICE_RULE_TEXT['fixed']) ?></p>
      <label class="inline"><input type="radio" name="associate_price_rule" value="ladder"<?= $__ar === 'ladder' ? ' checked' : '' ?><?= $__ro ?>> <strong>Ladder rate</strong> (each associate's current %)</label>
      <p class="help-line" style="margin-left:1.6em"><?= View::e(\App\Services\AssociateRules::PRICE_RULE_TEXT['ladder']) ?></p>
      <p class="small" style="border:1px solid #C9962B;padding:6px 8px" data-testid="associate-price-right-now"><strong>Right now:</strong>
        <?php if ($__ar === 'fixed'): ?>every client price includes <strong><?= View::e(PricingService::pct($__ap)) ?></strong>. Associates above it are paid the rest from our margin; associates below it have room to negotiate.
        <?php elseif ($__ar === 'starting'): ?>every client price includes the first ladder step. Associates above it are paid the rest from our margin.
        <?php else: ?>every client price includes the associate's current %; nothing comes from our margin, and the price rises as the associate climbs.<?php endif; ?>
        <?= (int) ($ownRuleCount ?? 0) > 0 ? View::e((string) (int) $ownRuleCount) . ' associate(s) follow their own choice (associate page › Commission).' : '' ?></p>
      <?php if ($__wi !== null): ?>
      <table class="list" style="max-width:760px" data-testid="associate-price-what-if"><caption style="text-align:left"><strong>What each choice would cost us</strong> — the last 12 months of associate orders (<?= (int) $__wi['orders'] ?>), with today's ladder</caption>
        <tr><th>Choice</th><th class="num">Paid from our margin in 12 months</th></tr>
        <tr<?= $__ar === 'starting' ? ' style="font-weight:700"' : '' ?>><td>Starting rate</td><td class="num">&#8377;<?= number_format((float) $__wi['starting'], 2) ?></td></tr>
        <tr<?= $__ar === 'fixed' ? ' style="font-weight:700"' : '' ?>><td>A fixed <?= View::e(PricingService::pct((float) $__wi['fixed_pct'])) ?></td><td class="num">&#8377;<?= number_format((float) $__wi['fixed'], 2) ?></td></tr>
        <tr<?= $__ar === 'ladder' ? ' style="font-weight:700"' : '' ?>><td>Ladder rate</td><td class="num">&#8377;0.00 <small class="muted">(the client's price rises instead)</small></td></tr>
      </table>
      <?php endif; ?>
      <label>Reason for changing this setting <small class="muted">(needed when the choice or the % changes; kept in the Audit Log)</small>
        <input type="text" name="associate_price_reason" minlength="10" maxlength="300" placeholder="e.g. margin too thin on high-volume associates"<?= $__ro ?>><small class="reason-hint muted">Must be at least 10 characters.</small></label>
    </fieldset>

    <?php if ($canManage): ?><button type="submit">Save settings</button><?php else: ?><p class="muted small">Only the MD can change these settings.</p><?php endif; ?>
  </form>

  <h2>Who Can Do What</h2>
  <table class="list">
    <tr><th>Permission</th><th>Default roles</th></tr>
    <tr><td>Record supplier / market prices and costs to FOB</td><td>Procurement (Executive, Manager, Director), MD</td></tr>
    <tr><td>See the full price build-up</td><td>MD, ED</td></tr>
    <tr><td>Change these settings, product overrides, commission levels and ladder, category specifications</td><td>MD only</td></tr>
    <tr><td>Change one product's specifications (hide, change, add for that product)</td><td>Roles with "Manage a product's specifications" — Admin and MD to start</td></tr>
  </table>
  <p><a class="btn-sm" href="/pricing/commission-levels">Associate commission levels and ladder</a> <a class="btn-sm" href="/products/price-rules">Category price rules</a> <a class="btn-sm" href="/associates/price-requests">Associate price requests</a></p>
</div>

<?php if (\App\Services\SalesTargetService::canManage()): // Owner decision D-378: a link card only; the settings live on their own page ?>
<div class="card" id="sales-targets-link">
  <h2 style="margin-top:0;">Sales targets settings</h2>
  <p class="muted">The company target, the target period and multiple, the build-up for new joiners, the review interval, the alert level and the default targets per designation.</p>
  <p><a class="btn-sm" href="/sales-targets/settings">Sales targets settings</a> <a class="btn-sm btn-secondary" href="/sales-targets">Sales targets</a></p>
</div>
<?php endif; ?>
