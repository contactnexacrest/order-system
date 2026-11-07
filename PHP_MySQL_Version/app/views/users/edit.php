<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/users">&larr; Back to Users</a></p>
  <h1>Edit User — <?= htmlspecialchars($target['name']) ?></h1>
  <form method="post" action="/users/<?= (int) $target['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="field-grid">
      <label>Name *
        <input type="text" name="name" value="<?= htmlspecialchars($target['name']) ?>" placeholder="e.g., Export Executive" required>
      </label>
      <label>Email *
        <input type="email" name="email" value="<?= htmlspecialchars($target['email']) ?>" placeholder="e.g., name@example.com" required>
      </label>
      <label>Phone
        <input type="text" name="phone" value="<?= htmlspecialchars($target['phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
      </label>
      <label>Role
        <select name="role_id">
          <option value="">— none —</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) ($target['role_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <?php // D-259 (owner approval 6 Nov 2026): the team incentive switch; only the MD changes it.
    $__first = explode(' ', (string) $target['name'])[0]; ?>
    <fieldset id="incentive-eligible"><legend>Team incentive</legend>
      <?php if (!empty($incentiveCanEdit)): ?><input type="hidden" name="incentive_eligible_present" value="1"><?php endif; ?>
      <label><input type="checkbox" name="incentive_eligible" value="1"<?= !empty($incentiveEligible) ? ' checked' : '' ?><?= !empty($incentiveCanEdit) ? '' : ' disabled' ?>> Eligible for team incentive</label>
      <p class="muted small">On by default. Off: <?= htmlspecialchars($__first) ?> gets no share of any order's team incentive; the share stays with the company.
        <?php if (!empty($incentiveDesignation)): ?>Their designation (<?= htmlspecialchars($incentiveDesignation['title']) ?>) is <?= $incentiveDesignation['eligible'] ? 'eligible' : 'not eligible' ?> — this switch overrides it for them only.<?php endif; ?>
        Applies to orders whose incentive is not locked yet; locked and paid orders never change.<?= !empty($incentiveCanEdit) ? '' : ' <strong>Only the MD can change this.</strong>' ?></p>
    </fieldset>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/users" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<div class="card page-wide" id="departments">
  <h2>Departments</h2>
  <p class="muted small"><?= htmlspecialchars($target['name']) ?> chooses one of these at "Log in as". Add or end a membership here — that is the only day-to-day change. With no department, the role above applies.</p>
  <table class="list">
    <tr><th>Department</th><th>Designation (role)</th><th>From</th><th>Until</th><th></th></tr>
    <?php foreach ($memberships as $m): $ended = $m['ends_on'] !== null && $m['ends_on'] < date('Y-m-d'); ?>
    <tr<?= $ended ? ' class="muted"' : '' ?>>
      <td><span class="dept-tag<?= $ended ? ' off' : '' ?>" style="--dept-color:<?= htmlspecialchars((string) $m['department_color']) ?>"><?= htmlspecialchars((string) $m['department_name']) ?></span></td>
      <td><?= htmlspecialchars((string) $m['designation_title']) ?> <span class="muted small">(<?= htmlspecialchars((string) $m['role_name']) ?>)</span><?= (int) $m['is_default'] === 1 ? ' <span class="badge badge-active">starts here</span>' : '' ?></td>
      <td><?= htmlspecialchars((string) $m['starts_on']) ?></td>
      <td><?= $m['ends_on'] !== null ? htmlspecialchars((string) $m['ends_on']) : '—' ?></td>
      <td>
        <?php if (!$ended): ?>
        <form method="post" action="/users/<?= (int) $target['id'] ?>/memberships/<?= (int) $m['id'] ?>/end" class="dept-end" onsubmit="return confirm('End this membership on the date given?');">
          <?= \App\Helpers\Csrf::field() ?>
          <input type="hidden" name="starts_on" value="<?= htmlspecialchars((string) $m['starts_on']) ?>">
          <input type="date" name="ends_on" value="<?= date('Y-m-d') ?>" required aria-label="Last day" data-not-before="[name=starts_on]" data-not-before-message="The last day cannot be before the membership started.">
          <button type="submit" class="btn-sm btn-danger">End</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($memberships === []): ?><tr><td colspan="5" class="muted">No department yet — this person works with their role above.</td></tr><?php endif; ?>
  </table>
  <form method="post" action="/users/<?= (int) $target['id'] ?>/memberships" class="field-grid" style="margin-top:12px">
    <?= \App\Helpers\Csrf::field() ?>
    <label>Department and designation *
      <select name="designation_id" required>
        <option value="">— choose —</option>
        <?php $group = null; foreach ($designations as $d): ?>
          <?php if ($group !== $d['department_name']): ?><?= $group !== null ? '</optgroup>' : '' ?><optgroup label="<?= htmlspecialchars((string) $d['department_name']) ?>"><?php $group = $d['department_name']; endif; ?>
          <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars((string) $d['title']) ?></option>
        <?php endforeach; ?><?= $group !== null ? '</optgroup>' : '' ?>
      </select>
    </label>
    <label>From *
      <input type="date" name="starts_on" value="<?= date('Y-m-d') ?>" required>
    </label>
    <div class="btn-row"><button type="submit" class="btn-sm btn-accent">+ Add membership</button></div>
  </form>
</div>

<?php if (\App\Services\DivisionService::isMulti()): // D-257: shown only with two or more active divisions ?>
<div class="card" data-testid="user-division">
  <h2>Division</h2>
  <p class="muted">The division this person works in; they see only its orders, clients and reports. Company level: sees every division (needs "See all divisions").</p>
  <form method="post" action="/users/<?= (int) $target['id'] ?>/division" class="field-grid">
    <?= \App\Helpers\Csrf::field() ?>
    <label>Division
        <select name="division_id">
          <option value=""<?= ($target['division_id'] ?? null) === null ? ' selected' : '' ?>>Company level (all divisions)</option>
          <?php foreach (\App\Services\DivisionService::all() as $__div): ?>
            <option value="<?= (int) $__div['id'] ?>"<?= (int) ($target['division_id'] ?? 0) === (int) $__div['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $__div['name']) ?><?= $__div['status'] === 'active' ? '' : ' (planned)' ?></option>
          <?php endforeach; ?>
        </select>
    </label>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save division</button></div>
  </form>
</div>
<?php endif; ?>
<?php $__partyType = "staff"; $__partyId = (int) $target["id"]; require __DIR__ . "/../agreements/_party_card.php"; ?>
<?php require __DIR__ . '/_sop_extras.php'; // D-266 ?>
