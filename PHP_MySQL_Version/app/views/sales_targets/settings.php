<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\SalesTargetService;

/**
 * Owner decision D-378 (15 Nov 2026): Sales targets settings (manage_sales_targets). The company
 * target (₹) only for holders of view_price_buildup as well ($canMoney).
 * Node twin: NODE_MySQL_Version/app/views/sales_targets/settings.njk
 */
$__money = static fn ($v): string => $v === null ? '' : number_format((float) $v, 2, '.', '');
// Containers and the multiple are shown as on the Targets page: 3 not 3.0, 2.5 stays 2.5.
$__num = static fn ($v): string => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
?>
<div class="card page-wide" id="sales-target-settings">
  <p class="muted small"><a href="/sales-targets">&larr; Sales targets</a> · <a href="/pricing/settings">Pricing settings</a></p>
  <h1>Sales targets settings</h1>
  <p class="muted">The company target, the target period, the multiple used by the calculator, the build-up for new joiners, the review interval and the alert level; and the default targets per designation. Every save needs a reason and is in the Audit Log. Order data is never changed here.</p>

  <form method="post" action="/sales-targets/settings">
    <?= Csrf::field() ?>
    <?php if ($canMoney): ?>
    <fieldset><legend>Company target (₹ per month)</legend>
      <p class="muted small">Needed in a period = (costs + profit) × the months of the period. Leave both empty to switch the company target off. A change starts in the month you choose; earlier periods keep the figures that applied to them.</p>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Company costs per month (₹)</span><small class="muted setting-help">Salaries, rent and every other running cost.</small></span>
        <input type="number" name="costs_monthly" min="0" step="0.01" value="<?= View::e($__money($company['costs_monthly'] ?? null)) ?>" placeholder="e.g. 1500000.00"></label>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Profit wanted per month (₹)</span><small class="muted setting-help">On top of the costs.</small></span>
        <input type="number" name="profit_monthly" min="0" step="0.01" value="<?= View::e($__money($company['profit_monthly'] ?? null)) ?>" placeholder="e.g. 500000.00"></label>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Effective from (month)</span><small class="muted setting-help">Needed when costs or profit are given.</small></span>
        <input type="month" name="effective_from" value="<?= View::e(isset($company['effective_from']) ? substr((string) $company['effective_from'], 0, 7) : '') ?>"></label>
    </fieldset>
    <?php else: ?>
    <p class="muted small">The company target (₹) is shown only to people who may also see the full price build-up.</p>
    <?php endif; ?>

    <fieldset><legend>Targets</legend>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Target period *</span><small class="muted setting-help">Targets, counts and alerts are per period.</small></span>
        <select name="period" required>
          <?php foreach (SalesTargetService::PERIOD_TYPES as $k => $label): ?>
            <option value="<?= View::e($k) ?>"<?= $settings['period'] === $k ? ' selected' : '' ?>><?= View::e($label) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Target multiple *</span><small class="muted setting-help">Used only by the calculator on the Targets page: a person should bring in this many times their cost. A person can have their own.</small></span>
        <input type="number" name="multiple" min="1" max="10" step="0.1" required value="<?= View::e($__num($settings['multiple'])) ?>" placeholder="e.g. 3"></label>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Build-up months *</span><small class="muted setting-help">A new joiner's target in their first month is 0, then half until this many months, then full. 0 or 1 = no build-up.</small></span>
        <input type="number" name="buildup_months" min="0" max="6" step="1" required value="<?= View::e((string) $settings['buildup_months']) ?>" placeholder="e.g. 3"></label>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Review every … months *</span><small class="muted setting-help">A person's target not reviewed for this long gives an alert.</small></span>
        <input type="number" name="review_months" min="3" max="24" step="1" required value="<?= View::e((string) $settings['review_months']) ?>" placeholder="e.g. 12"></label>
      <label class="setting-row"><span class="setting-label"><span class="setting-name">Alert below … % *</span><small class="muted setting-help">From half-way through the period: an alert when containers shipped or confirmed are below this share of the containers needed.</small></span>
        <input type="number" name="alert_pct" min="1" max="100" step="1" required value="<?= View::e((string) $settings['alert_pct']) ?>" placeholder="e.g. 60"></label>
    </fieldset>

    <fieldset><legend>Default targets per designation (per period)</legend>
      <p class="muted small">Used for everyone of that designation who has no own target. Empty = no target. Quotations are shown to a person during their build-up.</p>
      <div class="table-scroll">
      <table class="list">
        <tr><th>Department</th><th>Designation</th><th>Containers</th><th>New buyers</th><th>Quotations</th></tr>
        <?php foreach ($designations as $d): $__t = $defaults[(int) $d['id']] ?? null; $__id = (int) $d['id']; ?>
        <tr>
          <td><?= View::e((string) $d['department_name']) ?></td>
          <td><?= View::e((string) $d['title']) ?></td>
          <td><input type="number" name="designation[<?= $__id ?>][containers]" min="0" step="0.1" value="<?= View::e($__num($__t['containers'] ?? null)) ?>" placeholder="e.g. 6" aria-label="<?= View::e($d['title'] . ' — containers') ?>"></td>
          <td><input type="number" name="designation[<?= $__id ?>][new_buyers]" min="0" step="1" value="<?= View::e($__t['new_buyers'] ?? '') ?>" placeholder="e.g. 1" aria-label="<?= View::e($d['title'] . ' — new buyers') ?>"></td>
          <td><input type="number" name="designation[<?= $__id ?>][quotations]" min="0" step="1" value="<?= View::e($__t['quotations'] ?? '') ?>" placeholder="e.g. 10" aria-label="<?= View::e($d['title'] . ' — quotations') ?>"></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($designations === []): ?><tr><td colspan="5" class="muted">No designations yet (Admin › Departments).</td></tr><?php endif; ?>
      </table>
      </div>
    </fieldset>

    <label>Reason for this change *<input type="text" name="reason" required minlength="10" data-reason-hint="on-error" maxlength="500" placeholder="e.g. New targets for the next quarter agreed with the team"></label>
    <button type="submit">Save settings</button>
  </form>
  <p class="muted small">Who is told about the target alerts: Admin › <a href="/admin/email-settings">E-mail settings</a>, section E.</p>
</div>
