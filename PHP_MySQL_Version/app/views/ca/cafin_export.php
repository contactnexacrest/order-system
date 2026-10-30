<?php
use App\Services\CafinExportService;
?>
<div class="card">
  <h1>CAFIN export (CSV)</h1>
  <div class="alert alert-warning"><strong>Internal only.</strong> The CA Financial Annexure holds profit, costs and incentives. Never send this file to a client or buyer. Every download is recorded in the audit log.</div>
  <p class="muted">The CAFIN figures of every order of a financial year (by order date), from the same calculation as the CAFIN PDF. Test and sample orders are left out.</p>
  <form method="get" action="/ca/cafin-export" class="field-grid">
    <label>Financial year
      <select name="fy" required>
        <?php foreach ($years as $y): ?><option value="<?= htmlspecialchars($y) ?>"<?= $y === $fy ? ' selected' : '' ?>>FY <?= htmlspecialchars($y) ?></option><?php endforeach; ?>
      </select></label>
    <label>Orders
      <select name="status" required>
        <?php foreach ($statuses as $k => $label): ?><option value="<?= htmlspecialchars($k) ?>"<?= $k === $status ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
      </select></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-secondary">Show count</button>
      <button type="submit" name="format" value="summary" class="btn-sm">Download summary (one row per order)</button>
      <button type="submit" name="format" value="detail" class="btn-sm">Download detail (every CAFIN line)</button>
    </div>
  </form>
  <p><strong><?= (int) $orderCount ?></strong> order<?= $orderCount === 1 ? '' : 's' ?> in FY <?= htmlspecialchars($fy) ?> (<?= htmlspecialchars(strtolower(CafinExportService::STATUSES[$status])) ?>).</p>
  <ul class="muted small">
    <li><b>Summary</b>: order, client, status, currency and FOB value; revenue, benefits, supplier cost, C1–C5 expenses (with the team incentive and associate commission inside C3), total cost, profit and margin; and the cash position so far. All amounts in rupees, as plain numbers.</li>
    <li><b>Detail</b>: every line of every order's CAFIN, sections A to F, with the order and the section on each row.</li>
  </ul>
</div>
