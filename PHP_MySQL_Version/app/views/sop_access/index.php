<?php
use App\Helpers\Csrf;

/** Owner decision D-266: Admin › SOP access. Node twin: views/sop_access/index.njk. */
$__roleId = $role !== null ? (int) $role['id'] : 0;
?>
<div class="card page-wide">
  <h1>SOP access</h1>
  <p class="muted"><a href="/settings">&larr; Company Settings</a> · <a href="/sop">Open the SOP</a></p>

  <div class="alert alert-info" data-sop-library>
    <strong>SOP library: <?= $libraryOn ? 'On for staff' : 'Off' ?></strong> — off hides the SOP menu, every chapter, its screenshots and the Gallery from all staff except MD and Admin. Getting Started always stays open.
    <form method="post" action="/admin/sop-access/library" class="inline-form" style="margin-top:8px">
      <?= Csrf::field() ?>
      <input type="hidden" name="role_id" value="<?= $__roleId ?>">
      <input type="hidden" name="library" value="<?= $libraryOn ? 'off' : 'on' ?>">
      <button type="submit" class="btn-sm <?= $libraryOn ? 'btn-secondary' : '' ?>"><?= $libraryOn ? 'Switch the SOP library off' : 'Switch the SOP library on' ?></button>
    </form>
  </div>

  <form method="get" action="/admin/sop-access" class="field-grid">
    <label>Role *
      <select name="role_id" required>
        <option value="">Choose…</option>
        <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $__roleId === (int) $r['id'] ? ' selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Copy ticks from
      <select name="copy_from">
        <option value="">Choose…</option>
        <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $copyFrom === (int) $r['id'] ? ' selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Show
      <select name="show">
        <?php foreach ($showOptions as $k => $label): ?><option value="<?= htmlspecialchars($k) ?>"<?= $show === $k ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div><button type="submit" class="btn-sm">Show chapters</button></div>
  </form>

  <?php if ($role === null): ?>
    <p class="muted">Choose a role to tick the chapters it sees.</p>
  <?php else: ?>
    <?php if ($role['sees_all']): ?><div class="alert alert-warning">This role manages company settings, so it always sees every chapter. Ticks only matter if that permission is taken away.</div><?php endif; ?>
    <?php if ($copyFrom !== null): ?><div class="alert alert-warning">Ticks copied from another role — check them and press Save to keep them.</div><?php endif; ?>
    <form method="post" action="/admin/sop-access/chapter" id="sop-chapter-toggle"><?= Csrf::field() ?><input type="hidden" name="role_id" value="<?= $__roleId ?>"></form>
    <form method="post" action="/admin/sop-access/role" data-sop-role-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="role_id" value="<?= $__roleId ?>">
      <?php foreach ($hiddenTicked as $__s): ?><input type="hidden" name="slugs[]" value="<?= htmlspecialchars($__s) ?>"><?php endforeach; ?>
      <table class="list">
        <tr><th>Chapter (ticked = <?= htmlspecialchars($role['name']) ?> sees it)</th><th>Note</th><th>On for everyone</th></tr>
        <?php foreach ($rows as $row): ?>
          <tr data-sop-row="<?= htmlspecialchars($row['slug']) ?>">
            <td><label class="checkbox-inline"><input type="checkbox" name="slugs[]" value="<?= htmlspecialchars($row['slug']) ?>"<?= $row['ticked'] ? ' checked' : '' ?><?= $row['state'] === 'always' ? ' disabled' : '' ?>> <?= htmlspecialchars($row['title']) ?></label></td>
            <td><?php
              if ($row['state'] === 'always') { echo 'always shown'; }
              elseif ($row['state'] === 'new') { echo '<span class="badge badge-warning">New — only MD/Admin until ticked</span>'; }
              elseif ($row['state'] === 'off') { echo 'switched off for everyone'; }
              elseif ($row['ca']) { echo 'also needs CA permission'; }
            ?></td>
            <td><?php if ($row['state'] !== 'always'): ?>
              <?php $__on = $row['state'] === 'on'; ?>
              <button type="submit" form="sop-chapter-toggle" name="toggle" value="<?= htmlspecialchars($row['slug']) ?>:<?= $__on ? '0' : '1' ?>" class="btn-sm <?= $__on ? 'btn-secondary' : '' ?>"><?= $__on ? 'On — switch off' : ($row['state'] === 'new' ? 'Switch on' : 'Off — switch on') ?></button>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="3" class="muted">No chapters match.</td></tr><?php endif; ?>
      </table>
      <p class="muted small">MD and Admin always see every chapter, so nobody can hide the help from the people who manage it. Every change is written to the Audit Log.</p>
      <button type="submit">Save <?= htmlspecialchars($role['name']) ?></button>
    </form>
  <?php endif; ?>
</div>
