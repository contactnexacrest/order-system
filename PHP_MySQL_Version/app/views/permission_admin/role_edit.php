<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Edit Role — <?= htmlspecialchars($role['name']) ?></h1>
  <form method="post" action="/admin/roles/<?= (int) $role['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="kv-grid">
      <label>Name * <input type="text" name="name" value="<?= htmlspecialchars($role['name']) ?>" required></label>
      <label>Description <input type="text" name="description" value="<?= htmlspecialchars($role['description'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/admin/permissions" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
