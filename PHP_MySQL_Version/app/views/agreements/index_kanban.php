<?php
$listOfBoard = false;
/** Owner hard rule 2026-11-01: every field, link and action of the Standard list (agreements/index.php), as cards by status. */
$renderKanbanCard = static function (array $__a) use ($canManage, $canClientFile, $canAssociateFile): string {
    ob_start();
    ?>
    <div class="entity-card kanban-card">
      <div class="entity-card-header"><a class="entity-card-title" href="<?= htmlspecialchars(\App\Repositories\AgreementRepository::partyUrl($__a['party_type'], (int) $__a['party_id'])) ?>"><?= htmlspecialchars((string) $__a['party_name']) ?></a></div>
      <div class="muted small"><?= htmlspecialchars(\App\Repositories\AgreementRepository::PARTY_TYPES[$__a['party_type']]) ?></div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) $__a['agreement_type']) ?><?php if (!empty($__a['title'])): ?> — <?= htmlspecialchars((string) $__a['title']) ?><?php endif; ?></div>
      <div class="small">Signed <?= htmlspecialchars((string) ($__a['signed_on'] ?? '—')) ?> · Expires <?= htmlspecialchars((string) ($__a['expires_on'] ?? 'No expiry')) ?><?php if ($__a['days_left'] !== null && $__a['days_left'] >= 0 && in_array($__a['status'], ['expiring', 'active'], true)): ?> (in <?= (int) $__a['days_left'] ?> day(s))<?php endif; ?></div>
      <?php if (!empty($__a['terminated_on'])): ?><div class="muted small">Ended <?= htmlspecialchars((string) $__a['terminated_on']) ?> — <?= htmlspecialchars((string) $__a['terminated_reason']) ?></div><?php endif; ?>
      <?php $__st = (string) $__a['status']; require __DIR__ . '/_status_badge.php'; ?>
      <div class="entity-card-footer"><?php require __DIR__ . '/_actions.php'; ?></div>
    </div>
    <?php
    return (string) ob_get_clean();
};
$columns = $f['status'] === 'all' ? array_diff_key(\App\Repositories\AgreementRepository::STATUSES, $counts['renewed'] === 0 ? ['renewed' => 1] : []) : [$f['status'] => \App\Repositories\AgreementRepository::STATUSES[$f['status']]];
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Agreements — Kanban Board</h1>
      <p class="muted" style="margin:0">Every agreement signed with a client, supplier, logistics partner, associate<?= $canManage ? ' or staff member' : '' ?> — its signed copy and dates. A reminder goes out 30 and 7 days before an agreement expires, and on the day.</p>
    </div>
    <div class="btn-row" style="margin-top:0">
      <a class="btn-sm btn-secondary" href="/agreements?view=list">Switch to list view</a>
      <?php if ($canManage): ?><a class="btn-sm" href="/agreements/new">Add an agreement</a><?php endif; ?>
    </div>
  </div>
  <?php require __DIR__ . '/_filters.php'; ?>
  <div class="kanban-board">
    <?php foreach ($columns as $key => $label): $__in = array_values(array_filter($rows, static fn (array $a): bool => $a['status'] === $key)); ?>
      <div class="kanban-column">
        <div class="kanban-column-header"><span class="kanban-column-title"><?= htmlspecialchars($label) ?></span><span class="kanban-column-count"><?= count($__in) ?></span></div>
        <?php if ($__in === []): ?><p class="kanban-column-empty">Nothing here</p><?php endif; ?>
        <?php foreach ($__in as $__row): ?><?= $renderKanbanCard($__row) ?><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
