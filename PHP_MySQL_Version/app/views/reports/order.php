<div class="card page-wide">
  <h1>Order Report — <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted"><?= htmlspecialchars($order['company_legal_name']) ?> · <?= htmlspecialchars(ucfirst($order['status'])) ?></p>
  <div class="btn-row"><a class="btn-sm btn-secondary" href="/orders/<?= (int) $order['id'] ?>">View Order</a></div>

  <div class="section">
    <h2>Stage Data</h2>
    <table class="list">
      <tr><th>Stage</th><th>Status</th><th>Unlocked</th><th>Gate Passed</th><th>By</th><th>Skip Reason</th></tr>
      <?php foreach ($stages as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['stage_name']) ?></td>
        <td><?= htmlspecialchars(str_replace('_', ' ', $s['status'])) ?></td>
        <td><?= htmlspecialchars($s['unlocked_at'] ?? '—') ?></td>
        <td><?= htmlspecialchars($s['gate_passed_at'] ?? '—') ?></td>
        <td><?= htmlspecialchars($s['gate_passed_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($s['skip_reason'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="section">
    <h2>Documents</h2>
    <table class="list">
      <tr><th>Type</th><th>Reference</th><th>Rev.</th><th>Status</th><th>Generated</th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['document_type_code'] ?? '—') ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= (int) $d['revision_number'] ?></td>
        <td><?= htmlspecialchars($d['status']) ?></td>
        <td><?= htmlspecialchars($d['generated_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?><tr><td colspan="5" class="muted">No documents yet.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Full Audit Trail</h2>
    <table class="list">
      <tr><th>When</th><th>User</th><th>Action</th><th>Field</th><th>Old → New</th><th>Reason</th></tr>
      <?php foreach ($audit as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['created_at']) ?></td>
        <td><?= htmlspecialchars($a['user_name'] ?? 'System') ?></td>
        <td><?= htmlspecialchars($a['action_type']) ?></td>
        <td><?= htmlspecialchars($a['field_name'] ?? '—') ?></td>
        <td><?= ($a['old_value'] !== null || $a['new_value'] !== null) ? htmlspecialchars(($a['old_value'] ?? '—') . ' → ' . ($a['new_value'] ?? '—')) : '—' ?></td>
        <td><?= htmlspecialchars($a['reason'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($audit)): ?><tr><td colspan="6" class="muted">No audit entries yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
