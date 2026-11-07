<?php // Shown inside the associate portal when a record is not theirs or does not exist (same wording either way, 404). ?>
<div class="card page-wide">
  <h1>Not found</h1>
  <p><?= htmlspecialchars((string) $message) ?></p>
  <p><a class="btn-sm" href="/associate">Back to your home page</a></p>
</div>
