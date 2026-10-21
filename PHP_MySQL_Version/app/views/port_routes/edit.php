<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/port-routes">&larr; Port Routes</a></p>
  <h1>Edit Port Route</h1>
  <form method="post" action="/port-routes/<?= (int) $route['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="field-grid">
      <label>Port of Loading *
        <select name="port_of_loading_id" required>
          <?php foreach ($loadingPorts as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $route['port_of_loading_id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Port of Discharge *
        <select name="port_of_discharge_id" required>
          <?php foreach ($dischargePorts as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $route['port_of_discharge_id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Sailing Days Min *
        <input type="number" min="0" name="sailing_days_min" required value="<?= (int) $route['sailing_days_min'] ?>">
      </label>
      <label>Sailing Days Max *
        <input type="number" min="0" name="sailing_days_max" required value="<?= (int) $route['sailing_days_max'] ?>">
      </label>
      <label class="full">Notes (optional)
        <input type="text" name="notes" value="<?= htmlspecialchars((string) ($route['notes'] ?? '')) ?>" placeholder="e.g. Direct service, no transshipment">
      </label>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save</button>
  </form>
</div>
