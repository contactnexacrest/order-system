<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Roles &amp; Permissions</h1>
  <p class="muted">The role matrix below is read-only — roles are seed-managed. What you can do here is give one specific person an extra permission beyond what their role gives them, with a mandatory reason. This is additive only: it can never take away a permission a role grants.</p>

  <div class="section">
    <h2>Role Matrix (read-only)</h2>
    <table class="list">
      <tr>
        <th>Permission</th>
        <?php foreach (array_keys($roleMatrix) as $roleName): ?>
          <th><?= htmlspecialchars($roleName) ?></th>
        <?php endforeach; ?>
      </tr>
      <?php foreach ($allPermissions as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['name']) ?> <span class="muted small">(<?= htmlspecialchars($p['permission_key']) ?>)</span></td>
        <?php foreach ($roleMatrix as $perms): ?>
          <td><?= !empty($perms[$p['permission_key']]) ? '✓' : '—' ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="section">
    <h2>Extra Permissions Granted to Individuals</h2>
    <table class="list">
      <tr><th>User</th><th>Permission</th><th>Reason</th><th>Granted By</th><th>When</th><th>Action</th></tr>
      <?php foreach ($overrides as $o): ?>
      <tr>
        <td><?= htmlspecialchars($o['user_name']) ?></td>
        <td><?= htmlspecialchars($o['permission_name']) ?></td>
        <td><?= htmlspecialchars($o['reason'] ?? '') ?></td>
        <td><?= htmlspecialchars($o['granted_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars((string) $o['granted_at']) ?></td>
        <td>
          <form method="post" action="/admin/permissions/<?= (int) $o['id'] ?>/remove" style="display:inline" onsubmit="return confirm('Remove this extra permission?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($overrides)): ?>
        <tr><td colspan="6" class="muted">No individual extra permissions granted.</td></tr>
      <?php endif; ?>
    </table>

    <h3 style="margin-top:16px">Grant an extra permission</h3>
    <form method="post" action="/admin/permissions/grant" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
      <?= Csrf::field() ?>
      <select name="user_id">
        <?php foreach ($allUsers as $u): ?>
          <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> — <?= htmlspecialchars($u['role_name'] ?? 'No role') ?></option>
        <?php endforeach; ?>
      </select>
      <select name="permission_id">
        <?php foreach ($allPermissions as $p): ?>
          <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="reason" placeholder="Reason (min <?= $minReasonLength ?> chars)" required minlength="<?= $minReasonLength ?>" style="width:280px">
      <button type="submit" class="btn-sm btn-success">Grant</button>
    </form>
  </div>
</div>
