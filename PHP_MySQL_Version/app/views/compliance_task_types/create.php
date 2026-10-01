<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/compliance-task-types">&larr; Back to Compliance Task Types</a></p>
  <h1>Add Compliance Task Type</h1>
  <form method="post" action="/compliance-task-types">
    <?= Csrf::field() ?>
    <label>Task Name * <input type="text" name="name" required placeholder="e.g. ECGC Cover, Pre-Shipment Inspection"></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Add Task Type</button>
      <a href="/compliance-task-types" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
