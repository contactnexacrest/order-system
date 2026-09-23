<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Users</h1>
  <p class="muted">Create logins, edit an existing one, deactivate one, or force a password reset. A temporary password is shown once when you create a user or force-reset one — hand it to them directly (never emailed) since they're forced to set their own on first login regardless.</p>

  <div class="section">
    <h2>Existing Users</h2>
    <table class="list">
      <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th>Last Login</th><th>Actions</th></tr>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['name']) ?><?php if ((int) $u['is_protected_account'] === 1): ?> <span class="badge badge-protected">Protected founder</span><?php endif; ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['role_name'] ?? '—') ?></td>
        <td>
          <?php if ($u['is_active']): ?>
            <span class="badge badge-active">Active</span>
          <?php else: ?>
            <span class="badge badge-inactive">Deactivated</span>
          <?php endif; ?>
          <?php if ($u['force_password_change']): ?><br><small class="muted">Must change password</small><?php endif; ?>
          <?php if ($u['locked_until']): ?><br><small class="muted">Locked until <?= htmlspecialchars($u['locked_until']) ?></small><?php endif; ?>
        </td>
        <td><?= $u['two_fa_enabled'] ? 'On' : '—' ?></td>
        <td><?= htmlspecialchars($u['last_login_at'] ?? 'Never') ?></td>
        <td>
          <?php if ((int) $u['is_protected_account'] === 1): ?>
            <span class="muted small">Protected — cannot be edited, deactivated, or password-reset by anyone else. They can recover their own login via "Forgot password" on the login screen.</span>
          <?php else: ?>
          <a href="/users/<?= (int) $u['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/users/<?= (int) $u['id'] ?>/toggle-active" style="display:inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($u['name'])) ?>?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <details style="margin-top:0.4rem;">
            <summary class="muted small">Force password reset</summary>
            <form method="post" action="/users/<?= (int) $u['id'] ?>/force-reset-password" onsubmit="return confirmForceReset(this, '<?= htmlspecialchars(addslashes($u['name'])) ?>');">
              <?= Csrf::field() ?>
              <textarea class="reset-reason" name="reason" rows="2" placeholder="Reason for this reset *" required></textarea>
              <button type="submit" class="btn-sm btn-danger">Force Reset</button>
            </form>
          </details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="section">
    <h2>Create User</h2>
    <form method="post" action="/users/create">
      <?= Csrf::field() ?>
      <div class="kv-grid">
        <label>Name * <input type="text" name="name" required></label>
        <label>Email * <input type="email" name="email" required></label>
        <label>Phone <input type="text" name="phone"></label>
        <label>Role
          <select name="role_id">
            <option value="">— none —</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <button type="submit" class="btn-sm">Create User</button>
    </form>
  </div>
</div>
<script>
function confirmForceReset(form, name) {
  var reasonEl = form.querySelector('.reset-reason');
  if (!reasonEl || reasonEl.value.trim() === '') {
    alert('A reason is required before resetting.');
    return false;
  }
  return confirm('Force-reset the password for ' + name + '? A new one-time temporary password will be generated and shown once. This is logged.');
}
</script>
