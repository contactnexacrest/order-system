<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Set your password</h1>
  <?php if (!$valid): ?>
    <p>This link has expired or was already used. <a href="/associate/forgot-password">Get a new link</a>, or ask your NexaCrest relationship manager for one.</p>
  <?php else: ?>
    <p class="muted small">Your login e-mail: <b><?= htmlspecialchars((string) $email) ?></b></p>
    <form method="post" action="/associate/set-password/<?= htmlspecialchars($token) ?>">
      <?= Csrf::field() ?>
      <label>New password <small class="muted">(at least 10 characters, letters and digits)</small><input type="password" name="new_password" required minlength="10" maxlength="200" pattern="(?=.*[A-Za-z])(?=.*\d).{10,}" title="At least 10 characters, with letters and digits" autocomplete="new-password" placeholder="At least 10 characters, letters and digits"></label>
      <label>Re-enter the new password<input type="password" name="confirm_password" required minlength="10" maxlength="200" autocomplete="new-password" placeholder="Type the new password again"></label>
      <button type="submit">Set password</button>
    </form>
  <?php endif; ?>
</div>
