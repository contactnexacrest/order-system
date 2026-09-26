<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Conversion Rate Report</h1>
  <p class="muted">The same underlying activity as the Operations Queues funnel section, shown as the percentages a sales lead actually wants at a glance rather than raw counts.</p>

  <form method="get" action="/reports/conversion" class="section">
    <div class="kv-grid">
      <label>Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
    </div>
  </form>

  <div class="section">
    <table class="list">
      <tr><th>Metric</th><th>Value</th></tr>
      <tr><td>Quotations sent</td><td><?= (int) $conversion['quotations_sent'] ?></td></tr>
      <tr><td>Quotation &rarr; PI conversion</td><td><?= $conversion['quotation_to_pi_pct'] !== null ? $conversion['quotation_to_pi_pct'] . '%' : '—' ?></td></tr>
      <tr><td>Quotation lost rate</td><td><?= $conversion['quotation_lost_pct'] !== null ? $conversion['quotation_lost_pct'] . '%' : '—' ?></td></tr>
      <tr><td>PI sent</td><td><?= (int) $conversion['pi_sent'] ?></td></tr>
      <tr><td>PI &rarr; Confirmed Order conversion</td><td><?= $conversion['pi_to_confirmed_pct'] !== null ? $conversion['pi_to_confirmed_pct'] . '%' : '—' ?></td></tr>
      <tr><td>PI lost rate</td><td><?= $conversion['pi_lost_pct'] !== null ? $conversion['pi_lost_pct'] . '%' : '—' ?></td></tr>
      <tr><td><strong>Overall Quotation &rarr; Confirmed Order</strong></td><td><strong><?= $conversion['overall_quotation_to_confirmed_pct'] !== null ? $conversion['overall_quotation_to_confirmed_pct'] . '%' : '—' ?></strong></td></tr>
    </table>
  </div>
</div>
