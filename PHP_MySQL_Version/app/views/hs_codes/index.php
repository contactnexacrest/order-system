<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>HS Code Master List</h1>
  <p class="muted">Order creation's HS Code field only offers codes from this list — a new code has to be added here first. Format is strict: exactly 6 or 8 digits, no dots or other characters, matching the real Indian HS code convention.</p>

  <div class="section">
    <table class="list">
      <tr><th>Code</th><th>Description</th><th>Status</th><th>Action</th></tr>
      <?php if (empty($codes)): ?>
      <tr><td colspan="4" class="muted">No HS codes yet — add the first one below.</td></tr>
      <?php endif; ?>
      <?php foreach ($codes as $c): ?>
      <tr>
        <td><code><?= htmlspecialchars($c['code']) ?></code></td>
        <td>
          <form method="post" action="/hs-codes/<?= (int) $c['id'] ?>/update" style="display:flex;gap:8px;align-items:center">
            <?= Csrf::field() ?>
            <input type="text" name="description" value="<?= htmlspecialchars($c['description']) ?>" required style="width:320px">
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
</div>
