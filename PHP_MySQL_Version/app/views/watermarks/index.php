<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Watermarks</h1>
  <p class="muted">Every generated PDF carries one of these two watermarks — <strong>Draft</strong> until a document is fully reviewer-approved, then <strong>Final</strong>. Each can show text only, an image only, or both at the same time — they're independent overlay layers, not either/or.</p>

  <div class="section">
    <p class="muted small">
      Watermark image on file: <?= $watermarkImageAsset ? '<strong>yes</strong> — <a href="/company-assets">replace it from Company Assets</a>' : '<strong>none yet</strong> — <a href="/company-assets">upload one from Company Assets</a> before picking Image or Both below.' ?>
    </p>
  </div>

  <?php foreach (['draft' => $draft, 'final' => $final] as $which => $w): ?>
  <div class="section">
    <h2><?= ucfirst($which) ?> Watermark</h2>
    <form method="post" action="/watermarks/<?= $which ?>">
      <?= Csrf::field() ?>
      <label>Mode
        <select name="mode">
          <option value="text" <?= (!$w || $w['mode'] === 'text') ? 'selected' : '' ?>>Text only</option>
          <option value="image" <?= ($w && $w['mode'] === 'image') ? 'selected' : '' ?>>Image only</option>
          <option value="both" <?= ($w && $w['mode'] === 'both') ? 'selected' : '' ?>>Text and image together</option>
        </select>
      </label>

      <fieldset style="margin-top:8px">
        <legend class="muted small">Text settings (used when mode is Text or Both)</legend>
        <label>Text<input type="text" name="text_content" value="<?= htmlspecialchars($w['text_content'] ?? ($which === 'draft' ? 'DRAFT — NOT FOR RELEASE' : '')) ?>" style="width:280px"></label>
        <label>Color<input type="color" name="color" value="<?= htmlspecialchars($w['color'] ?? '#CCCCCC') ?>"></label>
        <label>Font size (pt)<input type="number" name="font_size" value="<?= (int) ($w['font_size'] ?? 60) ?>" style="width:80px"></label>
        <label>Opacity (0–1)<input type="number" step="0.05" min="0" max="1" name="opacity" value="<?= htmlspecialchars((string) ($w['opacity'] ?? 0.3)) ?>" style="width:80px"></label>
        <label>Angle (degrees)<input type="number" name="angle" value="<?= (int) ($w['angle'] ?? 45) ?>" style="width:80px"></label>
      </fieldset>

      <fieldset style="margin-top:8px">
        <legend class="muted small">Image settings (used when mode is Image or Both)</legend>
        <label>Position
          <select name="image_position">
            <?php foreach (['center' => 'Center', 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right'] as $val => $label): ?>
              <option value="<?= $val ?>" <?= ($w && $w['image_position'] === $val) ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Opacity (0–1)<input type="number" step="0.05" min="0" max="1" name="image_opacity" value="<?= htmlspecialchars((string) ($w['image_opacity'] ?? 0.15)) ?>" style="width:80px"></label>
      </fieldset>

      <button type="submit" class="btn-sm btn-accent" style="margin-top:8px">Save <?= ucfirst($which) ?> Watermark</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
