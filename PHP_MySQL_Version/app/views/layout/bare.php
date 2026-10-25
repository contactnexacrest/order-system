<?php use App\Helpers\Flash; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Export Operations</title>
<link rel="icon" href="/assets/img/favicon.ico?v=2" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32x32.png?v=2">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/favicon-16x16.png?v=2">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/img/apple-touch-icon.png?v=2">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/app.js" defer></script>
</head>
<body class="bare">
<?php if (\App\Config\Env::isDevServer()): ?>
  <div class="dev-server-banner">&#9888; DEV / TEST ENVIRONMENT — this is not the production server &#9888;</div>
<?php endif; ?>
<div class="bare-wrap<?= isset($wrapClass) ? ' ' . htmlspecialchars($wrapClass) : '' ?>">
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
