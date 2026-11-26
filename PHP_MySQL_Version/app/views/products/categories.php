<?php use App\Helpers\Csrf; ?>
<?php // Owner decision D-215 (5a): product categories and the attributes their order lines carry.
// D-306 (point 20, 11 Nov 2026): each category opens on one page with tabs (General | Options | Price rules | Products). ?>
<div class="card page-wide">
  <h1>Product categories</h1>
  <p class="muted">Each category names the attributes an order line of that category carries — they print on the Quotation, Proforma Invoice and Commercial Invoice in the line's detail row, in this order, followed by the HS code. Natural Stone is today's products: its Dimensions and Finish stay exactly as they are.</p>
  <?php // D-435 (26 Nov 2026): brand → main category → sub-category. A sub-category follows its main category's settings until it sets its own. ?>
  <form method="get" action="/products/categories" class="filter-row">
    <label>Brand<select name="brand" onchange="this.form.submit()">
      <option value="">All brands</option>
      <option value="none"<?= $brandFilter === 'none' ? ' selected' : '' ?>>Company-wide (no brand)</option>
      <?php foreach ($brands as $b): ?>
      <?php if ($brandScope !== null && (int) $b['id'] !== $brandScope) { continue; } ?>
      <option value="<?= (int) $b['id'] ?>"<?= $brandFilter === (string) $b['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $b['name']) ?></option>
      <?php endforeach; ?>
    </select></label>
    <noscript><button type="submit" class="btn-sm">Show</button></noscript>
  </form>
  <table class="list">
    <tr><th>Category</th><th>Brand</th><th>Attributes</th><th>Units</th><th>HS code</th><th>Packing</th><th>Quality documents</th><th>T&amp;C clauses</th><th>Status</th><th></th></tr>
    <?php foreach ($categories as $c): ?>
    <?php
      $rowBrand = !empty($c['brand_id']) ? (string) (int) $c['brand_id'] : 'none';
      if ($brandFilter !== '' && $brandFilter !== $rowBrand) { continue; }
      $isSub = (int) ($c['level'] ?? 1) === 2;
      $inh = '<small class="muted">as main</small>';
    ?>
    <tr<?= $isSub ? ' class="cat-sub"' : '' ?>>
      <td><?= $isSub ? '<span class="muted">↳</span> ' : '' ?><a href="/products/categories/<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['name']) ?></a><?= (int) $c['is_system'] === 1 ? ' <small class="muted">(today\'s products)</small>' : '' ?><?= $isSub ? ' <small class="muted">sub-category of ' . htmlspecialchars((string) $c['main_category_name']) . '</small>' : '' ?></td>
      <td><?= ($c['brand_name'] ?? '') !== '' ? htmlspecialchars((string) $c['brand_name']) : '<span class="muted">Company-wide</span>' ?></td>
      <td><?= $c['attributes'] !== [] ? htmlspecialchars(implode(' · ', $c['attributes'])) : ($isSub ? $inh : '') ?></td>
      <?php // D-257 0B: the category's units, HS code, packing, quality documents and T&C clause set. A sub-category's empty value = its main category's. ?>
      <td><?= $isSub && empty($c['default_unit']) && empty($c['allowed_units']) ? $inh : htmlspecialchars(($c['default_unit'] ?? null) ? $c['default_unit'] . ($c['allowed_units'] ? ' (' . str_replace(',', ', ', (string) $c['allowed_units']) . ')' : '') : (($c['allowed_units'] ?? null) ? str_replace(',', ', ', (string) $c['allowed_units']) : 'Any (as today)')) ?></td>
      <td><?= $isSub && empty($c['default_hs_code']) ? $inh : htmlspecialchars((string) (($c['default_hs_code'] ?? null) ?: '—')) ?></td>
      <td><?= $isSub && empty($c['packing_type']) ? $inh : htmlspecialchars((string) (($c['packing_type'] ?? null) ?: '—')) ?></td>
      <td><?= $isSub && empty($c['quality_documents']) ? $inh : htmlspecialchars(implode(' · ', \App\Repositories\ProductCategoryRepository::qualityDocuments($c)) ?: 'None') ?></td>
      <td><?= $isSub && empty($c['tc_clause_set']) ? $inh : htmlspecialchars((string) ($tcClauseSets[$c['tc_clause_set'] ?? ''] ?? '—')) ?></td>
      <td><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
      <td><a class="btn-sm" href="/products/categories/<?= (int) $c['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <p class="muted small">Open a category to change its name, attributes and settings (units, HS code, packing, quality documents, T&amp;C clauses), the options that change its prices, and its Category price rules.</p>

  <h2>Add a main category</h2>
  <form method="post" action="/products/categories">
    <?= Csrf::field() ?>
    <?php // D-363 (13 Nov 2026): the name alone on row 1 (half width), then the attributes two per row (1 | 2, 3 | 4, 5 | 6); one column on a phone. Still exactly MAX_ATTRIBUTES (6) boxes. ?>
    <div class="cat-attr-grid">
    <label class="cat-name">Name *<input type="text" name="name" required minlength="2" maxlength="80" placeholder="e.g. Ceramic Tiles"></label>
    <?php if ($brandScope === null): ?>
    <label>Brand<select name="brand_id">
      <option value="">Company-wide (no brand)</option>
      <?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars((string) $b['name']) ?></option><?php endforeach; ?>
    </select></label>
    <?php else: ?>
    <p class="muted small">The category is added to your brand.</p>
    <?php endif; ?>
    <?php for ($i = 0; $i < $maxAttributes; $i++): ?>
    <label<?= $i === 0 ? ' class="cat-attr-first"' : '' ?>>Attribute <?= $i + 1 ?><?= $i === 0 ? ' *' : '' ?><input type="text" name="attributes[]" maxlength="60" <?= $i === 0 ? 'required' : '' ?> placeholder="<?= ['e.g. Size', 'e.g. Grade', 'e.g. Colour', 'e.g. Finish', 'e.g. Thickness', 'e.g. Packing'][$i] ?? 'e.g. Finish' ?>"></label>
    <?php endfor; ?>
    </div>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Add Category</button></div>
  </form>

  <?php if ($mainCategories !== []): ?>
  <h2>Add a sub-category</h2>
  <p class="muted small">A sub-category sits under a main category (e.g. Natural Stones › Granite). It uses the main category's brand, attributes and settings; open it afterwards to set anything of its own.</p>
  <form method="post" action="/products/categories">
    <?= Csrf::field() ?>
    <div class="cat-attr-grid">
    <label class="cat-name">Main category *<select name="parent_id" required>
      <option value="">Choose…</option>
      <?php foreach ($mainCategories as $m): ?><option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars((string) $m['name']) ?><?= ($m['brand_name'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $m['brand_name']) . ')' : '' ?></option><?php endforeach; ?>
    </select></label>
    <label>Name *<input type="text" name="name" required minlength="2" maxlength="80" placeholder="e.g. Granite"></label>
    <?php for ($i = 0; $i < $maxAttributes; $i++): ?>
    <label>Attribute <?= $i + 1 ?><input type="text" name="attributes[]" maxlength="60" placeholder="<?= $i === 0 ? 'Empty = the main category\'s attributes' : 'e.g. Finish' ?>"></label>
    <?php endfor; ?>
    </div>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Add Sub-category</button></div>
  </form>
  <?php endif; ?>
</div>
