<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Email Templates</h1>
  <p class="muted">Templates are added or edited here, never deleted — every email already sent keeps its own frozen copy of exactly what it said, in the Email Log, regardless of what happens to the template afterward. Deactivating a template just stops it being offered for a new send.</p>
  <p><a href="/email-templates/create" class="btn-sm btn-accent">+ New Template</a></p>

  <div class="section">
    <table class="list">
      <tr><th>Key</th><th>Subject</th><th>Status</th><th>Action</th></tr>
      <?php if (empty($templates)): ?>
      <tr><td colspan="4" class="muted">No templates yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($templates as $t): ?>
      <tr>
        <td><code><?= htmlspecialchars($t['template_key']) ?></code></td>
        <td><?= htmlspecialchars($t['subject']) ?></td>
        <td><span class="badge <?= $t['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a class="btn-sm" href="/email-templates/<?= (int) $t['id'] ?>/edit">Edit</a>
          <form method="post" action="/email-templates/<?= (int) $t['id'] ?>/toggle-active" style="display:inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm"><?= $t['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
