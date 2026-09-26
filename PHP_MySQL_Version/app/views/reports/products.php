<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Product / HS-Code Sales Report</h1>
  <p class="muted">Which products/HS codes actually drive FOB value, ranked highest first — filtered by order creation date.</p>

  <form method="get" action="/reports/products" class="section">
    <div class="kv-grid">
      <label>Created From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Created To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/products?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <table class="list">
      <tr><th>HS Code</th><th>Description</th><th>Currency</th><th>Orders</th><th>Total Quantity</th><th>Total FOB Value</th></tr>
      <?php foreach ($byHsCode as $hs => $entry): ?>
        <?php foreach ($entry['by_currency'] as $cc => $b): ?>
        <tr>
          <td><?= htmlspecialchars($hs) ?></td>
          <td><?= htmlspecialchars($entry['description']) ?></td>
          <td><?= htmlspecialchars($cc) ?></td>
          <td><?= (int) $b['order_count'] ?></td>
          <td><?= number_format($b['total_quantity'], 3) ?></td>
          <td><?= number_format($b['total_fob_value'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (empty($byHsCode)): ?><tr><td colspan="6" class="muted">No products match these filters.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
