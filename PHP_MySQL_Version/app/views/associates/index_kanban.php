<?php
/** Owner hard rule 2026-11-01: every field, link and action of the Standard list (associates/index.php), as cards by status. */
$renderKanbanCard = static function (array $a) use ($canManage, $canBank): string {
    ob_start();
    ?>
    <div class="entity-card kanban-card">
      <div class="entity-card-header"><a class="entity-card-title" href="/associates/<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></a></div>
      <?php if (!empty($a['email'])): ?><div class="muted small"><?= htmlspecialchars((string) $a['email']) ?></div><?php endif; ?>
      <div class="entity-card-sub"><?= $a['based_in'] === 'india' ? 'India' : htmlspecialchars('Abroad' . ($a['country'] ? ' — ' . $a['country'] : '')) ?> · Code <?= htmlspecialchars((string) ($a['personal_code'] ?? '—')) ?></div>
      <div class="small">Clients: <?= count($a['clients']) ?><?php foreach (array_slice($a['clients'], 0, 3) as $c): ?><br><a href="/clients/<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['company_legal_name']) ?></a><?php endforeach; ?></div>
      <?php if ($canManage): ?><div class="muted small"><?= (int) $a['no_commission'] === 1 ? 'No commission' : (($a['commission_mode'] ?? 'ladder') === 'fixed' ? 'Fixed: ' . htmlspecialchars((string) ($a['commission_level_name'] ?? '?')) : 'Ladder') ?></div><?php endif; ?>
      <?php if (($canManage || $canBank) && $a['pending_bank'] > 0): ?><span class="badge badge-pending">Change to check</span><?php endif; ?>
      <div class="entity-card-footer"><a class="btn-sm" href="/associates/<?= (int) $a['id'] ?>">Open</a></div>
    </div>
    <?php
    return (string) ob_get_clean();
};
$columns = $status === 'all' ? array_diff_key(\App\Repositories\AssociateRepository::STATUSES, $counts['inactive'] === 0 ? ['inactive' => 1] : []) : [$status => \App\Repositories\AssociateRepository::STATUSES[$status]];
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Associates — Kanban Board</h1>
      <p class="muted" style="margin:0">Outside people who introduce clients under a signed agreement. They follow their clients, orders and commission in their own portal.</p>
    </div>
    <div class="btn-row" style="margin-top:0">
      <a class="btn-sm btn-secondary" href="/associates?view=list">Switch to list view</a>
      <?php if ($canManage): ?><a class="btn-sm" href="/associates/new">Add an associate</a> <a class="btn-sm btn-secondary" href="/associates/registrations">Registrations<?php $__pendingReg = \App\Repositories\AssociateRegistrationRepository::pendingCount(); if ($__pendingReg > 0): ?> (<?= $__pendingReg ?> waiting)<?php endif; ?></a> <a class="btn-sm btn-secondary" href="/associates/ladder">Commission settings</a><?php endif; ?><?php /* D-255 */ $__pu = \App\Services\AuthService::currentUser(); $__pr = $__pu !== null && $__pu['role_id'] !== null ? (int) $__pu['role_id'] : null; if ($__pu !== null && (\App\Services\PermissionService::can((int) $__pu['id'], $__pr, 'view_price_buildup') || \App\Services\PermissionService::can((int) $__pu['id'], $__pr, 'manage_pricing'))): ?> <a class="btn-sm btn-secondary" href="/associates/price-requests">Price requests<?php $__pendPr = \App\Services\PriceRequestService::pendingCount(); if ($__pendPr > 0): ?> (<?= $__pendPr ?> to price)<?php endif; ?></a><?php endif; ?><?php if ($canManage): ?> <a class="btn-sm btn-secondary" href="/reports/associates">Report</a><?php endif; ?>
    </div>
  </div>
  <?php require __DIR__ . '/_filters.php'; ?>
  <div class="kanban-board">
    <?php foreach ($columns as $key => $label): $__in = array_values(array_filter($rows, static fn (array $a): bool => $a['status'] === $key)); ?>
      <div class="kanban-column">
        <div class="kanban-column-header"><span class="kanban-column-title"><?= htmlspecialchars($label) ?></span><span class="kanban-column-count"><?= count($__in) ?></span></div>
        <?php if ($__in === []): ?><p class="kanban-column-empty">Nothing here</p><?php endif; ?>
        <?php foreach ($__in as $a): ?><?= $renderKanbanCard($a) ?><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
