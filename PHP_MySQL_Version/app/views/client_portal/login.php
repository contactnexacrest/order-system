<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Client Login</h1>
  <p class="muted small">For NexaCrest buyers with an active order. Your login is set up automatically once your advance payment is cleared — check your email.</p>
  <form method="post" action="/client/login">
    <?= Csrf::field() ?>
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button type="submit">Sign in</button>
  </form>
</div>
