<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/products">&larr; Back to Products</a></p>
  <h1><?= View::e($product['name']) ?> <span class="badge <?= $product['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $product['is_active'] ? 'Active' : 'Inactive' ?></span></h1>
  <p class="muted">HS Code: <strong><?= View::e($product['hs_code']) ?></strong> &nbsp;·&nbsp; Origin: <?= View::e($product['origin'] ?? '—') ?></p>

  <?php if ($canManage): ?>
    <p>
      <a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/edit">Edit</a>
      <form method="post" action="/products/<?= (int) $product['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Permanently delete this product and all its images/suppliers/misc charges? This cannot be undone.');">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-sm btn-danger">Delete Product</button>
      </form>
    </p>
  <?php endif; ?>

  <div class="section">
    <h2>Specifications</h2>
    <p><?= nl2br(View::e($product['specifications'])) ?></p>
    <?php /* Owner decision D-255 (5 Nov 2026): the options that change the price. */
    $__su = \App\Services\AuthService::currentUser(); $__sr = $__su !== null && $__su['role_id'] !== null ? (int) $__su['role_id'] : null;
    $__canSpecs = $__su !== null && (\App\Services\PermissionService::can((int) $__su['id'], $__sr, 'view_price_buildup') || \App\Services\PermissionService::can((int) $__su['id'], $__sr, 'manage_product_specifications') || \App\Services\PermissionService::can((int) $__su['id'], $__sr, 'manage_pricing'));
    if ($__canSpecs): ?><p><a class="btn-sm btn-secondary" href="/products/<?= (int) $product['id'] ?>/specifications">Price options (specifications)</a>
      <?php // D-300 (point 12): the category's price rules; a product with its own values shows "own rule". ?>
      <?php $__catId = \App\Services\ProductSpecs::categoryIdFor($product); if ($__catId !== null && (\App\Services\PermissionService::can((int) $__su['id'], $__sr, 'view_price_buildup') || \App\Services\PermissionService::can((int) $__su['id'], $__sr, 'manage_pricing'))): ?><a class="btn-sm btn-secondary" href="/products/categories/<?= (int) $__catId ?>?tab=rules">Price rules</a><?php endif; ?>
      <?php if (($product['thick_k_factor'] ?? null) !== null || ($product['container_cbm'] ?? null) !== null || ($product['container_max_t'] ?? null) !== null): ?> <span class="badge badge-warning" title="This product has its own price rule (× K or container) — set on Edit">own rule</span><?php endif; ?></p><?php endif; ?>
  </div>


  <?php include __DIR__ . '/_pricing_ready.php'; ?>

  <?php include __DIR__ . '/_pricing.php'; ?>

  <?php if ($canViewPricing): ?>
  <div class="section">
    <h2>Default Cost Components</h2>
    <p class="muted small">These are the catalog-wide fallbacks a computed-FOB supplier uses for any cost component it does not itself override.</p>
    <div class="kv-grid">
      <?php // Owner decision D-214, phase 2: the factory cost is the buying side; the rest are logistics costs. ?>
      <?php if ($sees['purchase']): ?><div><span class="k">Factory Cost</span><span class="v"><?= $product['default_factory_cost'] === null ? '—' : number_format((float) $product['default_factory_cost'], 2) ?></span></div><?php endif; ?>
      <?php if ($sees['logistics']): ?>
      <div><span class="k">Transportation Cost</span><span class="v"><?= $product['default_transportation_cost'] === null ? '—' : number_format((float) $product['default_transportation_cost'], 2) ?></span></div>
      <div><span class="k">Packing Cost</span><span class="v"><?= $product['default_packing_cost'] === null ? '—' : number_format((float) $product['default_packing_cost'], 2) ?></span></div>
      <div><span class="k">Loading Cost</span><span class="v"><?= $product['default_loading_cost'] === null ? '—' : number_format((float) $product['default_loading_cost'], 2) ?></span></div>
      <div><span class="k">CHA Cost</span><span class="v"><?= $product['default_cha_cost'] === null ? '—' : number_format((float) $product['default_cha_cost'], 2) ?></span></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Images</h2>
    <div class="asset-grid">
      <?php foreach ($images as $img): ?>
        <div class="asset-tile" style="position:relative">
          <img src="/products/images/<?= (int) $img['id'] ?>/view" alt="Product image" class="asset-preview">
          <?php if ($canManage): ?>
            <form method="post" action="/products/images/<?= (int) $img['id'] ?>/delete" style="position:absolute;top:4px;right:4px" onsubmit="return confirm('Remove this image?');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger" title="Remove image">&times;</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($images)): ?><p class="muted">No images uploaded yet.</p><?php endif; ?>
    </div>
    <?php if ($canManage): ?>
      <form method="post" action="/products/<?= (int) $product['id'] ?>/images/upload" enctype="multipart/form-data" style="margin-top:0.75rem">
        <?= Csrf::field() ?>
        <input type="file" name="image" accept=".png,.jpg,.jpeg" required aria-label="Image">
        <button type="submit" class="btn-sm">Upload Image</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($canViewPricing && !empty($canSeeSuppliers)): ?>
  <div class="section">
    <h2>Suppliers</h2>
    <table class="list">
      <tr>
        <th>Supplier</th><th>Location</th><th>Contact</th><th>FOB Source</th><th>Effective FOB</th><th>Used for pricing</th><th></th>
      </tr>
      <?php if (empty($suppliers)): ?>
        <tr><td colspan="7" class="muted">No suppliers on file yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $s): ?>
        <tr>
          <td><?= View::e($s['supplier_name']) ?></td>
          <td><?= View::e($s['location'] ?? '—') ?></td>
          <td>
            <?= View::e($s['contact_person'] ?? '—') ?>
            <?php if ($s['contact_phone']): ?><br><small class="muted"><?= View::e($s['contact_phone']) ?></small><?php endif; ?>
            <?php if ($s['contact_email']): ?><br><small class="muted"><?= View::e($s['contact_email']) ?></small><?php endif; ?>
          </td>
          <td><?= View::e(ucfirst($s['fob_source'])) ?></td>
          <td><?= $s['effective_fob'] === null ? 'To Be Confirmed' : number_format((float) $s['effective_fob'], 2) ?></td>
          <td><?= $s['is_primary'] ? '&#9733;' : '' ?></td>
          <td>
            <?php if ($canManage): ?>
              <?php if (!$s['is_primary']): ?>
                <form method="post" action="/products/suppliers/<?= (int) $s['id'] ?>/set-primary" style="display:inline">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn-sm">Use for pricing</button>
                </form>
              <?php endif; ?>
              <details style="display:inline-block">
                <summary class="btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                <div class="card-nested">
                  <form method="post" action="/products/suppliers/<?= (int) $s['id'] ?>/update">
                    <?= Csrf::field() ?>
                    <label>Supplier Name *<input type="text" name="supplier_name" value="<?= View::e($s['supplier_name']) ?>" required placeholder="e.g. Sri Balaji Granites"></label>
                    <label>Location<input type="text" name="location" value="<?= View::e($s['location'] ?? '') ?>" placeholder="e.g. Krishnagiri, Tamil Nadu"></label>
                    <label>Contact Person<input type="text" name="contact_person" value="<?= View::e($s['contact_person'] ?? '') ?>" placeholder="e.g., Sales Manager"></label>
                    <label>Contact Phone<input type="text" name="contact_phone" value="<?= View::e($s['contact_phone'] ?? '') ?>" placeholder="e.g. +91-0000000000"></label>
                    <label>Contact Email<input type="text" name="contact_email" value="<?= View::e($s['contact_email'] ?? '') ?>" placeholder="e.g. name@company.com"></label>
                    <label>FOB Source
                      <select name="fob_source">
                        <option value="direct" <?= $s['fob_source'] === 'direct' ? 'selected' : '' ?>>Direct</option>
                        <option value="computed" <?= $s['fob_source'] === 'computed' ? 'selected' : '' ?>>Computed</option>
                      </select>
                    </label>
                    <label>FOB Value (direct only)<input type="number" step="0.01" min="0" name="fob_value" value="<?= View::e($s['fob_value'] !== null ? (string) $s['fob_value'] : '') ?>" placeholder="e.g. 4500.00"></label>
                    <label>Factory Cost override<input type="number" step="0.01" min="0" name="factory_cost" value="<?= View::e($s['factory_cost'] !== null ? (string) $s['factory_cost'] : '') ?>" placeholder="e.g. 350000"></label>
                    <label>Transportation Cost override<input type="number" step="0.01" min="0" name="transportation_cost" value="<?= View::e($s['transportation_cost'] !== null ? (string) $s['transportation_cost'] : '') ?>" placeholder="e.g. 9000"></label>
                    <label>Packing Cost override<input type="number" step="0.01" min="0" name="packing_cost" value="<?= View::e($s['packing_cost'] !== null ? (string) $s['packing_cost'] : '') ?>" placeholder="e.g. 12000"></label>
                    <label>Loading Cost override<input type="number" step="0.01" min="0" name="loading_cost" value="<?= View::e($s['loading_cost'] !== null ? (string) $s['loading_cost'] : '') ?>" placeholder="e.g. 8000"></label>
                    <label>CHA Cost override<input type="number" step="0.01" min="0" name="cha_cost" value="<?= View::e($s['cha_cost'] !== null ? (string) $s['cha_cost'] : '') ?>" placeholder="e.g. 15000"></label>
                    <label>Notes<textarea name="notes" rows="2" placeholder="Optional notes"><?= View::e($s['notes'] ?? '') ?></textarea></label>
                    <label><input type="checkbox" name="is_primary" value="1" <?= $s['is_primary'] ? 'checked' : '' ?>> Used for pricing</label>
                    <button type="submit" class="btn-sm">Save</button>
                  </form>
                </div>
              </details>
              <form method="post" action="/products/suppliers/<?= (int) $s['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this supplier?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm btn-danger">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <?php if ($canManage): ?>
      <details>
        <summary class="btn-sm" style="display:inline-block;cursor:pointer">+ Add Supplier</summary>
        <div class="card-nested">
          <form method="post" action="/products/<?= (int) $product['id'] ?>/suppliers/add">
            <?= Csrf::field() ?>
            <label>Supplier Name *<input type="text" name="supplier_name" required placeholder="e.g. Sri Balaji Granites"></label>
            <label>Location<input type="text" name="location" placeholder="e.g. Krishnagiri, Tamil Nadu"></label>
            <label>Contact Person<input type="text" name="contact_person" placeholder="e.g., Sales Manager"></label>
            <label>Contact Phone<input type="text" name="contact_phone" placeholder="e.g. +91-0000000000"></label>
            <label>Contact Email<input type="text" name="contact_email" placeholder="e.g. name@company.com"></label>
            <label>FOB Source
              <select name="fob_source">
                <option value="direct">Direct</option>
                <option value="computed">Computed</option>
              </select>
            </label>
            <label>FOB Value (direct only)<input type="number" step="0.01" min="0" name="fob_value" placeholder="e.g. 4500.00"></label>
            <label>Factory Cost override<input type="number" step="0.01" min="0" name="factory_cost" placeholder="e.g. 350000"></label>
            <label>Transportation Cost override<input type="number" step="0.01" min="0" name="transportation_cost" placeholder="e.g. 9000"></label>
            <label>Packing Cost override<input type="number" step="0.01" min="0" name="packing_cost" placeholder="e.g. 12000"></label>
            <label>Loading Cost override<input type="number" step="0.01" min="0" name="loading_cost" placeholder="e.g. 8000"></label>
            <label>CHA Cost override<input type="number" step="0.01" min="0" name="cha_cost" placeholder="e.g. 15000"></label>
            <label>Notes<textarea name="notes" rows="2" placeholder="Optional notes"></textarea></label>
            <label><input type="checkbox" name="is_primary" value="1"> Used for pricing</label>
            <button type="submit" class="btn-sm btn-accent">Add Supplier</button>
          </form>
        </div>
      </details>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Misc Charges <span class="muted small">(reference only — never included in any FOB calculation)</span></h2>
    <table class="list">
      <tr><th>Label</th><th>Amount</th><th>Notes</th><th></th></tr>
      <?php if (empty($miscCharges)): ?>
        <tr><td colspan="4" class="muted">No misc charges on file.</td></tr>
      <?php endif; ?>
      <?php foreach ($miscCharges as $mc): ?>
        <tr>
          <td><?= View::e($mc['label']) ?></td>
          <td><?= number_format((float) $mc['amount'], 2) ?></td>
          <td><?= View::e($mc['notes'] ?? '') ?></td>
          <td>
            <?php if ($canManage): ?>
              <form method="post" action="/products/misc-charges/<?= (int) $mc['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this misc charge?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm btn-danger">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($canManage): ?>
      <form method="post" action="/products/<?= (int) $product['id'] ?>/misc-charges/add" style="display:flex;gap:8px;align-items:center">
        <?= Csrf::field() ?>
        <input type="text" name="label" placeholder="e.g. Bank charges" required aria-label="Label">
        <input type="number" step="0.01" name="amount" placeholder="Amount" required aria-label="Amount">
        <input type="text" name="notes" placeholder="Notes (optional)" aria-label="Notes">
        <button type="submit" class="btn-sm btn-accent">Add</button>
      </form>
    <?php endif; ?>
  </div>
  <?php else: ?>
    <p class="muted">You do not have permission to view supplier prices or misc charges for this product.</p>
  <?php endif; ?>
</div>
