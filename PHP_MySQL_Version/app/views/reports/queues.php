<?php
/**
 * Added 2026-09-19 — Operations Queues snapshot + date-ranged funnel
 * activity (see ReportRepository::operationsQueues()/funnelActivity()).
 */
if (!function_exists('nc_order_links')) {
    function nc_order_links(array $orders): string
    {
        if (empty($orders)) {
            return '<span class="muted">none</span>';
        }
        $links = array_map(
            static fn(array $o): string => '<a href="/orders/' . (int) $o['id'] . '">' . htmlspecialchars($o['order_reference']) . '</a>',
            $orders
        );
        return implode(', ', $links);
    }
}
?>
<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Operations Queues</h1>
  <p class="muted">Snapshot buckets below are "right now" — no date filter applies to them. The funnel activity section at the bottom is date-ranged.</p>
  <div class="btn-row">
    <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/queues?format=csv&section=queues">Export Queues CSV</a>
  </div>

  <div class="section">
    <h2>Quotation &amp; Buyer PO</h2>
    <table class="list">
      <tr><th>Queue</th><th>Count</th><th>Orders</th></tr>
      <tr>
        <td>Quotation drafted, not yet sent to buyer</td>
        <td><?= count($queues['quotationAwaitingSend']) ?></td>
        <td><?= nc_order_links($queues['quotationAwaitingSend']) ?></td>
      </tr>
      <tr>
        <td>Quotation sent — waiting on buyer's PO</td>
        <td><?= count($queues['buyerPoAwaited']) ?></td>
        <td><?= nc_order_links($queues['buyerPoAwaited']) ?></td>
      </tr>
      <tr>
        <td>Our Order-Acceptance (PO) not yet sent to buyer</td>
        <td><?= count($queues['orderAcceptanceAwaitingSend']) ?></td>
        <td><?= nc_order_links($queues['orderAcceptanceAwaitingSend']) ?></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2>PI &amp; Order Confirmation</h2>
    <table class="list">
      <tr><th>Queue</th><th>Count</th><th>Orders</th></tr>
      <tr>
        <td>Sitting at PI stage</td>
        <td><?= count($queues['piStage']) ?></td>
        <td><?= nc_order_links($queues['piStage']) ?></td>
      </tr>
      <tr>
        <td>Order Confirmation drafted, not yet sent</td>
        <td><?= count($queues['ocAwaitingSend']) ?></td>
        <td><?= nc_order_links($queues['ocAwaitingSend']) ?></td>
      </tr>
      <tr>
        <td>Order Confirmation sent — awaiting buyer's acknowledgement</td>
        <td><?= count($queues['ocAwaitingAck']) ?></td>
        <td><?= nc_order_links($queues['ocAwaitingAck']) ?></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2>Supplier PO</h2>
    <table class="list">
      <tr><th>Queue</th><th>Count</th><th>Orders</th></tr>
      <tr>
        <td>Reached Supplier PO stage — nothing drafted for our supplier yet</td>
        <td><?= count($queues['supplierPoNeeded']) ?></td>
        <td><?= nc_order_links($queues['supplierPoNeeded']) ?></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2>Commercial Invoice, BL &amp; Balance Payment</h2>
    <table class="list">
      <tr><th>Queue</th><th>Count</th><th>Orders</th></tr>
      <tr>
        <td>CI issued — scanned BL not yet sent to buyer</td>
        <td><?= count($queues['blAwaitingSend']) ?></td>
        <td><?= nc_order_links($queues['blAwaitingSend']) ?></td>
      </tr>
      <tr>
        <td>Scanned BL sent — balance payment not yet received</td>
        <td><?= count($queues['balanceAwaited']) ?></td>
        <td><?= nc_order_links($queues['balanceAwaited']) ?></td>
      </tr>
      <tr>
        <td>Balance received — hard-copy document set not yet couriered</td>
        <td><?= count($queues['hardCopyAwaited']) ?></td>
        <td><?= nc_order_links($queues['hardCopyAwaited']) ?></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2>Funnel Activity — date range</h2>
    <form method="get" action="/reports/queues" class="section">
      <div class="kv-grid">
        <label>Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
        <label>Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
      </div>
      <div class="btn-row">
        <button type="submit" class="btn-sm">Run</button>
        <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/queues?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv&section=funnel">Export Funnel CSV</a>
      </div>
    </form>
    <table class="list">
      <tr><th>Metric</th><th>Count</th></tr>
      <tr><td>Quotations sent (all)</td><td><?= (int) $funnel['quotationsSent'] ?></td></tr>
      <tr><td>Quotations lost (marked lost before reaching PI)</td><td><?= (int) $funnel['quotationsLost'] ?></td></tr>
      <tr><td>Quotations won (reached PI)</td><td><?= (int) $funnel['quotationsWon'] ?></td></tr>
      <tr><td>PI sent (all)</td><td><?= (int) $funnel['piSent'] ?></td></tr>
      <tr><td>PI lost (marked lost after reaching PI, before CI)</td><td><?= (int) $funnel['piLost'] ?></td></tr>
      <tr><td>Amendments</td><td><?= (int) $funnel['amendments'] ?></td></tr>
    </table>
    <p class="muted small">"Lost" only counts orders explicitly marked lost (Order screen &rarr; Mark as Lost, reason required). An order still active and simply slow-moving is not counted as lost here.</p>
  </div>
</div>
