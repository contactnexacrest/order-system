<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Financial Year Lock</h1>
  <p class="muted">Once a financial year is locked here, every CA write path that touches that year's data — recording or removing an INR actual, the assumed exchange rate, a FIRC/eBRC reference, an expense's TDS annotation, and matching/unmatching a bank statement line — is blocked, so a closed year's numbers can't be quietly changed after the CA has signed off on them.</p>

  <div class="section">
    <h2>Financial Years</h2>
    <?php if (empty($years)): ?>
      <p class="muted">No financial year has any CA activity yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Financial Year</th><th>Status</th><th>Action</th></tr>
        <?php foreach ($years as $fy): ?>
          <?php $isLocked = in_array($fy, $lockedYears, true); ?>
          <tr>
            <td><?= htmlspecialchars($fy) ?></td>
            <td>
              <?php if ($isLocked): ?>
                <span style="color:var(--danger)">Locked</span>
              <?php else: ?>
                <span style="color:var(--success)">Open</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isLocked): ?>
                <form method="post" action="/ca/fy-locks/unlock" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                  <?= \App\Helpers\Csrf::field() ?>
                  <input type="hidden" name="financial_year" value="<?= htmlspecialchars($fy) ?>">
                  <input type="text" name="unlock_reason" placeholder="Reason for reopening *" required style="min-width:220px;">
                  <button type="submit" class="btn-sm btn-secondary" onclick="return confirm('Reopen FY <?= htmlspecialchars($fy) ?> for CA data entry?');">Unlock</button>
                </form>
              <?php else: ?>
                <form method="post" action="/ca/fy-locks/lock" onsubmit="return confirm('Lock FY <?= htmlspecialchars($fy) ?>? No CA data entry against that year will be accepted until it is reopened.');">
                  <?= \App\Helpers\Csrf::field() ?>
                  <input type="hidden" name="financial_year" value="<?= htmlspecialchars($fy) ?>">
                  <button type="submit" class="btn-sm">Lock</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Lock / Unlock History</h2>
    <p class="muted small">Independent of the main system audit log — every lock and unlock, who did it and (for an unlock) why, stays on record even after a year is reopened and re-locked.</p>
    <?php if (empty($history)): ?>
      <p class="muted">No financial year has ever been locked.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Financial Year</th><th>Locked</th><th>Unlocked</th><th>Unlock Reason</th></tr>
        <?php foreach ($history as $row): ?>
          <?php
            $lockedByName = $row['locked_by'] !== null ? ($usersById[(int) $row['locked_by']] ?? ('User #' . $row['locked_by'])) : null;
            $unlockedByName = $row['unlocked_by'] !== null ? ($usersById[(int) $row['unlocked_by']] ?? ('User #' . $row['unlocked_by'])) : null;
          ?>
          <tr>
            <td><?= htmlspecialchars($row['financial_year']) ?></td>
            <td><?= htmlspecialchars((string) $row['locked_at']) ?><?= $lockedByName ? ' by ' . htmlspecialchars($lockedByName) : '' ?></td>
            <td>
              <?php if ($row['unlocked_at'] !== null): ?>
                <?= htmlspecialchars((string) $row['unlocked_at']) ?><?= $unlockedByName ? ' by ' . htmlspecialchars($unlockedByName) : '' ?>
              <?php else: ?>
                <span class="muted">Still locked</span>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= htmlspecialchars((string) ($row['unlock_reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
