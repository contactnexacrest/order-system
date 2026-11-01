<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Enter your code</h1>
  <p class="muted small">We e-mailed you a 6-digit code. It is valid for 10 minutes.</p>
  <form method="post" action="/associate/login/code">
    <?= Csrf::field() ?>
    <label>Code<input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" autofocus placeholder="6-digit code"></label>
    <button type="submit">Log in</button>
  </form>
  <p class="muted small"><a href="/associate/login">Start again</a></p>
</div>
