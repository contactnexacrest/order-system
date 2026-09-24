<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Roles &amp; Permissions</h1>
  <p class="muted">Create/edit/delete roles and permissions, edit which permissions a role has, or give one specific person an extra permission beyond what their role gives them.</p>

  <div class="section">
    <h2>Roles</h2>
    <table class="list">
      <tr><th>Name</th><th>Description</th><th>System</th><th>Action</th></tr>
      <?php foreach ($roles as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['description'] ?? '—') ?></td>
        <td><?= !empty($r['is_system_role']) ? 'Yes' : '—' ?></td>
        <td>
          <a href="/admin/roles/<?= (int) $r['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <a href="/admin/roles/<?= (int) $r['id'] ?>/permissions" class="btn-sm btn-secondary">Edit Permissions</a>
          <?php if (empty($r['is_system_role'])): ?>
          <form method="post" action="/admin/roles/<?= (int) $r['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete role &quot;<?= htmlspecialchars(addslashes($r['name'])) ?>&quot;? This only works if no user currently has this role.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <h3 style="margin-top:16px">Create a role</h3>
    <form method="post" action="/admin/roles" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
      <?= Csrf::field() ?>
      <input type="text" name="name" placeholder="Role name" required style="width:200px">
      <input type="text" name="description" placeholder="Description (optional)" style="width:320px">
      <button type="submit" class="btn-sm btn-accent">Create Role</button>
    </form>
  </div>

  <div class="section">
    <h2>Role Matrix</h2>
    <p class="muted small">Read-only summary — use "Edit Permissions" above to change a role's row.</p>
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
    <h2>Permission Definitions</h2>
    <table class="list">
      <tr><th>Key</th><th>Name</th><th>Category</th><th>System</th><th>Action</th></tr>
      <?php foreach ($allPermissions as $p): ?>
      <tr>
        <td><code><?= htmlspecialchars($p['permission_key']) ?></code></td>
        <td><?= htmlspecialchars($p['name']) ?></td>
        <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
        <td><?= !empty($p['is_system_permission']) ? 'Yes' : '—' ?></td>
        <td>
          <a href="/admin/permission-definitions/<?= (int) $p['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <?php if (empty($p['is_system_permission'])): ?>
          <form method="post" action="/admin/permission-definitions/<?= (int) $p['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete permission &quot;<?= htmlspecialchars(addslashes($p['name'])) ?>&quot;? This only works if it is not currently granted to any role or user.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <h3 style="margin-top:16px">Create a permission</h3>
    <p class="muted small">A new permission does nothing on its own — a developer still has to add a check for its key somewhere in the code before it gates anything.</p>
    <form method="post" action="/admin/permission-definitions" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
      <?= Csrf::field() ?>
      <input type="text" name="permission_key" placeholder="permission_key (e.g. manage_widgets)" required style="width:220px">
      <input type="text" name="name" placeholder="Display name" required style="width:200px">
      <input type="text" name="category" placeholder="Category (optional)" style="width:140px">
      <input type="text" name="description" placeholder="Description (optional)" style="width:280px">
      <button type="submit" class="btn-sm btn-accent">Create Permission</button>
    </form>
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
