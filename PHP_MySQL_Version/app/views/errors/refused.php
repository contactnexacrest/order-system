<?php
/**
 * D-379 cosmetic follow-up (16 Nov 2026, owner-approved mockup): the 403 refusal page of the report gates
 * (ReportAccessCheck) and of PermissionCheck, inside the normal app layout — a red "Not open to you" pill,
 * the title, the existing explanatory text and a "← Back" button. Drawn by App\Helpers\RefusalPage.
 * Node twin: NODE_MySQL_Version/app/views/errors/refused.njk
 */
?>
<div class="card page-wide refused-page" id="refused-page" data-refused>
  <span class="refused-pill">Not open to you</span>
  <h1><?= htmlspecialchars($title) ?></h1>
  <p class="refused-text"><?= htmlspecialchars($message) ?></p>
  <p><a class="btn" href="<?= htmlspecialchars($backUrl) ?>">&larr; <?= htmlspecialchars($backLabel) ?></a></p>
</div>
