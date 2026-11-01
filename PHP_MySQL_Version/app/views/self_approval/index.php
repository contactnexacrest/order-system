<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Self-approval rules</h1>
  <p class="muted">Normally a second person approves what someone else created. Tick the roles that may approve their <b>own</b> work for each rule. A person qualifies when any of their roles (their main role, the department they are logged in as, or a department membership) is ticked. Every self-approval is written to the audit log; leave a rule empty so that nobody self-approves. Only Super Admins can change this page; every change is logged.</p>
  <form method="post" action="/admin/self-approval">
    <?= Csrf::field() ?>
    <?php foreach ($rules as $key => $label): ?>
      <fieldset id="rule-<?= htmlspecialchars($key) ?>">
        <legend><?= htmlspecialchars($label) ?></legend>
        <div class="field-grid">
          <?php foreach ($roles as $r): ?>
            <label class="checkbox"><input type="checkbox" name="<?= htmlspecialchars($key) ?>[]" value="<?= (int) $r['id'] ?>"<?= in_array((int) $r['id'], $current[$key], true) ? ' checked' : '' ?>> <?= htmlspecialchars((string) $r['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endforeach; ?>
    <div class="btn-row"><button type="submit">Save rules</button></div>
  </form>
</div>
