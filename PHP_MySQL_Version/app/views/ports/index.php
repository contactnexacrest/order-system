<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Ports — Loading &amp; Discharge</h1>
  <p class="muted">The list staff pick from on the New Order form's Port of Loading / Port of Discharge dropdowns. A port tagged "Loading &amp; Discharge" shows up in both lists. One port per role (or marked "Loading &amp; Discharge") can be the default that's pre-selected on a new order.</p>

  <div class="section">
    <div class="btn-row"><a href="/ports/create" class="btn-sm btn-accent" style="margin-left:auto;">Add Port</a></div>

    <table class="list">
      <tr><th>Name</th><th>City</th><th>Country</th><th>Role</th><th>Default</th><th>Status</th><th>Actions</th></tr>
      <?php if (empty($ports)): ?>
      <tr><td colspan="7" class="muted">No ports yet — add the first one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($ports as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['name']) ?></td>
        <td><?= $p['city'] ? htmlspecialchars($p['city']) : '—' ?></td>
        <td><?= $p['country'] ? htmlspecialchars($p['country']) : '—' ?></td>
        <td><?= htmlspecialchars($roles[$p['port_role']] ?? $p['port_role']) ?></td>
        <td>
          <?php if ($p['is_default']): ?>
            <span class="badge badge-active">Default</span>
          <?php else: ?>
            <form method="post" action="/ports/<?= (int) $p['id'] ?>/set-default" style="display:inline">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-secondary">Set default</button>
            </form>
          <?php endif; ?>
        </td>
        <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a href="/ports/<?= (int) $p['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/ports/<?= (int) $p['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('<?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($p['name'])) ?>? <?= $p['is_active'] ? 'It will no longer be offered on new orders.' : '' ?>');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $p['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
