<div class="card page-wide">
  <h1>Reference Library</h1>
  <p class="muted">Stage Gate conditions, hard-rules quick reference, the cross-verification checklist, and the sales-process SOPs — kept here so any staff member can look them up without asking around.</p>

  <table class="list">
    <tr><th>Document</th><th>Last Updated</th><th>Action</th></tr>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['name']) ?></td>
        <td><?= $d['updated_at'] ? htmlspecialchars($d['updated_at']) : '<span class="muted">not yet entered</span>' ?></td>
        <td><a href="/reference-docs/<?= htmlspecialchars($d['code']) ?>" class="btn-sm">View</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
