<?php
/**
 * Owner decision D-379 (15 Nov 2026): Roles and permissions › <role> › Reports tab (manage_report_access).
 * One row per report, grouped in the 7 sections of the Reports page: a compact Off / Own / Department / All
 * choice (no extra label per D-366), what the report needs in plain words (✓ green when the role has it, ⚠ red
 * "missing for this role" when the level set needs it and the role lacks it — D-379 cosmetic follow-up); a reason is
 * required to save (audit REPORT_ACCESS_CHANGED, one row per changed report). "See Reports as this role" and
 * "Reset to defaults" (POST, reason required). One view for the Standard and the Kanban template.
 * Node twin: NODE_MySQL_Version/app/views/permission_admin/role_reports.njk
 */
use App\Helpers\Csrf;
use App\Services\ReportAccess;
$__roleId = (int) $role['id'];
?>
<div class="card page-wide" id="role-reports">
  <p class="muted small"><a href="/admin/permissions">&larr; Back to Roles &amp; Permissions</a></p>
  <h1>Report access — <?= htmlspecialchars($role['name']) ?></h1>
  <div class="btn-row" role="tablist" aria-label="Role settings">
    <?php if (\App\Helpers\PermittedLinks::currentUserHas('manage_permissions')): ?><a class="btn-sm btn-secondary" role="tab" href="/admin/roles/<?= $__roleId ?>/permissions">Permissions</a><?php endif; ?>
    <a class="btn-sm btn-accent" role="tab" aria-selected="true" href="/admin/roles/<?= $__roleId ?>/reports">Reports</a>
  </div>
  <p class="muted">How far each report reaches for this role. <strong>Own</strong>: orders the person is assigned to, owns for their department or is the salesperson of — and their clients and own figures. <strong>Department</strong>: also the orders of the people of their departments. <strong>All</strong>: every order. <strong>Off</strong>: not in their menu or Reports page, and the page refuses them. A person with several roles gets the highest level of their roles; a level still needs the report's own permission (money is never shown without it).</p>
  <div class="btn-row">
    <a class="btn-sm btn-secondary" id="see-reports-as-role" href="/admin/roles/<?= $__roleId ?>/reports/preview">See Reports as this role</a>
  </div>

  <form method="post" action="/admin/roles/<?= $__roleId ?>/reports" id="role-reports-form">
    <?= Csrf::field() ?>
    <div class="table-scroll">
    <table class="list" id="role-reports-table">
      <tr><th>Report</th><?php foreach (ReportAccess::LEVELS as $__l): ?><th><?= htmlspecialchars(ReportAccess::LEVEL_LABELS[$__l]) ?></th><?php endforeach; ?><th>Needs</th><th>Default</th></tr>
      <?php foreach ($sections as $__section => $__rows): ?>
      <tr class="report-section-row"><th colspan="7"><?= htmlspecialchars(ReportAccess::SECTIONS[$__section]) ?></th></tr>
      <?php foreach ($__rows as $__row): ?>
      <tr data-report="<?= htmlspecialchars($__row['key']) ?>">
        <td><?= htmlspecialchars($__row['label']) ?></td>
        <?php foreach (ReportAccess::LEVELS as $__l): ?>
        <td><input type="radio" name="levels[<?= htmlspecialchars($__row['key']) ?>]" value="<?= $__l ?>" <?= $__row['level'] === $__l ? 'checked' : '' ?> aria-label="<?= htmlspecialchars($__row['label'] . ': ' . ReportAccess::LEVEL_LABELS[$__l]) ?>"></td>
        <?php endforeach; ?>
        <td class="small report-needs">
          <?php foreach ($__row['needsItems'] as $__need): ?>
            <?php if ($__need['has']): ?><span class="need need-ok">&#10003; <?= htmlspecialchars($__need['text']) ?></span>
            <?php elseif ($__need['missing']): ?><span class="need need-missing">&#9888; <?= htmlspecialchars($__need['text']) ?> — missing for this role</span>
            <?php else: ?><span class="need muted"><?= htmlspecialchars($__need['text']) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </td>
        <td class="small muted"><?= htmlspecialchars(ReportAccess::LEVEL_LABELS[$defaults[$__row['key']]]) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </table>
    </div>
    <label>Reason for this change *
      <input type="text" name="reason" required minlength="10" maxlength="500" data-reason-hint="on-error" placeholder="e.g. Sales managers see their department's payments from December">
    </label>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save report access</button></div>
  </form>

  <form method="post" action="/admin/roles/<?= $__roleId ?>/reports/reset" id="role-reports-reset" class="section" onsubmit="return confirm('Put every report of this role back to its default level?');">
    <?= Csrf::field() ?>
    <h2>Reset to defaults</h2>
    <p class="muted small">Every report of this role goes back to the level in the "Default" column (Off for a role that is not in the defaults).</p>
    <label>Reason for the reset *
      <input type="text" name="reason" required minlength="10" maxlength="500" data-reason-hint="on-error" placeholder="e.g. Undo the test changes of this week">
    </label>
    <div class="btn-row"><button type="submit" class="btn-sm btn-danger">Reset to defaults</button></div>
  </form>
</div>
