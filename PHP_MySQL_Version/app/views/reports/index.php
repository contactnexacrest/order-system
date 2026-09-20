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
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td><?= htmlspecialchars($r['report_type']) ?></td>
          <td><?= htmlspecialchars($r['owner_name']) ?></td>
          <td><?= htmlspecialchars(ucfirst($r['visibility'])) ?></td>
          <td><?= htmlspecialchars($r['last_run_at'] ?? 'never') ?></td>
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
