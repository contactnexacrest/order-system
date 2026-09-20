<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Email Send Approvals (Level 2)</h1>
  <table class="list">
    <tr><th>Order</th><th>To</th><th>Subject</th><th>Scheduled</th><th>Requested</th><th>Action</th></tr>
    <?php foreach ($pending as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['order_reference'] ?? '—') ?></td>
      <td><?= htmlspecialchars($row['recipient_email']) ?></td>
      <td><?= htmlspecialchars($row['subject']) ?></td>
      <td><?= htmlspecialchars($row['scheduled_at'] ?? 'Immediate') ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
      <td>
        <form method="post" action="/email-log/<?= (int) $row['id'] ?>/approve" style="display:inline">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-success">Approve</button>
        </form>
        <form method="post" action="/email-log/<?= (int) $row['id'] ?>/reject" style="display:inline">
          <?= Csrf::field() ?>
          <input type="text" name="reason" placeholder="Reason (mandatory)" required style="width:160px">
          <button type="submit" class="btn-sm btn-danger">Reject</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($pending)): ?>
      <tr><td colspan="6" class="muted">Nothing awaiting approval.</td></tr>
    <?php endif; ?>
  </table>
</div>
