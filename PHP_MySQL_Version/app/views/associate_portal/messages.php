<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Messages</h1>
  <p class="muted">Your relationship manager: <b><?= htmlspecialchars((string) ($rm['name'] ?? 'NexaCrest associates team')) ?></b></p>
  <?php foreach ($messages as $m): ?>
    <div class="comment"><b><?= (int) $m['from_associate'] === 1 ? 'You' : htmlspecialchars((string) ($m['user_name'] ?? 'NexaCrest')) ?></b> <small class="muted"><?= htmlspecialchars((string) $m['created_at']) ?></small><br><?= nl2br(htmlspecialchars((string) $m['body'])) ?></div>
  <?php endforeach; ?>
  <?php if ($messages === []): ?><p class="muted">No messages yet.</p><?php endif; ?>
  <form method="post" action="/associate/messages">
    <?= Csrf::field() ?>
    <label>Your message<textarea name="body" required maxlength="4000" rows="4"></textarea></label>
    <button type="submit" class="btn-sm">Send</button>
  </form>
</div>
