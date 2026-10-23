<?php
$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'Resolved' => 'badge-active',
        'Escalated' => 'badge-lost',
        default => 'badge-protected',
    };
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Dispute Log (All Orders)</h1>
      <p class="muted" style="margin:0;"><?= count($disputes) ?> shown.</p>
    </div>
  </div>

  <div class="filter-chip-row">
    <a class="filter-chip<?= $statusFilter === null ? ' active' : '' ?>" href="/disputes">All</a>
    <?php foreach ($statusOptions as $opt): ?>
      <a class="filter-chip<?= $statusFilter === $opt['option_value'] ? ' active' : '' ?>" href="/disputes?status=<?= urlencode($opt['option_value']) ?>"><?= htmlspecialchars($opt['option_value']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($disputes)): ?>
    <p class="muted">No disputes recorded.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($disputes as $d): ?>
    <a class="entity-card" href="/orders/<?= (int) $d['order_id'] ?>/disputes">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($d['order_reference']) ?></span>
        <span class="badge <?= $statusBadgeClass($d['status']) ?>"><?= htmlspecialchars($d['status']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars($d['company_legal_name']) ?></div>
      <div class="muted small"><?= htmlspecialchars(mb_strimwidth($d['description'], 0, 110, '…')) ?></div>
      <div class="entity-card-footer">
        <span class="muted small">Notice: <?= htmlspecialchars($d['notice_date']) ?></span>
        <span class="muted small">Response due: <?= htmlspecialchars($d['response_due_date'] ?? '—') ?></span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
