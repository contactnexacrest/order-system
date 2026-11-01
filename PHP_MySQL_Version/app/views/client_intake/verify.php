<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Confirm your request</h1>
  <p>We e-mailed a 6-digit code to <b><?= htmlspecialchars((string) $s['email']) ?></b>. Enter it below to confirm your quotation request for <b><?= htmlspecialchars((string) $s['company_legal_name']) ?></b>.</p>
  <form method="post" action="/quotation-details/verify">
    <?= Csrf::field() ?>
    <label>Code from the e-mail *<input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code"></label>
    <fieldset><legend>Did <b><?= htmlspecialchars((string) $s['associate_name']) ?></b> introduce you to NexaCrest? *</legend>
      <label class="checkbox"><input type="radio" name="introduced" value="yes" required<?= (int) $s['introduction_confirmed'] === 1 ? ' checked' : '' ?>> Yes</label>
      <label class="checkbox"><input type="radio" name="introduced" value="no"> No</label>
    </fieldset>
    <button type="submit">Confirm</button>
  </form>
  <p class="muted small">The code is valid for 30 minutes. If it has expired, submit this page and we will send a new one.</p>
</div>
