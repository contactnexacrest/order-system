<?php use App\Helpers\Csrf; ?>
<div class="card card-narrow">
  <h1>My Account</h1>
  <p class="muted small"><?= htmlspecialchars($user['name']) ?> &middot; <?= htmlspecialchars($user['email']) ?></p>

  <div class="section">
    <h2>Email Signature</h2>
    <p class="muted">Appended to every email you send through the system — document sends, order updates, anything else. Leave blank to fall back to your name and title.</p>
    <form method="post" action="/account/signature">
      <?= Csrf::field() ?>
      <label>Signature<textarea name="email_signature" rows="6" placeholder="Regards,&#10;Jane Doe&#10;Export Executive&#10;+91-XXXXXXXXXX"><?= htmlspecialchars($user['email_signature'] ?? '') ?></textarea></label>
      <button type="submit" class="btn-sm btn-accent">Save Signature</button>
    </form>
  </div>

  <div class="section">
    <h2>Notifications</h2>
    <p class="muted">The system emails a notification for every action logged against orders, clients, documents, payments, and more. This controls whether you personally receive a copy of your own actions — a separate, always-on notification address can also be configured by an admin (Settings &gt; Notifications).</p>
    <form method="post" action="/account/notifications">
      <?= Csrf::field() ?>
      <label><input type="checkbox" name="email_action_notifications_enabled" value="1" <?= !empty($user['email_action_notifications_enabled']) ? 'checked' : '' ?>> Email me when I perform an action in the system</label>
      <button type="submit" class="btn-sm btn-accent">Save Notification Preference</button>
    </form>
  </div>

  <div class="section">
    <h2>Change Password</h2>
    <form method="post" action="/account/password">
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
      <button type="submit" class="btn-sm btn-accent">Update Password</button>
    </form>
  </div>
</div>
