<?php
/** Add / edit / renew an agreement (owner decision 2 Nov 2026, point 4). Node twin: agreements/form.njk. */
$__isNew = $agreement === null;
$__val = static fn (string $k): string => htmlspecialchars((string) ($v[$k] ?? ''));
?>
<div class="card">
  <h1><?= $__isNew ? ($renews !== null ? 'Renew an agreement' : 'Add an agreement') : 'Edit an agreement' ?></h1>
  <?php if ($renews !== null): ?><p class="muted">Renewing <?= htmlspecialchars((string) $renews['agreement_type']) ?> signed <?= htmlspecialchars((string) $renews['signed_on']) ?>. Attach the newly signed copy; the old agreement is then shown as "Renewed (replaced)" and gets no more reminders.</p><?php endif; ?>
  <form method="post" action="<?= $__isNew ? '/agreements' : '/agreements/' . (int) $agreement['id'] . '/update' ?>" enctype="multipart/form-data">
    <?= \App\Helpers\Csrf::field() ?>
    <div class="field-grid">
    <?php if ($renews !== null): ?><input type="hidden" name="renews_agreement_id" value="<?= (int) $renews['id'] ?>"><?php endif; ?>
    <?php if ($__isNew): ?>
      <label>Agreement with
        <select name="party_type" required>
          <option value="">Choose…</option>
          <?php foreach (\App\Repositories\AgreementRepository::PARTY_TYPES as $k => $label): ?>
            <option value="<?= $k ?>"<?= ($v['party_type'] ?? '') === $k ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php foreach (\App\Repositories\AgreementRepository::PARTY_TYPES as $k => $label): ?>
        <label data-show-when="party_type=<?= $k ?>"><?= htmlspecialchars($label) ?>
          <select name="party_id_<?= $k ?>" required>
            <option value="">Choose…</option>
            <?php foreach ($parties[$k] ?? [] as $pid => $pname): ?>
              <option value="<?= (int) $pid ?>"<?= ($v['party_type'] ?? '') === $k && (int) ($v['party_id'] ?? 0) === (int) $pid ? ' selected' : '' ?>><?= htmlspecialchars((string) $pname) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    <?php else: ?>
      <p style="grid-column:1/-1"><strong>With:</strong> <?= htmlspecialchars(\App\Repositories\AgreementRepository::PARTY_TYPES[$agreement['party_type']]) ?> — <?= htmlspecialchars((string) $partyName) ?></p>
    <?php endif; ?>
    <label>Type of agreement
      <select name="agreement_type" required>
        <option value="">Choose…</option>
        <?php foreach ($types as $t): ?><option value="<?= htmlspecialchars($t) ?>"<?= ($v['agreement_type'] ?? '') === $t ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Title <small class="muted">(optional)</small>
      <input type="text" name="title" maxlength="200" value="<?= $__val('title') ?>" placeholder="e.g. Granite supply 2026–2028">
    </label>
    <label>Signed on
      <input type="date" name="signed_on" required max="<?= date('Y-m-d') ?>" value="<?= $__val('signed_on') ?>">
    </label>
    <label>Starts on <small class="muted">(if different)</small>
      <input type="date" name="starts_on" value="<?= $__val('starts_on') ?>">
    </label>
    <label>Expires on <small class="muted">(leave empty if it never expires)</small>
      <input type="date" name="expires_on" value="<?= $__val('expires_on') ?>">
    </label>
    <label>Signed copy <small class="muted">(PDF, Word or a scan<?= $__isNew ? '' : ' — attach only to replace the copy on file' ?>)</small>
      <input type="file" name="agreement_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"<?= $__isNew ? ' required' : '' ?>>
    </label>
    <label style="grid-column:1/-1">Notes <small class="muted">(optional)</small>
      <textarea name="notes" maxlength="2000" rows="3" placeholder="e.g. Renewal needs 60 days' notice; exclusive for Georgia"><?= $__val('notes') ?></textarea>
    </label>
    </div>
    <div class="btn-row"><button type="submit" class="btn">Save agreement</button> <a class="btn-sm btn-secondary" href="/agreements">Cancel</a></div>
  </form>
</div>
<?php if (!$__isNew && empty($agreement['terminated_on'])): ?>
<div class="card">
  <h2>The agreement has ended early</h2>
  <p class="muted">Record it here so no more reminders go out. To continue with a new signed agreement, use Renew on the register instead.</p>
  <form method="post" action="/agreements/<?= (int) $agreement['id'] ?>/terminate" onsubmit="return confirm('Record this agreement as ended?');">
    <?= \App\Helpers\Csrf::field() ?>
    <div class="field-grid">
    <label>Ended on
      <input type="date" name="terminated_on" required value="<?= date('Y-m-d') ?>">
    </label>
    <label style="grid-column:1/-1">Reason
      <textarea name="terminated_reason" required minlength="5" maxlength="500" rows="2" placeholder="e.g. Supplier relationship ended by mutual consent"></textarea>
    </label>
    </div>
    <div class="btn-row"><button type="submit" class="btn-sm btn-danger">Record as ended</button></div>
  </form>
</div>
<?php endif; ?>
