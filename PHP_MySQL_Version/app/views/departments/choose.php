<?php use App\Helpers\Csrf;
$selectedId = (int) ($current['id'] ?? $memberships[0]['id']); ?>
<div class="card">
  <h1>Log in as</h1>
  <p class="muted small"><?= htmlspecialchars((string) $user['name']) ?>, you work in more than one department. Choose one for this session — you can switch at any time from the menu.</p>
  <form method="post" action="/choose-department">
    <?= Csrf::field() ?>
    <div class="dept-choice">
      <?php foreach ($memberships as $m): ?>
        <label style="--dept-color:<?= htmlspecialchars((string) $m['department_color']) ?>">
          <input type="radio" name="membership_id" value="<?= (int) $m['id'] ?>" required <?= (int) $m['id'] === $selectedId ? 'checked' : '' ?>>
          <span class="dot" aria-hidden="true"></span>
          <span><strong><?= htmlspecialchars((string) $m['designation_title']) ?></strong><small><?= htmlspecialchars((string) $m['department_name']) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
    <label class="inline"><input type="checkbox" name="remember" value="1" checked> Start here next time</label>
    <div class="btn-row">
      <button type="submit" class="btn-success">Continue</button>
    </div>
  </form>
  <form method="post" action="/logout"><?= Csrf::field() ?><button type="submit" class="btn-link">Log out</button></form>
</div>
