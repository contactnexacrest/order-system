<?php $listOfBoard = !empty($kanbanUser); ?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Associates</h1>
      <p class="muted" style="margin:0">Outside people who introduce clients under a signed agreement. They follow their clients, orders and commission in their own portal.</p>
    </div>
    <div class="btn-row" style="margin-top:0">
      <?php if (!empty($kanbanUser)): ?><a class="btn-sm btn-secondary" href="/associates">Switch to board view</a><?php endif; ?>
      <?php if ($canManage): ?><a class="btn-sm" href="/associates/new">Add an associate</a> <a class="btn-sm btn-secondary" href="/associates/ladder">Commission settings</a> <a class="btn-sm btn-secondary" href="/reports/associates">Report</a><?php endif; ?>
    </div>
  </div>
  <?php require __DIR__ . '/_filters.php'; ?>
  <table class="list">
    <tr><th>Associate</th><th>Status</th><th>Based in</th><th>Personal code</th><th>Clients</th><?php if ($canManage): ?><th>Commission terms</th><?php endif; ?><?php if ($canManage || $canBank): ?><th>Bank</th><?php endif; ?><th></th></tr>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><a href="/associates/<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></a><?php if (!empty($a['email'])): ?><br><small class="muted"><?= htmlspecialchars((string) $a['email']) ?></small><?php endif; ?></td>
        <td><?php $__st = (string) $a['status']; require __DIR__ . '/_status_badge.php'; ?></td>
        <td><?= $a['based_in'] === 'india' ? 'India' : htmlspecialchars('Abroad' . ($a['country'] ? ' — ' . $a['country'] : '')) ?></td>
        <td><?= htmlspecialchars((string) ($a['personal_code'] ?? '—')) ?></td>
        <td><?= count($a['clients']) ?><?php foreach (array_slice($a['clients'], 0, 3) as $c): ?><br><small><a href="/clients/<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['company_legal_name']) ?></a></small><?php endforeach; ?></td>
        <?php if ($canManage): ?><td><?= (int) $a['no_commission'] === 1 ? 'No commission' : (($a['commission_mode'] ?? 'ladder') === 'fixed' ? 'Fixed: ' . htmlspecialchars((string) ($a['commission_level_name'] ?? '?')) : 'Ladder') ?></td><?php endif; ?>
        <?php if ($canManage || $canBank): ?><td><?= $a['pending_bank'] > 0 ? '<span class="badge badge-pending">Change to check</span>' : '' ?></td><?php endif; ?>
        <td><a class="btn-sm" href="/associates/<?= (int) $a['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="8" class="muted">No associates match.</td></tr><?php endif; ?>
  </table>
</div>
