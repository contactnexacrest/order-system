<?php
/**
 * Owner decision D-379 (15 Nov 2026): the Reports page — 7 section boxes in a fixed order, each with
 * only the reports this person may open (App\Services\ReportAccess; button text = the report's label),
 * and a banner when every open order report shows only their own business or their department's.
 * With $previewRole set it is "See Reports as this role": the same rows, read-only (names as plain text,
 * the search box read-only, no data). D-379 cosmetic follow-up (16 Nov 2026): one row per report with a pill.
 * One view for the Standard and the Kanban template. Node twin: NODE_MySQL_Version/app/views/reports/index.njk
 */
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\ReportAccess;
$__u = AuthService::currentUser();
$__uid = $__u ? (int) $__u['id'] : 0;
$__preview = $previewRole !== null;
// D-379 cosmetic follow-up (16 Nov 2026, owner-approved mockup cosmetic_1): one row per report — name link ·
// description · a pill on the right ("Your orders" / "Your department"; none for All). The preview draws the
// very same rows, with the names as plain text and the search box read-only.
$__name = static function (string $key) use ($__preview): string {
    $r = ReportAccess::REPORTS[$key];
    $label = htmlspecialchars($r['label']);
    if ($__preview || $r['href'] === null) {
        return '<span class="report-link is-preview" aria-disabled="true">' . $label . '</span>';
    }
    return '<a class="report-link" href="' . htmlspecialchars($r['href']) . '">' . $label . '</a>';
};
$__tag = static function (string $key) use ($levels, $__preview): string {
    $level = $levels[$key] ?? ReportAccess::ALL;
    if (!isset(ReportAccess::TAG_LABELS[$level])) {
        return '';
    }
    return '<span class="report-tag" title="How far this report reaches for ' . ($__preview ? 'this role' : 'you') . '">' . htmlspecialchars(ReportAccess::TAG_LABELS[$level]) . '</span>';
};
$__bannerParts = $banner !== null ? explode(' — ', $banner, 2) : [];
?>
<div class="card page-wide" id="reports-page"<?= $__preview ? ' data-preview' : '' ?>>
  <?php if ($__preview): ?>
    <div class="alert alert-warning" id="reports-preview-banner"><strong>Preview as <?= htmlspecialchars((string) $previewRole['name']) ?></strong> — what this role sees on the Reports page (its own levels and permissions; a person's extra report access is not included). Read-only. <a href="/admin/roles/<?= (int) $previewRole['id'] ?>/reports">Back to the Reports tab</a></div>
  <?php endif; ?>
  <h1>Reports</h1>
  <p class="muted">Per-order reports are one click from the order's own screen ("Full report").</p>
  <?php if ($banner !== null): ?>
    <div class="alert alert-info" id="reports-scope-banner"><strong><?= htmlspecialchars($__bannerParts[0]) ?></strong><?= isset($__bannerParts[1]) ? ' — ' . htmlspecialchars($__bannerParts[1]) : '' ?></div>
  <?php endif; ?>
  <?php if ($sections === []): ?>
    <p class="muted">No report is open to <?= $__preview ? 'this role' : 'you' ?>.</p>
  <?php endif; ?>

  <?php foreach ($sections as $__section => $__keys): ?>
  <div class="section report-section" id="reports-<?= htmlspecialchars($__section) ?>" data-report-section="<?= htmlspecialchars($__section) ?>">
    <h2><?= htmlspecialchars(ReportAccess::SECTIONS[$__section]) ?></h2>
    <div class="report-list">
    <?php foreach ($__keys as $__key): $__r = ReportAccess::REPORTS[$__key]; ?>
      <?php if ($__key === 'order_search'): ?>
      <div class="report-item report-search" data-report="<?= htmlspecialchars($__key) ?>">
        <div class="report-search-head"><span class="report-search-label"><?= htmlspecialchars($__r['label']) ?></span><?= $__tag($__key) ?></div>
        <?php if ($__preview): ?>
        <div class="report-search-form">
          <input type="text" name="q" value="" placeholder="Order no. or client — e.g. SC/ORD/26/001" aria-label="Order reference or client name" readonly>
          <button type="button" class="btn-sm" disabled>Search</button>
        </div>
        <?php else: ?>
        <form method="get" action="/reports" class="report-search-form">
          <input type="text" name="q" value="<?= htmlspecialchars($searchQuery ?? '') ?>" placeholder="Order no. or client — e.g. SC/ORD/26/001" aria-label="Order reference or client name" required maxlength="100">
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
      </div>
      <?php elseif ($__key === 'per_client' && !$__preview): ?>
      <details class="report-item report-details" id="per-client" data-report="<?= htmlspecialchars($__key) ?>">
        <summary class="report-row"><span class="report-name"><span class="report-link"><?= htmlspecialchars($__r['label']) ?></span></span><span class="report-desc"><?= htmlspecialchars($__r['description']) ?></span><span class="report-tag-cell"><?= $__tag($__key) ?></span></summary>
        <table class="list report-details-body">
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
      <?php else: ?>
      <div class="report-item report-row" data-report="<?= htmlspecialchars($__key) ?>">
        <span class="report-name"><?= $__name($__key) ?></span>
        <span class="report-desc"><?= htmlspecialchars($__r['description']) ?>
          <?php if ($__key === 'incentives'): ?>
            <?php if ($__preview): ?>
            <span class="report-sublinks"><span class="report-link is-preview">To pay</span> · <span class="report-link is-preview">Owed at month end</span> · <span class="report-link is-preview">Person statement</span></span>
            <?php else: ?>
            <span class="report-sublinks"><a class="report-link" href="/reports/incentives/to-pay">To pay</a> · <a class="report-link" href="/reports/incentives/owed">Owed at month end</a> · <a class="report-link" href="/reports/incentives/statement">Person statement</a></span>
            <?php endif; ?>
          <?php endif; ?>
        </span>
        <span class="report-tag-cell"><?= $__tag($__key) ?></span>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <?php if (in_array('aggregate', $__keys, true)): ?>
    <div class="saved-reports" id="saved-reports">
      <?php if ($__preview): ?>
        <p class="muted small"><strong>Saved reports:</strong> each person's own and shared saved reports are listed here.</p>
      <?php elseif (empty($savedReports)): ?>
        <p class="muted small"><strong>Saved reports:</strong> no saved reports yet — save one from the Aggregate report screen.</p>
      <?php else: ?>
        <strong class="small">Saved reports</strong>
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
  </div>
  <?php endforeach; ?>
</div>
