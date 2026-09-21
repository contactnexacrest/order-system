<div class="card page-wide">
  <p><a href="/orders/<?= (int) $order['id'] ?>">&larr; Order <?= htmlspecialchars($order['order_reference']) ?></a></p>
  <h1>Audit Log — Order <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted">Every logged change against this order, its documents, disputes, amendments, and buyer email sends — immutable, newest first. For anything else (company settings, other orders' data), use the <a href="/audit-log">full Audit Log</a>.</p>

  <table class="list">
    <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Field</th><th>Old</th><th>New</th><th>Reason</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td><?= htmlspecialchars($r['user_name'] ?? 'system') ?></td>
        <td><?= htmlspecialchars($r['action_type']) ?></td>
        <td><?= htmlspecialchars(($r['entity_type'] ?? '') . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?></td>
        <td><?= htmlspecialchars($r['field_name'] ?? '') ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string) ($r['old_value'] ?? ''), 0, 40, '…')) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string) ($r['new_value'] ?? ''), 0, 40, '…')) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string) ($r['reason'] ?? ''), 0, 60, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="muted">Nothing logged against this order yet.</td></tr>
    <?php endif; ?>
  </table>
</div>
