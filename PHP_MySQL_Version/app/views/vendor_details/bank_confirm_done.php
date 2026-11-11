<?php /** D-294: after the vendor's choice on the bank confirmation link. Node twin: bank_confirm_done.njk. */ ?>
<div class="card card-narrow">
  <?php if ($confirmed): ?>
    <h1>&#10003; Thank you — confirmed</h1>
    <p>NexaCrest will now check the new bank details. Until then, payments go to the account we already have.</p>
  <?php else: ?>
    <h1>Cancelled</h1>
    <p data-not-us>Your bank details have not changed. We have told our Managing Director and Finance team. If someone contacted you about your bank details, please reply to our e-mail.</p>
  <?php endif; ?>
  <p class="muted small">This link has now been used.</p>
</div>
