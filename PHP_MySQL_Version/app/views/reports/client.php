<?php use App\Helpers\Mask; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Client Report — <?= htmlspecialchars($client['company_legal_name']) ?></h1>
  <p class="muted">Buyer Inquiry Ref: <strong><?= htmlspecialchars($client['client_unique_number']) ?></strong></p>
  <div class="btn-row">
    <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/client/<?= (int) $client['id'] ?>?format=csv">Export Orders CSV</a>
    <a class="btn-sm btn-secondary" href="/clients/<?= (int) $client['id'] ?>">View Client</a>
  </div>

  <div class="section">
    <h2>Totals</h2>
    <p class="muted small">Totals are broken down by currency — a client's orders can be in different currencies, so a single combined number would be meaningless.</p>
    <div class="kv-grid">
      <div>
        <span class="k">Total FOB Value (all orders)</span>
        <span class="v">
          <?php if (empty($total_fob_value_by_currency)): ?>—<?php else: ?>
            <?php foreach ($total_fob_value_by_currency as $cc => $amt): ?>
              <?= htmlspecialchars($cc) ?> <?= number_format($amt, 2) ?><br>
            <?php endforeach; ?>
          <?php endif; ?>
        </span>
      </div>
      <div>
        <span class="k">Total Payments Cleared</span>
        <span class="v">
          <?php if (empty($total_cleared_by_currency)): ?>—<?php else: ?>
            <?php foreach ($total_cleared_by_currency as $cc => $amt): ?>
              <?= htmlspecialchars($cc) ?> <?= number_format($amt, 2) ?><br>
            <?php endforeach; ?>
          <?php endif; ?>
        </span>
      </div>
      <div><span class="k">Order Count</span><span class="v"><?= count($orders) ?></span></div>
      <div><span class="k">Contact Email</span><span class="v"><?= $canViewFullEmail ? htmlspecialchars($client['email'] ?? '—') : htmlspecialchars(Mask::email($client['email'] ?? null)) ?></span></div>
    </div>
  </div>

  <div class="section">
    <h2>Order History</h2>
    <table class="list">
      <tr><th>Order Ref</th><th>Status</th><th>Stage</th><th>Incoterm</th><th>Currency</th><th>Created</th><th></th></tr>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= htmlspecialchars($o['order_reference']) ?></td>
        <td><?= htmlspecialchars(ucfirst($o['status'])) ?></td>
        <td><?= htmlspecialchars($o['current_stage_name'] ?? 'Quotation') ?></td>
        <td><?= htmlspecialchars($o['incoterm_code']) ?></td>
        <td><?= htmlspecialchars($o['currency_code']) ?></td>
        <td><?= htmlspecialchars($o['created_at']) ?></td>
        <td><a href="/orders/<?= (int) $o['id'] ?>">View</a> · <a href="/reports/order/<?= (int) $o['id'] ?>">Full Report</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?><tr><td colspan="7" class="muted">No orders yet.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Documents (all orders)</h2>
    <table class="list">
      <tr><th>Type</th><th>Reference</th><th>Rev.</th><th>Status</th><th>Generated</th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['type_code']) ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= (int) $d['revision_number'] ?></td>
        <td><?= htmlspecialchars($d['status']) ?></td>
        <td><?= htmlspecialchars($d['generated_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?><tr><td colspan="5" class="muted">No documents yet.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Payments</h2>
    <table class="list">
      <tr><th>Advance</th><th>Advance Cleared</th><th>Balance</th><th>Balance Cleared</th><th>Freight</th><th>Freight Cleared</th></tr>
      <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= $p['advance_amount'] !== null ? number_format((float) $p['advance_amount'], 2) : '—' ?></td>
        <td><?= htmlspecialchars($p['advance_cleared_at'] ?? '—') ?></td>
        <td><?= $p['balance_amount'] !== null ? number_format((float) $p['balance_amount'], 2) : '—' ?></td>
        <td><?= htmlspecialchars($p['balance_cleared_at'] ?? '—') ?></td>
        <td><?= $p['freight_amount'] !== null ? number_format((float) $p['freight_amount'], 2) : '—' ?></td>
        <td><?= htmlspecialchars($p['freight_cleared_at'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($payments)): ?><tr><td colspan="6" class="muted">No payment records yet.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Products (all orders)</h2>
    <table class="list">
      <tr><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>FOB Value</th></tr>
      <?php foreach ($products as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['description']) ?></td>
        <td><?= $p['quantity_is_tbc'] ? 'TBC' : rtrim(rtrim(number_format((float) $p['quantity'], 3), '0'), '.') ?></td>
        <td><?= htmlspecialchars($p['unit'] ?? '—') ?></td>
        <td><?= $p['unit_price'] !== null ? number_format((float) $p['unit_price'], 2) : '—' ?></td>
        <td><?= $p['fob_value'] !== null ? number_format((float) $p['fob_value'], 2) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($products)): ?><tr><td colspan="5" class="muted">No product lines yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
