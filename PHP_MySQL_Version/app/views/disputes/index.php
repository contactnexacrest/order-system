<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Dispute Log (All Orders)</h1>
      <p class="muted" style="margin:0;"><?= count($disputes) ?> shown.</p>
    </div>
    <?php if (!empty($kanbanUser)): ?><div class="btn-row" style="margin-top:0;"><a class="btn-sm btn-secondary" href="/disputes">Switch to board view</a></div><?php endif; ?>
  </div>

  <?php $listOfBoard = !empty($kanbanUser); include __DIR__ . '/_filters.php'; ?>

  <?php if (empty($disputes)): ?>
    <p class="muted">No disputes recorded.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($disputes as $d): $kanbanCard = false; include __DIR__ . '/_card.php'; endforeach; ?>
  </div>
  <?php endif; ?>
</div>
