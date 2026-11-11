<div class="card card-narrow">
  <h1>&#10003; Thank you</h1>
  <p>We have your details, <?= htmlspecialchars((string) $vendor[$meta['name']]) ?>. Our accounts team will verify the bank account before the first payment and may contact you for a cancelled cheque or bank letter.</p>
  <?php if (!empty($bankChanged)): // D-294 ?>
    <p data-bank-confirm-needed><b>One more step for your bank details:</b> <?= !empty($confirmTo) ? 'we have e-mailed a confirmation link to ' . htmlspecialchars((string) $confirmTo) . ' (the e-mail we have on file). Please open it and confirm within ' . \App\Services\BankDetailsCheck::CONFIRM_DAYS . ' days — we pay into new bank details only after you confirm them.' : 'we have no e-mail on file to send the confirmation link to — our team will contact you.' ?></p>
  <?php endif; ?>
  <p class="muted small">This link has now been used. Reply to our email if anything changes.</p>
</div>
