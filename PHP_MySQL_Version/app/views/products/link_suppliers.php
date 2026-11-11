<?php
/**
 * Owner decision D-303 (point 15, 11 Nov 2026): one-time "Link suppliers" — supplier names
 * typed on product prices before the Suppliers directory became the master. "Match by name"
 * links every exact match at once; pick (or quick-add) the rest one by one. Nothing is lost:
 * the price stays, it only gets its directory supplier. Node twin: link_suppliers.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products">&larr; Products</a></p>
  <h1>Link suppliers</h1>
  <p class="muted">The supplier of a product price is now a record of the Suppliers directory. These names were typed on prices before — link each one to its supplier.</p>
  <?php $__exact = count(array_filter($names, static fn (array $n): bool => $n['match'] !== null)); ?>
  <?php if ($__exact > 0): ?>
    <form method="post" action="/products/link-suppliers">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="match_all">
      <p><button type="submit" class="btn-sm btn-accent">Match by name (<?= $__exact ?>)</button> <small class="muted">links every typed name that is exactly a directory supplier's name</small></p>
    </form>
  <?php endif; ?>
  <table class="list">
    <tr><th>Typed name</th><th class="num">Prices</th><th>Link to</th></tr>
    <?php foreach ($names as $i => $n): ?>
      <tr>
        <td><?= View::e((string) $n['supplier_name']) ?><br><small class="muted"><?= (int) $n['products'] ?> product(s)</small></td>
        <td class="num"><?= (int) $n['prices'] ?></td>
        <td>
          <form method="post" action="/products/link-suppliers" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="typed_name" value="<?= View::e((string) $n['supplier_name']) ?>">
            <input type="text" name="supplier_name" list="link-supplier-list" required autocomplete="off" maxlength="255" data-pick-id="supplier_id" data-in-list="link-supplier-list"
              data-in-list-message="Pick the supplier from the Suppliers directory — or add it with &quot;+ Add a new supplier (quick)&quot;."
              value="<?= View::e((string) ($n['match']['name'] ?? '')) ?>" placeholder="Type the name, e.g. Sri Balaji Granites" aria-label="Directory supplier for <?= View::e((string) $n['supplier_name']) ?>">
            <input type="hidden" name="supplier_id" value="<?= (int) ($n['match']['id'] ?? 0) ?: '' ?>">
            <button type="submit" class="btn-sm">Link</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($names === []): ?><tr><td colspan="3" class="muted">Every product price is linked to a directory supplier — nothing to do.</td></tr><?php endif; ?>
  </table>
  <datalist id="link-supplier-list">
    <?php foreach ($pickList as $__sp): ?><option value="<?= View::e($__sp['name']) ?>" data-id="<?= (int) $__sp['id'] ?>"><?= View::e(implode(' · ', array_filter([implode(', ', array_slice($__sp['locations'], 0, 1)), $__sp['pending'] ? 'Details pending' : '']))) ?></option><?php endforeach; ?>
  </datalist>
  <?php $__quickProductId = 0; include __DIR__ . '/_supplier_quick_add.php'; ?>
</div>
