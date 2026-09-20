<div class="card page-wide">
  <h1>Notifications</h1>
  <table class="list">
    <tr><th>When</th><th>Type</th><th>Message</th><th>Order</th></tr>
    <?php foreach ($notifications as $n): ?>
      <tr>
        <td><?= htmlspecialchars($n['created_at']) ?></td>
        <td><?= htmlspecialchars($n['type']) ?></td>
        <td><?= htmlspecialchars($n['message']) ?></td>
        <td><?php if ($n['related_order_id']): ?><a href="/orders/<?= (int) $n['related_order_id'] ?>">Order #<?= (int) $n['related_order_id'] ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($notifications)): ?>
      <tr><td colspan="4" class="muted">No notifications yet.</td></tr>
    <?php endif; ?>
  </table>
</div>
