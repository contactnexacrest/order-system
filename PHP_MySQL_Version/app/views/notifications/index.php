<div class="card page-wide">
  <h1>Notifications</h1>
  <table class="list">
    <tr><th>When</th><th>Type</th><th>Message</th><th>Order</th></tr>
    <?php foreach ($notifications as $n): ?>
      <tr>
        <td><?= htmlspecialchars($n['created_at']) ?></td>
        <td><?= htmlspecialchars($n['type']) ?></td>
        <td><?= htmlspecialchars($n['message']) ?></td>
        <td><?php if ($n['type'] === 'email_send_pending_approval'): ?><a href="/email-approvals">Review &amp; approve</a>
          <?php elseif ($n['type'] === \App\Services\ProspectService::NOTIFICATION_TYPE && $n['related_order_id']): // D-394 ?>
            <a href="/orders/<?= (int) $n['related_order_id'] ?>">Open the order</a>
            <?php if (!empty($n['prospect_client'])): ?>
            <form method="post" action="/clients/<?= (int) $n['prospect_client']['id'] ?>/prospect-keep" style="display:inline">
              <?= \App\Helpers\Csrf::field() ?>
              <input type="hidden" name="back" value="notifications">
              <button type="submit" class="btn-sm btn-secondary">Keep for <?= \App\Services\ProspectService::expiryDays() ?> more days</button>
            </form>
            <?php endif; ?>
          <?php elseif ($n['related_order_id']): ?><a href="/orders/<?= (int) $n['related_order_id'] ?>">Order #<?= (int) $n['related_order_id'] ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($notifications)): ?>
      <tr><td colspan="4" class="muted">No notifications yet.</td></tr>
    <?php endif; ?>
  </table>
</div>
