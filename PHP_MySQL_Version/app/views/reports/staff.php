<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Staff Productivity Report</h1>
  <p class="muted">Documents generated per user (from each document's own generated-by record) and overall system activity (audit log actions). Deliberately limited to these two directly-attributable counts — see the code comment on <code>staffProductivity()</code> for why a "turnaround time per staff member" figure was left out rather than risk a shaky derived number.</p>

  <form method="get" action="/reports/staff" class="section">
    <div class="kv-grid">
      <label>Date From (documents generated)<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Date To (documents generated)<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
    </div>
  </form>

  <div class="section">
    <table class="list">
      <tr><th>User</th><th>Documents Generated (by type)</th><th>Documents Total</th><th>Audit Log Actions</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['user_name']) ?></td>
        <td>
          <?php if (empty($r['by_type'])): ?>—<?php else: ?>
            <?php foreach ($r['by_type'] as $type => $n): ?>
              <?= htmlspecialchars($type) ?>: <?= (int) $n ?><br>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td><?= (int) $r['documents_total'] ?></td>
        <td><?= (int) $r['audit_actions'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="4" class="muted">No activity in this range.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
