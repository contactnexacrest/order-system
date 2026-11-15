<?php
use App\Helpers\View;

/**
 * Owner decision D-379 (15 Nov 2026): Sales targets at the Department level — a read-only table of the
 * people of the viewer's departments (target, confirmed, shipped, pipeline, new buyers, progress), in
 * containers. No Edit, no money, no calculator, no associates, no settings link (those are the All level:
 * views/sales_targets/index.php). One view for the Standard and the Kanban template.
 * Node twin: NODE_MySQL_Version/app/views/sales_targets/team.njk
 */
$__num = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
?>
<div class="card page-wide" id="sales-targets-team">
  <h1>Sales targets — <?= View::e($period['label']) ?></h1>
  <p class="muted">Your department's people, in containers. An order counts for its salesperson on the day it is confirmed; cancellations, losses and a different number of containers shipped are corrections on the day they happen.</p>
  <div class="btn-row" id="target-periods">
    <?php foreach ($periods as $p): ?>
      <a class="btn-sm <?= $p['key'] === $period['key'] ? 'btn-accent' : 'btn-secondary' ?>" href="/sales-targets?period=<?= View::e($p['key']) ?>"><?= View::e($p['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="table-scroll">
  <table class="list target-team" id="target-team-view">
    <tr><th>Person</th><th>Target</th><th>Confirmed</th><th>Shipped</th><th>Pipeline</th><th>New buyers</th><th>Progress</th></tr>
    <?php foreach ($rows as $r): $t = $r['target']; $c = $r['counts']; ?>
    <tr data-user-id="<?= (int) $r['user']['id'] ?>">
      <td><?= View::e((string) $r['user']['name']) ?></td>
      <td><?= $t['containers'] > 0 ? View::e($__num($t['containers'])) : '—' ?></td>
      <td><?= (int) $c['counted'] ?></td>
      <td><?= (int) $c['shipped'] ?></td>
      <td><?= (int) $r['pipeline'] ?></td>
      <td><?= (int) $c['new_buyers'] ?><?= $t['new_buyers'] !== null ? ' / ' . (int) $t['new_buyers'] : '' ?></td>
      <td><?= $r['progress'] !== null ? (int) $r['progress'] . '%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7" class="muted">Nobody in your departments has a target or a credited order in this period.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
