<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Edit User — <?= htmlspecialchars($target['name']) ?></h1>
  <form method="post" action="/users/<?= (int) $target['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="kv-grid">
      <label>Name * <input type="text" name="name" value="<?= htmlspecialchars($target['name']) ?>" required></label>
      <label>Email * <input type="email" name="email" value="<?= htmlspecialchars($target['email']) ?>" required></label>
      <label>Phone <input type="text" name="phone" value="<?= htmlspecialchars($target['phone'] ?? '') ?>"></label>
      <label>Role
        <select name="role_id">
          <option value="">— none —</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) ($target['role_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/users" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
