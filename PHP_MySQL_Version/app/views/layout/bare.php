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
    <span class="bare-brand-mark" aria-hidden="true"><img src="/assets/img/logo.jpg" alt=""></span>
    NexaCrest <span>International</span>
  </div>
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</div>
</body>
</html>
