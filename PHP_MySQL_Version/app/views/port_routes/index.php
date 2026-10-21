<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/ports">&larr; Ports</a></p>
  <h1>Port Routes — Sailing Days</h1>
  <p class="muted">A reference document of known port-to-port sailing times. Picking both ports on an order's Port of Loading/Discharge fields auto-suggests the Sailing Lead Time from a matching route here — staff can still freely override it on the order.</p>

  <div class="section">
    <div class="btn-row"><a href="/port-routes/create" class="btn-sm btn-accent" style="margin-left:auto;">Add Route</a></div>

    <table class="list">
      <tr><th>Port of Loading</th><th>Port of Discharge</th><th>Sailing Days</th><th>Notes</th><th>Status</th><th>Actions</th></tr>
      <?php if (empty($routes)): ?>
      <tr><td colspan="6" class="muted">No routes yet — add the first one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($routes as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['port_of_loading_name']) ?></td>
        <td><?= htmlspecialchars($r['port_of_discharge_name']) ?></td>
        <td><?= (int) $r['sailing_days_min'] ?>&ndash;<?= (int) $r['sailing_days_max'] ?> days</td>
        <td class="muted small"><?= $r['notes'] ? htmlspecialchars($r['notes']) : '—' ?></td>
        <td><span class="badge <?= $r['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a href="/port-routes/<?= (int) $r['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/port-routes/<?= (int) $r['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('<?= $r['is_active'] ? 'Deactivate' : 'Reactivate' ?> this route? <?= $r['is_active'] ? 'It will no longer be offered as an auto-suggestion on new orders.' : '' ?>');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $r['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $r['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
