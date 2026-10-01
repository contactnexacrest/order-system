<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Compliance Task Types</h1>
  <p class="muted">The checklist of compliance/pre-closure tasks (ECGC Cover, Pre-Shipment Inspection, etc.) that appears on every order's own page. Add, rename, or deactivate task names here — deactivating removes a task from every order's checklist going forward without touching any status already recorded against it. The checklist itself, on an order's own page, is visible to whoever has the close_orders permission — not this screen.</p>

  <div class="section">
    <p><a href="/compliance-task-types/create" class="btn-sm btn-accent">Add Task Type</a></p>

    <table class="list">
      <tr><th>Task Name</th><th>Status</th><th>Actions</th></tr>
      <?php if (empty($taskTypes)): ?>
      <tr><td colspan="3" class="muted">No compliance task types yet — add the first one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($taskTypes as $t): ?>
      <tr>
        <td><?= htmlspecialchars($t['name']) ?></td>
        <td><span class="badge <?= $t['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td class="action-buttons">
          <a href="/compliance-task-types/<?= (int) $t['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/compliance-task-types/<?= (int) $t['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('<?= $t['is_active'] ? 'Deactivate' : 'Reactivate' ?> \"<?= htmlspecialchars(addslashes($t['name'])) ?>\"?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $t['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $t['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <form method="post" action="/compliance-task-types/<?= (int) $t['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Permanently delete \"<?= htmlspecialchars(addslashes($t['name'])) ?>\"? This only works if no order has ever recorded a status against it — otherwise deactivate it instead.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
