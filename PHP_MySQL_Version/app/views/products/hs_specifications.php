<?php
/**
 * Owner decision D-383 (18 Nov 2026): Products › Specifications (HS) — what a product is sold as and
 * the HS code that goes with it. A product ticks the ones it is sold as (its Specifications page ›
 * Sold as); an order line picks one, which sets the line's HS code. The HS code is an active code of
 * the HS Code Master. A specification ticked on a product or used on an order line can only be
 * switched off. Every change needs a reason and is logged. Node twin: views/products/hs_specifications.njk.
 *
 * In: $specs (each with the form's name and 'used'), $forms, $hsCodes (active), $finishNames, $edit,
 *     $canManage, $old.
 */
use App\Helpers\Csrf;
use App\Helpers\View;

$__specFields = static function (?array $s) use ($forms, $finishNames, $old): void {
    $isNew = $s === null;
    $key = $isNew ? 'new' : (string) (int) $s['id'];
    $useOld = !empty($old) && (string) ($old['_spec_key'] ?? '') === $key;
    $val = static fn (string $k, $default = '') => $useOld ? ($old[$k] ?? $default) : ($s[$k] ?? $default);
    $active = $useOld ? !empty($old['is_active']) : ($isNew || (int) $s['is_active'] === 1);
    ?>
    <input type="hidden" name="_spec_key" value="<?= View::e($key) ?>">
    <label>Name *<input type="text" name="name" required maxlength="120" value="<?= View::e((string) $val('name')) ?>" placeholder="e.g. Gangsaw slab"></label>
    <label>Form *<select name="form_id" required>
      <option value="">Choose…</option>
      <?php foreach ($forms as $__f): if ((int) $__f['is_active'] !== 1 && (int) $val('form_id', 0) !== (int) $__f['id']) { continue; } ?>
        <option value="<?= (int) $__f['id'] ?>"<?= (int) $val('form_id', 0) === (int) $__f['id'] ? ' selected' : '' ?>><?= View::e((string) $__f['name']) ?><?= (int) $__f['is_active'] !== 1 ? ' (switched off)' : '' ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>Usual finish<select name="usual_finish">
      <option value="">Choose…</option>
      <?php foreach ($finishNames as $__fn): ?><option value="<?= View::e($__fn) ?>"<?= (string) $val('usual_finish') === $__fn ? ' selected' : '' ?>><?= View::e($__fn) ?></option><?php endforeach; ?>
    </select></label>
    <label>HS code * <small class="muted">(from the HS Code Master, active only)</small><input type="text" name="hs_code" required maxlength="8" pattern="[0-9]{6}([0-9]{2})?" list="hs-spec-codes" data-in-list="hs-spec-codes" data-in-list-message="Pick an active code from the HS Code Master List." autocomplete="off" value="<?= View::e((string) $val('hs_code')) ?>" placeholder="e.g. 68022310"></label>
    <label class="full">When to use<input type="text" name="when_to_use" maxlength="255" value="<?= View::e((string) $val('when_to_use')) ?>" placeholder="e.g. As it comes from the quarry"></label>
    <label>Order *<input type="number" name="sort_order" required min="0" max="999" step="1" value="<?= View::e((string) $val('sort_order', '')) ?>" placeholder="e.g. 3"></label>
    <label class="inline"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> Active</label>
    <label class="full">Reason *<input type="text" name="reason" required minlength="10" maxlength="500" value="<?= $useOld ? View::e((string) ($old['reason'] ?? '')) : '' ?>" placeholder="e.g. Customs asked for 68022390 on random slabs"></label>
    <?php
};
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products">&larr; Products</a></p>
  <h1>Specifications (HS)</h1>
  <p class="muted">What a product is sold as, and the HS code that goes with it. Tick the ones a product is sold as on its Specifications page (Sold as); an order line then picks one, and its HS code comes from it — only the MD or the ED can change a line's HS code, with a reason. Each specification belongs to one form (<a href="/pricing/forms">Forms of stone</a>), which sets the units and finishes the line offers. Changing a specification here never changes order lines already saved.</p>
  <table class="list" data-testid="hs-specifications-table">
    <tr><th>Order</th><th>Specification</th><th>Form</th><th>Usual finish</th><th>HS code</th><th>When to use</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
    <?php foreach ($specs as $s): ?>
      <tr id="spec-<?= (int) $s['id'] ?>">
        <td><?= (int) $s['sort_order'] ?></td>
        <td><b><?= View::e((string) $s['name']) ?></b></td>
        <td><?= View::e((string) $s['form_name']) ?><?= (int) $s['form_is_active'] !== 1 ? ' <small class="muted">(form switched off)</small>' : '' ?></td>
        <td><?= View::e((string) ($s['usual_finish'] ?? '—')) ?></td>
        <td><span class="tag"><?= View::e((string) $s['hs_code']) ?></span></td>
        <td class="small"><?= View::e((string) ($s['when_to_use'] ?? '')) ?></td>
        <td><?= (int) $s['is_active'] === 1 ? '<span class="badge badge-active">Active</span>' : '<span class="badge">Switched off</span>' ?></td>
        <?php if ($canManage): ?>
          <td>
            <a class="btn-sm" href="/products/hs-specifications?edit=<?= (int) $s['id'] ?>#spec-<?= (int) $s['id'] ?>">Edit</a>
            <?php if (!$s['used']): ?>
              <form method="post" action="/products/hs-specifications/<?= (int) $s['id'] ?>/delete" class="inline-form" data-confirm="Delete the specification &quot;<?= View::e((string) $s['name']) ?>&quot;?">
                <?= Csrf::field() ?>
                <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Reason (required)" aria-label="Reason for deleting">
                <button type="submit" class="btn-sm btn-secondary">Delete</button>
              </form>
            <?php else: ?><small class="muted">used — can only be switched off</small><?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>
      <?php if ($canManage && $edit === (int) $s['id']): ?>
        <tr><td colspan="8">
          <h3>Edit: <?= View::e((string) $s['name']) ?></h3>
          <form method="post" action="/products/hs-specifications/<?= (int) $s['id'] ?>" class="field-grid">
            <?= Csrf::field() ?>
            <?php $__specFields($s); ?>
            <div><button type="submit" class="btn-sm">Save specification</button> <a class="btn-sm btn-secondary" href="/products/hs-specifications#spec-<?= (int) $s['id'] ?>">Cancel</a></div>
          </form>
        </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($specs === []): ?><tr><td colspan="8" class="muted">No specifications yet.</td></tr><?php endif; ?>
  </table>
  <?php if ($canManage): ?>
    <h2 id="add-spec">Add a specification</h2>
    <form method="post" action="/products/hs-specifications" class="field-grid">
      <?= Csrf::field() ?>
      <?php $__specFields(null); ?>
      <div><button type="submit" class="btn-sm btn-accent">+ Add specification</button></div>
    </form>
    <datalist id="hs-spec-codes"><?php foreach ($hsCodes as $__h): ?><option value="<?= View::e((string) $__h['code']) ?>"><?= View::e((string) $__h['description']) ?></option><?php endforeach; ?></datalist>
  <?php else: ?>
    <p class="muted small">Changing the specifications needs the "Manage pricing" permission.</p>
  <?php endif; ?>
</div>
