<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/admin/permissions">&larr; Back to Permissions</a></p>
  <h1>Edit Permission — <?= htmlspecialchars($permission['name']) ?></h1>
  <p class="muted small">Key: <code><?= htmlspecialchars($permission['permission_key']) ?></code> (immutable — every permission check in code refers to this exact string).</p>
  <form method="post" action="/admin/permission-definitions/<?= (int) $permission['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="kv-grid">
      <label>Name * <input type="text" name="name" value="<?= htmlspecialchars($permission['name']) ?>" required></label>
      <label>Category <input type="text" name="category" value="<?= htmlspecialchars($permission['category'] ?? '') ?>"></label>
      <label>Description <input type="text" name="description" value="<?= htmlspecialchars($permission['description'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/admin/permissions" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
