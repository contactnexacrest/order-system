<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>HS Code Master List</h1>
  <p class="muted">Order creation's HS Code field only offers codes from this list — a new code has to be added here first. Format is strict: exactly 6 or 8 digits, no dots or other characters, matching the real Indian HS code convention.</p>

  <div class="section">
    <table class="list">
      <tr><th>Code</th><th>Description</th><th>Usage Note</th><th>Status</th><th>Action</th></tr>
      <?php if (empty($codes)): ?>
      <tr><td colspan="5" class="muted">No HS codes yet — add the first one below.</td></tr>
      <?php endif; ?>
      <?php foreach ($codes as $c): ?>
      <tr>
        <td><code><?= htmlspecialchars($c['code']) ?></code></td>
        <td colspan="2">
          <form method="post" action="/hs-codes/<?= (int) $c['id'] ?>/update" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?= Csrf::field() ?>
            <input type="text" name="description" value="<?= htmlspecialchars($c['description']) ?>" placeholder="Description" required style="width:280px">
            <input type="text" name="usage_note" value="<?= htmlspecialchars($c['usage_note'] ?? '') ?>" placeholder="When to use this one (optional, for freshers)" style="width:320px">
            <button type="submit" class="btn-sm">Save</button>
          </form>
        </td>
        <td><span class="badge <?= $c['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <form method="post" action="/hs-codes/<?= (int) $c['id'] ?>/toggle" style="display:inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm"><?= $c['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <form method="post" action="/hs-codes/<?= (int) $c['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete HS code <?= htmlspecialchars($c['code']) ?>? Only possible if it has never been used on an order.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" action="/hs-codes" style="margin-top:8px;display:flex;gap:8px;align-items:center">
      <?= Csrf::field() ?>
      <input type="text" name="code" placeholder="e.g. 680293" pattern="\d{6}|\d{8}" title="Exactly 6 or 8 digits, no dots" required style="width:140px">
      <input type="text" name="description" placeholder="Description" required style="width:320px">
      <button type="submit" class="btn-sm btn-accent">Add HS Code</button>
    </form>
  </div>

  <div class="section">
    <h2>Bulk Import</h2>
    <p class="muted">Onboarding a real customs reference sheet? Paste it straight from Excel — copying two columns (Code, Description) and pasting here keeps them Tab-separated automatically. One line per code. A comma also works if you're typing lines by hand. Anything that isn't a valid 6- or 8-digit code, or already exists, is skipped and listed below the result.</p>
    <form method="post" action="/hs-codes/bulk-import">
      <?= Csrf::field() ?>
      <textarea name="bulk_codes" rows="6" style="width:100%;font-family:monospace" placeholder="25161100&#9;Granite - crude / roughly trimmed&#10;25161200&#9;Granite - cut into blocks or slabs" required></textarea>
      <button type="submit" class="btn-sm btn-accent" style="margin-top:8px">Import Codes</button>
    </form>
  </div>

  <div class="section">
    <h2>Product Guide — Which Code to Use</h2>
    <p class="muted">Read-only reference for whoever is about to pick a code for a new product line — most useful the first time you have to classify one. Not a hard rule: always confirm per-SKU when a row lists more than one candidate code.</p>
    <table class="list">
      <tr><th>Product</th><th>Code</th><th>Note</th><th>Action</th></tr>
      <?php if (empty($productGuide)): ?>
      <tr><td colspan="4" class="muted">No product guide entries yet — import some below.</td></tr>
      <?php endif; ?>
      <?php foreach ($productGuide as $g): ?>
      <tr>
        <td><?= htmlspecialchars($g['product_description']) ?></td>
        <td><code><?= htmlspecialchars($g['code_reference']) ?></code></td>
        <td class="muted"><?= htmlspecialchars($g['note'] ?? '') ?></td>
        <td>
          <form method="post" action="/hs-codes/product-guide/<?= (int) $g['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this product guide entry?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" action="/hs-codes/product-guide" style="margin-top:8px">
      <?= Csrf::field() ?>
      <textarea name="bulk_guide" rows="6" style="width:100%;font-family:monospace" placeholder="Custom-to-size slabs (polished)&#9;68022310&#10;Monument slabs / blanks&#9;68022310 / 68029300&#9;Flat blank -&gt; .2310; pre-shaped/profiled blank -&gt; .9300 - check per SKU" required></textarea>
      <p class="muted" style="margin:4px 0">Each line is Product, Code, and an optional Note — Tab-separated (paste straight from Excel) or separated with <code>|</code> if typing by hand.</p>
      <button type="submit" class="btn-sm btn-accent">Import Product Guide Rows</button>
    </form>
  </div>
</div>
