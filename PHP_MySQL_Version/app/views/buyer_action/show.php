<?php use App\Helpers\Csrf; use App\Helpers\Dates; ?>
<div class="card">
  <h1>Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted"><?= htmlspecialchars($order['company_legal_name'] ?? '') ?></p>

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
    <form method="post" action="/buyer-action/<?= htmlspecialchars($token) ?>/acknowledge-oc">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-success">I acknowledge and confirm to proceed</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Buyer PO</h2>
    <p class="muted small">Once you've signed your Purchase Order, upload a scanned copy here any time — each upload is kept, so re-uploading a corrected copy never loses the earlier one.</p>
    <?php if (!empty($buyerPoDocuments)): ?>
      <table class="list">
        <tr><th>File</th><th>Uploaded</th></tr>
        <?php foreach ($buyerPoDocuments as $doc): ?>
        <tr>
          <td><?= htmlspecialchars($doc['original_filename']) ?></td>
          <td><?= htmlspecialchars(Dates::human($doc['uploaded_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="muted">No Buyer PO copy on file yet.</p>
    <?php endif; ?>
    <?php if ($advanceCleared): ?>
      <p class="muted small">The advance payment has already cleared, so self-service upload is now closed here. Contact your NexaCrest representative if you need to submit an updated copy.</p>
    <?php else: ?>
      <form method="post" action="/buyer-action/<?= htmlspecialchars($token) ?>/buyer-po" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label>Upload Buyer PO (signed) <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.eml,.msg" required></label>
        <button type="submit" class="btn-sm">Upload</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$advanceCleared): ?>
  <div class="section">
    <h2>Advance Payment</h2>
    <p class="muted">Made your advance payment on this order? Let us know the transaction details below — our team will verify it against our bank statement before updating your order. (Once your advance clears, you'll also get full client portal access to report any later payment the same way.)</p>
    <form method="post" action="/buyer-action/<?= htmlspecialchars($token) ?>/report-payment" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <label>Transaction ID / UTR *<input type="text" name="transaction_ref" required></label>
      <label>Amount Paid<input type="text" name="amount" placeholder="e.g. 12500.00"></label>
      <label>Date Paid<input type="date" name="payment_date"></label>
      <label>Your Bank / Payer Details<input type="text" name="payer_bank_details" placeholder="e.g. Sent from HSBC UK, account ending 4321"></label>
      <label>Payment Screenshot (optional)<input type="file" name="screenshot" accept=".pdf,.jpg,.jpeg,.png"></label>
      <button type="submit" class="btn-sm">Submit Payment Details</button>
    </form>
  </div>
  <?php endif; ?>

  <p class="muted small">Need to see your full order, documents, or message our team? <a href="/client/login">Log in to your client portal</a> (set up automatically once your advance payment clears).</p>
</div>
