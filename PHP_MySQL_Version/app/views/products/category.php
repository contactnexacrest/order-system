<?php
/**
 * Owner decision D-306 (point 20, 11 Nov 2026): Products › Categories › <category> is one
 * page with tabs — General (name, units, HS code, packing, documents) | Options and their
 * price effect (was Admin › Pricing › Specifications) | Price rules (D-300) | Products in
 * this category. Node twin: views/products/category.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;

$__c = $category;
$__id = (int) $__c['id'];
$__tabs = ['general' => 'General (name, units, HS code, packing, documents)'];
if ($seesPricing) {
    $__tabs['options'] = 'Options and their price effect';
    $__tabs['rules'] = 'Price rules (thickness %, container)';
}
$__tabs['products'] = 'Products in this category (' . count($products) . ')';
$__return = "/products/categories/{$__id}?tab=general";
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products/categories">&larr; Categories</a></p>
  <h1>Category: <?= View::e((string) $__c['name']) ?><?= (int) $__c['is_system'] === 1 ? ' <small class="muted">(today\'s products)</small>' : '' ?></h1>
  <nav class="tab-pills" aria-label="Category sections">
    <?php foreach ($__tabs as $__k => $__l): ?>
      <a class="btn-sm<?= $tab === $__k ? ' btn-accent' : ' btn-secondary' ?>" href="/products/categories/<?= $__id ?>?tab=<?= $__k ?>"<?= $tab === $__k ? ' aria-current="page"' : '' ?>><?= View::e($__l) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'general'): $__allowed = \App\Repositories\ProductCategoryRepository::allowedUnits($__c); $__ro = $canManageCatalog ? '' : ' disabled'; ?>
    <?php if ((int) $__c['is_system'] !== 1): ?>
      <h2>Name and attributes</h2>
      <p class="muted small">The attributes an order line of this category carries — they print on the Quotation, Proforma Invoice and Commercial Invoice in the line's detail row, in this order, followed by the HS code.</p>
      <form method="post" action="/products/categories/<?= $__id ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= View::e($__return) ?>">
        <label>Name *<input type="text" name="name" required minlength="2" maxlength="80" value="<?= View::e((string) $__c['name']) ?>" placeholder="e.g. Granite slabs"<?= $__ro ?>></label>
        <?php for ($i = 0; $i < $maxAttributes; $i++): ?>
        <label>Attribute <?= $i + 1 ?><?= $i === 0 ? ' *' : '' ?><input type="text" name="attributes[]" maxlength="60" <?= $i === 0 ? 'required' : '' ?> value="<?= View::e((string) ($__c['attributes'][$i] ?? '')) ?>" placeholder="e.g. Finish"<?= $__ro ?>></label>
        <?php endfor; ?>
        <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?= (int) $__c['is_active'] === 1 ? 'checked' : '' ?><?= $__ro ?>> Active (offered on new order lines)</label>
        <?php if ($canManageCatalog): ?><div class="btn-row"><button type="submit" class="btn-sm btn-success">Save <?= View::e((string) $__c['name']) ?></button></div><?php endif; ?>
      </form>
    <?php else: ?>
      <p class="muted">Natural Stone is today's products: its Dimensions and Finish stay exactly as they are.</p>
    <?php endif; ?>

    <h2>Settings</h2>
    <p class="muted small">What an order line of the category starts with: its unit (and the only units it may use), its HS code for goods, its packing type, the quality documents its orders must collect, and the T&amp;C clause set.</p>
    <form method="post" action="/products/categories/<?= $__id ?>/settings">
      <?= Csrf::field() ?>
      <input type="hidden" name="return_to" value="<?= View::e($__return) ?>">
      <label>Default unit
        <select name="default_unit"<?= $__ro ?>>
          <option value="">No default (as today)</option>
          <?php foreach ($units as $u): ?><option value="<?= View::e($u) ?>" <?= strtoupper($u) === strtoupper((string) ($__c['default_unit'] ?? '')) ? 'selected' : '' ?>><?= View::e($u) ?></option><?php endforeach; ?>
        </select>
      </label>
      <fieldset><legend>Allowed units <small class="muted">(none ticked = any unit)</small></legend>
        <?php foreach ($units as $u): ?><label class="checkbox-row"><input type="checkbox" name="allowed_units[]" value="<?= View::e($u) ?>" <?= in_array(strtoupper($u), $__allowed, true) ? 'checked' : '' ?><?= $__ro ?>> <?= View::e($u) ?></label><?php endforeach; ?>
      </fieldset>
      <label>Default HS code <small class="muted">(goods; from the HS Code master list)</small><input type="text" name="default_hs_code" list="category_hs_codes" pattern="\d{6,8}" maxlength="8" data-in-list="category_hs_codes" data-in-list-message="Pick a code from the HS Code Master List." value="<?= View::e((string) ($__c['default_hs_code'] ?? '')) ?>" placeholder="e.g. 680293"<?= $__ro ?>></label>
      <label>Packing type
        <select name="packing_type"<?= $__ro ?>>
          <option value="">Not set</option>
          <?php foreach ($packageTypes as $p): ?><option value="<?= View::e($p) ?>" <?= $p === (string) ($__c['packing_type'] ?? '') ? 'selected' : '' ?>><?= View::e($p) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Quality documents its orders need <small class="muted">(one per line, at most <?= \App\Repositories\ProductCategoryRepository::MAX_QUALITY_DOCUMENTS ?>)</small><textarea name="quality_documents" rows="3" maxlength="1300" placeholder="e.g. Mill test certificate 3.1&#10;Drawing approval"<?= $__ro ?>><?= View::e((string) ($__c['quality_documents'] ?? '')) ?></textarea></label>
      <label>Weight per CBM (tonnes) <small class="muted">(for Ton prices and the container weight limit; also on Price rules)</small><input type="number" name="weight_t_per_cbm" min="0.1" max="20" step="0.001" value="<?= ($__c['weight_t_per_cbm'] ?? null) !== null ? View::e(rtrim(rtrim((string) $__c['weight_t_per_cbm'], '0'), '.')) : '' ?>" placeholder="e.g. 2.7"<?= $__ro ?>></label>
      <label>T&amp;C clause set
        <select name="tc_clause_set"<?= $__ro ?>>
          <option value="">Not set</option>
          <?php foreach ($tcClauseSets as $k => $label): ?><option value="<?= View::e($k) ?>" <?= $k === (string) ($__c['tc_clause_set'] ?? '') ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if ($canManageCatalog): ?><div class="btn-row"><button type="submit" class="btn-sm btn-success">Save settings</button></div><?php endif; ?>
    </form>
    <datalist id="category_hs_codes"><?php foreach ($hsCodes as $h): ?><option value="<?= View::e((string) $h['code']) ?>"><?= View::e((string) ($h['description'] ?? '')) ?></option><?php endforeach; ?></datalist>

  <?php elseif ($tab === 'options'): ?>
    <?php include __DIR__ . '/../pricing/_spec_table.php'; ?>

  <?php elseif ($tab === 'rules'): ?>
    <?php include __DIR__ . '/_price_rules.php'; ?>

  <?php else: ?>
    <table class="list">
      <tr><th>Product</th><th>HS code</th><th>Unit</th><th>Status</th><th></th></tr>
      <?php foreach ($products as $p): ?>
        <tr><td><a href="/products/<?= (int) $p['id'] ?>"><?= View::e((string) $p['name']) ?></a><?= ($p['thick_k_factor'] ?? null) !== null || ($p['container_cbm'] ?? null) !== null || ($p['container_max_t'] ?? null) !== null ? ' <span class="badge badge-warning">own rule</span>' : '' ?></td>
          <td><?= View::e((string) $p['hs_code']) ?></td><td><?= View::e((string) ($p['reference_unit'] ?? '—')) ?></td>
          <td><?= (int) $p['is_active'] === 1 ? 'Active' : 'Inactive' ?></td><td><a class="btn-sm" href="/products/<?= (int) $p['id'] ?>">View</a></td></tr>
      <?php endforeach; ?>
      <?php if ($products === []): ?><tr><td colspan="5" class="muted">No products in this category yet.</td></tr><?php endif; ?>
    </table>
  <?php endif; ?>
</div>
