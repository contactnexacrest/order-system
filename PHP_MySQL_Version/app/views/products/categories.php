<?php use App\Helpers\Csrf; ?>
<?php // Owner decision D-215 (5a): product categories and the attributes their order lines carry.
// D-306 (point 20, 11 Nov 2026): each category opens on one page with tabs (General | Options | Price rules | Products). ?>
<div class="card page-wide">
  <h1>Product categories</h1>
  <p class="muted">Each category names the attributes an order line of that category carries — they print on the Quotation, Proforma Invoice and Commercial Invoice in the line's detail row, in this order, followed by the HS code. Natural Stone is today's products: its Dimensions and Finish stay exactly as they are.</p>
  <table class="list">
    <tr><th>Category</th><th>Attributes</th><th>Units</th><th>HS code</th><th>Packing</th><th>Quality documents</th><th>T&amp;C clauses</th><th>Status</th><th></th></tr>
    <?php foreach ($categories as $c): ?>
    <tr>
      <td><a href="/products/categories/<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['name']) ?></a><?= (int) $c['is_system'] === 1 ? ' <small class="muted">(today\'s products)</small>' : '' ?></td>
      <td><?= htmlspecialchars(implode(' · ', $c['attributes'])) ?></td>
      <?php // D-257 0B: the category's units, HS code, packing, quality documents and T&C clause set. ?>
      <td><?= htmlspecialchars(($c['default_unit'] ?? null) ? $c['default_unit'] . ($c['allowed_units'] ? ' (' . str_replace(',', ', ', (string) $c['allowed_units']) . ')' : '') : (($c['allowed_units'] ?? null) ? str_replace(',', ', ', (string) $c['allowed_units']) : 'Any (as today)')) ?></td>
      <td><?= htmlspecialchars((string) (($c['default_hs_code'] ?? null) ?: '—')) ?></td>
      <td><?= htmlspecialchars((string) (($c['packing_type'] ?? null) ?: '—')) ?></td>
      <td><?= htmlspecialchars(implode(' · ', \App\Repositories\ProductCategoryRepository::qualityDocuments($c)) ?: 'None') ?></td>
      <td><?= htmlspecialchars((string) ($tcClauseSets[$c['tc_clause_set'] ?? ''] ?? '—')) ?></td>
      <td><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
      <td><a class="btn-sm" href="/products/categories/<?= (int) $c['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <p class="muted small">Open a category to change its name, attributes and settings (units, HS code, packing, quality documents, T&amp;C clauses), the options that change its prices, and its Category price rules.</p>

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
