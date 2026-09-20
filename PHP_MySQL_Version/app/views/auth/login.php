<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Sign in</h1>
  <form method="post" action="/login">
    <?= Csrf::field() ?>
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button type="submit">Sign in</button>
  </form>
  <p class="muted small"><a href="/forgot-password">Forgot your password?</a></p>
</div>
