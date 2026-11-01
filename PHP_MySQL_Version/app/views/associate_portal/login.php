<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>Associate Login</h1>
  <p class="muted small">For NexaCrest associates. After your password we e-mail you a 6-digit code.</p>
  <form method="post" action="/associate/login">
    <?= Csrf::field() ?>
    <label>E-mail<input type="email" name="email" required autofocus maxlength="190"></label>
    <label>Password<input type="password" name="password" required maxlength="200"></label>
    <button type="submit">Continue</button>
  </form>
  <p class="muted small">Forgot your password or no access yet? Ask your NexaCrest relationship manager to send you a new link.</p>
</div>
