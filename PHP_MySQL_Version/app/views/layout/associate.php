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
<?= \App\Services\SessionTimeouts::metaTag() // D-283: idle warning popup (app.js) ?>
</head>
<body>
<?php if (\App\Config\Env::isDevServer()): ?>
  <div class="dev-server-banner">&#9888; DEV / TEST ENVIRONMENT — this is not the production server &#9888;</div>
<?php endif; ?>
<?php if ($__viewAs): ?>
  <div class="impersonation-banner">&#128100; Staff view of this associate's portal — read-only.
    <form method="post" action="/associate/logout" style="display:inline"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="impersonation-banner-end">End staff view</button></form></div>
<?php endif; ?>
<header class="client-topbar">
  <div class="topbar-brand">
    <span class="bare-brand-mark" aria-hidden="true"><img src="/assets/img/logo.jpg" alt=""></span>
    NexaCrest <span>Associate Portal</span>
  </div>
  <?php if (!empty($me)): ?>
  <nav class="topbar-nav">
    <a href="/associate">Home<?= !empty($unread) ? ' (' . (int) $unread . ' new)' : '' ?></a>
    <?php $__can = static fn (string $f): bool => \App\Services\AssociateAccess::allows($me, $f); // D-261: only what the access level shows ?>
    <?php if ($__can('clients')): ?><a href="/associate/clients">My clients</a><?php endif; ?>
    <?php if ($__can('orders')): ?><a href="/associate/orders">Orders</a><?php endif; ?>
    <?php if ($__can('catalogue')): ?><a href="/associate/catalogue">Catalogue</a><?php endif; ?>
    <?php if ($__can('commission') || $__can('tds_certificates')): ?><a href="/associate/commission">Commission</a><?php endif; ?>
    <?php if ($__can('messages')): ?><a href="/associate/messages">Messages</a><?php endif; ?>
    <a href="/associate/profile">Profile</a>
    <?php if ($__viewAs): ?><form method="post" action="/associate/logout" style="margin-left:auto"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="topbar-nav-logout">End staff view</button></form><?php else: ?>
    <form method="post" action="/associate/logout" style="margin-left:auto"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="topbar-nav-logout">Log out</button></form><?php endif; ?>
  </nav>
  <?php endif; ?>
</header>
<main class="page">
  <?php if (\App\Services\AssociatePortalService::isSuspended($me ?? null)): ?>
    <div class="alert alert-warning" role="status" data-associate-suspended><?= htmlspecialchars(\App\Services\AssociatePortalService::SUSPENDED_BANNER) ?></div>
  <?php endif; ?>
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?><?php if (!empty($f['copy_link'])) { $__copyLink = $f['copy_link']; $__copyNote = null; require dirname(__DIR__) . '/partials/copy_link.php'; } // D-280 ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
