<div class="card page-wide">
  <h1>Dispute Log (All Orders)</h1>
  <form method="get" action="/disputes">
    <label>Status
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($statusOptions as $opt): ?>
          <option value="<?= htmlspecialchars($opt['option_value']) ?>" <?= $statusFilter === $opt['option_value'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['option_value']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <table class="list">
    <tr><th>Order</th><th>Notice Date</th><th>Status</th><th>Response Due</th><th>Description</th></tr>
    <?php foreach ($disputes as $d): ?>
      <tr>
        <td><a href="/orders/<?= (int) $d['order_id'] ?>/disputes"><?= htmlspecialchars($d['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($d['notice_date']) ?></td>
        <td><?= htmlspecialchars($d['status']) ?></td>
        <td><?= htmlspecialchars($d['response_due_date'] ?? '—') ?></td>
        <td><?= htmlspecialchars(mb_strimwidth($d['description'], 0, 100, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($disputes)): ?>
      <tr><td colspan="5" class="muted">No disputes recorded.</td></tr>
    <?php endif; ?>
  </table>
</div>
