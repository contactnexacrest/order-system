<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;

// Owner request (2026-10-26): same look as the Quotation/PI details forms —
// a wide card, a summary bar and numbered sections — instead of one narrow
// column. Sections appear in the order the buyer needs them.
$ocPending = !empty($ocAcknowledgment) && $ocAcknowledgment['acknowledged_at'] === null;
$ocDone = !empty($ocAcknowledgment) && $ocAcknowledgment['acknowledged_at'] !== null;
$section = 0;
?>
<div class="card">
  <h1>Your Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted">For <?= htmlspecialchars($order['company_legal_name'] ?? '') ?> — everything you need to do on this order, in one place. No login needed.</p>
  <div class="intake-summary">
    <span>&#128230; <?= htmlspecialchars((string) count($products)) ?> product line<?= count($products) === 1 ? '' : 's' ?></span>
    <span>&#128176; <?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $totalFobValue, 2) ?></span>
    <span>&#128674; <?= htmlspecialchars($order['incoterm_code'] ?? '—') ?><?= !empty($order['port_of_discharge_name']) ? ' · ' . htmlspecialchars($order['port_of_discharge_name']) : '' ?></span>
  </div>

  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num"><?= ++$section ?></span> Your Purchase Order
      <?php if ($buyerPoConfirmed): ?><span class="action-status done">&#10003; Received</span><?php elseif (!empty($buyerPoDocuments)): ?><span class="action-status">Received — being checked</span><?php else: ?><span class="action-status todo">Action needed</span><?php endif; ?>
    </legend>
    <?php if ($buyerPoConfirmed): ?>
      <p>Thank you — we have your signed Purchase Order<?= !empty($order['buyers_po_ref']) && $order['buyers_po_ref'] !== 'NIL' ? ' <strong>' . htmlspecialchars($order['buyers_po_ref']) . '</strong>' : '' ?>. Nothing more is needed here.</p>
    <?php else: ?>
      <ol class="action-howto">
        <li><strong>Download</strong> the Purchase Order we prepared for you.</li>
        <li><strong>Sign and stamp</strong> it.</li>
        <li><strong>Upload</strong> the signed copy below with your PO number.</li>
      </ol>
      <?php if ($issuedBuyerPo): ?>
        <p><a class="btn btn-secondary" href="/buyer-action/<?= htmlspecialchars($token) ?>/buyer-po-document">&#11015; Download Purchase Order (<?= htmlspecialchars((string) $issuedBuyerPo['document_reference']) ?>)</a></p>
      <?php else: ?>
        <p class="intake-note">Your Purchase Order is being prepared — you can already upload your own signed PO below if you have one.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($buyerPoDocuments)): ?>
      <table class="list">
        <tr><th>Uploaded file</th><th>Uploaded</th></tr>
        <?php foreach ($buyerPoDocuments as $doc): ?>
        <tr>
          <td><?= htmlspecialchars($doc['original_filename']) ?></td>
          <td><?= htmlspecialchars(Dates::human($doc['uploaded_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php if ($advanceCleared): ?>
      <p class="muted small">The advance payment has already cleared, so uploads here are closed. Contact your NexaCrest representative if you need to send an updated copy.</p>
    <?php elseif (!$buyerPoConfirmed): ?>
      <form method="post" action="/buyer-action/<?= htmlspecialchars($token) ?>/buyer-po" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="field-grid">
          <label>Your PO Number *<input type="text" name="buyers_po_ref" required placeholder="e.g., PO-2026-0142"></label>
          <label>Signed Purchase Order *<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.eml,.msg" required></label>
        </div>
        <button type="submit">Upload Signed PO</button>
      </form>
    <?php endif; ?>
  </fieldset>

  <?php if ($ocPending || $ocDone): ?>
  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num"><?= ++$section ?></span> Order Confirmation
      <?php if ($ocDone): ?><span class="action-status done">&#10003; Acknowledged</span><?php else: ?><span class="action-status todo">Action needed</span><?php endif; ?>
    </legend>
    <?php if ($ocDone): ?>
      <p>Thank you — you acknowledged the Order Confirmation on <?= htmlspecialchars(Dates::human($ocAcknowledgment['acknowledged_at'])) ?>.</p>
    <?php else: ?>
      <p><strong>Your Acknowledgement Needed</strong> — please check the order below and confirm to proceed.</p>
      <div class="action-kv">
        <div><span class="k">Order Reference</span><span class="v"><?= htmlspecialchars($order['order_reference']) ?></span></div>
        <div><span class="k">Total Order Value</span><span class="v"><?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $totalFobValue, 2) ?></span></div>
        <div><span class="k">Incoterm</span><span class="v"><?= htmlspecialchars($order['incoterm_code'] ?? '—') ?></span></div>
        <div><span class="k">Port of Discharge</span><span class="v"><?= htmlspecialchars($order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? '—') ?></span></div>
      </div>
      <table class="list">
        <tr><th>Product</th><th>Quantity</th></tr>
        <?php foreach ($products as $p): ?>
        <tr><td><?= htmlspecialchars($p['description']) ?></td><td><?= htmlspecialchars((string) $p['quantity']) ?> <?= htmlspecialchars($p['unit'] ?? '') ?></td></tr>
        <?php endforeach; ?>
      </table>
      <form method="post" action="/buyer-action/<?= htmlspecialchars($token) ?>/acknowledge-oc">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-success">I acknowledge and confirm to proceed</button>
      </form>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <?php if (!$advanceCleared): ?>
  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num"><?= ++$section ?></span> Advance Payment <small class="muted">(once you've paid)</small></legend>
    <p class="muted small">Made your advance payment? Tell us the transaction details — our team checks it against our bank statement before updating your order. Once it clears you'll also get client portal access.</p>
    <form method="post" action="/buyer-action/<?= htmlspecialchars($token) ?>/report-payment" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <label>Transaction ID / UTR *<input type="text" name="transaction_ref" required></label>
        <label>Amount Paid<input type="text" name="amount" placeholder="e.g. 12500.00"></label>
        <label>Date Paid<input type="date" name="payment_date"></label>
        <label>Your Bank / Payer Details<input type="text" name="payer_bank_details" placeholder="e.g. Sent from HSBC UK, account ending 4321"></label>
        <label class="full">Payment Screenshot / Proof of Payment (optional)<input type="file" name="screenshot" accept=".pdf,.jpg,.jpeg,.png"></label>
      </div>
      <button type="submit">Submit Payment Details</button>
    </form>
  </fieldset>
  <?php endif; ?>

  <p class="muted small">Need to see your full order, documents, or message our team? <a href="/client/login">Log in to your client portal</a> (set up automatically once your advance payment clears).</p>
</div>
