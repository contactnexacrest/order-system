<?php use App\Helpers\Flash; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Export Operations</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bare">
<div class="bare-wrap">
  <div class="bare-brand">
    <span class="bare-brand-mark" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M7 8c0-2 2-3.4 4.5-3.4S16 6.2 16 9s-2 3.6-4.5 3.6S7 14.2 7 17s2 3.4 4.5 3.4S16 18.8 16 16" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round"></path></svg></span>
    NexaCrest <span>International</span>
  </div>
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</div>
</body>
</html>
