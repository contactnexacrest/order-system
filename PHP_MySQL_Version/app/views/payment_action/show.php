<?php
use App\Helpers\Csrf;

// Owner request (2026-10-27): payment only, one payment at a time — the
// strip shows the order's payments (advance, freight on CFR/CIF, balance);
// below it, just the one the client acts on now.
$currency = (string) ($order['currency_code'] ?? '');
$labels = array_map(static fn(array $l): string => $l['label'], \App\Services\PaymentActionService::LEGS);
$legName = $legInfo['label'] ?? '';
?>
<div class="card">
  <h1>Payment Details — Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted">For <?= htmlspecialchars($order['company_legal_name'] ?? '') ?> — no login needed. Send us your payment details here once you have paid.</p>

  <ol class="action-progress" aria-label="Your payments">
    <?php $__n = 0; foreach ($current['legs'] as $leg => $state): $__n++; ?>
      <li class="<?= $state ?>"><span class="dot"><?= $state === 'done' ? '&#10003;' : $__n ?></span><?= htmlspecialchars($labels[$leg]) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($current['state'] === 'due'): ?>
  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num"><?= array_search($current['leg'], array_keys($current['legs']), true) + 1 ?></span> <?= htmlspecialchars($legName) ?> <span class="action-status todo">Action needed</span></legend>
    <ol class="action-howto">
      <li><strong>Download</strong> the <?= htmlspecialchars($legInfo['document']) ?> — our bank details are on it.</li>
      <li><strong>Pay</strong> <?= $amountDue !== null ? '<strong>' . htmlspecialchars($currency) . ' ' . number_format($amountDue, 2) . '</strong>' : 'the amount shown on it' ?> by T/T (bank transfer).</li>
      <li><strong>Send us</strong> the transaction details and a screenshot below — our team checks them against our bank statement.</li>
    </ol>
    <?php if ($document): ?>
      <p><a class="btn btn-secondary" href="/payment-action/<?= htmlspecialchars($token) ?>/document">&#11015; Download <?= htmlspecialchars($legInfo['document']) ?> (<?= htmlspecialchars((string) $document['document_reference']) ?>)</a></p>
    <?php endif; ?>
    <form method="post" action="/payment-action/<?= htmlspecialchars($token) ?>/report" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <label>Transaction ID / UTR *<input type="text" name="transaction_ref" required></label>
        <label>Amount Paid (<?= htmlspecialchars($currency) ?>) *<input type="number" step="0.01" min="0.01" name="amount" required value="<?= $amountDue !== null ? number_format($amountDue, 2, '.', '') : '' ?>"></label>
        <label>Date Paid *<input type="date" name="payment_date" required value="<?= date('Y-m-d') ?>"></label>
        <label>Your Bank / Payer Details<input type="text" name="payer_bank_details" placeholder="e.g. Sent from HSBC UK, account ending 4321"></label>
        <label class="full">Payment Screenshot / Bank Remittance Advice *<input type="file" name="screenshot" accept=".pdf,.jpg,.jpeg,.png" required></label>
      </div>
      <button type="submit">Send Payment Details</button>
    </form>
  </fieldset>
  <?php else: ?>
  <div class="action-wait">
    <?php if ($current['state'] === 'reported'): ?>
      <h2>&#10003; Thank you — we're checking your <?= htmlspecialchars(strtolower($legName)) ?></h2>
      <p>We have your payment details. Our team is verifying them against our bank statement and will confirm by email. Nothing more is needed from you right now.</p>
    <?php elseif ($current['state'] === 'waiting'): ?>
      <h2>&#10003; Nothing to pay right now</h2>
      <p>Your next payment is the <strong><?= htmlspecialchars(strtolower($legName)) ?></strong>, against our <?= htmlspecialchars($legInfo['document']) ?>. We'll email it to you when it's due — then this same link will show you what to pay.</p>
    <?php else: ?>
      <h2>&#10003; All payments received — thank you</h2>
      <p>There is nothing left to pay on this order.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <p class="muted small">Questions? Reply to the email this link came with.</p>
</div>
