<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Verification code</h1>
  <p>Enter the 6-digit code sent to you.</p>
  <?php if (!empty($devCode)): ?>
    <div class="alert alert-dev">DEV MODE ONLY (APP_ENV=local) — code is: <strong><?= htmlspecialchars($devCode) ?></strong></div>
  <?php endif; ?>
  <form method="post" action="/2fa">
    <?= Csrf::field() ?>
    <label>Code
      <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
    </label>
    <button type="submit">Verify</button>
  </form>
</div>
