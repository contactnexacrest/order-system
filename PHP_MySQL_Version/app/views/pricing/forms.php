<?php
/**
 * Owner decision D-382 (18 Nov 2026): Admin › Pricing › Forms of stone — the forms a product is sold
 * in, the units each form is sold in, the unit a new line starts on, its group (which category
 * specifications it uses: Blocks, Slabs, Tiles …) and its default finish. A form used by a
 * specification is switched off, never deleted. Every change needs a reason and is logged.
 * Node twin: views/pricing/forms.njk.
 *
 * In: $forms (each with 'used'), $edit (form id being edited), $canManage, $finishNames, $old.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ProductForms;
use App\Services\ProductSpecs;

// One add / edit form (null = add).
$__formFields = static function (?array $f) use ($finishNames, $old): void {
    $isNew = $f === null;
    $key = $isNew ? 'new' : (string) (int) $f['id'];
    $useOld = !empty($old) && (string) ($old['_form_key'] ?? '') === $key;
    $val = static fn (string $k, $default = '') => $useOld ? ($old[$k] ?? $default) : ($f[$k] ?? $default);
    $units = $useOld ? array_map('strval', (array) ($old['units'] ?? [])) : ($isNew ? [] : ProductForms::formUnits($f));
    $active = $useOld ? !empty($old['is_active']) : ($isNew || (int) $f['is_active'] === 1);
    ?>
    <input type="hidden" name="_form_key" value="<?= View::e($key) ?>">
    <label>Name *<input type="text" name="name" required maxlength="80" value="<?= View::e((string) $val('name')) ?>" placeholder="e.g. Raw block"></label>
    <fieldset class="full"><legend>Sold in (units) *</legend>
      <?php foreach (ProductForms::UNITS as $__i => $__u): ?><label class="inline"><input type="checkbox" name="units[]" value="<?= $__u ?>"<?= in_array($__u, $units, true) ? ' checked' : '' ?><?= $__i === 0 ? ' data-min-ticked="' . View::e(implode(',', array_map(static fn ($x) => 'units-' . $key . '-' . $x, ProductForms::UNITS))) . ':1" data-min-ticked-message="Tick at least one unit the form is sold in."' : '' ?> id="units-<?= View::e($key) ?>-<?= $__u ?>"> <?= View::e(ProductForms::unitLabel($__u)) ?></label> <?php endforeach; ?>
    </fieldset>
    <label>Starts on *<select name="default_unit" required>
      <option value="">Choose…</option>
      <?php foreach (ProductForms::UNITS as $__u): ?><option value="<?= $__u ?>"<?= (string) $val('default_unit') === $__u ? ' selected' : '' ?>><?= View::e(ProductForms::unitLabel($__u)) ?></option><?php endforeach; ?>
    </select></label>
    <label>Group * <small class="muted">(which specifications it uses)</small><select name="spec_group" required>
      <option value="">Choose…</option>
      <?php foreach (ProductSpecs::FORMS as $__g => $__label): ?><option value="<?= $__g ?>"<?= (string) $val('spec_group') === $__g ? ' selected' : '' ?>><?= View::e($__label) ?></option><?php endforeach; ?>
    </select></label>
    <label>Default finish<select name="default_finish">
      <option value="">Choose…</option>
      <?php foreach ($finishNames as $__fn): ?><option value="<?= View::e($__fn) ?>"<?= (string) $val('default_finish') === $__fn ? ' selected' : '' ?>><?= View::e($__fn) ?></option><?php endforeach; ?>
    </select></label>
    <label>Order *<input type="number" name="sort_order" required min="0" max="999" step="1" value="<?= View::e((string) $val('sort_order', '')) ?>" placeholder="e.g. 4"></label>
    <label class="inline"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> Active</label>
    <label class="full">Reason *<input type="text" name="reason" required minlength="10" maxlength="500" value="<?= $useOld ? View::e((string) ($old['reason'] ?? '')) : '' ?>" placeholder="e.g. New form for cut blocks sold per tonne"></label>
    <?php
};
?>
<div class="card page-wide">
  <p class="muted small"><a href="/pricing/specifications">&larr; Pricing › Specifications</a></p>
  <h1>Forms of stone</h1>
  <p class="muted">The forms a product is sold in. Each form says which units an order line may use, the unit a new line starts on, which category specifications it uses (its group — e.g. Blocks use "Size / format", Slabs also "Thickness") and the finish a line starts with. The specifications in <a href="/products/hs-specifications">Products › Specifications (HS)</a> each belong to one form. A form a specification uses can only be switched off.</p>
  <table class="list" data-testid="forms-table">
    <tr><th>Order</th><th>Form</th><th>Sold in</th><th>Starts on</th><th>Group</th><th>Default finish</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
    <?php foreach ($forms as $f): ?>
      <tr id="form-<?= (int) $f['id'] ?>">
        <td><?= (int) $f['sort_order'] ?></td>
        <td><b><?= View::e((string) $f['name']) ?></b></td>
        <td><?= View::e(ProductForms::unitsText($f)) ?></td>
        <td><?= View::e(ProductForms::unitLabel((string) $f['default_unit'])) ?></td>
        <td><?= View::e(ProductSpecs::FORMS[(string) $f['spec_group']] ?? (string) $f['spec_group']) ?></td>
        <td><?= View::e((string) ($f['default_finish'] ?? '—')) ?></td>
        <td><?= (int) $f['is_active'] === 1 ? '<span class="badge badge-active">Active</span>' : '<span class="badge">Switched off</span>' ?></td>
        <?php if ($canManage): ?>
          <td>
            <a class="btn-sm" href="/pricing/forms?edit=<?= (int) $f['id'] ?>#form-<?= (int) $f['id'] ?>">Edit</a>
            <?php if (!$f['used']): ?>
              <form method="post" action="/pricing/forms/<?= (int) $f['id'] ?>/delete" class="inline-form" data-confirm="Delete the form &quot;<?= View::e((string) $f['name']) ?>&quot;?">
                <?= Csrf::field() ?>
                <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Reason (required)" aria-label="Reason for deleting">
                <button type="submit" class="btn-sm btn-secondary">Delete</button>
              </form>
            <?php else: ?><small class="muted">used — can only be switched off</small><?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>
      <?php if ($canManage && $edit === (int) $f['id']): ?>
        <tr><td colspan="8">
          <h3>Edit: <?= View::e((string) $f['name']) ?></h3>
          <form method="post" action="/pricing/forms/<?= (int) $f['id'] ?>" class="field-grid">
            <?= Csrf::field() ?>
            <?php $__formFields($f); ?>
            <div><button type="submit" class="btn-sm">Save form</button> <a class="btn-sm btn-secondary" href="/pricing/forms#form-<?= (int) $f['id'] ?>">Cancel</a></div>
          </form>
        </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($forms === []): ?><tr><td colspan="8" class="muted">No forms yet.</td></tr><?php endif; ?>
  </table>
  <?php if ($canManage): ?>
    <h2 id="add-form">Add a form</h2>
    <form method="post" action="/pricing/forms" class="field-grid">
      <?= Csrf::field() ?>
      <?php $__formFields(null); ?>
      <div><button type="submit" class="btn-sm btn-accent">+ Add form</button></div>
    </form>
  <?php else: ?>
    <p class="muted small">Changing the forms needs the "Manage pricing" permission.</p>
  <?php endif; ?>
</div>
