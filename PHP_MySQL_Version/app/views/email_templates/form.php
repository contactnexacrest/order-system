<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/email-templates">&larr; Back to Email Templates</a></p>
  <h1><?= $template ? 'Edit Email Template' : 'New Email Template' ?></h1>
  <p class="muted">Use any of these tokens in the subject/body/footer — they're substituted with the real order/document/sender values when a staff member composes a send: <?= implode(', ', array_map('htmlspecialchars', $availableTokens)) ?></p>

  <form method="post" action="<?= $template ? "/email-templates/{$template['id']}/update" : '/email-templates' ?>">
    <?= Csrf::field() ?>

    <?php if ($template): ?>
      <label>Template Key<input type="text" value="<?= htmlspecialchars($template['template_key']) ?>" disabled></label>
    <?php else: ?>
      <label>Template Key * <span class="muted small">(lowercase, letters/numbers/underscores only — cannot be changed after creation)</span>
        <input type="text" name="template_key" placeholder="e.g. payment_followup" pattern="[a-z0-9_]+" required>
      </label>
    <?php endif; ?>

    <label>Subject *<input type="text" name="subject" value="<?= htmlspecialchars($template['subject'] ?? '') ?>" required></label>
    <label>Body *<textarea name="body" rows="10" required><?= htmlspecialchars($template['body'] ?? '') ?></textarea></label>
    <label>Footer<textarea name="footer" rows="3"><?= htmlspecialchars($template['footer'] ?? '') ?></textarea></label>

    <button type="submit" class="btn-sm btn-accent"><?= $template ? 'Save Changes' : 'Create Template' ?></button>
    <a class="btn-sm" href="/email-templates">Cancel</a>
  </form>
</div>
