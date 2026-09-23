<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>My Review Queue</h1>
  <table class="list">
    <tr><th>Order</th><th>Type</th><th>Reference</th><th>Rev.</th><th>Assigned</th><th>Action</th></tr>
    <?php foreach ($pendingReviews as $r): ?>
    <tr>
      <td><a href="/orders/<?= (int) $r['order_id'] ?>">Order #<?= (int) $r['order_id'] ?></a></td>
      <td><?= htmlspecialchars($r['document_type_code']) ?> — <?= htmlspecialchars($r['document_type_name']) ?></td>
      <td><?= htmlspecialchars($r['document_reference'] ?? '—') ?></td>
      <td><?= (int) $r['revision_number'] ?></td>
      <td><?= htmlspecialchars($r['assigned_at']) ?></td>
      <td>
        <form method="post" action="/reviews/<?= (int) $r['id'] ?>/approve" style="display:inline">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-success">Approve</button>
        </form>
        <form method="post" action="/reviews/<?= (int) $r['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this document? It goes back for rework.');">
          <?= Csrf::field() ?>
          <input type="text" name="comments" placeholder="Reason (mandatory)" required style="width:160px">
          <button type="submit" class="btn-sm btn-danger">Reject</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($pendingReviews)): ?>
      <tr><td colspan="6" class="muted">No documents are waiting on your review.</td></tr>
    <?php endif; ?>
  </table>
</div>
