<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/compliance-task-types">&larr; Back to Compliance Task Types</a></p>
  <h1>Edit Compliance Task Type</h1>
  <form method="post" action="/compliance-task-types/<?= (int) $taskType['id'] ?>/update">
    <?= Csrf::field() ?>
    <label>Task Name * <input type="text" name="name" value="<?= htmlspecialchars($taskType['name']) ?>" required></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/compliance-task-types" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
