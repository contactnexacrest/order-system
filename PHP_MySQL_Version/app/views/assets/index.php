<?php
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\PermissionService;
$__u = AuthService::currentUser();
$canDelete = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'delete_assets');
?>
<div class="card">
  <h1>Asset Management</h1>
  <p class="muted">The real logo, company seal, and legacy default signature/watermark files are shown below. Per-signatory signatures and designation seals are managed from <a href="/signatories">Signatories &amp; Designations</a>.</p>

  <div class="asset-grid">
    <?php
    $types = [
      'logo'         => 'Company Logo',
      'signature'    => 'Signature (legacy global fallback)',
      'seal'         => 'Company Seal',
      'watermark'    => 'PDF Watermark',
    ];
    foreach ($types as $type => $label):
      $current = $active[$type][0] ?? null;
      $history = array_filter($assets, fn($a) => $a['asset_type'] === $type && (int) $a['is_active'] === 0);
    ?>
      <div class="asset-tile">
        <h3><?= htmlspecialchars($label) ?></h3>
        <?php if ($current): ?>
          <img src="/company-assets/preview?type=<?= urlencode($type) ?>" alt="<?= htmlspecialchars($label) ?>" class="asset-preview">
          <p class="muted small">Current: <?= htmlspecialchars($current['name']) ?> · uploaded <?= htmlspecialchars($current['uploaded_at']) ?></p>
        <?php else: ?>
          <p class="muted small">No asset on file yet.</p>
        <?php endif; ?>
        <form method="post" action="/company-assets/replace" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <input type="hidden" name="asset_type" value="<?= htmlspecialchars($type) ?>">
          <input type="text" name="name" placeholder="<?= htmlspecialchars($label) ?>">
          <input type="file" name="file" accept=".png,.jpg,.jpeg,.svg" required>
          <button type="submit">Replace</button>
        </form>

        <?php if ($history): ?>
          <details class="asset-history">
            <summary class="muted small">Previous uploads (<?= count($history) ?>)</summary>
            <ul class="asset-history-list">
              <?php foreach ($history as $old): ?>
                <li>
                  <?= htmlspecialchars($old['name']) ?> · <?= htmlspecialchars($old['uploaded_at']) ?>
                  <?php if ($canDelete): ?>
                    <form method="post" action="/company-assets/<?= (int) $old['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Permanently delete this old upload? This cannot be undone.');">
                      <?= Csrf::field() ?>
                      <button type="submit" class="btn-sm btn-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
