<?php
/**
 * D-280: a link with a Copy button (app.js [data-copy]). Expects $__copyLink; optional $__copyNote.
 */
?>
<div class="copy-box" data-testid="copy-box">
  <?php if (!empty($__copyNote)): ?><p class="muted small copy-box-note"><?= htmlspecialchars((string) $__copyNote) ?></p><?php endif; ?>
  <div class="copy-box-row">
    <code class="copy-box-value"><?= htmlspecialchars((string) $__copyLink) ?></code>
    <button type="button" class="btn-sm" data-copy="<?= htmlspecialchars((string) $__copyLink) ?>">Copy</button>
  </div>
</div>
