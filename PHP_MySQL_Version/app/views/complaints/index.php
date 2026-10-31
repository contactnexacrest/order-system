<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Complaints (All Orders)</h1>
      <p class="muted" style="margin:0;">A process issue or flaw the client flagged for us to fix next time — not a Dispute. <?= count($complaints) ?> shown.</p>
    </div>
    <?php if (!empty($kanbanUser)): ?><div class="btn-row" style="margin-top:0;"><a class="btn-sm btn-secondary" href="/complaints">Switch to board view</a></div><?php endif; ?>
  </div>

  <?php $listOfBoard = !empty($kanbanUser); include __DIR__ . '/_filters.php'; ?>

  <?php if (empty($complaints)): ?>
    <p class="muted">No complaints recorded.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($complaints as $c): $kanbanCard = false; include __DIR__ . '/_card.php'; endforeach; ?>
  </div>
  <?php endif; ?>
</div>
