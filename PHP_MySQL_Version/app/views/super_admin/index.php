<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Super Admin</h1>
  <p class="muted">The tier above Admin/Managing Director: unrestricted everywhere, including self-approval on every gate that otherwise requires a different privileged user. A Super Admin account can never be deactivated or deleted. Temporary delegation lets a Super Admin loan this capability to an Admin for a period, without changing that Admin's actual role — revocable at any time.</p>

  <div class="section">
    <h2>Permanent Super Admins</h2>
    <table class="list">
      <tr><th>Name</th><th>Email</th><th>Action</th></tr>
      <?php foreach ($superAdmins as $sa): ?>
      <tr>
        <td><?= htmlspecialchars($sa['name']) ?><?php if ((int) $sa['is_protected_account'] === 1): ?> <span class="badge badge-protected">Protected founder</span><?php endif; ?></td>
        <td><?= htmlspecialchars($sa['email']) ?></td>
        <td>
          <?php if ((int) $sa['is_protected_account'] === 1): ?>
            <span class="muted small">Protected founder account — Super Admin status can never be removed.</span>
          <?php elseif (count($superAdmins) > 1): ?>
          <form method="post" action="/super-admin/set-permanent" style="display:flex;gap:4px;align-items:center" onsubmit="return confirm('Remove Super Admin status from this person?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="target_id" value="<?= (int) $sa['id'] ?>">
            <input type="hidden" name="is_super_admin" value="0">
            <input type="text" name="reason" placeholder="Reason (min <?= $minReasonLength ?> chars)" required minlength="<?= $minReasonLength ?>" style="width:220px">
            <button type="submit" class="btn-sm btn-danger">Remove</button>
          </form>
          <?php else: ?>
            <span class="muted small">Last remaining Super Admin — promote someone else first</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <h3 style="margin-top:16px">Promote a user to permanent Super Admin</h3>
    <form method="post" action="/super-admin/set-permanent" style="display:flex;gap:4px;align-items:center">
      <?= Csrf::field() ?>
      <select name="target_id">
        <?php foreach ($allUsers as $u): if ($u['is_super_admin']) continue; ?>
          <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="is_super_admin" value="1">
      <input type="text" name="reason" placeholder="Reason (min <?= $minReasonLength ?> chars)" required minlength="<?= $minReasonLength ?>" style="width:220px">
      <button type="submit" class="btn-sm btn-success">Promote</button>
    </form>
  </div>

  <div class="section">
    <h2>Active Delegations</h2>
    <table class="list">
      <tr><th>Delegate</th><th>Granted By</th><th>Reason</th><th>Granted</th><th>Expires</th><th>Action</th></tr>
      <?php foreach ($activeDelegations as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['delegate_name']) ?><br><span class="muted small"><?= htmlspecialchars($d['delegate_email']) ?></span></td>
        <td><?= htmlspecialchars($d['granted_by_name']) ?></td>
        <td><?= htmlspecialchars($d['reason']) ?></td>
        <td><?= htmlspecialchars((string) $d['granted_at']) ?></td>
        <td><?= $d['expires_at'] ? htmlspecialchars((string) $d['expires_at']) : 'No fixed expiry' ?></td>
        <td>
          <form method="post" action="/super-admin/delegations/<?= (int) $d['id'] ?>/revoke" style="display:flex;gap:4px;align-items:center" onsubmit="return confirm('Revoke this Super Admin delegation immediately? They lose the unrestricted access right away.');">
            <?= Csrf::field() ?>
            <input type="text" name="reason" placeholder="Reason (min <?= $minReasonLength ?> chars)" required minlength="<?= $minReasonLength ?>" style="width:180px">
            <button type="submit" class="btn-sm btn-danger">Revoke</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($activeDelegations)): ?>
        <tr><td colspan="6" class="muted">No active delegations.</td></tr>
      <?php endif; ?>
    </table>

    <h3 style="margin-top:16px">Grant a new delegation</h3>
    <form method="post" action="/super-admin/delegations" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
      <?= Csrf::field() ?>
      <select name="delegate_user_id">
        <?php foreach ($allUsers as $u): if ($u['is_super_admin']) continue; ?>
          <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> — <?= htmlspecialchars($u['role_name'] ?? 'No role') ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="reason" placeholder="Reason (min <?= $minReasonLength ?> chars) — e.g. covering approvals while MD is on leave" required minlength="<?= $minReasonLength ?>" style="width:320px">
      <input type="datetime-local" name="expires_at" title="Leave blank for no fixed expiry — still revocable at any time">
      <button type="submit" class="btn-sm btn-success">Grant</button>
    </form>
  </div>

  <div class="section">
    <h2>Delegation History</h2>
    <table class="list">
      <tr><th>Delegate</th><th>Granted By</th><th>Granted</th><th>Ended</th><th>Ended By</th><th>End Reason</th></tr>
      <?php foreach ($history as $h): ?>
      <tr>
        <td><?= htmlspecialchars($h['delegate_name']) ?></td>
        <td><?= htmlspecialchars($h['granted_by_name']) ?></td>
        <td><?= htmlspecialchars((string) $h['granted_at']) ?></td>
        <td><?= $h['revoked_at'] ? htmlspecialchars((string) $h['revoked_at']) : 'Expired (' . htmlspecialchars((string) $h['expires_at']) . ')' ?></td>
        <td><?= $h['revoked_by_name'] ? htmlspecialchars($h['revoked_by_name']) : '—' ?></td>
        <td><?= $h['revoked_reason'] ? htmlspecialchars($h['revoked_reason']) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($history)): ?>
        <tr><td colspan="6" class="muted">No past delegations.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
