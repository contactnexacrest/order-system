<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canViewArchivedOrders = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_archived_orders');

$statusLabels = ['all' => 'All', 'active' => 'Active', 'overdue' => 'Overdue', 'complete' => 'Complete', 'lost' => 'Lost'];
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Orders</h1>
      <p class="muted" style="margin:0;"><?= (int) $counts['all'] ?> total &middot; a card for every order, not a spreadsheet row.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn btn-accent" href="/clients">Go to Clients to start a new order</a>
      <?php if ($canViewArchivedOrders): ?><a class="btn-sm btn-secondary" href="/orders/archived">View Archived Orders</a><?php endif; ?>
    </div>
  </div>

  <div class="filter-chip-row">
    <?php foreach ($statusLabels as $key => $label): ?>
      <a class="filter-chip<?= $statusFilter === $key ? ' active' : '' ?>" href="/orders<?= $key === 'all' ? '' : '?status=' . $key ?>"><?= $label ?> (<?= (int) $counts[$key] ?>)</a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($orders)): ?>
    <p class="muted">No orders match this filter.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($orders as $o):
      $stageTotal = 9;
      $stageNumber = (int) ($o['current_stage_number'] ?? 0);
      $isLost = $o['status'] === 'lost';
      $isComplete = $o['status'] === 'complete';
      $filledSegments = $isComplete ? $stageTotal : ($isLost ? min(1, max($stageNumber, 1)) : $stageNumber);
      $currency = $o['currency_code'] ?? '';
      $amount = $currency . ' ' . number_format((float) $o['total_fob_value'], 2);
    ?>
    <a class="entity-card<?= !empty($o['is_overdue']) ? ' entity-card-overdue' : '' ?>" href="/orders/<?= (int) $o['id'] ?>">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($o['order_reference']) ?></span>
        <?php if (!empty($o['incoterm_code'])): ?><span class="tag tag-gold"><?= htmlspecialchars($o['incoterm_code']) ?></span><?php endif; ?>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars($o['company_legal_name']) ?></div>
      <div>
        <div class="mini-stage-track">
          <?php for ($i = 1; $i <= $stageTotal; $i++): ?>
            <div class="mini-stage-seg<?= $i <= $filledSegments ? ' filled' . ($isLost ? ' lost' : '') : '' ?>"></div>
          <?php endfor; ?>
        </div>
        <div class="mini-stage-label">
          <?php if ($isLost): ?>
            <?= htmlspecialchars($o['lost_reason'] ? mb_strimwidth($o['lost_reason'], 0, 46, '…') : 'Marked lost') ?>
          <?php elseif ($isComplete): ?>
            Stage 9 of 9 &middot; Closed
          <?php else: ?>
            Stage <?= $stageNumber ?: 1 ?> of <?= $stageTotal ?> &middot; <?= htmlspecialchars($o['current_stage_name'] ?? 'Quotation') ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="entity-card-footer">
        <?php if (!empty($o['is_overdue'])): ?>
          <span class="badge badge-lost">Overdue</span>
        <?php elseif ($isLost): ?>
          <span class="badge badge-lost">Lost</span>
        <?php elseif ($isComplete): ?>
          <span class="badge badge-complete">Complete</span>
        <?php else: ?>
          <span class="badge badge-active">Active</span>
        <?php endif; ?>
        <span class="entity-card-amount"><?= htmlspecialchars($amount) ?></span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
