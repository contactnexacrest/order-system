<?php use App\Helpers\Csrf; ?>
<?php $__statusLabel = ['done' => 'Done', 'needed' => 'Needed', 'optional' => 'Optional', 'review' => 'Check']; ?>
<div class="card page-wide">
  <h1>Setup checklist</h1>
  <p class="muted">What a new installation still needs before real use. <strong><?= (int) $neededCount ?></strong> item(s) needed. Each button opens the page where it is set; the starting values from the installation are flagged until the company's own are entered.</p>
  <table class="list">
    <tr><th>Item</th><th>Status</th><th>Details</th><th></th></tr>
    <?php foreach ($items as $__it): $__cls = $__it['status'] === 'review' ? 'needed' : $__it['status']; ?>
    <tr id="setup-<?= htmlspecialchars($__it['key']) ?>">
      <td><strong><?= htmlspecialchars($__it['title']) ?></strong></td>
      <td><span class="setup-status <?= htmlspecialchars($__cls) ?>"><?= htmlspecialchars($__statusLabel[$__it['status']] ?? $__it['status']) ?></span></td>
      <td><?= htmlspecialchars($__it['detail']) ?>
        <?php if (isset($__it['tick'])): ?>
          <?php if (!empty($__it['may_tick'])): ?>
          <form method="post" action="/admin/setup-checklist/tick" class="btn-row" style="margin-top:6px;align-items:center;flex-wrap:wrap">
            <?= Csrf::field() ?>
            <input type="hidden" name="item_key" value="<?= htmlspecialchars($__it['tick']) ?>">
            <?php if ($__it['ticked'] === null): ?>
              <input type="hidden" name="ticked" value="1">
              <label class="small"><input type="checkbox" name="confirm" value="1" required> <?= htmlspecialchars($__it['tick_label']) ?> *</label>
              <input type="text" name="note" maxlength="300" placeholder="Note (optional), e.g. confirmed with the CA on the phone" aria-label="Note" style="min-width:240px">
              <button type="submit" class="btn-sm">Tick</button>
            <?php else: ?>
              <input type="hidden" name="ticked" value="0">
              <button type="submit" class="btn-sm btn-secondary">Remove the tick</button>
            <?php endif; ?>
          </form>
          <?php elseif ($__it['ticked'] === null): ?>
          <br><span class="muted small">Ask someone who may tick it (the MD for pricing and commission levels).</span>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (($__it['action'] ?? '') === 'national_holidays'): ?>
          <form method="post" action="/admin/setup-checklist/national-holidays" style="margin-top:6px">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-secondary">Add the national holidays for <?= (int) $year ?> and <?= (int) $year + 1 ?></button>
          </form>
        <?php endif; ?>
      </td>
      <td><a class="btn-sm" href="<?= htmlspecialchars($__it['url']) ?>"><?= htmlspecialchars($__it['button']) ?></a></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <div class="section" id="recommended">
    <h2>Settings that differ from the recommended values</h2>
    <?php if ($differences === []): ?>
      <p class="muted">Every listed setting is at its recommended value.</p>
    <?php else: ?>
    <form method="post" action="/admin/setup-checklist/apply-recommended">
      <?= Csrf::field() ?>
      <table class="list">
        <tr><th>Apply</th><th>Setting</th><th>Now</th><th>Recommended</th></tr>
        <?php foreach ($differences as $__d): ?>
        <tr>
          <td><input type="checkbox" name="keys[]" value="<?= htmlspecialchars($__d['key']) ?>" checked aria-label="Apply the recommended value for <?= htmlspecialchars($__d['label']) ?>"></td>
          <td><?= htmlspecialchars($__d['label']) ?></td>
          <td><span class="badge badge-warning">Differs</span> <?= htmlspecialchars($__d['current'] !== '' ? $__d['current'] : '(empty)') ?></td>
          <td><?= htmlspecialchars($__d['recommended'] !== '' ? $__d['recommended'] : '(empty)') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <label>Reason for applying the recommended values *
        <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="At least 10 characters, e.g. setting up the new installation as recommended"></textarea>
      </label>
      <button type="submit" class="btn-sm">Apply recommended</button>
    </form>
    <?php endif; ?>
    <p class="muted small">Recommended: <?php $__parts = []; foreach ($recommended as $__k => [$__label, $__value]) { $__parts[] = $__label . ' = ' . $__value; } echo htmlspecialchars(implode(' · ', $__parts)); ?>. Every change is recorded in the Audit Log with your reason.</p>
  </div>
</div>
