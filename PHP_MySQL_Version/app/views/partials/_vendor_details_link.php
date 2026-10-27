<?php
// Owner request (2026-10-27): the no-login Vendor Details Link. Expects
// $vendorKind, $vendorId, $vendor (the row), $detailsLink (live link or null).
use App\Helpers\Csrf;

$__base = $vendorKind === 'supplier' ? '/suppliers/' : '/logistics-partners/';
?>
  <div class="section" id="vendor-details-link">
    <h2 style="font-size:1rem;">Vendor Details Link</h2>
    <p class="muted small">Send this link so they fill in their own company and bank details — no login needed. It works once and for 30 days; when they submit, the details land here and the bank account shows as <strong>not verified</strong> until you check it. If they reply by email instead, fill the details in above on their behalf.</p>
    <?php if (!empty($vendor['details_submitted_at'])): ?>
      <p class="small">&#10003; Last filled in by them on <?= htmlspecialchars((string) $vendor['details_submitted_at']) ?>.</p>
    <?php endif; ?>
    <?php if ($detailsLink): ?>
      <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
        <input type="text" readonly value="<?= htmlspecialchars($detailsLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px" aria-label="Vendor Details Link">
        <button type="button" class="btn-sm btn-secondary" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($detailsLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
      </div>
    <?php endif; ?>
    <form method="post" action="<?= $__base . (int) $vendorId ?>/details-link">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm"><?= $detailsLink ? 'Regenerate &amp; Email Details Link' : 'Generate &amp; Email Details Link' ?></button>
      <?php if (empty($vendor['email'])): ?><span class="muted small">No email on file — copy the link and send it yourself (WhatsApp etc.).</span><?php endif; ?>
    </form>
  </div>
