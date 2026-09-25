<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Dispute Report</h1>
  <p class="muted">Every dispute across all orders — filtered by notice date. "Days Open" counts from notice date to today for open disputes, or to resolution for resolved ones.</p>

  <form method="get" action="/reports/disputes" class="section">
    <div class="kv-grid">
      <label>Notice Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Notice Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/disputes?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Summary</h2>
    <p class="muted small">Computed from exactly the <?= count($rows) ?> dispute(s) listed below.</p>
    <div class="kv-grid">
      <div><span class="k">Open (not Resolved)</span><span class="v"><?= (int) $openCount ?></span></div>
      <div><span class="k">Average Resolution Time</span><span class="v"><?= $avgResolutionDays !== null ? $avgResolutionDays . ' days' : '—' ?></span></div>
      <div>
        <span class="k">By Status</span>
        <span class="v"><?php foreach ($byStatus as $status => $n): ?><?= htmlspecialchars($status) ?>: <?= (int) $n ?><br><?php endforeach; ?><?= empty($byStatus) ? '—' : '' ?></span>
      </div>
    </div>
  </div>

  <div class="section">
    <h2>Disputes</h2>
    <table class="list">
      <tr><th>Order Ref</th><th>Client</th><th>Notice Date</th><th>From</th><th>Status</th><th>Response Due</th><th>Resolved At</th><th>Days Open</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars($r['notice_date']) ?></td>
        <td><?= htmlspecialchars($r['from_party'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['status']) ?></td>
        <td><?= htmlspecialchars($r['response_due_date'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['resolved_at'] ?? '—') ?></td>
        <td><?= (int) $r['days_open'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="8" class="muted">No disputes match these filters.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
