<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Field Protection</h1>
  <p class="muted">One shared mechanism, reused across Company Settings, T&amp;C Clauses, and Payment Presets. A <span class="badge badge-protected">protected</span> field cannot be edited without an explicit unlock, and cannot be blanked or deleted even at the database level. Protecting or unprotecting a field is never a single person's decision: request it below, then a <strong>different</strong> privileged user must approve before anything actually changes.</p>

  <div class="section">
    <h2>Pending Requests</h2>
    <table class="list">
      <tr><th>#</th><th>Field</th><th>Requested</th><th>Reason</th><th>Requested By</th><th>When</th><th>Action</th></tr>
      <?php foreach ($pending as $r): ?>
      <tr>
        <td><?= (int) $r['id'] ?></td>
        <td><?= htmlspecialchars($r['table_name']) ?> — <?= htmlspecialchars($r['record_label']) ?></td>
        <td><?= $r['requested_action'] === 'lock' ? 'Lock' : 'Unlock' ?></td>
        <td><?= htmlspecialchars($r['reason']) ?></td>
        <td><?= htmlspecialchars($r['requested_by_name']) ?></td>
        <td><?= htmlspecialchars((string) $r['created_at']) ?></td>
        <td>
          <?php if ((int) $r['requested_by'] === $currentUserId && !$isSuperAdmin): ?>
            <span class="muted small">Awaiting a different privileged user</span>
          <?php else: ?>
            <form method="post" action="/admin/field-protection/<?= (int) $r['id'] ?>/approve" style="display:inline">
              <?= Csrf::field() ?>
              <input type="text" name="resolved_reason" placeholder="Note (optional)" style="width:140px">
              <button type="submit" class="btn-sm btn-success">Approve</button>
            </form>
            <form method="post" action="/admin/field-protection/<?= (int) $r['id'] ?>/reject" style="display:inline">
              <?= Csrf::field() ?>
              <input type="text" name="resolved_reason" placeholder="Reason (mandatory)" required style="width:140px">
              <button type="submit" class="btn-sm btn-danger">Reject</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
        <tr><td colspan="7" class="muted">No pending requests.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <?php foreach ($protectable as $tableName => $rows): ?>
  <div class="section">
    <h2>
      <?php if ($tableName === 'company_settings'): ?>Company Settings
      <?php elseif ($tableName === 'tc_clauses'): ?>T&amp;C Clauses
      <?php elseif ($tableName === 'payment_presets'): ?>Payment Presets
      <?php else: ?><?= htmlspecialchars($tableName) ?>
      <?php endif; ?>
    </h2>
    <table class="list">
      <tr><th>Field</th><th>Status</th><th>Request</th></tr>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= htmlspecialchars((string) $row['label']) ?></td>
        <td><?php if ($row['is_protected']): ?><span class="badge badge-protected">&#128274; protected</span><?php else: ?><span class="muted">unprotected</span><?php endif; ?></td>
        <td>
          <form method="post" action="/admin/field-protection/request" style="display:inline-flex; gap:.4rem; align-items:center">
            <?= Csrf::field() ?>
            <input type="hidden" name="table_name" value="<?= htmlspecialchars($tableName) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="record_label" value="<?= htmlspecialchars((string) $row['label']) ?>">
            <input type="hidden" name="action" value="<?= $row['is_protected'] ? 'unlock' : 'lock' ?>">
            <input type="text" name="reason" placeholder="Reason (mandatory)" required style="width:200px">
            <button type="submit" class="btn-sm"><?= $row['is_protected'] ? 'Request Unprotect' : 'Request Protect' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="section">
    <h2>Recent Decisions</h2>
    <table class="list">
      <tr><th>#</th><th>Field</th><th>Requested</th><th>Status</th><th>By</th><th>Resolved By</th><th>When</th></tr>
      <?php foreach ($resolved as $r): ?>
      <tr>
        <td><?= (int) $r['id'] ?></td>
        <td><?= htmlspecialchars($r['table_name']) ?> — <?= htmlspecialchars($r['record_label']) ?></td>
        <td><?= $r['requested_action'] === 'lock' ? 'Lock' : 'Unlock' ?></td>
        <td><?= htmlspecialchars($r['status']) ?></td>
        <td><?= htmlspecialchars($r['requested_by_name']) ?></td>
        <td><?= htmlspecialchars($r['resolved_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars((string) ($r['resolved_at'] ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($resolved)): ?>
        <tr><td colspan="7" class="muted">No resolved requests yet.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
