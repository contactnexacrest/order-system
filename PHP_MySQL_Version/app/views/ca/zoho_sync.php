<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Zoho Books Sync</h1>
  <p class="muted">Two independent, one-way directions: settlement legs with a recorded INR actual are pushed to Zoho Books as customer payments, and expenses recorded in Zoho Books are imported here (see <a href="/ca/expenses">Expenses</a>). Runs on demand here, or hourly via the scheduled job (see README's cron setup). If Zoho Books isn't configured, this is a routine no-op — nothing else in the app depends on it.</p>

  <div class="section">
    <h2>Status</h2>
    <div class="kv-grid">
      <div><span class="k">Zoho Books</span><span class="v"><?= $isEnabled ? '<span style="color:var(--success)">Enabled</span>' : '<span class="muted">Not configured</span>' ?></span></div>
      <div><span class="k">Legs Pending Sync</span><span class="v"><?= (int) $pendingCount ?></span></div>
    </div>
    <?php if (!$isEnabled): ?>
      <p class="muted small">Fill in every <code>zoho_books_*</code> setting and set <code>zoho_books_enabled</code> to on, under Admin &rarr; Settings, to start syncing.</p>
    <?php endif; ?>
    <form method="post" action="/ca/zoho-sync/run" style="margin-top:10px;">
      <?= \App\Helpers\Csrf::field() ?>
      <button type="submit" class="btn-sm">Sync Now</button>
    </form>
  </div>

  <div class="section">
    <h2>Sync Log</h2>
    <p class="muted small">Independent of the main system audit log — every sync attempt (manual or scheduled), success or failure, is one row here.</p>
    <?php if (empty($log)): ?>
      <p class="muted">No sync attempts yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>When</th><th>Trigger</th><th>Leg</th><th>Status</th><th>Zoho Reference</th><th>Message</th></tr>
        <?php foreach ($log as $row): ?>
        <tr>
          <td><?= htmlspecialchars((string) $row['created_at']) ?></td>
          <td><?= htmlspecialchars($row['triggered_by']) ?><?= $row['triggered_by_name'] ? ' (' . htmlspecialchars($row['triggered_by_name']) . ')' : '' ?></td>
          <td><?= $row['entity_id'] ? '<a href="/orders/' . (int) $row['entity_id'] . '">Order #' . (int) $row['entity_id'] . '</a>' . ($row['leg'] ? ' — ' . htmlspecialchars(ucfirst($row['leg'])) : '') : '<span class="muted">—</span>' ?></td>
          <td>
            <?php if ($row['status'] === 'success'): ?>
              <span style="color:var(--success)">Success</span>
            <?php elseif ($row['status'] === 'error'): ?>
              <span style="color:var(--danger)">Error</span>
            <?php else: ?>
              <span class="muted">Skipped</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars((string) ($row['zoho_reference'] ?? '—')) ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($row['message'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
