<?php
/**
 * Owner decisions 11 Nov 2026, point 8 (D-291): the MD sets an associate's commission rule —
 * Standard ladder (default) / Own ladder (a copy of the standard ladder, edited; it overrides it) /
 * Fixed %. A reason is needed (Audit Log); it applies to new orders only.
 * Node twin: views/associates/commission_rule.njk.
 */
use App\Helpers\Csrf;
use App\Services\AssociateCommission;
use App\Services\PricingService;
?>
<div class="card page-wide">
  <h1>Commission rule — <?= htmlspecialchars((string) $a['name']) ?></h1>
  <p class="muted">Now: <b><?= htmlspecialchars(AssociateCommission::RULE_LABELS[$a['commission_mode']] ?? 'Standard ladder') ?></b>. The standard ladder: <?= htmlspecialchars(AssociateCommission::ladderSummary($standard)) ?>.
    A change applies to new orders only — an order keeps the % fixed on it when its Proforma Invoice was issued.</p>
  <form method="post" action="/associates/<?= (int) $a['id'] ?>/commission-rule">
    <?= Csrf::field() ?>
    <fieldset><legend>Rule *</legend>
      <?php foreach (AssociateCommission::RULE_LABELS as $__k => $__l): ?>
        <label class="checkbox-label"><input type="radio" name="rule" value="<?= $__k ?>" required<?= $chosen === $__k ? ' checked' : '' ?>> <?= htmlspecialchars($__l) ?>
          <small class="muted"><?= ['ladder' => '— the default for every associate', 'own_ladder' => '— a copy of the standard ladder, changed for this associate; it overrides the standard ladder', 'fixed' => '— the same % on every order'][$__k] ?></small></label>
      <?php endforeach; ?>
    </fieldset>

    <fieldset><legend>Fixed %</legend>
      <label>Commission level for a fixed %<select name="commission_level_id" data-required-when="rule=fixed" data-required-when-message="Choose the commission level (its %).">
        <option value="">Choose…</option>
        <?php foreach ($levels as $__l): if ((int) $__l['is_active'] !== 1 && (int) $__l['id'] !== (int) ($a['commission_level_id'] ?? 0)) { continue; } ?>
          <option value="<?= (int) $__l['id'] ?>"<?= (int) $__l['id'] === (int) ($a['commission_level_id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars(PricingService::levelLabel($__l) . ' (' . $__l['name'] . ')') ?></option>
        <?php endforeach; ?>
      </select></label>
    </fieldset>

    <fieldset><legend>Own ladder <small class="muted">(used with "Own ladder"; steps start at order 1 and run on without gaps; leave the last "to" empty)</small></legend>
      <table class="list">
        <tr><th>From order no.</th><th>To order no.</th><th>Level</th><th>Remove</th></tr>
        <?php foreach ($rows as $__i => $__s): ?>
          <tr>
            <td><input type="number" name="from[<?= $__i ?>]" min="1" step="1" value="<?= htmlspecialchars((string) $__s['from_order']) ?>" placeholder="e.g. 1" aria-label="From order number, step <?= $__i + 1 ?>"></td>
            <td><input type="number" name="to[<?= $__i ?>]" min="1" step="1" value="<?= htmlspecialchars((string) ($__s['to_order'] ?? '')) ?>" placeholder="empty = onward" aria-label="To order number, step <?= $__i + 1 ?> (empty = onward)"></td>
            <td><select name="level_id[<?= $__i ?>]" aria-label="Level, step <?= $__i + 1 ?>"><option value="">—</option>
              <?php foreach ($levels as $__l): ?><option value="<?= (int) $__l['id'] ?>"<?= (string) $__s['level_id'] === (string) $__l['id'] ? ' selected' : '' ?>><?= htmlspecialchars($__l['name'] . ' — ' . PricingService::levelLabel($__l)) ?><?= (int) $__l['is_active'] === 1 ? '' : ' (inactive)' ?></option><?php endforeach; ?>
            </select></td>
            <td><?php if ((string) $__s['from_order'] !== ''): ?><label class="inline"><input type="checkbox" name="remove[<?= $__i ?>]" value="1"> Remove</label><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </fieldset>

    <label>Reason * <small class="muted">(kept in the Audit Log)</small><input type="text" name="reason" required minlength="10" maxlength="300" placeholder="e.g. Strong partner in UAE — agreed 10 Nov"></label>
    <div class="btn-row"><button type="submit">Save the rule</button> <a href="/associates/<?= (int) $a['id'] ?>#commission-rule">Cancel</a></div>
  </form>
</div>
