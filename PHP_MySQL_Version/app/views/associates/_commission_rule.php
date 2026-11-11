<?php
/**
 * Owner decisions 11 Nov 2026, points 8 and 9 (D-291 / D-292): the commission rule on the associate's
 * staff page. With "See associate commission rules": the rule, the % and level of the next order, the
 * counting orders so far ("3 of 5"), the steps and the plain counting rule; the MD (manage_pricing)
 * changes it. Everyone else: "Set by the MD." Expects $a, $id, $rule (null without the permission),
 * $canPricing. Node twin: views/associates/_commission_rule.njk.
 */
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
        <tr><th>Orders counted so far</th><td><b><?= (int) $rule['count'] ?><?= $rule['of'] !== null ? ' of ' . (int) $rule['of'] : '' ?></b><?= $rule['next_rate'] !== null ? ' — ' . (int) $rule['more'] . ' more counting order' . ((int) $rule['more'] === 1 ? '' : 's') . ' to reach ' . htmlspecialchars((string) $rule['next_rate']) : ' — the top step' ?></td></tr>
        <?php endif; ?>
      </table>
      <?php if ($rule['mode'] !== 'fixed'): ?>
        <div class="stat-row" data-ladder-steps>
          <?php foreach ($rule['steps'] as $__s): ?>
            <div class="stat<?= $__s['current'] ? ' stat-current' : '' ?>"<?= $__s['current'] ? ' style="border:2px solid var(--accent, #c9962b)"' : '' ?>><div class="stat-label"><?= htmlspecialchars($__s['label']) ?></div>
              <div class="stat-value"><?= htmlspecialchars($__s['rate']) ?></div><small><?= htmlspecialchars((string) $__s['level_name']) ?><?= $__s['current'] ? ' · ← now' : '' ?></small></div>
          <?php endforeach; ?>
        </div>
        <p class="muted small">An order counts once the buyer has paid in full and its invoiced FOB value is ₹<?= number_format((float) $rule['threshold_inr'], 0) ?> or more. A smaller order earns the current % but does not count.</p>
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
