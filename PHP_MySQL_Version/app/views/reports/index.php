<?php
use App\Helpers\Csrf;
use App\Services\AuthService;
$__u = AuthService::currentUser();
$__uid = $__u ? (int) $__u['id'] : 0;
?>
<div class="card page-wide">
  <h1>Reports</h1>
  <p class="muted">Per-client and per-order reports are one click from that client's or order's own screen. This page covers the aggregate report and your saved report definitions.</p>

  <div class="section">
    <h2>Aggregate Report</h2>
    <p class="muted">Filter orders by date range, stage, Incoterm, or country.</p>
    <a class="btn-sm" href="/reports/aggregate">Open Aggregate Report</a>
  </div>

  <div class="section">
    <h2>Operations Queues</h2>
    <p class="muted">How many quotations/PIs/POs/CIs are sitting in each queue right now, plus quotation/PI sent-won-lost totals for a date range.</p>
    <a class="btn-sm" href="/reports/queues">Open Operations Queues</a>
  </div>

  <div class="section">
    <h2>Payments Report</h2>
    <p class="muted">Total collected vs. outstanding across the whole business, by currency — advance, balance, and freight.</p>
    <a class="btn-sm" href="/reports/payments">Open Payments Report</a>
  </div>

  <div class="section">
    <h2>Disputes &amp; Amendments</h2>
    <p class="muted">Status/aging breakdown for disputes, and status/requested-by breakdown for amendments — across all orders.</p>
    <div class="btn-row">
      <a class="btn-sm" href="/reports/disputes">Open Dispute Report</a>
      <a class="btn-sm" href="/reports/amendments">Open Amendment Report</a>
    </div>
  </div>

  <div class="section">
    <h2>Trends</h2>
    <p class="muted">Orders created, quotations/PI sent, lost, and FOB value — month over month for the last 12 months.</p>
    <a class="btn-sm" href="/reports/trends">Open Trends</a>
  </div>

  <?php if ($canViewStaffReports): ?>
  <div class="section">
    <h2>Staff Productivity</h2>
    <p class="muted">Documents generated and audit-log activity per user.</p>
    <a class="btn-sm" href="/reports/staff">Open Staff Productivity Report</a>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Find an Order</h2>
    <p class="muted">Search by order reference or client company name to jump straight to that order's Full Report.</p>
    <form method="get" action="/reports" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
      <label style="display:inline-block;width:auto;">Order reference or client name
        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery ?? '') ?>" placeholder="e.g., SC/OC/2026/001 or Test Company Ltd" style="width:280px;">
      </label>
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
  </div>

  <div class="section">
    <h2>Per-Client Report</h2>
    <table class="list">
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
  </div>

  <div class="section">
    <h2>Per-Order Report</h2>
    <p class="muted">Open any order and use its "Full Report" link, or go directly to <code>/reports/order/&lt;id&gt;</code>.</p>
  </div>

  <div class="section">
    <h2>Saved Reports</h2>
    <?php if (empty($savedReports)): ?>
      <p class="muted">No saved reports yet — save one from the Aggregate Report screen.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Name</th><th>Type</th><th>Owner</th><th>Visibility</th><th>Last Run</th><th></th></tr>
        <?php foreach ($savedReports as $r): ?>
        <tr>
          <?php if ((int) $r['owner_user_id'] === $__uid): ?>
          <td colspan="5">
            <form method="post" action="/reports/saved/<?= (int) $r['id'] ?>/update" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <?= Csrf::field() ?>
              <input type="text" name="name" value="<?= htmlspecialchars($r['name']) ?>" required style="width:200px">
              <select name="visibility">
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
</div>
