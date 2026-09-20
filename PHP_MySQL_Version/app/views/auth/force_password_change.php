<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Set a new password</h1>
  <p>You must set a new password before continuing.</p>
  <form method="post" action="/force-password-change">
    <?= Csrf::field() ?>
    <label>New password (min. 10 characters)
      <input type="password" name="new_password" minlength="10" required autofocus>
    </label>
    <label>Confirm new password
      <input type="password" name="confirm_password" minlength="10" required>
    </label>
    <button type="submit">Set password</button>
  </form>
</div>
