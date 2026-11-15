<?php
/**
 * Owner decision D-379 (15 Nov 2026): the Reports page — 7 section boxes in a fixed order, each with
 * only the reports this person may open (App\Services\ReportAccess; button text = the report's label),
 * and a banner when every open order report shows only their own business or their department's.
 * With $previewRole set it is "See Reports as this role": the same boxes, read-only (no links, no data).
 * One view for the Standard and the Kanban template. Node twin: NODE_MySQL_Version/app/views/reports/index.njk
 */
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\ReportAccess;
$__u = AuthService::currentUser();
$__uid = $__u ? (int) $__u['id'] : 0;
$__preview = $previewRole !== null;
$__button = static function (string $key) use ($__preview): string {
    $r = ReportAccess::REPORTS[$key];
    $label = htmlspecialchars($r['label']);
    if ($__preview || $r['href'] === null) {
        return '<span class="btn-sm btn-secondary" aria-disabled="true">' . $label . '</span>';
    }
    return '<a class="btn-sm" href="' . htmlspecialchars($r['href']) . '">' . $label . '</a>';
};
?>
<div class="card page-wide" id="reports-page">
  <?php if ($__preview): ?>
    <div class="alert alert-info" id="reports-preview-banner">Preview as <?= htmlspecialchars((string) $previewRole['name']) ?> — what this role sees on the Reports page (its own levels and permissions; a person's extra report access is not included). Read-only. <a href="/admin/roles/<?= (int) $previewRole['id'] ?>/reports">Back to the Reports tab</a></div>
  <?php endif; ?>
  <h1>Reports</h1>
  <p class="muted">Per-order reports are one click from the order's own screen ("Full report").</p>
  <?php if ($banner !== null): ?>
    <div class="alert alert-info" id="reports-scope-banner"><?= htmlspecialchars($banner) ?></div>
  <?php endif; ?>
  <?php if ($sections === []): ?>
    <p class="muted">No report is open to <?= $__preview ? 'this role' : 'you' ?>.</p>
  <?php endif; ?>

  <?php foreach ($sections as $__section => $__keys): ?>
  <div class="section report-section" id="reports-<?= htmlspecialchars($__section) ?>" data-report-section="<?= htmlspecialchars($__section) ?>">
    <h2><?= htmlspecialchars(ReportAccess::SECTIONS[$__section]) ?></h2>
    <?php foreach ($__keys as $__key): $__r = ReportAccess::REPORTS[$__key]; ?>
    <div class="report-item" data-report="<?= htmlspecialchars($__key) ?>" style="margin:0 0 0.75rem">
      <?php if ($__key === 'order_search'): ?>
        <strong><?= htmlspecialchars($__r['label']) ?></strong> <span class="muted small"><?= htmlspecialchars($__r['description']) ?></span>
        <?php if (!$__preview): ?>
        <form method="get" action="/reports" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:4px">
          <input type="text" name="q" value="<?= htmlspecialchars($searchQuery ?? '') ?>" placeholder="e.g., NC/SC/ORD/26/0411001 or Test Company Ltd" aria-label="Order reference or client name" required maxlength="100" style="width:280px;">
          <button type="submit" class="btn-sm">Search</button>
        </form>
        <?php if (($searchQuery ?? '') !== ''): ?>
          <table class="list" style="margin-top:0.75rem;">
            <tr><th>Order Ref</th><th>Client</th><th>Status</th><th>Created</th><th></th></tr>
            <?php foreach ($searchResults as $sr): ?>
            <tr>
              <td><?= htmlspecialchars($sr['order_reference']) ?></td>
              <td><?= htmlspecialchars($sr['company_legal_name']) ?></td>
              <td><?= htmlspecialchars(ucfirst($sr['status'])) ?></td>
              <td><?= htmlspecialchars($sr['created_at']) ?></td>
              <td><a href="/reports/order/<?= (int) $sr['id'] ?>">Full Report</a> · <a href="/orders/<?= (int) $sr['id'] ?>">View Order</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($searchResults)): ?><tr><td colspan="5" class="muted">No orders match "<?= htmlspecialchars($searchQuery) ?>".</td></tr><?php endif; ?>
          </table>
        <?php endif; ?>
        <?php endif; ?>
      <?php elseif ($__key === 'per_client'): ?>
        <?php if ($__preview): ?>
          <?= $__button($__key) ?> <span class="muted small"><?= htmlspecialchars($__r['description']) ?></span>
        <?php else: ?>
        <details id="per-client">
          <summary class="btn-sm"><?= htmlspecialchars($__r['label']) ?></summary>
          <table class="list" style="margin-top:0.5rem">
            <tr><th>Buyer Inquiry Ref</th><th>Company</th><th></th></tr>
            <?php foreach ($clients as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['client_unique_number']) ?></td>
              <td><?= htmlspecialchars($c['company_legal_name']) ?></td>
              <td><a href="/reports/client/<?= (int) $c['id'] ?>">Run Report</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($clients)): ?><tr><td colspan="3" class="muted">No clients yet.</td></tr><?php endif; ?>
          </table>
        </details>
        <span class="muted small"><?= htmlspecialchars($__r['description']) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <?= $__button($__key) ?> <span class="muted small"><?= htmlspecialchars($__r['description']) ?></span>
        <?php if ($__key === 'incentives' && !$__preview): ?>
          <span class="small"><a href="/reports/incentives/to-pay">To pay</a> · <a href="/reports/incentives/owed">Owed at month end</a> · <a href="/reports/incentives/statement">Person statement</a></span>
        <?php endif; ?>
        <?php if ($__key === 'aggregate' && !$__preview): ?>
        <div class="saved-reports" id="saved-reports" style="margin-top:0.5rem">
          <strong class="small">Saved reports</strong>
          <?php if (empty($savedReports)): ?>
            <p class="muted small">No saved reports yet — save one from the Aggregate report screen.</p>
          <?php else: ?>
            <table class="list">
              <tr><th>Name</th><th>Type</th><th>Owner</th><th>Visibility</th><th>Last Run</th><th></th></tr>
              <?php foreach ($savedReports as $r): ?>
              <tr>
                <?php if ((int) $r['owner_user_id'] === $__uid): ?>
                <td colspan="5">
                  <form method="post" action="/reports/saved/<?= (int) $r['id'] ?>/update" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <?= Csrf::field() ?>
                    <input type="text" name="name" value="<?= htmlspecialchars($r['name']) ?>" placeholder="Report name" aria-label="Report name" required style="width:200px">
                    <select name="visibility" aria-label="Visibility">
                      <option value="private" <?= $r['visibility'] === 'private' ? 'selected' : '' ?>>Private</option>
                      <option value="shared" <?= $r['visibility'] === 'shared' ? 'selected' : '' ?>>Shared</option>
                    </select>
                    <button type="submit" class="btn-sm">Save</button>
                    <span class="muted small"><?= htmlspecialchars($r['report_type']) ?> &middot; last run <?= htmlspecialchars($r['last_run_at'] ?? 'never') ?></span>
                  </form>
                </td>
                <?php else: ?>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['report_type']) ?></td>
                <td><?= htmlspecialchars($r['owner_name']) ?></td>
                <td><?= htmlspecialchars(ucfirst($r['visibility'])) ?></td>
                <?php endif; ?>
                <td>
                  <a href="/reports/saved/<?= (int) $r['id'] ?>/run">Run</a>
                  <?php if ((int) $r['owner_user_id'] === $__uid): ?>
                  <form method="post" action="/reports/saved/<?= (int) $r['id'] ?>/delete" style="display:inline">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Delete this saved report?')">Delete</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (($levels[$__key] ?? 'all') !== ReportAccess::ALL): ?>
        <span class="badge badge-info small" title="How far this report reaches for <?= $__preview ? 'this role' : 'you' ?>"><?= htmlspecialchars(ReportAccess::LEVEL_LABELS[$levels[$__key]]) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
