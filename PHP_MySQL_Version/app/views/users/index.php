<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Users</h1>
  <p class="muted">Create logins, edit an existing one, deactivate one, or force a password reset. A temporary password is shown once when you create a user or force-reset one — hand it to them directly (never emailed) since they're forced to set their own on first login regardless.</p>

  <div class="section">
    <h2>Existing Users</h2>
    <div class="btn-row" style="margin-bottom:0.6rem;">
      <a class="btn-sm <?= $currentStatus === 'active' ? '' : 'btn-secondary' ?>" href="/users?status=active">Active (<?= (int) $statusCounts['active'] ?>)</a>
      <a class="btn-sm <?= $currentStatus === 'inactive' ? '' : 'btn-secondary' ?>" href="/users?status=inactive">Inactive (<?= (int) $statusCounts['inactive'] ?>)</a>
      <a class="btn-sm <?= $currentStatus === 'archived' ? '' : 'btn-secondary' ?>" href="/users?status=archived">Archived (<?= (int) $statusCounts['archived'] ?>)</a>
    </div>
    <table class="list">
      <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th>Last Login</th><th>Actions</th></tr>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['name']) ?><?php if ((int) $u['is_protected_account'] === 1): ?> <span class="badge badge-protected">Protected founder</span><?php endif; ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['role_name'] ?? '—') ?></td>
        <td>
          <?php if ((int) $u['is_archived'] === 1): ?>
            <span class="badge badge-inactive">Archived</span><br><small class="muted">Since <?= htmlspecialchars((string) $u['archived_at']) ?></small>
          <?php elseif ($u['is_active']): ?>
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
            <span class="muted small">Protected — cannot be edited, deactivated, archived, deleted, or password-reset by anyone else. They can recover their own login via "Forgot password" on the login screen.</span>
          <?php elseif ((int) $u['is_archived'] === 1): ?>
            <form method="post" action="/users/<?= (int) $u['id'] ?>/unarchive" style="display:inline" onsubmit="return confirm('Unarchive <?= htmlspecialchars(addslashes($u['name'])) ?>? They will still need to be separately reactivated to log in again.');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-secondary">Unarchive</button>
            </form>
            <form method="post" action="/users/<?= (int) $u['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($u['name'])) ?>? This cannot be undone, and only succeeds if they have no order/document/audit history on file.');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger">Delete</button>
            </form>
          <?php else: ?>
          <a href="/users/<?= (int) $u['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/users/<?= (int) $u['id'] ?>/toggle-active" style="display:inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($u['name'])) ?>?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <form method="post" action="/users/<?= (int) $u['id'] ?>/archive" style="display:inline" onsubmit="return confirm('Archive <?= htmlspecialchars(addslashes($u['name'])) ?>? They will no longer be able to log in, and will move out of the Active/Inactive lists into Archived.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-secondary">Archive</button>
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
      <?php if (empty($users)): ?><tr><td colspan="7" class="muted">No users in this view.</td></tr><?php endif; ?>
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
