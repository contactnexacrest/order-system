<div class="card page-wide">
  <h1>Procurement</h1>
  <p class="muted">Purchase requirements raised by Sales, earliest need first. Open one to record supplier quotes, compare, award, approve, and record receipt and the quality check.</p>
  <?php if (!empty($kanbanUser)): ?><div class="btn-row"><a class="btn-sm btn-secondary" href="/procurement">Switch to board view</a></div><?php endif; ?>
  <?php $listOfBoard = !empty($kanbanUser); include __DIR__ . '/_filters.php'; ?>
  <div class="table-scroll">
  <table class="list">
    <tr><th>PR</th><th>Order</th><th>What</th><th>Quantity</th><th>Needed by</th><th>Quotes</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): $late = !in_array($r['status'], ['received', 'cancelled'], true) && $r['needed_by'] < date('Y-m-d'); ?>
    <tr>
      <td>PR-<?= (int) $r['id'] ?></td>
      <td><?= htmlspecialchars((string) $r['order_reference']) ?></td>
      <td><?= htmlspecialchars((string) $r['description']) ?></td>
      <td><?= htmlspecialchars(rtrim(rtrim((string) $r['quantity'], '0'), '.')) ?> <?= htmlspecialchars((string) ($r['unit'] ?? '')) ?></td>
      <td><?= htmlspecialchars((string) $r['needed_by']) ?><?= $late ? ' <span class="badge badge-inactive">late</span>' : '' ?></td>
      <td><?= (int) $r['quote_count'] ?></td>
      <td><?= htmlspecialchars($statuses[$r['status']] ?? $r['status']) ?></td>
      <td><a class="btn-sm" href="/procurement/<?= (int) $r['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="8" class="muted">Nothing here.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
