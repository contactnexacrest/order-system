<?php use App\Helpers\Csrf; $annexureMode = $order['annexure_mode'] ?? 'SPEC'; ?>
<div class="card page-wide">
  <h1>Annexure A — <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <p class="muted">Product technical specifications (dimensions, finish, components, technical notes) and images, and/or free-form Additional Terms text, referenced from the Quotation/PI/OC/Buyer PO as an attached, integral document whenever "Include Annexure A" is on for this order.</p>

  <div class="section">
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/toggle">
      <?= Csrf::field() ?>
      <label><input type="checkbox" name="include_annexure_a" value="1" <?= $order['include_annexure_a'] ? 'checked' : '' ?> style="display:inline-block;width:auto;" onchange="this.form.submit()"> Include Annexure A on this order's documents</label>
    </form>
  </div>

  <div class="section">
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/mode" id="annexure-mode-form">
      <?= Csrf::field() ?>
      <label>Annexure A content
        <select name="mode" id="annexure-mode-select" onchange="annexureApplyMode(this.value); this.form.submit();">
          <option value="SPEC" <?= $annexureMode === 'SPEC' ? 'selected' : '' ?>>Product Specification (current view)</option>
          <option value="TERMS" <?= $annexureMode === 'TERMS' ? 'selected' : '' ?>>Additional Terms</option>
          <option value="BOTH" <?= $annexureMode === 'BOTH' ? 'selected' : '' ?>>Both</option>
        </select>
      </label>
      <p class="muted small">Product Specification is the structured product/dimensions table below. Additional Terms is free-form text (clauses, notes, anything agreed with the client) entered as rich text, including pasted images. Both prints Product Specification first, then Additional Terms. Switching this does not delete either section's saved content.</p>
    </form>
  </div>

  <div id="annexure-spec-section">
  <div class="section">
    <h2>Product Entries</h2>
    <?php foreach ($products as $p): ?>
      <div class="card-nested">
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/products/<?= (int) $p['id'] ?>">
          <?= Csrf::field() ?>
          <label>Name *<input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>" required></label>
          <label>Description<textarea name="description" rows="2"><?= htmlspecialchars($p['description'] ?? '') ?></textarea></label>
          <label>Dimensions<input type="text" name="dimensions" value="<?= htmlspecialchars($p['dimensions'] ?? '') ?>"></label>
          <label>Finish<input type="text" name="finish" value="<?= htmlspecialchars($p['finish'] ?? '') ?>"></label>
          <label>Components<textarea name="components" rows="2"><?= htmlspecialchars($p['components'] ?? '') ?></textarea></label>
          <label>Technical Notes<textarea name="technical_notes" rows="2"><?= htmlspecialchars($p['technical_notes'] ?? '') ?></textarea></label>
          <button type="submit" class="btn-sm">Save</button>
        </form>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/products/<?= (int) $p['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this product entry and its images?');">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-danger">Remove Entry</button>
        </form>

        <div class="section">
          <strong>Images</strong>
          <div class="btn-row">
            <?php foreach ($p['images'] as $img): ?>
              <div class="thumb-card">
                <img src="/file-store/<?= (int) $img['file_id'] ?>/download" alt="<?= htmlspecialchars($img['original_filename']) ?>" class="asset-preview">
                <span class="muted small"><?= htmlspecialchars($img['original_filename']) ?></span>
                <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/images/<?= (int) $img['id'] ?>/remove" onsubmit="return confirm('Remove this image from Annexure A?');">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn-sm btn-danger">&times;</button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if (empty($p['images'])): ?><span class="muted small">No images uploaded yet.</span><?php endif; ?>
          </div>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/products/<?= (int) $p['id'] ?>/images" enctype="multipart/form-data" style="display:inline">
            <?= Csrf::field() ?>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" required>
            <button type="submit" class="btn-sm">Upload Image</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($products)): ?>
      <p class="muted">No product entries yet — add one below.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Add Product Entry</h2>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/products">
      <?= Csrf::field() ?>
      <label>Name *<input type="text" name="name" required></label>
      <label>Description<textarea name="description" rows="2"></textarea></label>
      <label>Dimensions<input type="text" name="dimensions" placeholder="e.g. 210 x 528 cm"></label>
      <label>Finish<input type="text" name="finish" placeholder="e.g. Mirror Polished"></label>
      <label>Components<textarea name="components" rows="2" placeholder="e.g. 1. Left frame&#10;2. Right frame&#10;3. Base"></textarea></label>
      <label>Technical Notes<textarea name="technical_notes" rows="2"></textarea></label>
      <button type="submit">Add Product Entry</button>
    </form>
  </div>
  </div>

  <div id="annexure-terms-section" class="section">
    <h2>Additional Terms</h2>
    <p class="muted small">Free-form text — special terms, clauses, or anything else agreed with the client that doesn't fit the Product Specification table. Supports basic formatting and pasted/inserted images. Video/audio can't be embedded here: a generated PDF or Word document can't play media, so only images are supported.</p>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/terms" id="annexure-terms-form">
      <?= Csrf::field() ?>
      <div class="wysiwyg-toolbar" role="toolbar" aria-label="Text formatting">
        <button type="button" class="wysiwyg-btn" data-cmd="bold" title="Bold"><strong>B</strong></button>
        <button type="button" class="wysiwyg-btn" data-cmd="italic" title="Italic"><em>I</em></button>
        <button type="button" class="wysiwyg-btn" data-cmd="underline" title="Underline"><u>U</u></button>
        <button type="button" class="wysiwyg-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" data-block="H3" title="Heading">H3</button>
        <button type="button" class="wysiwyg-btn" data-block="H4" title="Subheading">H4</button>
        <button type="button" class="wysiwyg-btn" data-block="BLOCKQUOTE" title="Quote">&ldquo;&rdquo;</button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" data-cmd="insertUnorderedList" title="Bullet list">&bull; List</button>
        <button type="button" class="wysiwyg-btn" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" id="annexure-terms-insert-image" title="Insert image">Image</button>
        <button type="button" class="wysiwyg-btn" id="annexure-terms-insert-link" title="Insert link">Link</button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" data-cmd="removeFormat" title="Clear formatting">Clear</button>
        <button type="button" class="wysiwyg-btn" data-cmd="undo" title="Undo">&#8634;</button>
        <button type="button" class="wysiwyg-btn" data-cmd="redo" title="Redo">&#8635;</button>
      </div>
      <input type="file" id="annexure-terms-image-input" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;">
      <div id="annexure-terms-surface" class="wysiwyg-surface" contenteditable="true"><?= $terms['content_html'] ?? '' ?></div>
      <textarea name="content_html" id="annexure-terms-hidden" style="display:none;"></textarea>
      <button type="submit" class="btn-sm" style="margin-top:0.75rem;">Save Additional Terms</button>
    </form>
  </div>
