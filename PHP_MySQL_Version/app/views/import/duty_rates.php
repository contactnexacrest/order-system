<?php use App\Helpers\Csrf;
/* Owner decision D-257 Phase 3: Admin › Import duty rates for Import & Distribution (to be confirmed by the CA and the customs agent). */
$h = static fn ($v): string => htmlspecialchars((string) $v); ?>
<div class="card page-wide">
  <p><a href="/admin/divisions">&larr; Divisions</a></p>
  <h1>Import duty rates (Import &amp; Distribution)</h1>
  <p class="muted">An import line takes the rates of the longest HSN below that its HSN starts with, else the defaults: basic customs duty <strong><?= $h($defaults['bcd']) ?>%</strong>, social welfare surcharge <strong><?= $h($defaults['sws']) ?>%</strong> of the duty, IGST <strong><?= $h($defaults['igst']) ?>%</strong> (Import &amp; Distribution settings <code>imp_default_bcd_pct</code>, <code>imp_default_sws_pct</code>, <code>imp_default_igst_pct</code>). These are starting values — <strong>to be confirmed by the CA and the customs agent</strong>. A line's rates can also be typed on the line.</p>
  <table class="list" data-testid="import-duty-rates">
    <tr><th>HSN starts with</th><th>Basic duty</th><th>SWS</th><th>IGST</th><th>Note</th><th>In use</th><th></th></tr>
    <?php foreach ($rates as $r): ?>
    <tr>
      <td><?= $h($r['hsn_prefix']) ?></td><td><?= $h($r['bcd_pct']) ?>%</td><td><?= $h($r['sws_pct']) ?>%</td><td><?= $h($r['igst_pct']) ?>%</td><td><?= $h($r['note']) ?></td>
      <td><?= (int) $r['is_active'] === 1 ? 'Yes' : 'No' ?></td>
      <td><form method="post" action="/admin/import-duty-rates/<?= (int) $r['id'] ?>/toggle"><?= Csrf::field() ?><input type="hidden" name="active" value="<?= (int) $r['is_active'] === 1 ? '0' : '1' ?>"><button type="submit" class="btn-link"><?= (int) $r['is_active'] === 1 ? 'Stop using' : 'Use again' ?></button></form></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rates === []): ?><tr><td colspan="7" class="muted">None yet: every line takes the defaults.</td></tr><?php endif; ?>
  </table>
  <h2>Add a rate</h2>
  <form method="post" action="/admin/import-duty-rates" class="field-grid">
    <?= Csrf::field() ?>
    <label>HSN starts with *<input type="text" name="hsn_prefix" required minlength="2" maxlength="8" pattern="[0-9]{2,8}" placeholder="e.g. 6802"></label>
    <label>Basic customs duty % *<input type="number" name="bcd_pct" required min="0" max="150" step="0.01" placeholder="e.g. 20"></label>
    <label>Social welfare surcharge % *<input type="number" name="sws_pct" required min="0" max="150" step="0.01" placeholder="e.g. 10"></label>
    <label>IGST % *<input type="number" name="igst_pct" required min="0" max="150" step="0.01" placeholder="e.g. 18"></label>
    <label>Note<input type="text" name="note" maxlength="255" placeholder="e.g. Granite slabs — confirmed by the customs agent on 10 Nov"></label>
    <p><button type="submit" class="btn">Add rate</button></p>
  </form>
</div>
