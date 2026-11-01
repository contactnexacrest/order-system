<?php $__m = static fn ($v): string => '₹' . number_format((float) $v, 2); ?>
<div class="card page-wide">
  <h1>Welcome, <?= htmlspecialchars((string) $me['name']) ?></h1>
  <?php if ($me['status'] !== 'active'): ?><div class="alert alert-warning">Your account is <?= htmlspecialchars(strtolower(\App\Repositories\AssociateRepository::STATUSES[$me['status']] ?? $me['status'])) ?>. <?= $me['status'] === 'invited' ? 'It becomes active once NexaCrest has checked your documents and bank details — add them under Profile.' : 'Contact your relationship manager.' ?></div><?php endif; ?>
  <div class="stat-row">
    <div class="stat"><div class="stat-label">Earned this year</div><div class="stat-value"><?= $__m($totals['earned_year']) ?></div></div>
    <div class="stat"><div class="stat-label">Waiting to be paid</div><div class="stat-value"><?= $__m($totals['waiting']) ?></div></div>
    <div class="stat"><div class="stat-label">Paid this year</div><div class="stat-value"><?= $__m($totals['paid_year']) ?></div></div>
    <?php if ($totals['held'] > 0): ?><div class="stat"><div class="stat-label">On hold</div><div class="stat-value"><?= $__m($totals['held']) ?></div></div><?php endif; ?>
  </div>
  <p>Clients: <b><?= count($clients) ?></b> · Invitations open: <b><?= count(array_filter($invitations, static fn (array $i): bool => in_array($i['status'], ['created', 'opened', 'registered'], true))) ?></b>
    <?php if (!empty($me['personal_code'])): ?> · Your personal code: <b><?= htmlspecialchars((string) $me['personal_code']) ?></b><?php endif; ?></p>
  <p><a class="btn-sm" href="/associate/clients">Invite a client</a></p>

  <h2>Latest orders</h2>
  <table class="list"><tr><th>Order</th><th>Client</th><th>Stage</th><th>Commission</th></tr>
    <?php foreach ($orders as $o): ?><tr><td><a href="/associate/orders/<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['order_reference']) ?></a></td><td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td><td><?= htmlspecialchars((string) $o['stage']) ?></td><td><?php require __DIR__ . '/_commission_cell.php'; ?></td></tr><?php endforeach; ?>
    <?php if ($orders === []): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?>
  </table>

  <h2>Notices</h2>
  <table class="list">
    <?php foreach ($events as $e): ?><tr><td style="white-space:nowrap"><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $e['created_at'], 0, 10))) ?></td><td><?= empty($e['read_at']) && (int) $e['is_notice'] === 1 ? '<b>' . htmlspecialchars((string) $e['message']) . '</b>' : htmlspecialchars((string) $e['message']) ?></td></tr><?php endforeach; ?>
    <?php if ($events === []): ?><tr><td class="muted">Nothing yet.</td></tr><?php endif; ?>
  </table>
</div>
