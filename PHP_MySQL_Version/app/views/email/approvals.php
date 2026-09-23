<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Email Send Approvals (Level 2)</h1>
  <p class="muted small">Includes anything still in its deferred window — awaiting your approval, or already approved but not yet dispatched. Cancel stops either one before it goes out.</p>
  <table class="list">
    <tr><th>Order</th><th>To</th><th>Subject</th><th>Status</th><th>Scheduled</th><th>Requested</th><th>Action</th></tr>
    <?php foreach ($pending as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['order_reference'] ?? '—') ?></td>
      <td><?= htmlspecialchars($row['recipient_email']) ?></td>
      <td><?= htmlspecialchars($row['subject']) ?></td>
      <td><?= htmlspecialchars(str_replace('_', ' ', $row['status'])) ?></td>
      <td><?= htmlspecialchars($row['scheduled_at'] ?? 'Immediate') ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
      <td>
        <?php if ($row['status'] === 'pending_approval'): ?>
        <form method="post" action="/email-log/<?= (int) $row['id'] ?>/approve" style="display:inline">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-success">Approve</button>
        </form>
        <form method="post" action="/email-log/<?= (int) $row['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this send?');">
          <?= Csrf::field() ?>
          <input type="text" name="reason" placeholder="Reason (mandatory)" required style="width:160px">
          <button type="submit" class="btn-sm btn-danger">Reject</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/email-log/<?= (int) $row['id'] ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this send? It will never go out.');">
          <?= Csrf::field() ?>
          <input type="text" name="reason" placeholder="Cancel reason (mandatory)" required style="width:160px">
          <button type="submit" class="btn-sm btn-warning">Cancel Send</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($pending)): ?>
      <tr><td colspan="7" class="muted">Nothing awaiting approval or dispatch.</td></tr>
    <?php endif; ?>
  </table>
</div>
