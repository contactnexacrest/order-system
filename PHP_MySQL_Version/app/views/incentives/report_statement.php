<div class="card page-wide">
  <?php require __DIR__ . '/_report_nav.php'; ?>
  <h1>Person Statement</h1>
  <p class="muted">Every order of one staff member or one associate, in every state. Staff see their own on My incentives; associates on their portal (Commission).</p>
  <form method="get" action="/reports/incentives/statement" class="section">
    <div class="kv-grid">
      <label>Staff member
        <select name="user_id">
          <option value="0">Choose…</option>
          <?php foreach ($people as $id => $n): ?><option value="<?= (int) $id ?>"<?= $userId === (int) $id ? ' selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?>
        </select></label>
      <label>or Associate
        <select name="associate_id">
          <option value="0">Choose…</option>
          <?php foreach ($associates as $id => $n): ?><option value="<?= (int) $id ?>"<?= $associateId === (int) $id ? ' selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?>
        </select></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Show</button>
      <?php if ($rows !== []): ?><a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/incentives/statement?<?= htmlspecialchars(http_build_query(array_filter(['user_id' => $userId, 'associate_id' => $associateId]))) ?>&format=csv">Export CSV</a><?php endif; ?>
    </div>
  </form>
  <?php if ($name !== ''): ?>
  <div class="section"><h2><?= htmlspecialchars($name) ?> — &#8377;<?= number_format($totals['amount_inr'], 2) ?></h2><?php require __DIR__ . '/_report_table.php'; ?></div>
  <?php else: ?>
  <p class="muted">Choose a staff member or an associate.</p>
  <?php endif; ?>
</div>
