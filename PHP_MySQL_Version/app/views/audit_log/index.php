<div class="card page-wide">
  <h1>Audit Log</h1>
  <p class="muted">Immutable — no user, including Admin, can edit or delete a row here.</p>

  <form method="get" action="/audit-log">
    <label>Entity Type<input type="text" name="entity_type" value="<?= htmlspecialchars($filters['entity_type'] ?? '') ?>" placeholder="e.g. orders, documents, amendments"></label>
    <label>Entity ID<input type="number" name="entity_id" value="<?= htmlspecialchars((string) ($filters['entity_id'] ?? '')) ?>"></label>
    <label>User ID<input type="number" name="user_id" value="<?= htmlspecialchars((string) ($filters['user_id'] ?? '')) ?>"></label>
    <label>Action Type
      <select name="action_type">
        <option value="">All</option>
        <?php foreach ($actionTypes as $at): ?>
          <option value="<?= htmlspecialchars($at) ?>" <?= $filters['action_type'] === $at ? 'selected' : '' ?>><?= htmlspecialchars($at) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"></label>
    <label>To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"></label>
    <button type="submit" class="btn-sm">Filter</button>
  </form>

  <table class="list">
    <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Field</th><th>Old</th><th>New</th><th>Reason</th><th>IP</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td><?= htmlspecialchars($r['user_name'] ?? 'system') ?></td>
        <td><?= htmlspecialchars($r['action_type']) ?></td>
        <td><?= htmlspecialchars(($r['entity_type'] ?? '') . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?></td>
        <td><?= htmlspecialchars($r['field_name'] ?? '') ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string) ($r['old_value'] ?? ''), 0, 40, '…')) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string) ($r['new_value'] ?? ''), 0, 40, '…')) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string) ($r['reason'] ?? ''), 0, 60, '…')) ?></td>
        <td><?= htmlspecialchars($r['ip_address'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
      <tr><td colspan="9" class="muted">No matching audit log entries.</td></tr>
    <?php endif; ?>
  </table>

  <p>
    <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?>
    &nbsp;Page <?= (int) $page ?>&nbsp;
    <?php if (count($rows) === $pageSize): ?><a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
  </p>
</div>
