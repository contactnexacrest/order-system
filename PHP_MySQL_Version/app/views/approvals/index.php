<?php use App\Services\ApprovalsInbox; ?>
<div class="card page-wide">
  <h1>Approvals waiting for you (<?= (int) $counts['all'] ?>)</h1>
  <?php if (!empty($kanbanUser)): ?><div class="btn-row"><a class="btn-sm btn-secondary" href="/approvals?tab=<?= htmlspecialchars($tab) ?>">Switch to board view</a></div><?php endif; ?>
  <?php $listView = !empty($kanbanUser); include __DIR__ . '/_tabs.php'; ?>
  <table class="list approvals-table">
    <tr><th>Waiting since</th><th>What</th><th>Order / who</th><th>Details</th><th></th></tr>
    <?php foreach ($items as $__i): include __DIR__ . '/_cell.php'; ?>
    <tr id="approval-<?= htmlspecialchars(str_replace(':', '-', (string) $__i['key'])) ?>">
      <td><?= htmlspecialchars(ApprovalsInbox::sinceLabel((string) $__i['since'], $today)) ?></td>
      <td><?= $__whatPart ?></td>
      <td><?= $__whoPart !== '' ? $__whoPart : '—' ?></td>
      <td><?= htmlspecialchars((string) $__i['details']) ?></td>
      <td><?= $__button ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
    <tr><td colspan="5" class="muted">Nothing is waiting for your approval<?= $tab !== 'all' ? ' here' : '' ?>.</td></tr>
    <?php endif; ?>
  </table>
  <?php include __DIR__ . '/_footer.php'; ?>
</div>
