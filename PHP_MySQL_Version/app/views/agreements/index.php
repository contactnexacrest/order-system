<?php $listOfBoard = !empty($kanbanUser); ?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Agreements</h1>
      <p class="muted" style="margin:0">Every agreement signed with a client, supplier, logistics partner, associate<?= $canManage ? ' or staff member' : '' ?> — its signed copy and dates. A reminder goes out 30 and 7 days before an agreement expires, and on the day.</p>
    </div>
    <div class="btn-row" style="margin-top:0">
      <?php if (!empty($kanbanUser)): ?><a class="btn-sm btn-secondary" href="/agreements">Switch to board view</a><?php endif; ?>
      <?php if ($canManage): ?><a class="btn-sm" href="/agreements/new">Add an agreement</a><?php endif; ?>
    </div>
  </div>
  <?php require __DIR__ . '/_filters.php'; ?>
  <table class="list">
    <tr><th>With</th><th>Agreement</th><th>Signed</th><th>Expires</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $__a): ?>
      <tr>
        <td><a href="<?= htmlspecialchars(\App\Repositories\AgreementRepository::partyUrl($__a['party_type'], (int) $__a['party_id'])) ?>"><?= htmlspecialchars((string) $__a['party_name']) ?></a><br><small class="muted"><?= htmlspecialchars(\App\Repositories\AgreementRepository::PARTY_TYPES[$__a['party_type']]) ?></small></td>
        <td><?= htmlspecialchars((string) $__a['agreement_type']) ?><?php if (!empty($__a['title'])): ?><br><small class="muted"><?= htmlspecialchars((string) $__a['title']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars((string) ($__a['signed_on'] ?? '—')) ?></td>
        <td><?= htmlspecialchars((string) ($__a['expires_on'] ?? 'No expiry')) ?><?php if ($__a['days_left'] !== null && $__a['days_left'] >= 0 && in_array($__a['status'], ['expiring', 'active'], true)): ?><br><small class="muted">in <?= (int) $__a['days_left'] ?> day(s)</small><?php endif; ?><?php if (!empty($__a['terminated_on'])): ?><br><small class="muted">Ended <?= htmlspecialchars((string) $__a['terminated_on']) ?> — <?= htmlspecialchars((string) $__a['terminated_reason']) ?></small><?php endif; ?></td>
        <td><?php $__st = (string) $__a['status']; require __DIR__ . '/_status_badge.php'; ?></td>
        <td><?php require __DIR__ . '/_actions.php'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">No agreements match.</td></tr><?php endif; ?>
  </table>
</div>
