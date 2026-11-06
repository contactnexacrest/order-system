<?php
/** @var array<string, array<string,mixed>> $jobs @var array<string,string> $settings @var list<string> $problems */
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$badge = ['ok' => ['badge-success', 'OK'], 'stale' => ['badge-danger', 'Too old'], 'failed' => ['badge-danger', 'Failed'], 'missing' => ['badge-warning', 'No run yet'], 'not_set' => ['badge-warning', 'Not set up']];
?>
<div class="card page-wide">
  <h1>Backups</h1>
  <p class="muted">Every night the server makes an extra copy of the database and of every order document. The copies only read the live system — nothing live is ever changed. This page only shows what the backup jobs report; it cannot start, change or delete a backup. How to restore: <a href="/sop/32-backups-restore">SOP — Backups and restore</a>.</p>

  <?php if ($problems !== []): ?>
    <div class="alert alert-danger" role="alert"><strong>Needs attention</strong>
      <ul><?php foreach ($problems as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?></ul>
    </div>
  <?php elseif ($settings['backup_dir'] !== ''): ?>
    <div class="alert alert-success" role="status">All backup jobs ran on time.</div>
  <?php endif; ?>

  <div class="section">
    <h2>Last runs</h2>
    <div class="table-wrap"><table class="table" id="backup-jobs">
      <thead><tr><th>Job</th><th>Status</th><th>Finished</th><th>Copy</th><th>Size</th><th>Encrypted</th><th>Off-site</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $row): $s = $row['status'] ?? []; [$cls, $txt] = $badge[$row['state']]; ?>
        <tr data-job="<?= $h($row['job']) ?>">
          <td><?= $h($row['label']) ?></td>
          <td><span class="badge <?= $cls ?>"><?= $txt ?></span></td>
          <td><?= $s ? $h(date('j M Y, H:i', strtotime((string) $s['finished_at']) ?: 0)) . ($row['age_hours'] !== null ? '<br><small class="muted">' . $h(\App\Services\BackupStatusService::ago((int) $row['age_hours'])) . '</small>' : '') : '—' ?></td>
          <td><?= $s ? $h($s['file'] ?? '') : '—' ?></td>
          <td><?= $s ? $h(\App\Services\BackupStatusService::humanSize((int) ($s['size_bytes'] ?? 0))) : '—' ?></td>
          <td><?= $row['job'] === 'restore_test' ? '—' : ($s ? $h($s['encrypted'] ?? 'no') : '—') ?></td>
          <td><?= $row['job'] === 'restore_test' ? '—' : ($s ? $h($s['offsite'] ?? '') : '—') ?></td>
          <td><?= $h($row['message']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="section">
    <h2>Settings (app/.env)</h2>
    <p class="muted">Changed on the server in <code>app/.env</code>, not here. The cron lines only start the jobs.</p>
    <div class="kv-grid" id="backup-settings">
      <div><span class="k">Where copies are kept (BACKUP_DIR)</span><?= $settings['backup_dir'] !== '' ? $h($settings['backup_dir']) : '<strong>Not set — no backups are being made</strong>' ?></div>
      <div><span class="k">Keep for (BACKUP_KEEP_YEARS)</span><?= $h($settings['keep_years']) ?> years</div>
      <div><span class="k">One copy per month after (BACKUP_THIN_AFTER_DAYS)</span><?= $settings['thin_after_days'] === '0' ? 'Never — every nightly database copy is kept' : $h($settings['thin_after_days']) . ' days (database copies; documents are stored once each)' ?></div>
      <div><span class="k">Encryption (BACKUP_ENCRYPTION_KEY_FILE)</span><?= $settings['encryption_key_file'] !== '' ? 'On (key file set — keep a copy of the key somewhere safe, away from the server)' : '<strong>Off</strong> — set a key file' ?></div>
      <div><span class="k">Off-site copy (BACKUP_OFFSITE_TARGET)</span><?= $settings['offsite_target'] !== '' ? $h($settings['offsite_target']) : 'Not chosen yet — copies stay on this server only' ?></div>
    </div>
  </div>
</div>
