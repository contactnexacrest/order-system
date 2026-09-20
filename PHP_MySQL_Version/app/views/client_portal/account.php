<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>My Account</h1>
  <p><strong><?= htmlspecialchars($client['company_legal_name']) ?></strong><br><?= htmlspecialchars($client['email'] ?? '') ?></p>
  <p class="muted small">Your company details are managed by NexaCrest. The only thing you can change here is your password.</p>

  <h2>Change Password</h2>
  <form method="post" action="/client/account/password">
    <?= Csrf::field() ?>
    <label>Current Password
      <input type="password" name="current_password" required>
    </label>
    <label>New Password
      <input type="password" name="new_password" required>
    </label>
    <label>Confirm New Password
      <input type="password" name="confirm_password" required>
    </label>
    <button type="submit">Update Password</button>
  </form>
</div>