</div>

<script>
(function () {
  var mode = <?= json_encode($annexureMode) ?>;
  var specSection = document.getElementById('annexure-spec-section');
  var termsSection = document.getElementById('annexure-terms-section');

  window.annexureApplyMode = function (m) {
    specSection.style.display = (m === 'TERMS') ? 'none' : '';
    termsSection.style.display = (m === 'SPEC') ? 'none' : '';
  };
  annexureApplyMode(mode);

  var surface = document.getElementById('annexure-terms-surface');
  var hidden = document.getElementById('annexure-terms-hidden');
  var termsForm = document.getElementById('annexure-terms-form');
  function syncHidden() { hidden.value = surface.innerHTML; }
  surface.addEventListener('input', syncHidden);
  termsForm.addEventListener('submit', syncHidden);

  document.querySelectorAll('.wysiwyg-btn[data-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      surface.focus();
      document.execCommand(btn.getAttribute('data-cmd'), false, null);
      syncHidden();
    });
  });
  document.querySelectorAll('.wysiwyg-btn[data-block]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      surface.focus();
      document.execCommand('formatBlock', false, btn.getAttribute('data-block'));
      syncHidden();
    });
  });

  var imageInput = document.getElementById('annexure-terms-image-input');
  document.getElementById('annexure-terms-insert-image').addEventListener('click', function () {
    imageInput.value = '';
    imageInput.click();
  });
  imageInput.addEventListener('change', function () {
    var file = imageInput.files[0];
    if (!file) { return; }
    if (file.size > 3 * 1024 * 1024) {
      alert('Image is larger than 3 MB — please use a smaller image.');
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      surface.focus();
      document.execCommand('insertImage', false, reader.result);
      syncHidden();
    };
    reader.readAsDataURL(file);
  });

  document.getElementById('annexure-terms-insert-link').addEventListener('click', function () {
    var url = prompt('Link URL (https://...)');
    if (!url) { return; }
    surface.focus();
    document.execCommand('createLink', false, url);
    syncHidden();
  });

  syncHidden();
})();
</script>
