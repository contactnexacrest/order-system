<?php /** The status filter, shared by the Standard list and the Kanban board (owner hard rule 2026-11-01). Expects $statuses, $status, $listOfBoard. */ ?>
  <form method="get" action="/procurement" class="btn-row">
    <?php if (!empty($listOfBoard)): ?><input type="hidden" name="view" value="list"><?php endif; ?>
    <label>Status
      <select name="status" onchange="this.form.submit()">
        <option value="">All open</option>
        <?php foreach ($statuses as $key => $label): ?><option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <a class="btn-sm btn-secondary" href="/procurement/suppliers">Supplier performance</a>
  </form>
