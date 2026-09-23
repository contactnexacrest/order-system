<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Edit Permissions — <?= htmlspecialchars($role['name']) ?></h1>
  <p class="muted">Check every permission this role should grant. Saving replaces the role's entire permission set — a per-user extra permission (granted from the main Roles &amp; Permissions page) still applies on top of this regardless.</p>
  <form method="post" action="/admin/roles/<?= (int) $role['id'] ?>/permissions">
    <?= Csrf::field() ?>
    <table class="list">
      <tr><th></th><th>Permission</th><th>Category</th></tr>
      <?php foreach ($allPermissions as $p): ?>
      <tr>
        <td><input type="checkbox" name="permission_ids[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $enabledIds, true) ? 'checked' : '' ?>></td>
        <td><?= htmlspecialchars($p['name']) ?> <span class="muted small">(<?= htmlspecialchars($p['permission_key']) ?>)</span></td>
        <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Permissions</button>
      <a href="/admin/permissions" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
