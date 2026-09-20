<?php use App\Helpers\Flash; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — My Orders</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">NEXACREST <span>INTERNATIONAL</span></div>
  <nav class="topbar-nav">
    <a href="/client">My Orders</a>
    <a href="/client/account">My Account</a>
    <a href="/client/logout" style="margin-left:auto">Log out</a>
  </nav>
</header>
<main class="page">
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
