<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Annexure A — <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <p class="muted">Product technical specifications (dimensions, finish, components, technical notes) and images referenced from the Quotation/PI/OC/Buyer PO as an attached, integral document whenever "Include Annexure A" is on for this order.</p>

  <div class="section">
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/annexure/toggle">
      <?= Csrf::field() ?>
      <label><input type="checkbox" name="include_annexure_a" value="1" <?= $order['include_annexure_a'] ? 'checked' : '' ?> style="display:inline-block;width:auto;" onchange="this.form.submit()"> Include Annexure A on this order's documents</label>
    </form>
  </div>

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
