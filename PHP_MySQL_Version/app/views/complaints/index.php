<?php
$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'resolved' => 'badge-active',
        'acknowledged' => 'badge-protected',
        default => 'badge-lost',
    };
};
$statusLabel = static function (string $status): string {
    return match ($status) {
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
        default => 'Open',
    };
};
$allStatuses = ['open', 'acknowledged', 'resolved'];
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Complaints (All Orders)</h1>
      <p class="muted" style="margin:0;">A process issue or flaw the client flagged for us to fix next time — not a Dispute. <?= count($complaints) ?> shown.</p>
    </div>
  </div>

  <div class="filter-chip-row">
    <a class="filter-chip<?= $statusFilter === null ? ' active' : '' ?>" href="/complaints">All</a>
    <?php foreach ($allStatuses as $s): ?>
      <a class="filter-chip<?= $statusFilter === $s ? ' active' : '' ?>" href="/complaints?status=<?= urlencode($s) ?>"><?= htmlspecialchars($statusLabel($s)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($complaints)): ?>
    <p class="muted">No complaints recorded.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($complaints as $c): ?>
    <a class="entity-card" href="/orders/<?= (int) $c['order_id'] ?>/complaints">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($c['order_reference']) ?></span>
        <span class="badge <?= $statusBadgeClass($c['status']) ?>"><?= htmlspecialchars($statusLabel($c['status'])) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars($c['company_legal_name']) ?></div>
      <div class="muted small"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 110, '…')) ?></div>
      <div class="entity-card-footer">
        <span class="muted small">Raised: <?= htmlspecialchars((string) $c['created_at']) ?></span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
