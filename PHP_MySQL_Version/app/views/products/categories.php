<?php use App\Helpers\Csrf; ?>
<?php // Owner decision D-215 (5a): product categories and the attributes their order lines carry. ?>
<div class="card page-wide">
  <h1>Product categories</h1>
  <p class="muted">Each category names the attributes an order line of that category carries — they print on the Quotation, Proforma Invoice and Commercial Invoice in the line's detail row, in this order, followed by the HS code. Natural Stone is today's products: its Dimensions and Finish stay exactly as they are.</p>
  <table class="list">
    <tr><th>Category</th><th>Attributes</th><th>Status</th></tr>
    <?php foreach ($categories as $c): ?>
    <tr>
      <td><?= htmlspecialchars((string) $c['name']) ?><?= (int) $c['is_system'] === 1 ? ' <small class="muted">(today\'s products)</small>' : '' ?></td>
      <td><?= htmlspecialchars(implode(' · ', $c['attributes'])) ?></td>
      <td><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php foreach ($categories as $c): if ((int) $c['is_system'] === 1) { continue; } ?>
  <details class="category-edit">
    <summary>Edit <?= htmlspecialchars((string) $c['name']) ?></summary>
    <form method="post" action="/products/categories/<?= (int) $c['id'] ?>">
      <?= Csrf::field() ?>
      <label>Name *<input type="text" name="name" required minlength="2" maxlength="80" value="<?= htmlspecialchars((string) $c['name']) ?>"></label>
      <?php for ($i = 0; $i < $maxAttributes; $i++): ?>
      <label>Attribute <?= $i + 1 ?><?= $i === 0 ? ' *' : '' ?><input type="text" name="attributes[]" maxlength="60" <?= $i === 0 ? 'required' : '' ?> value="<?= htmlspecialchars((string) ($c['attributes'][$i] ?? '')) ?>"></label>
      <?php endfor; ?>
      <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?= (int) $c['is_active'] === 1 ? 'checked' : '' ?>> Active (offered on new order lines)</label>
      <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save <?= htmlspecialchars((string) $c['name']) ?></button></div>
    </form>
  </details>
  <?php endforeach; ?>

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
