<?php
/**
 * Owner decision D-255 (5 Nov 2026): Admin → Pricing → Specifications — a
 * category's specifications and their options (what changes the price). Every
 * option changes the cost; margin, team incentive, associate commission and buffer
 * are added on top, so every option keeps the margin. Node twin: specifications.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ProductSpecs;
$__ro = $canManage ? '' : ' disabled';
?>
<div class="card page-wide">
  <p class="muted small"><a href="/pricing/settings">&larr; Pricing settings</a></p>
  <h1>Specifications</h1>
  <p class="muted">What changes the price, by category. Every option is applied to the <b>cost</b>; the margin, team incentive, associate commission and buffer are then added as usual — so every option keeps your margin. The first (base) option of each changes nothing. % options multiply the cost, USD amounts are added, the quantity discount comes last. A product can hide or change any option, or add its own (Products → a product → Specifications).</p>
  <form method="get" action="/pricing/specifications" class="field-grid">
    <label>Category
      <select name="category" onchange="this.form.submit()">
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $categoryId ? ' selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?>
      </select></label>
  </form>
  <table class="list">
    <tr><th>Order</th><th>Specification</th><th>Applies to forms</th><th>Options (first = base)</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($specs as $s): ?>
      <tr id="spec-<?= (int) $s['id'] ?>">
        <td><?= (int) $s['sort_order'] ?></td>
        <td><b><?= View::e($s['name']) ?></b><?= $s['kind'] !== 'normal' ? '<br><small class="muted">' . View::e(ProductSpecs::KINDS[$s['kind']]) . '</small>' : '' ?></td>
        <td><?= View::e(ProductSpecs::formsLabel($s['applies_to'])) ?></td>
        <td><?php $__parts = []; foreach ($s['options'] as $o) { if ((int) $o['is_active'] === 1) { $__parts[] = View::e($o['name']) . ' <small class="muted">' . View::e(ProductSpecs::changeLabel((string) $o['change_type'], $o['value'] !== null ? (float) $o['value'] : null)) . '</small>'; } } echo implode(' · ', $__parts); ?></td>
        <td><?= (int) $s['is_active'] === 1 ? '<span class="badge badge-active">Active</span>' : '<span class="badge">Inactive</span>' ?></td>
        <td>
          <a class="btn-sm" href="/pricing/specifications?category=<?= (int) $categoryId ?>&edit=<?= (int) $s['id'] ?>#spec-<?= (int) $s['id'] ?>">Edit</a>
          <?php if ($canManage): ?>
            <form method="post" action="/pricing/specifications/<?= (int) $s['id'] ?>/active" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary"><?= (int) $s['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button></form>
            <?php if (!$s['used']): ?><form method="post" action="/pricing/specifications/<?= (int) $s['id'] ?>/delete" style="display:inline" data-confirm="Delete this specification and its options?"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Delete</button></form><?php else: ?><small class="muted">used — can only be deactivated</small><?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($edit === (int) $s['id']): ?>
        <tr><td colspan="6">
          <h3>Edit: <?= View::e($s['name']) ?></h3>
          <form method="post" action="/pricing/specifications/<?= (int) $s['id'] ?>" class="field-grid">
            <?= Csrf::field() ?>
            <label>Name *<input type="text" name="name" required maxlength="80" value="<?= View::e($s['name']) ?>" placeholder="e.g. Surface finish"<?= $__ro ?>></label>
            <label>Kind *<select name="kind" required<?= $__ro ?>><?php foreach (ProductSpecs::KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $s['kind'] === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
            <label>Order *<input type="number" name="sort_order" required min="0" max="999" step="1" value="<?= (int) $s['sort_order'] ?>" placeholder="e.g. 3"<?= $__ro ?>></label>
            <fieldset class="full"><legend>Applies to forms (none ticked = all)</legend>
              <?php $__forms = ProductSpecs::splitForms($s['applies_to']); foreach (ProductSpecs::FORMS as $k => $l): ?><label class="inline"><input type="checkbox" name="applies_to[]" value="<?= $k ?>"<?= in_array($k, $__forms, true) ? ' checked' : '' ?><?= $__ro ?>> <?= View::e($l) ?></label> <?php endforeach; ?>
            </fieldset>
            <?php if ($canManage): ?><div><button type="submit" class="btn-sm">Save specification</button></div><?php endif; ?>
          </form>
          <table class="list">
            <tr><th>Option</th><th>Change type</th><th class="num">Value</th><th>Thickness (mm)</th><th>Base?</th><th>Status</th><th></th></tr>
            <?php foreach ($s['options'] as $o): ?>
              <tr>
                <td colspan="5">
                  <form method="post" action="/pricing/spec-options/<?= (int) $o['id'] ?>" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="text" name="name" required maxlength="80" value="<?= View::e($o['name']) ?>" aria-label="Option name" placeholder="e.g. Leather"<?= $__ro ?>>
                    <select name="change_type" aria-label="Change type"<?= $__ro ?>><?php foreach (ProductSpecs::CHANGE_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $o['change_type'] === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
                    <input type="number" name="value" step="0.0001" value="<?= $o['value'] !== null ? View::e(rtrim(rtrim((string) $o['value'], '0'), '.')) : '' ?>" aria-label="Value" placeholder="e.g. 4.00 or -8"<?= $__ro ?>>
                    <input type="number" name="thickness_mm" min="1" max="2000" step="1" value="<?= $o['thickness_mm'] !== null ? (int) $o['thickness_mm'] : '' ?>" aria-label="Thickness in mm" placeholder="e.g. 30"<?= $__ro ?>>
                    <label class="inline"><input type="checkbox" name="is_base" value="1"<?= (int) $o['is_base'] === 1 ? ' checked' : '' ?><?= $__ro ?>> base</label>
                    <input type="hidden" name="sort_order" value="<?= (int) $o['sort_order'] ?>">
                    <?php if ($canManage): ?><button type="submit" class="btn-sm">Save</button><?php endif; ?>
                  </form>
                </td>
                <td><?= (int) $o['is_active'] === 1 ? '<span class="badge badge-active">Active</span>' : '<span class="badge">Inactive</span>' ?></td>
                <td><?php if ($canManage): ?>
                  <form method="post" action="/pricing/spec-options/<?= (int) $o['id'] ?>/active" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary"><?= (int) $o['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button></form>
                  <?php if (!$o['used']): ?><form method="post" action="/pricing/spec-options/<?= (int) $o['id'] ?>/delete" style="display:inline" data-confirm="Remove this option?"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Remove</button></form><?php endif; ?>
                <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php if ($canManage): ?>
            <form method="post" action="/pricing/specifications/<?= (int) $s['id'] ?>/options" class="field-grid">
              <?= Csrf::field() ?>
              <label>New option *<input type="text" name="name" required maxlength="80" placeholder="e.g. Antique"></label>
              <label>Change type *<select name="change_type" required><?php foreach (ProductSpecs::CHANGE_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= View::e($l) ?></option><?php endforeach; ?></select></label>
              <label>Value<input type="number" name="value" step="0.0001" placeholder="e.g. 6.00 or -8"></label>
              <label>Thickness (mm)<input type="number" name="thickness_mm" min="1" max="2000" step="1" placeholder="e.g. 60 (thickness options)"></label>
              <label>Order<input type="number" name="sort_order" min="0" max="999" step="1" value="<?= count($s['options']) + 1 ?>" placeholder="e.g. 7"></label>
              <label class="inline"><input type="checkbox" name="is_base" value="1"> This is the base option</label>
              <div><button type="submit" class="btn-sm btn-accent">+ Add option</button></div>
            </form>
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($specs === []): ?><tr><td colspan="6" class="muted">No specifications for this category yet.</td></tr><?php endif; ?>
  </table>
  <p class="small muted">Not here on purpose: the destination port, CFR or CIF (freight is a separate charge; prices stay FOB) and the colour (each stone is its own product). Changes apply to every product in the category that has not changed that option itself; an option already used on an order or a price request can only be deactivated. Every change is logged; prices already issued do not change.</p>

  <?php if ($canManage): ?>
    <h2>Add a specification</h2>
    <form method="post" action="/pricing/specifications" class="field-grid">
      <?= Csrf::field() ?>
      <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
      <label>Name *<input type="text" name="name" required maxlength="80" placeholder="e.g. Water-jet inlay"></label>
      <label>Kind *<select name="kind" required><?php foreach (ProductSpecs::KINDS as $k => $l): ?><option value="<?= $k ?>"><?= View::e($l) ?></option><?php endforeach; ?></select></label>
      <label>Order *<input type="number" name="sort_order" required min="0" max="999" step="1" value="<?= count($specs) + 1 ?>" placeholder="e.g. 10"></label>
      <fieldset class="full"><legend>Applies to forms (none ticked = all)</legend>
        <?php foreach (ProductSpecs::FORMS as $k => $l): ?><label class="inline"><input type="checkbox" name="applies_to[]" value="<?= $k ?>"> <?= View::e($l) ?></label> <?php endforeach; ?>
      </fieldset>
      <div><button type="submit" class="btn-sm btn-accent">+ Add specification</button></div>
    </form>
  <?php endif; ?>
</div>
