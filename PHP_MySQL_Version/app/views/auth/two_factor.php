<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Verification code</h1>
  <p>We have e-mailed you a 6-digit code. Enter it below to finish signing in. The code is valid for 10 minutes.</p>
  <?php if (!empty($devCode)): ?>
    <div class="alert alert-dev">DEV MODE ONLY (APP_ENV=local) — code is: <strong><?= htmlspecialchars($devCode) ?></strong></div>
  <?php endif; ?>
  <form method="post" action="/2fa">
    <?= Csrf::field() ?>
    <label>Code *
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus placeholder="e.g. 123456">
    </label>
    <?php if (!empty($rememberDays)): ?>
      <label class="full"><input type="checkbox" name="remember_browser" value="1"> This is my own office computer — don't ask for the code here for <?= (int) $rememberDays ?> day(s)</label>
      <p class="muted small" style="margin-top:-6px">Tick only on a computer nobody else uses. A password change forgets it; you can also forget all remembered computers under My Account.</p>
    <?php endif; ?>
    <button type="submit">Verify</button>
  </form>
  <form method="post" action="/2fa/resend" class="inline-form" style="margin-top:12px">
    <?= Csrf::field() ?>
    <button type="submit" class="btn-link">Send me a new code</button> <span class="muted small">(at most once a minute)</span>
  </form>
  <?php if (!empty($backupAllowed)): ?>
    <details class="collapsible-section" style="margin-top:16px" data-backup-code>
      <summary>Use a backup code instead</summary>
      <p class="small muted">Only if the e-mailed code cannot reach you. Each backup code works once; using one is recorded and the company-settings holders are told.</p>
      <form method="post" action="/2fa/backup">
        <?= Csrf::field() ?>
        <label>Backup code *
          <input type="text" name="backup_code" autocomplete="off" pattern="[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}" minlength="8" maxlength="9" required placeholder="e.g. ABCD-2345">
        </label>
        <button type="submit" class="btn-secondary">Sign in with the backup code</button>
      </form>
    </details>
  <?php endif; ?>
  <p class="small"><a href="/login">Back to sign in</a></p>
</div>
