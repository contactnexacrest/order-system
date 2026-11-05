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
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>">&larr; Back to <?= View::e($product['name']) ?></a></p>
  <h1>Specifications — <?= View::e($product['name']) ?></h1>
  <p class="muted">Starts from its category. Change only what is different for this product — everything else follows the category automatically, including later changes there.</p>
  <p><?= $__badge('category') ?> <?= $__badge('changed') ?> <?= $__badge('product') ?> <?= $__badge('hidden') ?></p>

  <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/settings" class="field-grid">
    <?= Csrf::field() ?>
    <label>Category *<select name="category_id" required<?= $__ro ?>><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $categoryId ? ' selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Priced per CBM from (mm)<input type="number" name="cbm_from_mm" min="1" max="2000" step="1" value="<?= $product['cbm_from_mm'] !== null ? (int) $product['cbm_from_mm'] : '' ?>" placeholder="global <?= (int) $cbmFrom ?>"<?= $__ro ?>></label>
    <fieldset class="full"><legend>Forms this product comes in (none ticked = all)</legend>
      <?php foreach (ProductSpecs::FORMS as $k => $l): ?><label class="inline"><input type="checkbox" name="forms[]" value="<?= $k ?>"<?= in_array($k, $__forms, true) ? ' checked' : '' ?><?= $__ro ?>> <?= View::e($l) ?></label> <?php endforeach; ?>
    </fieldset>
    <?php if ($canEdit): ?><div><button type="submit" class="btn-sm">Save</button></div><?php endif; ?>
  </form>

  <table class="list">
    <tr><th>Specification</th><th>Options for this product</th><th>Actions</th></tr>
    <?php foreach ($tree as $s): ?>
      <?php $__kind = $s['source'] === 'product' ? 'product' : ($s['hidden'] ? 'hidden' : ($s['changed'] ? 'changed' : 'category')); ?>
      <tr id="spec-<?= (int) $s['id'] ?>">
        <td><b><?= View::e($s['name']) ?></b> <?= $__badge($__kind) ?></td>
        <td><?php if ($s['hidden']): ?><span class="muted">Not offered for this product</span><?php else: foreach ($s['options'] as $o): ?>
          <?php $__ok = $o['hidden'] ? 'hidden' : ($o['source'] === 'product' ? 'product' : ($o['source'] === 'changed' ? 'changed' : 'category')); ?>
          <span class="badge<?= ['hidden' => ' badge-danger', 'product' => ' badge-active', 'changed' => ' badge-warning', 'category' => ''][$__ok] ?>"<?= $o['hidden'] ? ' style="text-decoration:line-through"' : '' ?>><?= View::e($o['name']) ?> <?= View::e(ProductSpecs::changeLabel($o['change_type'], $o['value'])) ?><?= $o['source'] === 'changed' && $o['category_value'] !== null ? ' (category ' . View::e(ProductSpecs::changeLabel($o['change_type'], $o['category_value'])) . ')' : '' ?></span>
        <?php endforeach; endif; ?></td>
        <td><?php if ($canEdit): ?>
          <?php if ($s['source'] === 'category'): ?>
            <?php if ($s['hidden']): ?>
              <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>/show" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm">Show again</button></form>
            <?php else: ?>
              <a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/specifications?edit=<?= (int) $s['id'] ?>#spec-<?= (int) $s['id'] ?>"><?= $s['changed'] ? 'Edit' : 'Change for this product' ?></a>
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
                  <td><form method="post" action="/products/<?= (int) $product['id'] ?>/spec-options/<?= (int) $o['id'] ?>/delete" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Remove</button></form></td></tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
          <form method="post" action="/products/<?= (int) $product['id'] ?>/specifications/<?= (int) $s['id'] ?>/options" class="field-grid">
            <?= Csrf::field() ?>
            <label>Add an option for this product only *<input type="text" name="name" required maxlength="80" placeholder="e.g. Antique"></label>
            <label>Change type *<select name="change_type" required><?php foreach (ProductSpecs::CHANGE_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= View::e($l) ?></option><?php endforeach; ?></select></label>
            <label>Value<input type="number" name="value" step="0.0001" placeholder="e.g. 6.00"></label>
            <label>Thickness (mm)<input type="number" name="thickness_mm" min="1" max="2000" step="1" placeholder="e.g. 25 (thickness options)"></label>
            <input type="hidden" name="sort_order" value="90">
            <label class="inline"><input type="checkbox" name="is_base" value="1"> base option</label>
            <div><button type="submit" class="btn-sm btn-accent">+ Add option</button></div>
          </form>
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
