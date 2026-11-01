<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Confirm your request</h1>
  <p>We e-mailed a 6-digit code to <b><?= htmlspecialchars((string) $s['email']) ?></b>. Enter it below to confirm your quotation request for <b><?= htmlspecialchars((string) $s['company_legal_name']) ?></b>.</p>
  <form method="post" action="/quotation-details/verify">
    <?= Csrf::field() ?>
    <label>Code from the e-mail *<input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code"></label>
    <button type="submit">Confirm</button>
  </form>
  <p class="muted small">The code is valid for 30 minutes. If it has expired, submit this page and we will send a new one.</p>
</div>
