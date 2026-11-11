<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Associate Login</h1>
  <p class="muted small">For NexaCrest associates. After your password we e-mail you a 6-digit code.</p>
  <form method="post" action="/associate/login">
    <?= Csrf::field() ?>
    <label>E-mail<input type="email" name="email" required autofocus maxlength="190" placeholder="e.g. name@company.com"></label>
    <label>Password<input type="password" name="password" required maxlength="200" placeholder="Your password"></label>
    <button type="submit">Continue</button>
  </form>
  <p class="small"><a href="/associate/forgot-password">Forgot password?</a></p>
  <p class="muted small">No access yet? Ask your NexaCrest relationship manager to send you a set-password link.</p>
</div>
