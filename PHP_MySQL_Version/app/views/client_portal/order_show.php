<?php use App\Helpers\Dates; ?>
<div class="card page-wide">
  <h1>Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted small"><a href="/client">&larr; Back to My Orders</a></p>

  <div class="section">
    <h2>Documents</h2>
    <table class="list">
      <tr><th>Document</th><th>Reference</th><th>Date</th><th></th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['document_type_name']) ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= htmlspecialchars(Dates::human($d['generated_at'])) ?></td>
        <td><a href="/client/documents/<?= (int) $d['id'] ?>/download">Download PDF</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?>
        <tr><td colspan="4" class="muted">No documents available yet — check back once your order progresses.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
