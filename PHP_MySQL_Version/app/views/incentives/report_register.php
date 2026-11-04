<?php use App\Services\IncentiveReportService; ?>
<div class="card page-wide">
  <?php require __DIR__ . '/_report_nav.php'; ?>
  <h1>Incentive &amp; Commission Register</h1>
  <p class="muted">One row per order and payee: each staff member's share of the team incentive and each associate's commission. Locked orders show what was locked (locked, held or paid); open orders show the estimate the order page shows today.</p>
  <form method="get" action="/reports/incentives" class="section">
    <div class="kv-grid">
      <label>State
        <select name="state">
          <option value="">All states</option>
          <?php foreach (IncentiveReportService::STATES as $k => $label): ?><option value="<?= $k ?>"<?= $filters['state'] === $k ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
        </select></label>
      <label>Payout month<input type="month" name="month" value="<?= htmlspecialchars($filters['month']) ?>" placeholder="e.g. 2026-12"></label>
      <label>Person
        <select name="user_id">
          <option value="0">Everyone</option>
          <?php foreach ($people as $id => $name): ?><option value="<?= (int) $id ?>"<?= $filters['user_id'] === (int) $id ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
        </select></label>
      <label>Associate
        <select name="associate_id">
          <option value="0">Every associate</option>
          <?php foreach ($associates as $id => $name): ?><option value="<?= (int) $id ?>"<?= $filters['associate_id'] === (int) $id ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
        </select></label>
      <label>Order date from<input type="date" name="from" value="<?= htmlspecialchars($filters['from']) ?>"></label>
      <label>Order date to<input type="date" name="to" value="<?= htmlspecialchars($filters['to']) ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/incentives?<?= htmlspecialchars(http_build_query(array_filter($filters))) ?>&format=csv">Export CSV</a>
    </div>
  </form>
  <div class="section"><?php require __DIR__ . '/_report_table.php'; ?>
    <?php if (($pages ?? 1) > 1): $__q = array_filter($filters); ?>
    <p class="pager">Page <?= (int) $page ?> of <?= (int) $pages ?> (the totals are for every row) ·
      <?php if ($page > 1): ?><a href="/reports/incentives?<?= htmlspecialchars(http_build_query($__q + ['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
      <?php if ($page < $pages): ?><a href="/reports/incentives?<?= htmlspecialchars(http_build_query($__q + ['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
    </p>
    <?php endif; ?></div>
</div>
