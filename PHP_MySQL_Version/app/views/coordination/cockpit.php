<div class="card page-wide">
  <h1>Management cockpit</h1>
  <p class="muted">Open work per department from every order's state — counts and status only, no amounts.</p>
  <table class="list">
    <tr><th>Department</th><th>Open tasks</th><th>Overdue</th><th>Escalated</th><th>Orders without an owner</th></tr>
    <?php foreach ($byDepartment as $name => $c): ?>
    <tr>
      <td><span class="dept-tag" style="--dept-color:<?= htmlspecialchars((string) $c['color']) ?>"><?= htmlspecialchars((string) $name) ?></span></td>
      <td><?= (int) $c['open'] ?></td>
      <td><?= (int) $c['overdue'] > 0 ? '<strong style="color:var(--danger)">' . (int) $c['overdue'] . '</strong>' : '0' ?></td>
      <td><?= (int) $c['escalated'] ?></td>
      <td><?= (int) $c['unowned'] ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($byDepartment === []): ?><tr><td colspan="5" class="muted">No open work.</td></tr><?php endif; ?>
  </table>
</div>

<div class="card page-wide">
  <h2>Orders by status</h2>
  <div class="kv-grid">
    <?php foreach ($ordersByStatus as $status => $n): ?><div><span class="k"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></span><span class="v"><?= (int) $n ?></span></div><?php endforeach; ?>
  </div>
</div>

<div class="card page-wide">
  <h2>Escalated — needs management</h2>
  <table class="list">
    <tr><th>Due</th><th>Department</th><th>Task</th><th>Order</th><th>Detail</th><th>Owner</th></tr>
    <?php foreach ($escalated as $t): ?>
    <tr>
      <td><?= htmlspecialchars((string) $t['due_on']) ?></td>
      <td><?= htmlspecialchars((string) $t['department_name']) ?></td>
      <td><?= htmlspecialchars((string) $t['title']) ?></td>
      <td><a href="<?= htmlspecialchars((string) $t['link']) ?>"><?= htmlspecialchars((string) $t['order_reference']) ?></a></td>
      <td><?= htmlspecialchars((string) $t['subject']) ?></td>
      <td><?= $t['owner_name'] !== null ? htmlspecialchars((string) $t['owner_name']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($escalated === []): ?><tr><td colspan="6" class="muted">Nothing escalated.</td></tr><?php endif; ?>
  </table>
</div>

<div class="card page-wide">
  <h2>Supplier delays</h2>
  <table class="list">
    <tr><th>Needed by</th><th>Order</th><th>Purchase</th></tr>
    <?php foreach ($supplierDelays as $t): ?>
    <tr><td><?= htmlspecialchars((string) $t['since']) ?></td><td><?= htmlspecialchars((string) $t['order_reference']) ?></td><td><a href="<?= htmlspecialchars((string) $t['link']) ?>"><?= htmlspecialchars((string) $t['subject']) ?></a></td></tr>
    <?php endforeach; ?>
    <?php if ($supplierDelays === []): ?><tr><td colspan="3" class="muted">No supplier is late.</td></tr><?php endif; ?>
  </table>
</div>
