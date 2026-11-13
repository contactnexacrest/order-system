<?php use App\Helpers\Csrf;
$columns = ['executive' => 'Executive', 'manager' => 'Manager', 'director' => 'Director', 'other' => 'Other']; ?>
<div class="card page-wide">
  <h1>Departments &amp; designations</h1>
  <p class="muted">Each department has its own ladder. Each designation is its own role (its permissions and money view). To give someone access, add them to a department on their user page — no permission editing needed.</p>
  <?php // D-259: who shares in the team incentive. ?>
  <p class="muted small" id="incentive-eligibility-note">Each designation says whether its people share in the team incentive ("Incentive eligible"; only the MD changes it). A person's own switch (Edit User) overrides this for that person only.
    <?php if (!empty($incentiveOverrides)): ?><br><strong>People overriding their designation:</strong> <?= implode('; ', array_map(static fn (array $o): string => htmlspecialchars($o['name'] . ' (' . ($o['designation'] ?? 'no designation') . ', switched ' . ($o['eligible'] ? 'on' : 'off') . ')'), $incentiveOverrides)) ?>.<?php endif; ?></p>

  <div class="table-scroll">
  <table class="list dept-ladder">
    <tr><th>Department</th><?php foreach ($columns as $label): ?><th><?= $label ?></th><?php endforeach; ?></tr>
    <?php foreach ($departments as $dept): $list = $byDepartment[(int) $dept['id']] ?? []; ?>
    <tr>
      <td><span class="dept-tag<?= (int) $dept['is_active'] === 1 ? '' : ' off' ?>" style="--dept-color:<?= htmlspecialchars((string) $dept['color']) ?>"><?= htmlspecialchars((string) $dept['name']) ?><?= (int) $dept['is_active'] === 1 ? '' : ' (off)' ?></span>
        <?php if ((int) $dept['sees_whole_company'] === 1): ?><br><small class="muted">sees the whole company</small><?php endif; ?></td>
      <?php foreach (array_keys($columns) as $level): ?>
      <td>
        <?php foreach ($list as $d): if ($d['level'] !== $level) { continue; } ?>
          <div class="desig">
            <strong><?= htmlspecialchars((string) $d['title']) ?></strong><?= (int) $d['is_active'] === 1 ? '' : ' <span class="badge badge-inactive">off</span>' ?>
            <small><?= (int) $d['member_count'] ?> <?= (int) $d['member_count'] === 1 ? 'person' : 'people' ?> · role: <a href="/admin/roles/<?= (int) $d['role_id'] ?>/permissions"><?= htmlspecialchars((string) $d['role_name']) ?></a><?= $d['approval_limit_inr'] !== null ? ' · approves up to ₹' . number_format((float) $d['approval_limit_inr'], 0) : '' ?></small>
            <form method="post" action="/admin/preview-as" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="designation_id" value="<?= (int) $d['id'] ?>"><button type="submit" class="btn-link" title="See the app exactly as a <?= htmlspecialchars((string) $d['title']) ?> sees it">Preview the app as this</button></form>
          </div>
        <?php endforeach; ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>

  <div class="section">
    <h2>Add a designation</h2>
    <form method="post" action="/admin/designations" class="field-grid">
      <?= Csrf::field() ?>
      <label>Department *
        <select name="department_id" required>
          <option value="">Choose…</option>
          <?php foreach ($departments as $dept): ?><option value="<?= (int) $dept['id'] ?>"><?= htmlspecialchars((string) $dept['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Designation *
        <input type="text" name="title" required minlength="2" maxlength="100" placeholder="e.g., Quality Manager">
      </label>
      <label>Level *
        <select name="level" required>
          <option value="">Choose…</option>
          <?php foreach ($levels as $key => $label): if ($key === 'director' && empty($canLimits)) { continue; } ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Role (its permissions) *
        <select name="role_id" required>
          <option value="">Choose…</option>
          <option value="new">A new role with the same name (set its permissions next)</option>
          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars((string) $r['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="btn-row"><button type="submit" class="btn-sm btn-accent">+ Add designation</button></div>
    </form>
  </div>

  <div class="section">
    <h2>Add a department</h2>
    <form method="post" action="/admin/departments" class="field-grid">
      <?= Csrf::field() ?>
      <label>Name *
        <input type="text" name="name" required minlength="2" maxlength="100" placeholder="e.g., Quality Control">
      </label>
      <label>Short code *
        <input type="text" name="code" required pattern="[A-Za-z][A-Za-z0-9_]{1,19}" maxlength="20" placeholder="e.g., QUALITY" title="2 to 20 letters, digits or _, starting with a letter">
      </label>
      <label>Colour *
        <input type="color" name="color" value="#3a4140" required>
      </label>
      <div class="btn-row"><button type="submit" class="btn-sm btn-accent">+ Add department</button></div>
    </form>
  </div>

  <div class="section">
    <h2>Edit</h2>
    <?php foreach ($departments as $dept): $list = $byDepartment[(int) $dept['id']] ?? []; ?>
    <details class="card-nested dept-edit" id="department-<?= (int) $dept['id'] ?>">
      <summary><span class="dot" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= htmlspecialchars((string) $dept['color']) ?>"></span><?= htmlspecialchars((string) $dept['name']) ?> <span class="muted small"><?= htmlspecialchars((string) $dept['code']) ?> · <?= count($list) ?> designation<?= count($list) === 1 ? '' : 's' ?></span></summary>
      <form method="post" action="/admin/departments/<?= (int) $dept['id'] ?>/update" class="field-grid">
        <?= Csrf::field() ?>
        <label>Name *<input type="text" name="name" value="<?= htmlspecialchars((string) $dept['name']) ?>" required minlength="2" maxlength="100" placeholder="e.g. Logistics"></label>
        <label>Colour *<input type="color" name="color" value="<?= htmlspecialchars((string) $dept['color']) ?>" required></label>
        <label class="inline"><input type="checkbox" name="is_active" value="1" <?= (int) $dept['is_active'] === 1 ? 'checked' : '' ?>> Active (people can log in as it)</label>
        <label class="inline"><input type="checkbox" name="sees_whole_company" value="1" <?= (int) $dept['sees_whole_company'] === 1 ? 'checked' : '' ?>> Sees the whole company</label>
        <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save department</button></div>
      </form>
      <?php if (\App\Services\DivisionService::isMulti()): // D-257: shown only with two or more active divisions ?>
      <form method="post" action="/admin/departments/<?= (int) $dept['id'] ?>/division" class="field-grid" data-testid="department-division">
        <?= Csrf::field() ?>
        <label>Division
        <select name="division_id">
          <option value=""<?= ($dept['division_id'] ?? null) === null ? ' selected' : '' ?>>Company level (all divisions)</option>
          <?php foreach (\App\Services\DivisionService::all() as $__div): ?>
            <option value="<?= (int) $__div['id'] ?>"<?= (int) ($dept['division_id'] ?? 0) === (int) $__div['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $__div['name']) ?><?= $__div['status'] === 'active' ? '' : ' (planned)' ?></option>
          <?php endforeach; ?>
        </select>
        </label>
        <div class="btn-row"><button type="submit" class="btn-sm">Save division</button></div>
      </form>
      <?php endif; ?>
      <?php if ($list !== []): ?>
      <table class="list">
        <tr><th>Designation</th><th>Level</th><th>Approval limit (₹)</th><th>Incentive eligible</th><th>Active</th><th></th></tr>
        <?php foreach ($list as $d): $formId = 'designation-' . (int) $d['id']; ?>
        <tr>
          <td><input form="<?= $formId ?>" type="text" name="title" value="<?= htmlspecialchars((string) $d['title']) ?>" required minlength="2" maxlength="100" placeholder="e.g. Logistics Executive" aria-label="Title"></td>
          <?php // QA Round 8, R8-N10: the level and approval limit change only with "Manage approval limits" (MD, ED), never on one's own designation. ?>
          <?php $__lim = !empty($canLimits) && !in_array((int) $d['role_id'], $ownRoleIds ?? [], true); ?>
          <?php if ($__lim): ?>
          <td><select form="<?= $formId ?>" name="level" required aria-label="Designation level"><?php foreach ($levels as $key => $label): ?><option value="<?= $key ?>" <?= $d['level'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></td>
          <td><input form="<?= $formId ?>" type="number" name="approval_limit_inr" min="0" step="0.01" value="<?= $d['approval_limit_inr'] !== null ? htmlspecialchars((string) $d['approval_limit_inr']) : '' ?>" placeholder="none" aria-label="Approval limit (INR)"></td>
          <?php else: ?>
          <td><input form="<?= $formId ?>" type="hidden" name="level" value="<?= htmlspecialchars((string) $d['level']) ?>"><?= htmlspecialchars($levels[$d['level']] ?? (string) $d['level']) ?></td>
          <td><input form="<?= $formId ?>" type="hidden" name="approval_limit_inr" value="<?= $d['approval_limit_inr'] !== null ? htmlspecialchars((string) $d['approval_limit_inr']) : '' ?>"><?= $d['approval_limit_inr'] !== null ? number_format((float) $d['approval_limit_inr'], 2) : 'none' ?><br><small class="muted"><?= empty($canLimits) ? 'Only the MD or ED changes approval limits.' : 'Your own designation — another MD/ED changes it.' ?></small></td>
          <?php endif; ?>
          <?php // D-259 (owner approval 6 Nov 2026): whether this designation's people share in the team incentive (MD only). ?>
          <td><?php if (!empty($incentiveCanEdit)): ?><input form="<?= $formId ?>" type="hidden" name="incentive_eligible_present" value="1"><?php endif; ?>
            <label class="small"><input form="<?= $formId ?>" type="checkbox" name="incentive_eligible" value="1" <?= (int) ($d['incentive_eligible'] ?? 1) === 1 ? 'checked' : '' ?><?= !empty($incentiveCanEdit) ? '' : ' disabled' ?> aria-label="<?= htmlspecialchars((string) $d['title']) ?> incentive eligible"> <?= (int) ($d['incentive_eligible'] ?? 1) === 1 ? 'Eligible' : 'Not eligible' ?></label></td>
          <td><input form="<?= $formId ?>" type="checkbox" name="is_active" value="1" <?= (int) $d['is_active'] === 1 ? 'checked' : '' ?> aria-label="Active"></td>
          <td><form id="<?= $formId ?>" method="post" action="/admin/designations/<?= (int) $d['id'] ?>/update"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-success">Save</button></form></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </details>
    <?php endforeach; ?>
  </div>
</div>
