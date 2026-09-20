<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Asset Management</h1>
  <p class="muted">Placeholder images are in place for every slot below so the app runs end-to-end. Replace each with the real file when it's ready — no code changes needed, just an upload here.</p>

  <div class="asset-grid">
    <?php
    $types = [
      'logo'         => 'Company Logo',
      'signature'    => 'MD Signature',
      'seal'         => 'Company Seal',
      'watermark'    => 'PDF Watermark',
      'email_header' => 'Email Header',
    ];
    foreach ($types as $type => $label):
      $current = $active[$type][0] ?? null;
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
      </div>
    <?php endforeach; ?>
  </div>
</div>
