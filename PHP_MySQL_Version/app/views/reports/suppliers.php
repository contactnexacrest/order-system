<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Supplier Performance Report</h1>
  <p class="muted">All-time, per supplier. "Avg Signing Days" is PO creation to signature confirmation. "On-Time %" compares the packing date against the PO's required delivery date — the closest available proxy for "material received on time," since the schema has no separate goods-receipt timestamp.</p>

  <div class="section">
    <table class="list">
      <tr><th>Supplier</th><th>POs</th><th>Signed</th><th>Avg Signing Days</th><th>Delivery Tracked</th><th>On-Time %</th></tr>
      <?php foreach ($rows as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['supplier_name']) ?></td>
        <td><?= (int) $s['po_count'] ?></td>
        <td><?= (int) $s['signed_count'] ?></td>
        <td><?= $s['avg_signing_days'] !== null ? $s['avg_signing_days'] : '—' ?></td>
        <td><?= (int) $s['delivery_tracked_count'] ?></td>
        <td><?= $s['on_time_pct'] !== null ? $s['on_time_pct'] . '%' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="6" class="muted">No supplier POs recorded yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
