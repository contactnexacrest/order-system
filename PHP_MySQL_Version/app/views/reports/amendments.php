<div class="card page-wide">
  <h1>Amendment Report</h1>
  <p class="muted">Every amendment across all orders — filtered by date created.</p>

  <form method="get" action="/reports/amendments" class="section">
    <div class="kv-grid">
      <label>Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/amendments?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Summary</h2>
    <p class="muted small">Computed from exactly the <?= count($rows) ?> amendment(s) listed below.</p>
    <div class="kv-grid">
      <div>
        <span class="k">By Status</span>
        <span class="v"><?php foreach ($byStatus as $status => $n): ?><?= htmlspecialchars(str_replace('_', ' ', $status)) ?>: <?= (int) $n ?><br><?php endforeach; ?><?= empty($byStatus) ? '—' : '' ?></span>
      </div>
      <div>
        <span class="k">By Requested By</span>
        <span class="v"><?php foreach ($byRequestedBy as $who => $n): ?><?= htmlspecialchars(ucfirst($who)) ?>: <?= (int) $n ?><br><?php endforeach; ?><?= empty($byRequestedBy) ? '—' : '' ?></span>
      </div>
    </div>
  </div>

  <div class="section">
    <h2>Amendments</h2>
    <table class="list">
      <tr><th>Amendment Ref</th><th>Order Ref</th><th>Client</th><th>Requested By</th><th>Status</th><th>Amended Advance</th><th>Amended Balance</th><th>Effective From</th><th>Created</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['amendment_reference']) ?></td>
        <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars(ucfirst($r['requested_by'])) ?></td>
        <td><?= htmlspecialchars(str_replace('_', ' ', $r['status'])) ?></td>
        <td><?= $r['amended_advance_amount'] !== null ? htmlspecialchars($r['currency_code']) . ' ' . number_format((float) $r['amended_advance_amount'], 2) : '—' ?></td>
        <td><?= $r['amended_balance_amount'] !== null ? htmlspecialchars($r['currency_code']) . ' ' . number_format((float) $r['amended_balance_amount'], 2) : '—' ?></td>
        <td><?= htmlspecialchars($r['effective_from'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="9" class="muted">No amendments match these filters.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
