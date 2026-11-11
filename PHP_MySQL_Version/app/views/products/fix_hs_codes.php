<?php
/**
 * Owner decision D-302 (point 11, 11 Nov 2026): one-time "Fix HS codes" — products whose HS
 * code is not on the HS Code Master List. Choose a code from the list (pre-filled with the
 * category's default); people who manage HS codes can add a code to the list first.
 * Node twin: views/products/fix_hs_codes.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products">&larr; Products</a></p>
  <h1>Fix HS codes</h1>
  <p class="muted">A product's HS code now comes only from the HS Code Master List, so an order line can always use it (the "not on the master list" refusal can no longer happen). These products still have a code typed before — choose the right code from the list.</p>
  <table class="list">
    <tr><th>Product</th><th>HS code now</th><th>Choose from the master list</th></tr>
    <?php foreach ($products as $p): ?>
      <tr>
        <td><a href="/products/<?= (int) $p['id'] ?>"><?= View::e((string) $p['name']) ?></a></td>
        <td><span class="badge badge-warning"><?= View::e((string) $p['hs_code']) ?></span> <small class="muted">not on the list</small></td>
        <td>
          <form method="post" action="/products/<?= (int) $p['id'] ?>/hs-code" class="inline-form">
            <?= Csrf::field() ?>
            <input type="text" name="hs_code" list="fix_hs_codes" required autocomplete="off" maxlength="8" data-in-list="fix_hs_codes" data-in-list-message="Pick a code from the HS Code Master List."
              value="<?= View::e((string) ($p['default_hs_code'] ?? '')) ?>" placeholder="e.g. 68022310" aria-label="New HS code for <?= View::e((string) $p['name']) ?>">
            <button type="submit" class="btn-sm">Save</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($products === []): ?><tr><td colspan="3" class="muted">Every product's HS code is on the master list — nothing to fix.</td></tr><?php endif; ?>
  </table>
  <datalist id="fix_hs_codes"><?php foreach ($hsCodes as $h): ?><option value="<?= View::e((string) $h['code']) ?>"><?= View::e((string) $h['description']) ?></option><?php endforeach; ?></datalist>
  <?php if ($canManageHs): ?>
    <details class="quick-add">
      <summary class="btn-sm">+ Add a new HS code to the master list…</summary>
      <form method="post" action="/hs-codes" class="field-grid card-nested">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="/products/fix-hs-codes">
        <label>HS code *<input type="text" name="code" required pattern="\d{6}|\d{8}" maxlength="8" data-pattern-message="6 or 8 digits, no dots." placeholder="e.g. 68029300"></label>
        <label>Description *<input type="text" name="description" required maxlength="255" placeholder="e.g. Granite, other (polished, worked)"></label>
        <div><button type="submit" class="btn-sm btn-accent">Add HS code</button></div>
      </form>
    </details>
  <?php endif; ?>
</div>
