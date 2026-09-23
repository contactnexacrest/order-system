<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Holiday Calendar</h1>
  <p class="muted">Specific dated holidays that WorkingDaysCalculator skips when computing a "working days" deadline — e.g. the Dispute Resolution clause's contractual ten (10) working day response window. Deliberately a flat list of exact dates rather than a recurring rule: a holiday like Diwali falls on a different date every year, so re-enter each year's actual dates rather than relying on a "same day every year" rule. The weekly off-day (currently Sunday) is configured on the <a href="/settings">Settings</a> screen under the "disputes" category (<code>weekly_off_days</code>).</p>

  <div class="section">
    <table class="list">
      <tr><th>Date</th><th>Description</th><th>Action</th></tr>
      <?php if (empty($holidays)): ?>
      <tr><td colspan="3" class="muted">No holidays on the calendar yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($holidays as $h): ?>
      <tr>
        <td colspan="3">
          <form method="post" action="/holidays/<?= (int) $h['id'] ?>/update" style="display:flex;gap:8px;align-items:center">
            <?= Csrf::field() ?>
            <input type="date" name="holiday_date" value="<?= htmlspecialchars($h['holiday_date']) ?>" required>
            <input type="text" name="description" value="<?= htmlspecialchars($h['description']) ?>" required style="width:240px">
            <button type="submit" class="btn-sm">Save</button>
            <button type="submit" formaction="/holidays/<?= (int) $h['id'] ?>/delete" formnovalidate class="btn-sm btn-danger" onclick="return confirm('Remove this holiday?');">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" action="/holidays" style="margin-top:8px;display:flex;gap:8px;align-items:center">
      <?= Csrf::field() ?>
      <input type="date" name="holiday_date" required>
      <input type="text" name="description" placeholder="e.g. Diwali" required style="width:240px">
      <button type="submit" class="btn-sm btn-success">Add Holiday</button>
    </form>
  </div>
</div>
