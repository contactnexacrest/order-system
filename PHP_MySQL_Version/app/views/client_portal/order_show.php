<?php use App\Helpers\Csrf; use App\Helpers\Dates; ?>
<div class="card page-wide">
  <h1>Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted small"><a href="/client">&larr; Back to My Orders</a></p>

  <?php if (!empty($ocAcknowledgment) && $ocAcknowledgment['acknowledged_at'] === null): ?>
  <div class="section">
    <h2>Order Confirmation — Your Acknowledgement Needed</h2>
    <p>Please review your Order Confirmation below and confirm to proceed.</p>
    <table class="list">
      <tr><th>Order Reference</th><td><?= htmlspecialchars($order['order_reference']) ?></td></tr>
      <tr><th>Product(s)</th><td><?php
        $lines = [];
        foreach ($products as $p) {
            $lines[] = htmlspecialchars($p['description']) . ' (Qty: ' . htmlspecialchars((string) $p['quantity']) . ' ' . htmlspecialchars($p['unit'] ?? '') . ')';
        }
        echo implode('<br>', $lines) ?: '—';
      ?></td></tr>
      <tr><th>Total Order Value</th><td><?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $totalFobValue, 2) ?></td></tr>
      <tr><th>Incoterm</th><td><?= htmlspecialchars($order['incoterm_code'] ?? '—') ?></td></tr>
      <tr><th>Port of Discharge</th><td><?= htmlspecialchars($order['port_of_discharge_name'] ?? '—') ?></td></tr>
    </table>
    <p class="muted small">The full Order Confirmation document is available to download above under Documents.</p>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/acknowledge-oc">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-success">I acknowledge and confirm to proceed</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Documents</h2>
    <table class="list">
      <tr><th>Document</th><th>Reference</th><th>Date</th><th></th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['document_type_name']) ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= htmlspecialchars(Dates::human($d['generated_at'])) ?></td>
        <td><a href="/client/documents/<?= (int) $d['id'] ?>/download">Download PDF</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?>
        <tr><td colspan="4" class="muted">No documents available yet — check back once your order progresses.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Report a Payment</h2>
    <p class="muted">Made a payment on this order? Let us know the transaction details below — our team will verify it against our bank statement before updating your order.</p>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/report-payment" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <label>Which Payment? *
        <select name="payment_type" required>
          <option value="">Select…</option>
          <option value="advance">Advance Payment</option>
          <option value="balance">Balance Payment</option>
          <option value="freight">Freight Payment</option>
        </select>
      </label>
      <label>Transaction ID / UTR *<input type="text" name="transaction_ref" required></label>
      <label>Amount Paid<input type="text" name="amount" placeholder="e.g. 12500.00"></label>
      <label>Date Paid<input type="date" name="payment_date"></label>
      <label>Your Bank / Payer Details<input type="text" name="payer_bank_details" placeholder="e.g. Sent from HSBC UK, account ending 4321"></label>
      <label>Payment Screenshot (optional)<input type="file" name="screenshot" accept=".pdf,.jpg,.jpeg,.png"></label>
      <button type="submit">Submit Payment Details</button>
    </form>

    <?php if (!empty($paymentReports)): ?>
      <h3 style="margin-top:20px">Previously Reported</h3>
      <table class="list">
        <tr><th>Payment</th><th>Transaction ID / UTR</th><th>Amount</th><th>Date</th><th>Status</th></tr>
        <?php foreach ($paymentReports as $r): ?>
        <tr>
          <td><?= htmlspecialchars(ucfirst($r['payment_type'])) ?></td>
          <td><?= htmlspecialchars($r['transaction_ref']) ?></td>
          <td><?= $r['amount'] !== null ? number_format((float) $r['amount'], 2) : '—' ?></td>
          <td><?= htmlspecialchars($r['payment_date'] ?? '—') ?></td>
          <td><?= $r['status'] === 'reviewed' ? 'Reviewed by our team' : 'Received — pending review' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
