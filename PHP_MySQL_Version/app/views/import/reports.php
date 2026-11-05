<?php use App\Services\ImportService as I; use App\Services\GstCalculator as G;
/* Owner decision D-257 Phase 3: Import & Distribution reports — landed cost per item, stock on hand, customs duty and IGST input credit, stock movements. Only the person's divisions are shown. */
$h = static fn ($v): string => htmlspecialchars((string) $v);
$num = static fn (string $v): string => str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v; ?>
<div class="card page-wide" data-testid="imp-reports">
  <p><a href="/import">&larr; Imports</a></p>
  <h1>Import &amp; Distribution reports</h1>

  <h2 id="stock">Stock on hand</h2>
  <table class="list" data-testid="imp-stock">
    <tr><th>Product</th><th>On hand</th><th>Value ₹</th><th>Average cost ₹ per unit</th><th>Suggested price ₹ per unit</th></tr>
    <?php foreach ($stock as $r): ?>
    <tr><td><?= $h($r['product_name']) ?> <small class="muted"><?= $h($r['product_code']) ?></small></td><td><?= $h($num((string) $r['qty_on_hand'])) ?></td><td><?= $h(G::inr((string) $r['value_inr'])) ?></td><td><?= $h($r['avg_cost_inr']) ?></td><td><?= $h(G::inr($r['suggested_price_inr'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($stock === []): ?><tr><td colspan="5" class="muted">No stock.</td></tr><?php endif; ?>
  </table>
  <p class="muted">Valued at weighted average landed cost. Suggested price = average cost ÷ (1 − <?= $h($margin) ?>% margin), before GST.</p>

  <h2 id="landed">Landed cost per item</h2>
  <table class="list" data-testid="imp-landed">
    <tr><th>Import</th><th>Bill of entry</th><th>Product</th><th>Qty</th><th>Goods ₹</th><th>Assessable ₹</th><th>Basic duty ₹</th><th>SWS ₹</th><th>Landed ₹</th><th>Per unit ₹</th></tr>
    <?php foreach ($landed as $l): ?>
    <tr><td><a href="/import/<?= (int) $l['shipment_id'] ?>"><?= $h($l['reference']) ?></a></td><td><?= $h($l['boe_no']) ?></td><td><?= $h($l['product_name']) ?></td><td><?= $h($num((string) $l['quantity'])) ?> <?= $h($l['unit']) ?></td>
      <td><?= $h(G::inr((string) $l['value_inr'])) ?></td><td><?= $h(G::inr((string) $l['assessable_inr'])) ?></td><td><?= $h(G::inr((string) $l['bcd_inr'])) ?></td><td><?= $h(G::inr((string) $l['sws_inr'])) ?></td><td><?= $h(G::inr((string) $l['landed_inr'])) ?></td><td><?= $h($l['landed_unit_inr']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($landed === []): ?><tr><td colspan="10" class="muted">No cleared imports yet.</td></tr><?php endif; ?>
  </table>

  <h2 id="duty">Customs duty and IGST input credit</h2>
  <table class="list" data-testid="imp-duty">
    <tr><th>Import</th><th>Bill of entry</th><th>Date</th><th>Port</th><th>Assessable ₹</th><th>Basic duty ₹</th><th>SWS ₹</th><th>IGST ₹</th><th>IGST credit</th></tr>
    <?php foreach ($duty as $s): ?>
    <tr><td><a href="/import/<?= (int) $s['id'] ?>"><?= $h($s['reference']) ?></a></td><td><?= $h($s['boe_no']) ?></td><td><?= $h($s['boe_date']) ?></td><td><?= $h($s['port_code']) ?></td>
      <td><?= $h(G::inr((string) $s['assessable_total'])) ?></td><td><?= $h(G::inr((string) $s['bcd_total'])) ?></td><td><?= $h(G::inr((string) $s['sws_total'])) ?></td><td><?= $h(G::inr((string) $s['igst_total'])) ?></td>
      <td><?= $s['igst_credit_on'] ? 'Taken ' . $h($s['igst_credit_on']) : '<strong>Pending</strong>' ?></td></tr>
    <?php endforeach; ?>
    <?php if ($duty === []): ?><tr><td colspan="9" class="muted">No cleared imports yet.</td></tr><?php endif; ?>
  </table>

  <h2 id="movements">Stock movements (latest 50)</h2>
  <table class="list" data-testid="imp-movements">
    <tr><th>Date</th><th>Product</th><th>In / out</th><th>Qty</th><th>Value ₹</th><th>From / to</th></tr>
    <?php foreach ($movements as $m): ?>
    <tr><td><?= $h($m['moved_on']) ?></td><td><?= $h($m['product_name']) ?></td><td><?= $m['kind'] === 'receipt' ? 'In' : 'Out' ?></td><td><?= $h($num((string) $m['quantity'])) ?></td><td><?= $h(G::inr((string) $m['value_inr'])) ?></td>
      <td><?php if ($m['shipment_id']): ?><a href="/import/<?= (int) $m['shipment_id'] ?>"><?= $h($m['shipment_reference']) ?></a><?php elseif ($m['order_id']): ?><a href="/orders/<?= (int) $m['order_id'] ?>"><?= $h($m['order_reference']) ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if ($movements === []): ?><tr><td colspan="6" class="muted">None yet.</td></tr><?php endif; ?>
  </table>
</div>
