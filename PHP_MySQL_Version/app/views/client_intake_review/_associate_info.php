<?php
/** Owner decision 1 Nov 2026 (AS-5, guarantees 1–2): who introduced the buyer, and warnings — same on the list and the board. */
$__ai = \App\Services\AssociateIntakeService::reviewInfo($s);
if ($__ai['associate'] !== null || $__ai['flags'] !== []): ?>
  <div class="small associate-intake-info">
    <?php if ($__ai['associate'] !== null): ?><span class="badge <?= $__ai['verified'] && $__ai['confirmed'] ? 'badge-active' : 'badge-pending' ?>">Associate: <?= htmlspecialchars($__ai['associate']) ?><?= $__ai['verified'] ? ($__ai['confirmed'] ? ' — confirmed by the buyer' : ' — not confirmed') : ' — e-mail not verified' ?></span><?php endif; ?>
    <?php foreach ($__ai['flags'] as $__f): ?><div class="text-warning">&#9888; <?= htmlspecialchars($__f) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>
