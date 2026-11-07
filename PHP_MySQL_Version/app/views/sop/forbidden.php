<?php // Owner decision D-266: a SOP chapter (or the whole library) this person may not open — shown in the normal layout. ?>
<div class="card page-wide">
  <h1>Not permitted</h1>
  <div class="alert alert-warning" data-sop-forbidden><?= htmlspecialchars($message) ?></div>
  <p><a href="<?= htmlspecialchars($backUrl) ?>"><?= htmlspecialchars($backLabel) ?></a></p>
</div>
