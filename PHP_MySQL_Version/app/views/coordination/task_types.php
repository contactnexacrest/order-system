<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Task due days</h1>
  <p class="muted">Each kind of department task is due this many days after the step before it was done; after the escalation days more, it is shown to management as escalated.</p>
  <form method="post" action="/admin/task-types">
    <?= Csrf::field() ?>
    <table class="list">
      <tr><th>Department</th><th>Task</th><th>Due after (days)</th><th>Escalate after a further (days)</th></tr>
      <?php foreach ($types as $t): ?>
      <tr>
        <td><?= htmlspecialchars((string) ($t['department_name'] ?? $t['department_code'])) ?></td>
        <td><?= htmlspecialchars((string) $t['title']) ?></td>
        <td><input type="number" name="standard_days[<?= htmlspecialchars((string) $t['task_key']) ?>]" value="<?= (int) $t['standard_days'] ?>" min="0" max="90" step="1" required aria-label="Due after days"></td>
        <td><input type="number" name="escalate_after_days[<?= htmlspecialchars((string) $t['task_key']) ?>]" value="<?= (int) $t['escalate_after_days'] ?>" min="0" max="90" step="1" required aria-label="Escalate after days"></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Save</button></div>
  </form>
</div>
