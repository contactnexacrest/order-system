<?php
use App\Helpers\Csrf;
use App\Services\EmailPolicy;
use App\Services\StaffRouting;
/** D-280 / D-281: Admin › E-mail settings. Expects $matrix, $testingMode, $owners, $staff. */
?>
<div class="card" data-testid="email-settings">
  <h1>E-mail settings</h1>
  <p class="muted small">Each e-mail the app sends, and to whom.
    <span class="badge badge-must">MUST</span> = always sent, cannot be switched off (log-in and account access).
    <span class="badge badge-should">SHOULD</span> = on when installed.
    <span class="badge badge-optional">OPTIONAL</span> = off when installed.
    Every change needs a reason and is in the Audit Log. Each staff member can still turn off their own non-MUST e-mails under My Account.</p>

  <form method="post" action="/admin/email-settings">
    <?= Csrf::field() ?>
    <div class="alert alert-warning email-testing-mode">
      <label class="checkbox-label"><input type="checkbox" name="testing_mode" value="1" <?= $testingMode ? 'checked' : '' ?> data-testid="email-testing-mode">
        <b>Testing mode — hold all e-mails to buyers and suppliers</b></label>
      <p class="small" style="margin:4px 0 0">When ON, e-mails to buyers and suppliers are not sent (the screen says so and shows the link to copy). Staff and associate e-mails, and every MUST e-mail, still go out.</p>
    </div>

    <?php foreach (EmailPolicy::GROUPS as $group => $groupTitle): ?>
      <h2><?= htmlspecialchars($group . '. ' . $groupTitle) ?></h2>
      <div class="table-scroll">
      <table class="list email-settings-table">
        <tr><th>E-mail</th><th>Tier</th><?php foreach (EmailPolicy::AUDIENCES as $aud => $audLabel): ?><th class="text-center"><?= htmlspecialchars($audLabel) ?></th><?php endforeach; ?></tr>
        <?php foreach (EmailPolicy::KINDS as $kind => $def): if ($def['group'] !== $group) { continue; } ?>
          <?php $tiers = array_unique(array_values($def['audiences'])); $tierLabel = in_array('must', $tiers, true) ? 'must' : (in_array('should', $tiers, true) ? 'should' : 'optional'); ?>
          <tr data-email-kind="<?= htmlspecialchars($kind) ?>">
            <td><?= htmlspecialchars($def['label']) ?><?php if (!empty($def['access_critical'])): ?> <span class="muted small">(access-critical)</span><?php endif; ?></td>
            <td><span class="badge badge-<?= $tierLabel ?>"><?= strtoupper($tierLabel) ?></span></td>
            <?php foreach (EmailPolicy::AUDIENCES as $aud => $audLabel): $cell = $matrix[$kind][$aud] ?? null; ?>
              <td class="text-center">
                <?php if ($cell === null): ?><span class="muted">—</span>
                <?php elseif ($cell['tier'] === 'must'): ?><span class="badge badge-must" title="Always sent">&#128274; ON</span>
                <?php else: ?>
                  <label class="checkbox-label" style="justify-content:center"><input type="checkbox" name="on[<?= htmlspecialchars($kind . '|' . $aud) ?>]" value="1" <?= $cell['on'] ? 'checked' : '' ?> aria-label="<?= htmlspecialchars($def['label'] . ' — ' . $audLabel) ?>">
                    <?php if ($cell['tier'] === 'optional'): ?><span class="muted small">optional</span><?php endif; ?></label>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
    <?php endforeach; ?>
    <p class="muted small">Staff e-mails about an order go only to the people of the relevant department on that order (its department owner and the department's assignees). With nobody from that department on the order, the department's manager is e-mailed with an "assign someone" note; with no manager, the Managing Director. The Managing Director, Executive Director and Order Coordinator see routine events in the app only. Associate column for documents = a copy of the buyer's document e-mail to the introducing associate (only when their access level shows order documents).</p>

    <h2>E. Company compliance alerts — who is told</h2>
    <p class="muted small">Company-level alerts (not about one order) go by e-mail to the responsible person and the backup (for leave). Not set: the department's manager, then the Managing Director. Everyone who saw these alerts before still sees them in the app.</p>
    <div class="table-scroll">
    <table class="list">
      <tr><th>Alert</th><th>Responsible person</th><th>Backup person</th></tr>
      <?php foreach (StaffRouting::COMPANY_ITEMS as $item => [$itemLabel, $dept]): $o = $owners[$item]; ?>
        <tr>
          <td><?= htmlspecialchars($itemLabel) ?></td>
          <?php foreach (['responsible' => 'responsible_user_id', 'backup' => 'backup_user_id'] as $field => $col): ?>
            <td><select name="owners[<?= htmlspecialchars($item) ?>][<?= $field ?>]" aria-label="<?= htmlspecialchars($itemLabel . ' — ' . $field) ?>">
              <option value="">Not set (department manager, then MD)</option>
              <?php foreach ($staff as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) ($o[$col] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) $u['name']) ?></option><?php endforeach; ?>
            </select></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>

    <label>Reason for this change *<input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. Testing finished — buyer e-mails on again"></label>
    <button type="submit" data-testid="email-settings-save">Save</button>
  </form>
</div>
