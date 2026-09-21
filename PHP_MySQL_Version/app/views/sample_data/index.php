<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Sample Data Playground</h1>
  <p class="muted">Load a small set of realistic-looking clients and orders — tagged "[SAMPLE]" everywhere they show up — to explore the system without touching real data. Clear them again whenever you like; this only ever removes rows flagged as sample data, and it never looks at anything else in the database. Load it, play with it, clear it, load it again — as many times as you want, for anyone using this system.</p>

  <?php if ($isLoaded): ?>
    <div class="section">
      <h2>Currently loaded</h2>
      <table class="list">
        <tr><th>Client</th><th>Orders</th></tr>
        <?php foreach ($summary as $client): ?>
        <tr>
          <td>
            <?= htmlspecialchars($client['company_legal_name']) ?><br>
            <small class="muted"><?= htmlspecialchars($client['client_unique_number']) ?></small>
          </td>
          <td>
            <?php foreach ($client['orders'] as $order): ?>
              <div>
                <a href="/orders/<?= (int) $order['id'] ?>"><?= htmlspecialchars($order['order_reference']) ?></a>
                — <?= htmlspecialchars($order['current_stage_name'] ?? 'Stage 1') ?>
              </div>
            <?php endforeach; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>

      <form method="post" action="/sample-data/clear" style="margin-top:1rem;" onsubmit="return confirm('Remove all sample clients/orders and their generated documents? This only affects rows flagged as sample data — your real clients and orders are never touched. This cannot be undone, but you can load fresh sample data again right after.');">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-sm btn-danger">Clear Sample Data</button>
      </form>
    </div>
  <?php else: ?>
    <div class="section">
      <h2>Nothing loaded right now</h2>
      <p class="muted small">Loading creates 3 sample clients and 3 sample orders: one brand-new order at Stage 1 (to practice creating documents from scratch); one FOB order carried through to Stage 5 with a Quotation, Proforma Invoice and Order Confirmation generated, and an advance payment recorded and cleared (to see the dashboard/reports with some real-looking numbers in them); and one CIF order on the "Established Buyer — Post-BL" preset, taken all the way through Stage 9 to a closed order — every one of the nine document types generated, including the CFR/CIF-only Freight Payment stage.</p>
      <form method="post" action="/sample-data/load">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-sm">Load Sample Data</button>
      </form>
    </div>
  <?php endif; ?>
</div>
