<?php if (!empty($salesTargets)): // Owner decision D-378: company sales target (containers; ₹ only with view_price_buildup)
  $__st = $salesTargets['current'];
  $__money = static fn (float $v): string => htmlspecialchars(\App\Helpers\MoneyFormat::indian($v)); ?>
<div class="card page-wide" id="cockpit-sales-target">
  <h2 style="margin-top:0;">Sales target — <?= htmlspecialchars((string) $__st['period']['label']) ?></h2>
  <div class="kv-grid">
    <div><span class="k">Containers needed</span><span class="v"><?= $__st['containers_needed'] !== null ? (int) $__st['containers_needed'] : 'not enough history yet' ?></span></div>
    <div><span class="k">Break-even containers</span><span class="v"><?= $__st['breakeven_containers'] !== null ? (int) $__st['breakeven_containers'] : '—' ?></span></div>
    <div><span class="k">Counted (confirmed, with corrections)</span><span class="v"><?= (int) $__st['counted'] ?></span></div>
    <div><span class="k">Shipped · awaiting shipment</span><span class="v"><?= (int) $__st['shipped'] ?> · <?= (int) $__st['awaiting'] ?></span></div>
    <div><span class="k">Period gone</span><span class="v"><?= (int) $salesTargets['elapsed_pct'] ?>%</span></div>
    <?php if (!empty($salesTargetsMoney)): ?>
    <div><span class="k">Needed (₹)</span><span class="v"><?= $__money((float) $__st['needed']) ?></span></div>
    <div><span class="k">Earned — orders shipped (₹)</span><span class="v"><?= $__money((float) $__st['earned']) ?></span></div>
    <div><span class="k">Average margin per container (₹)</span><span class="v"><?= $__st['avg_margin'] !== null ? $__money((float) $__st['avg_margin']) : 'not enough history yet' ?></span></div>
    <?php endif; ?>
  </div>
  <table class="list">
    <tr><th>Period</th><th>Containers counted</th><th>Shipped</th><?php if (!empty($salesTargetsMoney)): ?><th>Needed (₹)</th><th>Earned (₹)</th><?php endif; ?></tr>
    <?php foreach ($salesTargets['previous'] as $__p): ?>
    <tr>
      <td><?= htmlspecialchars((string) $__p['period']['label']) ?></td>
      <td><?= (int) $__p['counted'] ?></td>
      <td><?= (int) $__p['shipped'] ?></td>
      <?php if (!empty($salesTargetsMoney)): ?><td><?= $__p['on'] ? $__money((float) $__p['needed']) : '—' ?></td><td><?= $__p['on'] ? $__money((float) $__p['earned']) : '—' ?></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <p><a href="/sales-targets">Open Sales targets →</a></p>
</div>
<?php endif; ?>
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
