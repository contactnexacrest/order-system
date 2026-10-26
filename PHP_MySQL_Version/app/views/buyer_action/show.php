<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;

// Owner request (2026-10-26): same look as the Quotation/PI details forms,
// and only ONE thing for the client to do at a time — $task (from
// BuyerActionService::currentTask()) picks the section; the strip on top
// shows where they are: Purchase Order → Advance Payment → Order Confirmation.
$progress = [
    'po'  => ['Purchase Order', $task === 'buyer_po' ? 'current' : 'done'],
    'pay' => ['Advance Payment', in_array($task, ['buyer_po', 'waiting_pi'], true) ? 'todo' : (in_array($task, ['advance', 'advance_reported'], true) ? 'current' : 'done')],
    'oc'  => ['Order Confirmation', $task === 'oc' ? 'current' : ($task === 'done' ? 'done' : 'todo')],
];
if ($task === 'waiting' && empty($advanceCleared) && ($order['payment_method'] ?? '') === 'LC') {
    $progress['pay'][1] = 'current';
}
?>
<div class="card">
  <h1>Your Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted">For <?= htmlspecialchars($order['company_legal_name'] ?? '') ?> — no login needed. We'll show you just the one thing to do next.</p>
  <div class="intake-summary">
    <span>&#128230; <?= htmlspecialchars((string) count($products)) ?> product line<?= count($products) === 1 ? '' : 's' ?></span>
    <span>&#128176; <?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $totalFobValue, 2) ?></span>
    <span>&#128674; <?= htmlspecialchars($order['incoterm_code'] ?? '—') ?><?= !empty($order['port_of_discharge_name']) ? ' · ' . htmlspecialchars($order['port_of_discharge_name']) : '' ?></span>
  </div>

  <ol class="action-progress" aria-label="Your steps">
    <?php $__n = 0; foreach ($progress as [$label, $state]): $__n++; ?>
      <li class="<?= $state ?>"><span class="dot"><?= $state === 'done' ? '&#10003;' : $__n ?></span><?= htmlspecialchars($label) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($task === 'buyer_po'): ?>
  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num">1</span> Your Purchase Order
      <?php if (!empty($buyerPoDocuments)): ?><span class="action-status">Received — being checked</span><?php else: ?><span class="action-status todo">Action needed</span><?php endif; ?>
    </legend>
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
    <?php if (!empty($advanceCleared)): ?>
      <p class="muted small">The advance payment has already cleared, so uploads here are closed. Contact your NexaCrest representative if you need to send an updated copy.</p>
    <?php else: ?>
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

  <?php elseif ($task === 'advance'): ?>
  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num">2</span> Advance Payment <span class="action-status todo">Action needed</span></legend>
    <ol class="action-howto">
      <li><strong>Download</strong> your Proforma Invoice and pay the advance shown on it.</li>
      <li><strong>Tell us</strong> the transaction details below — our team checks them against our bank statement.</li>
    </ol>
    <?php if ($issuedPi): ?>
      <p><a class="btn btn-secondary" href="/buyer-action/<?= htmlspecialchars($token) ?>/proforma-invoice">&#11015; Download Proforma Invoice (<?= htmlspecialchars((string) $issuedPi['document_reference']) ?>)</a></p>
    <?php endif; ?>
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

  <?php elseif ($task === 'oc'): ?>
  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num">3</span> Order Confirmation <span class="action-status todo">Action needed</span></legend>
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
  </fieldset>

  <?php else: ?>
  <div class="action-wait">
    <?php if ($task === 'waiting_pi'): ?>
      <h2>&#10003; Thank you — nothing to do right now</h2>
      <p>We have your signed Purchase Order<?= !empty($order['buyers_po_ref']) && $order['buyers_po_ref'] !== 'NIL' ? ' <strong>' . htmlspecialchars($order['buyers_po_ref']) . '</strong>' : '' ?>. We're preparing your Proforma Invoice and will email you when it's ready — then this same link will show you how to pay the advance.</p>
    <?php elseif ($task === 'advance_reported'): ?>
      <h2>&#10003; Thank you — we're checking your payment</h2>
      <p>We've received your advance payment details and our team is verifying them against our bank statement. We'll email you as soon as it's confirmed.</p>
    <?php elseif ($task === 'done'): ?>
      <h2>&#10003; All set — thank you</h2>
      <p>There's nothing left for you to do on this link. Your order is moving ahead; we'll keep you updated by email and in your <a href="/client/login">client portal</a>.</p>
    <?php else: ?>
      <h2>&#10003; Thank you — nothing to do right now</h2>
      <p>Your order is with our team. We'll email you when there's something for you to do next — this same link will show it.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <p class="muted small">Questions? Reply to the email this link came with<?= $task !== 'buyer_po' ? ', or <a href="/client/login">log in to your client portal</a> once your advance payment has cleared' : '' ?>.</p>
</div>
