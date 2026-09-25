<div class="card page-wide">
  <h1>Trends — Last 12 Months</h1>
  <p class="muted">Month-over-month activity. Months with no activity still appear as a zero row, so gaps in the business are as visible as growth.</p>

  <div class="section">
    <table class="list">
      <tr><th>Month</th><th>Orders Created</th><th>Quotations Sent</th><th>PI Sent</th><th>Lost</th><th>Total FOB Value</th></tr>
      <?php foreach ($months as $m): ?>
      <tr>
        <td><?= htmlspecialchars($m['month']) ?></td>
        <td><?= (int) $m['orders_created'] ?></td>
        <td><?= (int) $m['quotations_sent'] ?></td>
        <td><?= (int) $m['pi_sent'] ?></td>
        <td><?= (int) $m['lost'] ?></td>
        <td>
          <?php if (empty($m['fob_by_currency'])): ?>—<?php else: ?>
            <?php foreach ($m['fob_by_currency'] as $cc => $amt): ?>
              <?= htmlspecialchars($cc) ?> <?= number_format($amt, 2) ?><br>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
