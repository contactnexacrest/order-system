<?php use App\Helpers\Flash; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Export Operations</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bare">
<div class="bare-wrap">
  <div class="bare-brand">NEXACREST <span>INTERNATIONAL</span></div>
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</div>
</body>
</html>
