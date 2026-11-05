<?php use App\Helpers\Csrf; use App\Controllers\GstRateController;
/* Owner decision D-257 Phase 2: Admin › GST rates for Domestic Trade sales (to be confirmed by the CA). */
$h = static fn ($v): string => htmlspecialchars((string) $v); ?>
<div class="card page-wide">
  <p><a href="/admin/divisions">&larr; Divisions</a></p>
  <h1>GST rates (Domestic Trade)</h1>
  <p class="muted">A domestic order line takes the rate of the longest HSN below that its HSN starts with, else its product category's rate, else the default rate <strong><?= $h($defaultRate) ?>%</strong> (Domestic Trade setting <code>dom_default_gst_rate</code>). These are standard rates as a start — <strong>to be confirmed by the CA</strong>. A line's rate can also be typed on the line.</p>
  <table class="list" data-testid="gst-rates">
    <tr><th>HSN starts with</th><th>Category</th><th>GST</th><th>Note</th><th>In use</th><th></th></tr>
    <?php foreach ($rates as $r): ?>
    <tr>
      <td><?= $h($r['hsn_prefix'] ?? '—') ?></td><td><?= $h($r['category_name'] ?? '—') ?></td><td><?= $h($r['rate_pct']) ?>%</td><td><?= $h($r['note']) ?></td>
      <td><?= (int) $r['is_active'] === 1 ? 'Yes' : 'No' ?></td>
      <td><form method="post" action="/admin/gst-rates/<?= (int) $r['id'] ?>/toggle"><?= Csrf::field() ?><input type="hidden" name="active" value="<?= (int) $r['is_active'] === 1 ? '0' : '1' ?>"><button type="submit" class="btn-link"><?= (int) $r['is_active'] === 1 ? 'Stop using' : 'Use again' ?></button></form></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rates === []): ?><tr><td colspan="6" class="muted">None yet: every line takes the default rate.</td></tr><?php endif; ?>
  </table>
  <h2>Add a rate</h2>
  <form method="post" action="/admin/gst-rates" class="field-grid">
    <?= Csrf::field() ?>
    <label>HSN starts with<input type="text" name="hsn_prefix" maxlength="8" pattern="[0-9]{2,8}" placeholder="e.g. 6802"></label>
    <label>or Category
      <select name="category_id">
        <option value="">—</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $h($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>GST rate *
      <select name="rate_pct" required>
        <option value="">Choose…</option>
        <?php foreach (GstRateController::RATES as $r): ?><option value="<?= $h($r) ?>"><?= $h(rtrim(rtrim($r, '0'), '.')) ?>%</option><?php endforeach; ?>
      </select>
    </label>
    <label>Note<input type="text" name="note" maxlength="255" placeholder="e.g. Polished granite slabs — confirmed by the CA on 10 Nov"></label>
    <p><button type="submit" class="btn">Add rate</button></p>
  </form>
</div>
