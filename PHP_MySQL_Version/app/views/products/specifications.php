<?php
/**
 * Owner decision D-255 (5 Nov 2026): one product's specifications. It starts from
 * its category; change only what is different for this product — hide a
 * specification or an option, change an option's value (empty = follow the
 * category), add options or specifications for this product only, or reset.
 * Changing needs "Manage a product's specifications". Node twin: specifications.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ProductSpecs;
$__ro = $canEdit ? '' : ' disabled';
$__badge = static function (string $kind): string {
    return ['category' => '<span class="badge">From category</span>', 'changed' => '<span class="badge badge-warning">Changed for this product</span>',
        'product' => '<span class="badge badge-active">This product only</span>', 'hidden' => '<span class="badge badge-danger">Hidden for this product</span>'][$kind] ?? '';
};
$__forms = ProductSpecs::productForms($product);
$__catName = '';
foreach ($categories as $__c) {
    if ((int) $__c['id'] === (int) $categoryId) {
        $__catName = (string) $__c['name'];
    }
}
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>">&larr; Back to <?= View::e($product['name']) ?></a></p>
  <h1>Specifications — <?= View::e($product['name']) ?></h1>
  <p class="help-line">Example: the category says Leather +USD 4.00/SQM; type 6.00 here and this product uses USD 6.00 while every other product keeps 4.00. Type 0 to make it "Included in base price"; untick to stop offering it for this product.</p>
  <p class="muted">Starts from its category. Change only what is different for this product — everything else follows the category automatically, including later changes there.</p>
  <p><?= $__badge('category') ?> <?= $__badge('changed') ?> <?= $__badge('product') ?> <?= $__badge('hidden') ?></p>

  <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/settings" class="field-grid">
    <?= Csrf::field() ?>
    <label>Category *<select name="category_id" required<?= $__ro ?>><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $categoryId ? ' selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Priced per CBM from (mm)<input type="number" name="cbm_from_mm" min="1" max="2000" step="1" value="<?= $product['cbm_from_mm'] !== null ? (int) $product['cbm_from_mm'] : '' ?>" placeholder="global <?= (int) $cbmFrom ?>"<?= $__ro ?>></label>
    <?php // D-270 (point 25): this product's own weight per CBM, for order lines priced per Ton. ?>
    <label>Weight per CBM (tonnes) <small class="muted">(empty = the category's)</small><input type="number" name="weight_t_per_cbm" min="0.1" max="20" step="0.001" value="<?= ($product['weight_t_per_cbm'] ?? null) !== null ? View::e(rtrim(rtrim((string) $product['weight_t_per_cbm'], '0'), '.')) : '' ?>" placeholder="e.g. 3.0"<?= $__ro ?>></label>
    <?php if ($canEdit): ?><div><button type="submit" class="btn-sm">Save</button></div><?php endif; ?>
  </form>

  <?php // D-384 (18 Nov 2026): "Sold as" — the specifications (each with its HS code) this product is sold as; it replaces the old fixed "Forms" ticks. ?>
  <section class="section" id="sold-as">
    <h2>Sold as</h2>
    <p class="muted small">What this product is sold as. An order line of this product offers only these (its HS code comes from the one chosen); the category specifications that apply follow their forms (<?= $soldAsTicked === [] ? 'nothing ticked yet' : View::e(\App\Controllers\ProductFormController::groupsText(array_values(array_filter($soldAsSpecs, static fn (array $s): bool => in_array((int) $s['id'], $soldAsTicked, true))))) ?>). Specifications and their HS codes: <a href="/products/hs-specifications">Products › Specifications (HS)</a>.</p>
    <?php if ($soldAsTicked === []): ?>
      <p class="notice notice-info small" data-testid="sold-as-none">Nothing ticked yet — order lines offer every specification<?= $__forms !== [] ? ', and the specifications follow the forms ticked before (' . View::e(ProductSpecs::formsLabel($product['forms'] ?? null)) . ')' : '' ?>.</p>
    <?php endif; ?>
    <form method="post" action="/products/<?= (int) $product['id'] ?>/sold-as" class="field-grid">
      <?= Csrf::field() ?>
      <fieldset class="full"><legend>Sold as *</legend>
        <?php $__ids = array_map(static fn (array $s): string => 'sold-as-' . (int) $s['id'], $soldAsSpecs); ?>
        <?php foreach ($soldAsSpecs as $__i => $__s): ?>
          <label class="inline"><input type="checkbox" name="sold_as[<?= (int) $__s['id'] ?>]" value="1" id="sold-as-<?= (int) $__s['id'] ?>"<?= in_array((int) $__s['id'], $soldAsTicked, true) ? ' checked' : '' ?><?= $__ro ?><?= $__i === 0 ? ' data-min-ticked="' . View::e(implode(',', $__ids)) . ':1" data-min-ticked-message="Tick at least one specification the product is sold as."' : '' ?>> <?= View::e((string) $__s['name']) ?> <span class="tag">HS <?= View::e((string) $__s['hs_code']) ?></span><?= (int) $__s['is_active'] !== 1 || (int) $__s['form_is_active'] !== 1 ? ' <small class="muted">(switched off)</small>' : '' ?></label>
        <?php endforeach; ?>
        <?php if ($soldAsSpecs === []): ?><span class="muted">No active specifications — add them in Products › Specifications (HS).</span><?php endif; ?>
      </fieldset>
      <?php if ($canEdit): ?>
        <label class="full">Reason *<input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. This granite is also sold as raw blocks"></label>
        <div><button type="submit" class="btn-sm">Save Sold as</button></div>
      <?php endif; ?>
    </form>
  </section>

  <table class="list">
    <tr><th>Specification</th><th>Options for this product</th><th>Actions</th></tr>
    <?php foreach ($tree as $s): ?>
      <?php $__kind = $s['source'] === 'product' ? 'product' : ($s['hidden'] ? 'hidden' : ($s['changed'] ? 'changed' : 'category')); ?>
      <tr id="spec-<?= (int) $s['id'] ?>">
        <td><b><?= View::e($s['name']) ?></b> <?= $__badge($__kind) ?>
          <?php // D-306 (point 20): where the category's options are changed for every product. ?>
          <?php if ($s['source'] === 'category' && $__catName !== ''): ?><br><small class="muted">From category <?= View::e($__catName) ?> — <a href="/products/categories/<?= (int) $categoryId ?>?tab=options#spec-<?= (int) $s['id'] ?>">edit for all products in <?= View::e($__catName) ?></a></small><?php endif; ?></td>
        <td><?php if ($s['hidden']): ?><span class="muted">Not offered for this product</span><?php else: foreach ($s['options'] as $o): ?>
          <?php $__ok = $o['hidden'] ? 'hidden' : ($o['source'] === 'product' ? 'product' : ($o['source'] === 'changed' ? 'changed' : 'category')); ?>
          <span class="badge<?= ['hidden' => ' badge-danger', 'product' => ' badge-active', 'changed' => ' badge-warning', 'category' => ''][$__ok] ?>"<?= $o['hidden'] ? ' style="text-decoration:line-through"' : '' ?>><?= View::e($o['name']) ?> <?= View::e(ProductSpecs::changeLabel($o['change_type'], $o['value'])) ?><?= $o['source'] === 'changed' && $o['category_value'] !== null ? ' (category ' . View::e(ProductSpecs::changeLabel($o['change_type'], $o['category_value'])) . ')' : '' ?></span>
        <?php endforeach; endif; ?></td>
        <td><?php if ($canEdit): ?>
          <?php if ($s['source'] === 'category'): ?>
            <?php if ($s['hidden']): ?>
              <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>/show" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm">Show again</button></form>
            <?php else: ?>
              <a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/specifications?edit=<?= (int) $s['id'] ?>#spec-<?= (int) $s['id'] ?>"><?= $s['changed'] ? 'Edit for this product only' : 'Change for this product only' ?></a>
              <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>/hide" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Not offered</button></form>
            <?php endif; ?>
            <?php if ($s['changed']): ?><form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>/reset" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Reset to category</button></form><?php endif; ?>
          <?php else: ?>
            <a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/specifications?edit=<?= (int) $s['id'] ?>#spec-<?= (int) $s['id'] ?>">Edit</a>
            <form method="post" action="/products/<?= (int) $product['id'] ?>/own-specs/<?= (int) $s['id'] ?>/delete" style="display:inline" data-confirm="Remove this specification from this product?"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Remove</button></form>
          <?php endif; ?>
        <?php endif; ?></td>
      </tr>
      <?php if ($canEdit && $edit === (int) $s['id']): ?>
        <tr><td colspan="3">
          <?php if ($s['source'] === 'category'): ?>
            <h3>Edit: <?= View::e($s['name']) ?> — <?= View::e($product['name']) ?> only</h3>
            <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>">
              <?= Csrf::field() ?>
              <table class="list">
                <tr><th>Option</th><th>Category value</th><th>For this product <small class="muted">(empty = same as category)</small></th><th>Offered</th></tr>
                <?php foreach ($s['options'] as $o): if ($o['source'] === 'product') { continue; } ?>
                  <tr>
                    <td><?= View::e($o['name']) ?></td>
                    <td><?= View::e(ProductSpecs::changeLabel($o['change_type'], $o['category_value'])) ?></td>
                    <td><?php if (in_array($o['change_type'], ['none', 'cbm'], true)): ?>—<?php else: ?><input type="number" name="value[<?= (int) $o['id'] ?>]" step="0.0001" value="<?= $o['source'] === 'changed' ? View::e((string) $o['value']) : '' ?>" aria-label="<?= View::e($o['name']) ?> for this product" placeholder="same as category"><?php endif; ?></td>
                    <td><label class="inline"><input type="checkbox" name="offered[<?= (int) $o['id'] ?>]" value="1"<?= $o['hidden'] ? '' : ' checked' ?>> offered</label></td>
                  </tr>
                <?php endforeach; ?>
              </table>
              <button type="submit" class="btn-sm">Save for this product</button>
            </form>
          <?php else: ?>
            <h3>Edit: <?= View::e($s['name']) ?> (this product only)</h3>
            <form method="post" action="/products/<?= (int) $product['id'] ?>/own-specs/<?= (int) $s['id'] ?>" class="field-grid">
              <?= Csrf::field() ?>
              <label>Name *<input type="text" name="name" required maxlength="80" value="<?= View::e($s['name']) ?>" placeholder="e.g. Book-matching"></label>
              <input type="hidden" name="kind" value="<?= View::e($s['kind']) ?>"><input type="hidden" name="sort_order" value="50">
              <div><button type="submit" class="btn-sm">Save</button></div>
            </form>
          <?php endif; ?>
          <?php $__own = array_values(array_filter($s['options'], static fn (array $o): bool => $o['source'] === 'product')); ?>
          <?php if ($__own !== []): ?>
            <table class="list"><tr><th>This product's own options</th><th></th></tr>
              <?php foreach ($__own as $o): ?>
                <tr><td><?= View::e($o['name']) ?> <?= View::e(ProductSpecs::changeLabel($o['change_type'], $o['value'])) ?></td>
                  <td><?php // D-389: this product's own option's order (the other fields are posted unchanged). ?>
                    <form method="post" action="/products/<?= (int) $product['id'] ?>/spec-options/<?= (int) $o['id'] ?>" class="inline-form">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="name" value="<?= View::e($o['name']) ?>"><input type="hidden" name="change_type" value="<?= View::e($o['change_type']) ?>">
                      <input type="hidden" name="value" value="<?= $o['value'] !== null ? View::e((string) $o['value']) : '' ?>"><input type="hidden" name="thickness_mm" value="<?= $o['thickness_mm'] !== null ? (int) $o['thickness_mm'] : '' ?>">
                      <?php if ($o['is_base']): ?><input type="hidden" name="is_base" value="1"><?php endif; ?>
                      <input type="number" name="sort_order" min="0" max="999" step="1" value="<?= (int) ($o['sort_order'] ?? 90) ?>" aria-label="Order of <?= View::e($o['name']) ?>" placeholder="e.g. 90" class="input-order">
                      <button type="submit" class="btn-sm">Save order</button>
                    </form>
                    <form method="post" action="/products/<?= (int) $product['id'] ?>/spec-options/<?= (int) $o['id'] ?>/delete" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Remove</button></form></td></tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
          <?php if (($s['list_key'] ?? null) === \App\Services\SurfaceFinishList::LIST_KEY): // D-357 ?>
          <p class="small muted">The finishes come from <a href="/admin/dropdown-options#list-surface_finish">Settings › Lists › surface_finish</a> — add or rename a finish there; change its price for this product above.</p>
          <?php else: ?>
          <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>/options" class="field-grid">
            <?= Csrf::field() ?>
            <label>Add an option for this product only *<input type="text" name="name" required maxlength="80" placeholder="e.g. Antique"></label>
            <label>Change type *<select name="change_type" required><option value="">Choose…</option><?php foreach (ProductSpecs::CHANGE_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= View::e($l) ?></option><?php endforeach; ?></select></label>
            <label>Value<input type="number" name="value" step="0.0001" placeholder="e.g. 6.00"></label>
            <label>Thickness (mm)<input type="number" name="thickness_mm" min="1" max="2000" step="1" placeholder="e.g. 25 (thickness options)"></label>
            <label>Order<input type="number" name="sort_order" min="0" max="999" step="1" value="90" placeholder="e.g. 90"></label>
            <label class="inline"><input type="checkbox" name="is_base" value="1"> base option</label>
            <div><button type="submit" class="btn-sm btn-accent">+ Add option</button></div>
          </form>
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
  <?php if ($canEdit): ?>
    <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/add" class="field-grid">
      <?= Csrf::field() ?>
      <label>Add a specification for this product only *<input type="text" name="name" required maxlength="80" placeholder="e.g. Book-matching"></label>
      <input type="hidden" name="kind" value="normal"><input type="hidden" name="sort_order" value="50">
      <div><button type="submit" class="btn-sm btn-accent">+ Add specification</button></div>
    </form>
    <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/reset" data-confirm="Reset every specification of this product to its category?">
      <?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Reset whole product to category</button>
    </form>
    <p class="small muted">Untick to hide a category option for this product · type a value to change it for this product only (empty = follow the category) · add options that exist only here. If the category value changes later, this product follows it — except where you typed your own value.</p>
  <?php else: ?>
    <p class="muted small">Changing a product's specifications needs the "Manage a product's specifications" permission.</p>
  <?php endif; ?>
</div>
