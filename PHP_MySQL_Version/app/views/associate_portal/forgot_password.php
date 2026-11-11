<?php use App\Helpers\Csrf; /* D-282 (11 Nov 2026): the associate's own "Forgot password?" */ ?>
<div class="card card-narrow">
  <h1>Forgot password</h1>
  <p class="muted small">Enter your e-mail. If it belongs to an associate account, we send a link to set a new password (valid 72 hours, works once).</p>
  <form method="post" action="/associate/forgot-password">
    <?= Csrf::field() ?>
    <label>E-mail *<input type="email" name="email" required autofocus maxlength="190" placeholder="e.g. name@company.com"></label>
    <button type="submit">Send link</button>
  </form>
  <p class="small"><a href="/associate/login">Back to log in</a></p>
</div>
