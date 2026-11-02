<?php
/**
 * "Agreements" card on a client / supplier / logistics partner / associate / staff page
 * (owner decision 2 Nov 2026, point 4). Set $__partyType and $__partyId before including.
 * Shown to holders of view_agreements or manage_agreements; a staff member's card only to manage_agreements.
 */
$__canManage = \App\Controllers\AgreementController::can('manage_agreements');
if (($__canManage || \App\Controllers\AgreementController::can('view_agreements')) && ($__partyType !== 'staff' || $__canManage)):
    $__rows = \App\Repositories\AgreementRepository::forParty($__partyType, (int) $__partyId);
    $canManage = $__canManage;
    $canClientFile = \App\Controllers\AgreementController::can('manage_orders');
    $canAssociateFile = \App\Controllers\AgreementController::can('manage_associates') && \App\Controllers\AgreementController::can('manage_incentives');
?>
<div class="card" id="agreements">
  <div class="page-header-row">
    <h2 style="margin:0">Agreements</h2>
    <?php if ($__canManage): ?><a class="btn-sm" href="/agreements/new?party_type=<?= htmlspecialchars($__partyType) ?>&amp;party_id=<?= (int) $__partyId ?>">Add agreement</a><?php endif; ?>
  </div>
  <?php if ($__rows === []): ?>
    <p class="muted">No agreement on record yet.</p>
  <?php else: ?>
    <table class="list">
      <tr><th>Agreement</th><th>Signed</th><th>Expires</th><th>Status</th><th></th></tr>
      <?php foreach ($__rows as $__a): ?>
        <tr>
          <td><?= htmlspecialchars((string) $__a['agreement_type']) ?><?php if (!empty($__a['title'])): ?><br><small class="muted"><?= htmlspecialchars((string) $__a['title']) ?></small><?php endif; ?></td>
          <td><?= htmlspecialchars((string) ($__a['signed_on'] ?? '—')) ?></td>
          <td><?= htmlspecialchars((string) ($__a['expires_on'] ?? 'No expiry')) ?></td>
          <td><?php $__st = (string) $__a['status']; require __DIR__ . '/_status_badge.php'; ?></td>
          <td><?php require __DIR__ . '/_actions.php'; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>
