<?php
/**
 * Owner decisions 11 Nov 2026, points 8 and 9 (D-291 / D-292): the commission rule on the associate's
 * staff page. With "See associate commission rules": the rule, the % and level of the next order, the
 * counting orders so far ("3 of 5"), the steps and the plain counting rule; the MD (manage_pricing)
 * changes it. Everyone else: "Set by the MD." Expects $a, $id, $rule (null without the permission),
 * $canPricing. Node twin: views/associates/_commission_rule.njk.
 * D-410 (24 Nov 2026): "Ladder steps so far" with the new counting text, what the client's price includes for this
 * associate (company setting or their own, MD with a reason), the "Right now" line and "Paid from our margin so far".
 * Also expects $priceRule, $rightNow, $marginSoFar (null without the permission).
 */
use App\Services\AssociateRules;
use App\Helpers\Dates;
?>
  <div class="section" id="commission-rule">
    <h2>Commission<?= $rule !== null ? ' — ' . htmlspecialchars((string) $a['name']) : '' ?></h2>
    <?php if ((int) ($a['no_commission'] ?? 0) === 1): ?>
      <p><b>No commission</b> (introductions only).</p>
    <?php elseif ($rule === null): ?>
      <p data-commission-rule-hidden>Set by the MD. <small class="muted">(rule, levels and rates not shown)</small></p>
    <?php else: ?>
      <table class="kv" data-commission-rule>
        <tr><th>Rule</th><td><b><?= htmlspecialchars($rule['rule']) ?></b><?php if (!empty($rule['changed_at'])): ?> <small class="muted">(set <?= htmlspecialchars(Dates::short(substr((string) $rule['changed_at'], 0, 10))) ?><?= $rule['changed_by'] ? ' by ' . htmlspecialchars((string) $rule['changed_by']) : '' ?><?= $rule['reason'] ? ' — reason: "' . htmlspecialchars((string) $rule['reason']) . '"' : '' ?>)</small><?php endif; ?>
          <?php if ($rule['mode'] === 'ladder'): ?><br><small class="muted"><?= htmlspecialchars($rule['standard']) ?></small><?php endif; ?></td></tr>
        <tr><th>Next order earns</th><td><b class="stat-value"><?= htmlspecialchars($rule['rate']) ?></b><?= $rule['level_name'] ? ' — ' . htmlspecialchars((string) $rule['level_name']) : '' ?></td></tr>
        <?php if ($rule['mode'] !== 'fixed'): ?>
        <tr><th>Ladder steps so far <span class="help" title="Orders of ₹<?= number_format(AssociateRules::minOrderInr(), 0) ?> or more count as one step each once the advance has cleared. Orders of the same client within <?= AssociateRules::groupDays() ?> days of the first are one step.">ℹ</span></th><td><b><?= (int) $rule['count'] ?><?= $rule['of'] !== null ? ' of ' . (int) $rule['of'] : '' ?></b><?= $rule['next_rate'] !== null ? ' — ' . (int) $rule['more'] . ' more counting order' . ((int) $rule['more'] === 1 ? '' : 's') . ' to reach ' . htmlspecialchars((string) $rule['next_rate']) : ' — the top step' ?></td></tr>
        <?php endif; ?>
      </table>
      <?php if ($rule['mode'] !== 'fixed'): ?>
        <div class="stat-row" data-ladder-steps>
          <?php foreach ($rule['steps'] as $__s): ?>
            <div class="stat<?= $__s['current'] ? ' stat-current' : '' ?>"<?= $__s['current'] ? ' style="border:2px solid var(--accent, #c9962b)"' : '' ?>><div class="stat-label"><?= htmlspecialchars($__s['label']) ?></div>
              <div class="stat-value"><?= htmlspecialchars($__s['rate']) ?></div><small><?= htmlspecialchars((string) $__s['level_name']) ?><?= $__s['current'] ? ' · ← now' : '' ?></small></div>
          <?php endforeach; ?>
        </div>
        <p class="muted small" data-step-rule>An order of ₹<?= number_format(AssociateRules::minOrderInr(), 0) ?> or more (value of the goods, FOB) counts as one ladder step once its advance has cleared (LC orders: once the LC is confirmed; no advance and no LC: once the buyer has paid in full). Orders of the same client within <?= AssociateRules::groupDays() ?> days of the first order of the group are one step. A smaller order earns nothing and does not count — except the client's first order, which always earns. Cancelled orders never count.</p>
      <?php endif; ?>
      <?php if (!empty($priceRule)): ?>
        <h3 id="price-rule">Commission in the client's price <span class="help" title="How much commission the client's price already includes for this associate. Above it, the rest is paid from our margin.">ℹ</span></h3>
        <table class="kv" data-price-rule>
          <tr><th>Uses</th><td><b><?= htmlspecialchars(AssociateRules::PRICE_RULES[$priceRule['rule']]) ?><?= $priceRule['pct'] !== null ? ' — ' . htmlspecialchars(rtrim(rtrim(number_format((float) $priceRule['pct'], 2, '.', ''), '0'), '.')) . '%' : '' ?></b> <small class="muted"><?= $priceRule['own'] ? '(this associate\'s own choice)' : '(the company setting — Admin → Pricing)' ?></small></td></tr>
          <tr><th>Right now</th><td data-right-now><?= htmlspecialchars((string) $rightNow) ?></td></tr>
          <?php if ($marginSoFar !== null): ?><tr><th>Paid from our margin so far <span class="help" title="This financial year (from 1 April): the commission above the % in the client's price, on orders whose % was fixed at the Proforma Invoice.">ℹ</span></th><td data-margin-so-far>₹<?= number_format((float) $marginSoFar['inr'], 2) ?> <small class="muted">on <?= (int) $marginSoFar['orders'] ?> order<?= (int) $marginSoFar['orders'] === 1 ? '' : 's' ?> this financial year</small></td></tr><?php endif; ?>
        </table>
        <?php if ($canPricing): ?>
          <details class="card"><summary>Change for this associate</summary>
          <form method="post" action="/associates/<?= $id ?>/price-rule" data-price-rule-form>
            <?= \App\Helpers\Csrf::field() ?>
            <div class="choice-group"><label><input type="radio" name="price_rule" value="company"<?= !$priceRule['own'] ? ' checked' : '' ?> required> Use the company setting</label>
              <span class="choice-help">Follows Admin › Pricing › Settings (today: <?= htmlspecialchars(AssociateRules::PRICE_RULES[AssociateRules::companyPriceRule()['rule']]) ?>).</span></div>
            <?php foreach (AssociateRules::PRICE_RULES as $__k => $__l): ?>
              <div class="choice-group"><label><input type="radio" name="price_rule" value="<?= $__k ?>"<?= $priceRule['own'] && $priceRule['rule'] === $__k ? ' checked' : '' ?>> <?= htmlspecialchars($__l) ?></label>
                <span class="choice-help"><?= htmlspecialchars(AssociateRules::PRICE_RULE_TEXT[$__k]) ?></span>
                <?php if ($__k === 'fixed'): ?><label class="choice-extra">Fixed % <input type="number" name="price_rule_fixed_pct" min="0" max="50" step="0.01" placeholder="e.g. 5" style="width:7em" aria-label="Fixed % included in the client's price" data-required-when="price_rule=fixed" value="<?= $priceRule['own'] && $priceRule['rule'] === 'fixed' ? htmlspecialchars((string) $priceRule['pct']) : '' ?>"> %</label><?php endif; ?></div>
            <?php endforeach; ?>
            <label>Reason for the change *<input type="text" name="price_rule_reason" required minlength="10" maxlength="255" placeholder="e.g. Agreed with the associate on 24 Nov for the new market"></label>
            <p class="muted small">New prices only: orders whose prices are already fixed keep them. Kept in the Audit Log.</p>
            <button type="submit">Save</button>
          </form>
          </details>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($canPricing): ?>
        <div class="btn-row">
          <?php if ($rule['mode'] === 'own_ladder'): ?>
            <a class="btn-sm" href="/associates/<?= $id ?>/commission-rule?rule=own_ladder">Edit own ladder</a>
            <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/commission-rule?rule=ladder">Remove own ladder — use the standard ladder</a>
            <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/commission-rule?rule=fixed">Set a fixed %</a>
          <?php elseif ($rule['mode'] === 'fixed'): ?>
            <a class="btn-sm" href="/associates/<?= $id ?>/commission-rule?rule=fixed">Change the fixed %</a>
            <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/commission-rule?rule=ladder">Use the standard ladder</a>
            <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/commission-rule?rule=own_ladder">+ Add own ladder for this associate</a>
          <?php else: ?>
            <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/commission-rule?rule=own_ladder">+ Add own ladder for this associate</a>
            <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/commission-rule?rule=fixed">Set a fixed %</a>
          <?php endif; ?>
        </div>
        <p class="muted small">"Add own ladder" only when needed: it opens a copy of the standard ladder to change. Changes apply to new orders only (an order keeps the % fixed at its Proforma Invoice); a reason is needed and kept in the Audit Log.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
