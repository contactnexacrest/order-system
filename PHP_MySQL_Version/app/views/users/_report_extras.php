<?php
// Owner decision D-379: extra report access for one person — a higher level for one report than their roles
// give, with the reason. Shown only to people holding manage_report_access (seeded: MD, ED).
// Node twin: views/users/_report_extras.njk.
use App\Helpers\Csrf;
use App\Helpers\PermittedLinks;
use App\Services\ReportAccess;

if (PermittedLinks::currentUserHas('manage_report_access')):
    $__rx = ReportAccess::userExtras((int) $target['id']);
    $__gives = ReportAccess::roleGivesFor((int) $target['id'], $target['role_id'] !== null ? (int) $target['role_id'] : null);
?>
<div class="card" id="report-extras" data-report-extras>
  <h2>Extra report access for this person</h2>
  <p class="muted small">A higher level for one report than their roles give — for example while they cover for a colleague. It only ever raises the level; the report's own permission (and money permission) still applies.</p>
  <?php if ($__rx === []): ?>
    <p class="muted">None.</p>
  <?php else: ?>
  <table class="list" id="report-extras-list">
    <tr><th>Report</th><th>Role gives</th><th>Person gets</th><th>Reason</th><th>Set by / on</th><th></th></tr>
    <?php foreach ($__rx as $__key => $__x): if (!ReportAccess::isReport((string) $__key)) continue; ?>
    <tr data-report="<?= htmlspecialchars((string) $__key) ?>">
      <td><?= htmlspecialchars(ReportAccess::REPORTS[$__key]['label']) ?></td>
      <td><?= htmlspecialchars(ReportAccess::LEVEL_LABELS[$__gives[$__key]]) ?></td>
      <td><?= htmlspecialchars(ReportAccess::LEVEL_LABELS[(string) $__x['level']] ?? (string) $__x['level']) ?></td>
      <td><?= htmlspecialchars((string) $__x['reason']) ?></td>
      <td class="small"><?= htmlspecialchars((string) ($__x['created_by_name'] ?? '—')) ?> · <?= htmlspecialchars(\App\Helpers\Dates::short((string) $__x['created_at'])) ?></td>
      <td>
        <form method="post" action="/users/<?= (int) $target['id'] ?>/report-extras/remove" class="inline-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="report_key" value="<?= htmlspecialchars((string) $__key) ?>">
          <button type="submit" class="btn-sm btn-secondary" aria-label="Remove the extra access to <?= htmlspecialchars(ReportAccess::REPORTS[$__key]['label']) ?>">Remove</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <form method="post" action="/users/<?= (int) $target['id'] ?>/report-extras" class="field-grid" id="report-extras-add" style="margin-top:10px">
    <?= Csrf::field() ?>
    <label>Report *
      <select name="report_key" required data-report-extra-report>
        <option value="">Choose…</option>
        <?php foreach (ReportAccess::REPORTS as $__key => $__r): if ($__gives[$__key] === ReportAccess::ALL) continue; ?>
          <option value="<?= htmlspecialchars($__key) ?>" data-role-gives="<?= htmlspecialchars($__gives[$__key]) ?>"><?= htmlspecialchars($__r['label']) ?> (role gives <?= htmlspecialchars(ReportAccess::LEVEL_LABELS[$__gives[$__key]]) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Level *
      <select name="level" required data-report-extra-level>
        <option value="">Choose…</option>
        <?php foreach ([ReportAccess::OWN, ReportAccess::DEPARTMENT, ReportAccess::ALL] as $__l): ?>
          <option value="<?= $__l ?>" data-rank="<?= ReportAccess::rank($__l) ?>"><?= htmlspecialchars(ReportAccess::LEVEL_LABELS[$__l]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Reason *
      <input type="text" name="reason" required minlength="10" maxlength="500" data-reason-hint="on-error" placeholder="e.g. Covers the Sales/Export Manager's payments follow-up in December">
    </label>
    <div><button type="submit" class="btn-sm">Add extra access</button></div>
  </form>
</div>
<script>
// D-379: the Level list offers only the levels above what the person's roles give for the chosen report
// (the server refuses a lower one as well).
(function () {
  var form = document.getElementById('report-extras-add');
  if (!form) { return; }
  var report = form.querySelector('[data-report-extra-report]');
  var level = form.querySelector('[data-report-extra-level]');
  var ranks = { off: 0, own: 1, department: 2, all: 3 };
  function sync() {
    var opt = report.options[report.selectedIndex];
    var gives = ranks[(opt && opt.getAttribute('data-role-gives')) || 'off'] || 0;
    Array.prototype.forEach.call(level.options, function (o) {
      if (!o.value) { return; }
      var allowed = parseInt(o.getAttribute('data-rank'), 10) > gives;
      o.hidden = !allowed;
      o.disabled = !allowed;
      if (!allowed && o.selected) { level.value = ''; }
    });
  }
  report.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
