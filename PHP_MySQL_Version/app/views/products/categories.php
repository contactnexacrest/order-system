<?php use App\Helpers\Csrf; ?>
<?php // Owner decision D-215 (5a): product categories and the attributes their order lines carry. ?>
<div class="card page-wide">
  <h1>Product categories</h1>
  <p class="muted">Each category names the attributes an order line of that category carries — they print on the Quotation, Proforma Invoice and Commercial Invoice in the line's detail row, in this order, followed by the HS code. Natural Stone is today's products: its Dimensions and Finish stay exactly as they are.</p>
  <table class="list">
    <tr><th>Category</th><th>Attributes</th><th>Units</th><th>HS code</th><th>Packing</th><th>Quality documents</th><th>T&amp;C clauses</th><th>Status</th></tr>
    <?php foreach ($categories as $c): ?>
    <tr>
      <td><?= htmlspecialchars((string) $c['name']) ?><?= (int) $c['is_system'] === 1 ? ' <small class="muted">(today\'s products)</small>' : '' ?></td>
      <td><?= htmlspecialchars(implode(' · ', $c['attributes'])) ?></td>
      <?php // D-257 0B: the category's units, HS code, packing, quality documents and T&C clause set. ?>
      <td><?= htmlspecialchars(($c['default_unit'] ?? null) ? $c['default_unit'] . ($c['allowed_units'] ? ' (' . str_replace(',', ', ', (string) $c['allowed_units']) . ')' : '') : (($c['allowed_units'] ?? null) ? str_replace(',', ', ', (string) $c['allowed_units']) : 'Any (as today)')) ?></td>
      <td><?= htmlspecialchars((string) (($c['default_hs_code'] ?? null) ?: '—')) ?></td>
      <td><?= htmlspecialchars((string) (($c['packing_type'] ?? null) ?: '—')) ?></td>
      <td><?= htmlspecialchars(implode(' · ', \App\Repositories\ProductCategoryRepository::qualityDocuments($c)) ?: 'None') ?></td>
      <td><?= htmlspecialchars((string) ($tcClauseSets[$c['tc_clause_set'] ?? ''] ?? '—')) ?></td>
      <td><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php foreach ($categories as $c): if ((int) $c['is_system'] === 1) { continue; } ?>
  <details class="category-edit">
    <summary>Edit <?= htmlspecialchars((string) $c['name']) ?></summary>
    <form method="post" action="/products/categories/<?= (int) $c['id'] ?>">
      <?= Csrf::field() ?>
      <label>Name *<input type="text" name="name" required minlength="2" maxlength="80" value="<?= htmlspecialchars((string) $c['name']) ?>" placeholder="e.g. Granite slabs"></label>
      <?php for ($i = 0; $i < $maxAttributes; $i++): ?>
      <label>Attribute <?= $i + 1 ?><?= $i === 0 ? ' *' : '' ?><input type="text" name="attributes[]" maxlength="60" <?= $i === 0 ? 'required' : '' ?> value="<?= htmlspecialchars((string) ($c['attributes'][$i] ?? '')) ?>" placeholder="e.g. Finish"></label>
      <?php endfor; ?>
      <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?= (int) $c['is_active'] === 1 ? 'checked' : '' ?>> Active (offered on new order lines)</label>
      <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save <?= htmlspecialchars((string) $c['name']) ?></button></div>
    </form>
  </details>
  <?php endforeach; ?>

  <?php // Owner decision D-257 Phase 0B (5 Nov 2026): each category's settings, Natural Stone included. ?>
  <h2>Category settings</h2>
  <p class="muted">What an order line of the category starts with: its unit (and the only units it may use), its HS code for goods, its packing type, the quality documents its orders must collect (a checklist appears on the order page), and the T&amp;C clause set its documents will print from Phase 1. Natural Stone keeps today's values — any unit, HS 680293, wooden crates, the stone clauses, no extra quality documents.</p>
  <?php foreach ($categories as $c): $__allowed = \App\Repositories\ProductCategoryRepository::allowedUnits($c); ?>
  <details class="category-settings" id="category-settings-<?= (int) $c['id'] ?>">
    <summary>Settings: <?= htmlspecialchars((string) $c['name']) ?></summary>
    <form method="post" action="/products/categories/<?= (int) $c['id'] ?>/settings">
      <?= Csrf::field() ?>
      <label>Default unit
        <select name="default_unit">
          <option value="">No default (as today)</option>
          <?php foreach ($units as $u): ?><option value="<?= htmlspecialchars($u) ?>" <?= strtoupper($u) === strtoupper((string) ($c['default_unit'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option><?php endforeach; ?>
        </select>
      </label>
      <fieldset><legend>Allowed units <small class="muted">(none ticked = any unit)</small></legend>
        <?php foreach ($units as $u): ?><label class="checkbox-row"><input type="checkbox" name="allowed_units[]" value="<?= htmlspecialchars($u) ?>" <?= in_array(strtoupper($u), $__allowed, true) ? 'checked' : '' ?>> <?= htmlspecialchars($u) ?></label><?php endforeach; ?>
      </fieldset>
      <label>Default HS code <small class="muted">(goods; from the HS Code master list)</small><input type="text" name="default_hs_code" list="category_hs_codes" pattern="\d{6,8}" maxlength="8" value="<?= htmlspecialchars((string) ($c['default_hs_code'] ?? '')) ?>" placeholder="e.g. 730791"></label>
      <label>Packing type
        <select name="packing_type">
          <option value="">Not set</option>
          <?php foreach ($packageTypes as $p): ?><option value="<?= htmlspecialchars($p) ?>" <?= $p === (string) ($c['packing_type'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Quality documents its orders need <small class="muted">(one per line, at most <?= \App\Repositories\ProductCategoryRepository::MAX_QUALITY_DOCUMENTS ?>)</small><textarea name="quality_documents" rows="3" maxlength="1300" placeholder="e.g. Mill test certificate 3.1&#10;Drawing approval"><?= htmlspecialchars((string) ($c['quality_documents'] ?? '')) ?></textarea></label>
      <label>T&amp;C clause set
        <select name="tc_clause_set">
          <option value="">Not set</option>
          <?php foreach ($tcClauseSets as $k => $label): ?><option value="<?= htmlspecialchars($k) ?>" <?= $k === (string) ($c['tc_clause_set'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save settings for <?= htmlspecialchars((string) $c['name']) ?></button></div>
    </form>
  </details>
  <?php endforeach; ?>
  <datalist id="category_hs_codes"><?php foreach ($hsCodes as $h): ?><option value="<?= htmlspecialchars((string) $h['code']) ?>"><?= htmlspecialchars((string) ($h['description'] ?? '')) ?></option><?php endforeach; ?></datalist>

  <h2>Add a category</h2>
  <form method="post" action="/products/categories">
    <?= Csrf::field() ?>
    <label>Name *<input type="text" name="name" required minlength="2" maxlength="80" placeholder="e.g. Ceramic Tiles"></label>
    <?php for ($i = 0; $i < $maxAttributes; $i++): ?>
    <label>Attribute <?= $i + 1 ?><?= $i === 0 ? ' *' : '' ?><input type="text" name="attributes[]" maxlength="60" <?= $i === 0 ? 'required' : '' ?> placeholder="<?= ['e.g. Size', 'e.g. Grade', 'e.g. Colour', '', '', ''][$i] ?>"></label>
    <?php endfor; ?>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Add Category</button></div>
  </form>
</div>
