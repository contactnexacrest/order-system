<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/ports">&larr; Back to Ports</a></p>
  <h1>Edit Port — <?= htmlspecialchars($port['name']) ?></h1>
  <form method="post" action="/ports/<?= (int) $port['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="field-grid">
      <label>Port Name *
        <input type="text" name="name" value="<?= htmlspecialchars($port['name']) ?>" placeholder="e.g., Nhava Sheva (JNPT)" required>
      </label>
      <label>Country
        <input type="text" name="country" value="<?= htmlspecialchars($port['country'] ?? '') ?>" placeholder="e.g., India">
      </label>
      <label>Role *
        <select name="port_role" required>
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $port['port_role'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/ports" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
