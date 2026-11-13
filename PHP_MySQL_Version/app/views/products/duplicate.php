<?php
use App\Helpers\Csrf;
use App\Helpers\View;
/** Owner decision D-358 (13 Nov 2026): duplicate a product — confirm what is copied. */
$__n = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$__c = $counts;
?>
<div class="card page-wide" data-duplicate-product>
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>">&larr; Back to <?= View::e($product['name']) ?></a></p>
  <h1>Duplicate — confirm what is copied</h1>
  <p class="muted">A new product is made from <strong><?= View::e($product['name']) ?></strong>. Nothing on the original changes.</p>

  <form method="post" action="/products/<?= (int) $product['id'] ?>/duplicate">
    <?= Csrf::field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.5rem;align-items:start">
      <div>
        <label>Name of the new product *
          <input type="text" name="name" value="<?= View::e($name) ?>" placeholder="e.g. Absolute Black Granite (copy)" required maxlength="255"
            data-not-in-list="taken_product_names" data-not-in-list-message="A product with this name already exists — two products cannot have the same name. Choose another name."<?= $nameError !== null ? ' class="field-invalid"' : '' ?>>
          <?php if ($nameError !== null): ?><small class="reason-error form-field-error"><?= View::e($nameError) ?></small><?php endif; ?>
        </label>
        <datalist id="taken_product_names"><?php foreach ($takenNames as $__t): ?><option value="<?= View::e($__t) ?>"></option><?php endforeach; ?></datalist>
        <p class="muted small">Change it now or later — two products cannot have the same name.</p>

        <h2>Copied exactly</h2>
        <ul data-copied-list>
          <li>Category, HS code, origin, unit and the specifications text</li>
          <li>Weight, container CBM / maximum tonnes, thickness factor and costs to FOB</li>
          <li>Pricing overrides (margin, buffer, other costs, incentive)</li>
          <li><?= $__n($__c['specs'], 'own specification', 'own specifications') ?> with <?= $__n($__c['options'], 'option', 'options') ?> and their price changes<?php if ($__c['hidden_specs'] > 0 || $__c['option_overrides'] > 0): ?>; <?= $__n($__c['hidden_specs'], 'hidden category specification', 'hidden category specifications') ?> and <?= $__n($__c['option_overrides'], 'changed category option', 'changed category options') ?><?php endif; ?></li>
          <li>Supplier links with their prices and dates (<?= $__n($__c['suppliers'], 'supplier', 'suppliers') ?>)</li>
          <li>Thickness prices (<?= $__n($__c['thickness_rows'], 'row', 'rows') ?>)</li>
          <?php if ($__c['misc_charges'] > 0): ?><li><?= $__n($__c['misc_charges'], 'misc charge', 'misc charges') ?></li><?php endif; ?>
          <?php if ($__c['images'] > 0): ?><li><?= $__n($__c['images'], 'image', 'images') ?> (the image files are copied)</li><?php endif; ?>
        </ul>
      </div>
      <div>
        <h2>Not copied</h2>
        <ul data-not-copied-list>
          <li><strong>Zoho item link</strong> — the copy gets its own Zoho item when it is synced</li>
          <li>Orders, quotations, price requests and the supplier price history<?= $__c['price_history'] > 0 ? ' (' . $__n($__c['price_history'], 'change', 'changes') . ')' : '' ?> — the copy has none yet</li>
        </ul>
        <div class="alert alert-warning" data-inactive-note><strong>The copy starts as Inactive.</strong> It cannot be picked on an order until you check it, update the price and switch it to Active.</div>
        <p>
          <button type="submit" class="btn-sm btn-accent">Create the copy</button>
          <a class="btn-sm" href="/products/<?= (int) $product['id'] ?>">Cancel</a>
        </p>
      </div>
    </div>
  </form>
</div>
