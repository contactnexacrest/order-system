<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/admin/permissions">&larr; Back to Permissions</a></p>
  <h1>Edit Permissions — <?= htmlspecialchars($role['name']) ?></h1>
  <?php // Owner decision D-379: the role's Reports tab (manage_report_access). ?>
  <?php if (\App\Helpers\PermittedLinks::currentUserHas('manage_report_access')): ?>
  <div class="btn-row" role="tablist" aria-label="Role settings">
    <a class="btn-sm btn-accent" role="tab" aria-selected="true" href="/admin/roles/<?= (int) $role['id'] ?>/permissions">Permissions</a>
    <a class="btn-sm btn-secondary" role="tab" href="/admin/roles/<?= (int) $role['id'] ?>/reports">Reports</a>
  </div>
  <?php endif; ?>
  <p class="muted">Check every permission this role should grant. Saving replaces the role's entire permission set — a per-user extra permission (granted from the main Roles &amp; Permissions page) still applies on top of this regardless.</p>
  <form method="post" action="/admin/roles/<?= (int) $role['id'] ?>/permissions">
    <?= Csrf::field() ?>
    <table class="list">
      <tr><th></th><th>Permission</th><th>Category</th></tr>
      <?php foreach ($allPermissions as $p): ?>
      <tr>
        <td><input type="checkbox" name="permission_ids[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $enabledIds, true) ? 'checked' : '' ?> aria-label="Grant this permission"></td>
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
