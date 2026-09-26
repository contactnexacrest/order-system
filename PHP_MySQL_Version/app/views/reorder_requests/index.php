<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Reorder Requests</h1>
  <p class="muted">Repeat-order requests submitted by clients from an order already on file. Approving reviews and confirms each product line (HS code, unit price) before it becomes a real order — nothing here becomes live automatically.</p>

  <div class="section">
    <h2>Pending (<?= count($pending) ?>)</h2>
    <table class="list">
      <tr><th>Client</th><th>Source Order</th><th>Notes</th><th>Submitted</th><th>Action</th></tr>
      <?php foreach ($pending as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><a href="/orders/<?= (int) $r['source_order_id'] ?>"><?= htmlspecialchars($r['source_order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['notes'] ?? '—') ?></td>
        <td><?= htmlspecialchars((string) $r['submitted_at']) ?></td>
        <td><a href="/reorder-requests/<?= (int) $r['id'] ?>" class="btn-sm">Review</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
        <tr><td colspan="5" class="muted">No pending reorder requests.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Recently Resolved</h2>
    <table class="list">
      <tr><th>Client</th><th>Source Order</th><th>Status</th><th>Resolved By</th><th>When</th><th>Result</th></tr>
      <?php foreach ($resolved as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><a href="/orders/<?= (int) $r['source_order_id'] ?>"><?= htmlspecialchars($r['source_order_reference']) ?></a></td>
        <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
        <td><?= htmlspecialchars($r['reviewed_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars((string) $r['reviewed_at']) ?></td>
        <td>
          <?php if ($r['status'] === 'approved' && $r['new_order_reference']): ?>
            <a href="/orders/<?= (int) $r['new_order_id'] ?>"><?= htmlspecialchars($r['new_order_reference']) ?></a>
          <?php elseif ($r['status'] === 'rejected'): ?>
            <?= htmlspecialchars($r['rejection_reason'] ?? '') ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($resolved)): ?>
        <tr><td colspan="6" class="muted">Nothing resolved yet.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
