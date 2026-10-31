<div class="card page-wide">
  <h1>My tasks</h1>
  <p class="muted">What <?= $acting ? htmlspecialchars($acting) : 'your department' ?> has to do next, earliest due first. A task appears here when the step before it is done, and goes away when you do it. Overdue tasks are red; after a few more days they are escalated to management.</p>
  <div class="btn-row">
    <a class="btn-sm<?= $mine ? ' btn-secondary' : '' ?>" href="/my-tasks">All of my department's tasks</a>
    <a class="btn-sm<?= $mine ? '' : ' btn-secondary' ?>" href="/my-tasks?show=mine">Only orders I own</a>
  </div>
  <div class="table-scroll">
  <table class="list">
    <tr><th>Due</th><th>Task</th><th>Order</th><th>Detail</th><th>Owner</th><th></th></tr>
    <?php foreach ($tasks as $t): ?>
    <tr<?= (int) $t['overdue'] === 1 ? ' style="background:var(--danger-soft)"' : '' ?>>
      <td><?= htmlspecialchars((string) $t['due_on']) ?><?= (int) $t['escalated'] === 1 ? ' <span class="badge badge-inactive">escalated</span>' : ((int) $t['overdue'] === 1 ? ' <span class="badge badge-warning">overdue</span>' : '') ?></td>
      <td><span class="dept-tag" style="--dept-color:<?= htmlspecialchars((string) $t['department_color']) ?>"><?= htmlspecialchars((string) $t['department_name']) ?></span> <?= htmlspecialchars((string) $t['title']) ?></td>
      <td><?= htmlspecialchars((string) $t['order_reference']) ?></td>
      <td><?= htmlspecialchars((string) $t['subject']) ?></td>
      <td><?= $t['owner_name'] !== null ? htmlspecialchars((string) $t['owner_name']) : '<span class="muted">no owner yet</span>' ?></td>
      <td><a class="btn-sm" href="<?= htmlspecialchars((string) $t['link']) ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?><tr><td colspan="6" class="muted">Nothing to do right now.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
