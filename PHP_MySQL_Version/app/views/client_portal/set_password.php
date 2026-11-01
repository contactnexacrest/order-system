<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Set Your Password</h1>
  <p class="muted small">For <?= htmlspecialchars($email) ?></p>
  <form method="post" action="/client/set-password/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>
    <label>New Password
      <input type="password" name="new_password" required autofocus placeholder="At least 10 characters">
    </label>
    <label>Confirm Password
      <input type="password" name="confirm_password" required placeholder="Type the new password again">
    </label>
    <button type="submit">Set Password</button>
  </form>
</div>
