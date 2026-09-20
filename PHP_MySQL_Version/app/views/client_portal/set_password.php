<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Set Your Password</h1>
  <p class="muted small">For <?= htmlspecialchars($email) ?></p>
  <form method="post" action="/client/set-password/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>
    <label>New Password
      <input type="password" name="new_password" required autofocus>
    </label>
    <label>Confirm Password
      <input type="password" name="confirm_password" required>
    </label>
    <button type="submit">Set Password</button>
  </form>
</div>
