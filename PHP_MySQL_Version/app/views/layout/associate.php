<?php use App\Helpers\Flash; $__viewAs = \App\Services\AssociatePortalService::viewAsStaffId() !== null; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Associate Portal</title>
<link rel="icon" href="/assets/img/favicon.ico?v=2" sizes="any">
<link rel="stylesheet" href="<?= \App\Helpers\View::asset('/assets/css/app.css') ?>">
<script src="<?= \App\Helpers\View::asset('/assets/js/app.js') ?>" defer></script>
</head>
<body>
<?php if (\App\Config\Env::isDevServer()): ?>
  <div class="dev-server-banner">&#9888; DEV / TEST ENVIRONMENT — this is not the production server &#9888;</div>
<?php endif; ?>
<?php if ($__viewAs): ?>
  <div class="impersonation-banner">&#128100; Staff view of this associate's portal — read-only.
    <a class="impersonation-banner-end" href="/associate/logout">End staff view</a></div>
<?php endif; ?>
<header class="client-topbar">
  <div class="topbar-brand">
    <span class="bare-brand-mark" aria-hidden="true"><img src="/assets/img/logo.jpg" alt=""></span>
    NexaCrest <span>Associate Portal</span>
  </div>
  <?php if (!empty($me)): ?>
  <nav class="topbar-nav">
    <a href="/associate">Home<?= !empty($unread) ? ' (' . (int) $unread . ' new)' : '' ?></a>
    <a href="/associate/clients">My clients</a>
    <a href="/associate/orders">Orders</a>
    <a href="/associate/commission">Commission</a>
    <a href="/associate/messages">Messages</a>
    <a href="/associate/profile">Profile</a>
    <?php if ($__viewAs): ?><a href="/associate/logout" style="margin-left:auto">End staff view</a><?php else: ?>
    <form method="post" action="/associate/logout" style="margin-left:auto"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="topbar-nav-logout">Log out</button></form><?php endif; ?>
  </nav>
  <?php endif; ?>
</header>
<main class="page">
  <?php if (\App\Services\AssociatePortalService::isSuspended($me ?? null)): ?>
    <div class="alert alert-warning" role="status" data-associate-suspended><?= htmlspecialchars(\App\Services\AssociatePortalService::SUSPENDED_BANNER) ?></div>
  <?php endif; ?>
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
