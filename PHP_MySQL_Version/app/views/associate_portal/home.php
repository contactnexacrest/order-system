<?php $__m = static fn ($v): string => '₹' . number_format((float) $v, 2); ?>
<div class="card page-wide">
  <h1>Welcome, <?= htmlspecialchars((string) $me['name']) ?></h1>
  <?php if ($me['status'] !== 'active'): ?><div class="alert alert-warning">Your account is <?= htmlspecialchars(strtolower(\App\Repositories\AssociateRepository::STATUSES[$me['status']] ?? $me['status'])) ?>. <?= $me['status'] === 'invited' ? 'It becomes active once NexaCrest has checked your documents and bank details — add them under Profile.' : 'Contact your relationship manager.' ?></div><?php endif; ?>
  <?php $__kycMissing = \App\Services\AssociateKyc::missing($me); if ($__kycMissing !== [] || empty($me['address'])): ?>
    <?php // Owner decision D-253 / D-252: after the first login the associate completes the profile and the KYC documents. ?>
    <div class="alert alert-warning">Please complete <a href="/associate/profile#kyc">your profile and documents</a><?= $__kycMissing !== [] ? ' — still needed or being checked: ' . htmlspecialchars(implode(', ', $__kycMissing)) : '' ?><?= empty($me['address']) ? ($__kycMissing !== [] ? '; ' : ' — ') . 'your address' : '' ?>. We pay commission once we have accepted your documents.</div>
  <?php endif; ?>
  <?php if (($__grace = \App\Services\AssociateKyc::addressGraceNote($me)) !== null): // D-290 ?><p class="small" data-address-grace><?= htmlspecialchars($__grace) ?></p><?php endif; ?>
  <?php if (!empty($access['commission'])): // D-261 ?>
  <?php // D-264: the order currency (USD) is the figure an associate sees; rupees only on a fixed line, for an associate in India. ?>
  <?php $__fc = static fn (string $k): string => htmlspecialchars(\App\Repositories\AssociatePortalRepository::fcText($totals['fc'][$k] ?? [])); ?>
  <div class="stat-row">
    <div class="stat"><div class="stat-label">Earned in the last <?= (int) $totals['months'] ?> months</div><div class="stat-value"><?= $__fc('earned') ?></div></div>
    <div class="stat"><div class="stat-label">Waiting to be paid</div><div class="stat-value"><?= $__fc('waiting') ?></div></div>
    <div class="stat"><div class="stat-label">Paid in the last <?= (int) $totals['months'] ?> months</div><div class="stat-value"><?= $__fc('paid') ?></div></div>
    <?php if ($totals['held'] > 0): ?><div class="stat"><div class="stat-label">On hold</div><div class="stat-value"><?= $__fc('held') ?></div></div><?php endif; ?>
  </div>
  <?php endif; ?>
  <p><?php if (!empty($access['clients'])): ?>Clients: <b><?= count($clients) ?></b><?php endif; ?><?php if (!empty($access['registration_links'])): ?> · Buyer registration links open: <b><?= count(array_filter($invitations, static fn (array $i): bool => in_array($i['status'], ['created', 'opened', 'registered'], true))) ?></b>
    <?php if (!empty($me['personal_code'])): ?> · Your personal code: <b><?= htmlspecialchars((string) $me['personal_code']) ?></b><?php endif; ?><?php endif; ?></p>
  <?php if (!empty($access['registration_links'])): ?><p><a class="btn-sm" href="/associate/clients">Invite a client</a></p><?php endif; ?>

  <?php if (!empty($access['orders'])): // D-261 ?>
  <h2>Latest orders</h2>
  <table class="list"><tr><th>Order</th><th>Client</th><th>Stage</th><?php if (!empty($access['commission'])): ?><th>Commission</th><?php endif; ?></tr>
    <?php foreach ($orders as $o): ?><tr><td><a href="/associate/orders/<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['order_reference']) ?></a></td><td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td><td><?= htmlspecialchars((string) $o['stage']) ?></td><?php if (!empty($access['commission'])): ?><td><?php require __DIR__ . '/_commission_cell.php'; ?></td><?php endif; ?></tr><?php endforeach; ?>
    <?php if ($orders === []): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?>
  </table>
  <?php endif; ?>

  <h2>Notices</h2>
  <table class="list">
    <?php foreach ($events as $e): ?><tr><td style="white-space:nowrap"><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $e['created_at'], 0, 10))) ?></td><td><?= empty($e['read_at']) && (int) $e['is_notice'] === 1 ? '<b>' . htmlspecialchars((string) $e['message']) . '</b>' : htmlspecialchars((string) $e['message']) ?></td></tr><?php endforeach; ?>
    <?php if ($events === []): ?><tr><td class="muted">Nothing yet.</td></tr><?php endif; ?>
  </table>
</div>
