<div class="card page-wide">
  <p class="muted small"><a href="/procurement">&larr; Procurement</a></p>
  <h1>Supplier performance</h1>
  <p class="muted">From the purchase requirements: how often each supplier quoted and won, how many receipts came by the needed-by date, and how many failed the quality check.</p>
  <table class="list">
    <tr><th>Supplier</th><th>Quotes given</th><th>Awards won</th><th>Received</th><th>On time</th><th>Quality check failed</th></tr>
    <?php foreach ($rows as $s): ?>
    <tr>
      <td><?= htmlspecialchars((string) $s['supplier_legal_name']) ?></td>
      <td><?= (int) $s['quotes_given'] ?></td>
      <td><?= (int) $s['awards_won'] ?></td>
      <td><?= (int) $s['received'] ?></td>
      <td><?= (int) $s['received'] > 0 ? (int) $s['on_time'] . ' (' . round(100 * (int) $s['on_time'] / (int) $s['received']) . '%)' : '—' ?></td>
      <td><?= (int) $s['qc_failed'] ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">No supplier quotes recorded yet.</td></tr><?php endif; ?>
  </table>
</div>
