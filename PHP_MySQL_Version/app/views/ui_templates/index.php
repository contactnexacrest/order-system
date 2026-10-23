<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>UI Templates</h1>
  <p class="muted">Each user picks one of these for themselves on their own Account page, or leaves it unset to follow the company default (<a href="/settings">Settings &gt; UI</a>). Deactivating a template here doesn't delete anything — anyone currently using it (by personal preference or as the company default) automatically falls back to the company default, or to Standard if that's also inactive.</p>
  <p class="muted small">"Standard" can never be deactivated — it's the one template that's always available, so nobody can ever be locked out.</p>

  <div class="section">
    <table class="list">
      <tr><th>Key</th><th>Name</th><th>Status</th><th>Action</th></tr>
      <?php foreach ($templates as $t): ?>
      <tr>
        <td><code><?= htmlspecialchars($t['template_key']) ?></code></td>
        <td><?= htmlspecialchars($t['name']) ?></td>
        <td><span class="badge <?= $t['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <?php if ($t['template_key'] === 'standard' && $t['is_active']): ?>
            <button type="button" class="btn-sm" disabled title="Standard can never be deactivated">Deactivate</button>
          <?php else: ?>
            <form method="post" action="/ui-templates/<?= (int) $t['id'] ?>/toggle-active" style="display:inline">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm"><?= $t['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
