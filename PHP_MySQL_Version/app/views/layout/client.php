<?php use App\Helpers\Flash; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — My Orders</title>
<link rel="icon" href="/assets/img/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/app.js" defer></script>
</head>
<body>
<?php if (\App\Config\Env::isDevServer()): ?>
  <div class="dev-server-banner">&#9888; DEV / TEST ENVIRONMENT — this is not the production server &#9888;</div>
<?php endif; ?>
<?php if (\App\Services\ClientPortalService::isImpersonating()): ?>
  <div class="impersonation-banner">
    &#128100; A staff member is viewing this portal on this client's behalf.
    <form method="post" action="/client/end-impersonation" style="margin:0">
      <?= \App\Helpers\Csrf::field() ?>
      <button type="submit" class="impersonation-banner-end">End impersonation</button>
    </form>
  </div>
<?php endif; ?>
<header class="client-topbar">
  <div class="topbar-brand">
    <span class="bare-brand-mark" aria-hidden="true"><img src="/assets/img/logo.jpg" alt=""></span>
    NexaCrest <span>International</span>
  </div>
  <nav class="topbar-nav">
    <a href="/client">My Orders</a>
    <a href="/client/account">My Account</a>
    <form method="post" action="/client/logout" style="margin-left:auto"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="topbar-nav-logout">Log out</button></form>
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
