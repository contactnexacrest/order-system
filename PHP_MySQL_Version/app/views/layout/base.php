<?php use App\Helpers\Flash; use App\Repositories\ClientIntakeRepository; use App\Repositories\EmailLogRepository; use App\Repositories\NotificationRepository; use App\Repositories\PiIntakeRepository; use App\Services\AuthService; use App\Services\PermissionService; use App\Services\SuperAdminService; use App\Services\TestModeService;
$current = AuthService::currentUser();
$testModeEnabled = TestModeService::isEnabled();
$unreadCount = $current ? NotificationRepository::unreadCountForUser((int) $current['id']) : 0;
$isEffectiveSuperAdmin = $current && SuperAdminService::isEffective((int) $current['id']);
$roleId = $current && $current['role_id'] !== null ? (int) $current['role_id'] : null;
$can = static fn(string $perm): bool => $current && PermissionService::can((int) $current['id'], $roleId, $perm);

// Owner decision D-214: the department this person is working as, the others
// they can switch to, and Admin's "Preview as" banner.
$actingMembership = $current ? \App\Services\ActiveDepartmentService::current((int) $current['id']) : null;
$myMemberships = $actingMembership ? \App\Repositories\DepartmentRepository::activeMembershipsForUser((int) $current['id']) : [];
$previewDesignation = $current ? \App\Services\ActiveDepartmentService::preview() : null;
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$isActive = static function (string $path) use ($currentPath): bool {
    return $path === '/' ? $currentPath === '/' : str_starts_with($currentPath, $path);
};

// Owner decision D-320 (point 26): the short left menu (13 entries) and the
// menu bar of the section this page belongs to, both from one config
// (src/Config/navigation.json, the same file in the Node stack) — see
// App\Services\Navigation. Each entry shows only when this person can open
// at least one page behind it, exactly as the old long menu did per link.
$nav = null;
if ($current) {
    $__uid = (int) $current['id'];
    // The facts the config asks about besides permissions.
    $__activeCodes = $can('manage_divisions') ? array_column(\App\Services\DivisionService::active(), 'code') : [];
    $navFlags = [
        'superAdmin' => $isEffectiveSuperAdmin,
        'sopOpen' => \App\Services\SopAccessService::libraryVisibleTo($__uid, $roleId), // D-266
        'mod_supplier_purchase' => \App\Services\DivisionService::moduleEnabled('supplier_purchase'), // D-257: a module switched off hides its pages
        'mod_import_stock' => \App\Services\DivisionService::moduleEnabled('import_stock'),
        'mod_merchant_trade' => \App\Services\DivisionService::moduleEnabled('merchant_trade'),
        'mod_associates' => \App\Services\DivisionService::moduleEnabled('associates'),
        'mod_team_incentive' => \App\Services\DivisionService::moduleEnabled('team_incentive'),
        'div_gst' => array_intersect(['DOM', 'IMP'], $__activeCodes) !== [], // QA UI-12
        'div_import_duty' => in_array('IMP', $__activeCodes, true),
    ];
    // Live counts on every page load (not cached), each queried only for
    // people who could act on it — the same counts the old menu showed.
    $__approvalsCount = \App\Services\ApprovalsInbox::countFor($__uid, $roleId); // D-310
    $navFlags['approver'] = $__approvalsCount > 0 || \App\Services\ApprovalsInbox::isApprover($__uid, $roleId);
    $__tabCounts = [];
    if ($__approvalsCount > 0) {
        try {
            $__tabCounts = \App\Services\ApprovalsInbox::counts(\App\Services\ApprovalsInbox::items($__uid, $roleId));
        } catch (\Throwable $e) {
            $__tabCounts = [];
        }
    }
    $__settingsWarnings = $can('manage_company_settings') ? \App\Repositories\DashboardRepository::complianceExpiryWarnings() : ['lut' => null, 'rcmc' => null];
    $__zoho = $can('ca_module_manage') ? \App\Services\ZohoPendingService::menuCount() : null; // D-233 / D-239
    $navCounts = [
        'approvals' => $__approvalsCount,
        'approvals_prices' => (int) ($__tabCounts['prices'] ?? 0),
        'approvals_payment_terms' => (int) ($__tabCounts['payment_terms'] ?? 0),
        'approvals_bank' => (int) ($__tabCounts['bank'] ?? 0),
        'approvals_other' => (int) ($__tabCounts['other'] ?? 0),
        'myReviews' => \App\Repositories\DocumentReviewRepository::pendingCountForReviewer($__uid),
        'emailApprovals' => $can('approve_email_send') ? EmailLogRepository::awaitingDispatchCount() : 0,
        'myTasks' => ($can('manage_orders') || $can('view_orders')) ? \App\Controllers\CoordinationController::myTaskCount($__uid) : 0,
        'clientIntake' => $can('manage_orders') ? ClientIntakeRepository::pendingCount() : 0,
        'piIntake' => $can('manage_orders') ? PiIntakeRepository::pendingReviewCount() : 0,
        'associateRegistrations' => $can('manage_associates') ? \App\Repositories\AssociateRegistrationRepository::pendingCount() : 0,
        'zohoWaiting' => (int) ($__zoho ?? 0),
        'setupNeeded' => $can('manage_company_settings') ? \App\Services\SetupChecklist::neededCount() : 0, // D-312
        'settingsExpiry' => ($__settingsWarnings['lut'] ? 1 : 0) + ($__settingsWarnings['rcmc'] ? 1 : 0),
    ];
    $nav = \App\Services\Navigation::build($can, $navFlags, $navCounts, $currentPath, $_GET);
}
?>
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
<link rel="stylesheet" href="<?= \App\Helpers\View::asset('/assets/css/app.css') ?>">
<script src="<?= \App\Helpers\View::asset('/assets/js/app.js') ?>" defer></script>
<?= \App\Services\SessionTimeouts::metaTag() // D-283: idle warning popup (app.js) ?>
</head>
<body>
<?php if (\App\Config\Env::isDevServer()): ?>
  <div class="dev-server-banner">&#9888; DEV / TEST ENVIRONMENT — this is not the production server &#9888;</div>
<?php endif; ?>
<div class="app-shell">
<header class="sidebar">
  <div class="sidebar-brand">
    <span class="sidebar-brand-mark" aria-hidden="true">
      <img src="/assets/img/logo.jpg" alt="">
    </span>
    <span class="sidebar-brand-name">NexaCrest</span>
  </div>
  <?php if ($current): ?>
    <input type="checkbox" id="nav-toggle" class="nav-toggle-checkbox">
    <label for="nav-toggle" class="nav-toggle-label" aria-label="Toggle navigation">&#9776;</label>
  <?php endif; ?>
  <nav class="sidebar-nav" data-testid="main-menu">
    <?php if ($nav === null): ?>
      <a href="/" class="<?= $currentPath === '/' ? 'active' : '' ?>">Dashboard</a>
    <?php else: foreach ($nav['sidebar'] as $__entry): ?>
      <?php if ($__entry['heading'] !== null): ?><div class="nav-heading"><?= htmlspecialchars($__entry['heading']) ?></div><?php endif; ?>
      <a href="<?= htmlspecialchars($__entry['href']) ?>" class="<?= $__entry['active'] ? 'active' : '' ?>" data-nav="<?= htmlspecialchars($__entry['key']) ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $__entry['icon'] // trusted markup from src/Config/navigation.json ?></svg></span><?= htmlspecialchars($__entry['label']) ?><?= $__entry['count'] > 0 ? ' <span class="nav-badge">' . (int) $__entry['count'] . '</span>' : '' ?></a>
    <?php endforeach; endif; ?>
  </nav>
  <div class="sidebar-user">
    <?php if ($current): ?>
      <a href="/notifications" class="sidebar-user-notif <?= $isActive('/notifications') ? 'active' : '' ?>" aria-label="Notifications<?= $unreadCount > 0 ? ' (' . (int) $unreadCount . ' unread)' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        Notifications<?= $unreadCount > 0 ? ' <span class="nav-badge">' . (int) $unreadCount . '</span>' : '' ?>
      </a>
      <?php if ($actingMembership): ?>
        <div class="dept-badge" style="--dept-color:<?= htmlspecialchars((string) $actingMembership['department_color']) ?>" data-testid="dept-badge">
          <span class="dept-badge-label">Logged in as</span>
          <strong><?= htmlspecialchars((string) $actingMembership['designation_title']) ?></strong>
          <?= htmlspecialchars((string) $actingMembership['department_name']) ?>
          <?php if (count($myMemberships) > 1): ?>
            <details class="dept-switch">
              <summary>Switch department &#9662;</summary>
              <div class="dept-switch-list">
                <?php foreach ($myMemberships as $m): ?>
                  <?php if ((int) $m['id'] === (int) $actingMembership['id']): ?>
                    <div class="current">&#9679; <?= htmlspecialchars($m['designation_title'] . ' — ' . $m['department_name']) ?></div>
                  <?php else: ?>
                    <form method="post" action="/switch-department"><?= \App\Helpers\Csrf::field() ?><input type="hidden" name="membership_id" value="<?= (int) $m['id'] ?>"><button type="submit">&#9675; <?= htmlspecialchars($m['designation_title'] . ' — ' . $m['department_name']) ?></button></form>
                  <?php endif; ?>
                <?php endforeach; ?>
                <div class="hint">Switching reloads the app with that department's menus, tasks and data.</div>
              </div>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (\App\Services\DivisionService::showSwitcher()): // D-257: company level, two or more active divisions ?>
        <form method="post" action="/divisions/choose" class="division-switch" data-testid="division-switcher" style="margin:8px 0;color:#fff;font-size:12px">
          <?= \App\Helpers\Csrf::field() ?>
          <input type="hidden" name="back" value="<?= htmlspecialchars($currentPath) ?>">
          <label>Division
            <select name="division" onchange="this.form.submit()">
              <option value="all"<?= \App\Services\DivisionService::currentId() === null ? ' selected' : '' ?>>All divisions</option>
              <?php foreach (\App\Services\DivisionService::active() as $__div): ?>
                <option value="<?= (int) $__div['id'] ?>"<?= \App\Services\DivisionService::currentId() === (int) $__div['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $__div['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <noscript><button type="submit" class="btn-sm">Show</button></noscript>
        </form>
      <?php endif; ?>
      <a href="/account" class="sidebar-user-card">
        <span class="sidebar-user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($current['name'], 0, 1))) ?></span>
        <span class="sidebar-user-name"><?= htmlspecialchars($current['name']) ?></span>
      </a>
      <form method="post" action="/logout" class="sidebar-logout-form"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="sidebar-logout">Log out</button></form>
    <?php endif; ?>
  </div>
</header>
<div class="app-main">
  <?php if ($previewDesignation): ?>
    <div class="preview-banner" data-testid="preview-banner">
      <span>Previewing the app as <?= htmlspecialchars($previewDesignation['title'] . ' · ' . $previewDesignation['department_name']) ?> — you see only what this designation sees (your own extra rights are off).</span>
      <form method="post" action="/preview/exit"><?= \App\Helpers\Csrf::field() ?><button type="submit" class="btn-sm">Exit preview</button></form>
    </div>
  <?php endif; ?>
  <?php if ($testModeEnabled): ?>
    <div class="test-mode-banner">TEST MODE ACTIVE — client access suspended, all outbound business mail is redirected, admin panel settings are locked. Any order/client/document created now is test data.</div>
  <?php endif; ?>
  <main class="page">
    <?php if ($nav !== null && $nav['bar'] !== null) { $__bar = $nav['bar']; require dirname(__DIR__) . '/partials/section_nav.php'; } // D-320: this section's menu bar ?>
    <?php foreach (Flash::pull() as $f): ?>
      <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?><?php if (!empty($f['copy_link'])) { $__copyLink = $f['copy_link']; $__copyNote = null; require dirname(__DIR__) . '/partials/copy_link.php'; } // D-280 ?></div>
    <?php endforeach; ?>
    <?php if (str_starts_with($currentPath, '/reports') && \App\Services\DivisionService::showSwitcher()): // D-257: one shared Division filter for every report (two or more active divisions only) ?>
      <form method="get" class="report-division-filter" data-testid="report-division-filter">
        <?php foreach ($_GET as $__k => $__v): if ($__k === 'division' || !is_scalar($__v)) { continue; } ?><input type="hidden" name="<?= htmlspecialchars((string) $__k) ?>" value="<?= htmlspecialchars((string) $__v) ?>"><?php endforeach; ?>
        <label>Division
          <select name="division" onchange="this.form.submit()">
            <option value="">All divisions</option>
            <?php foreach (\App\Services\DivisionService::active() as $__div): ?>
              <option value="<?= (int) $__div['id'] ?>"<?= (int) ($_GET['division'] ?? 0) === (int) $__div['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $__div['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button type="submit" class="btn-sm">Show</button></noscript>
      </form>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
</div>
<script>
/* 90-item backlog "sitewide collapsible sections beyond Settings": the
   independently-collapsible, per-browser-remembered <details> pattern
   90-item backlog #27 built for Company Settings (originally inline in
   views/settings/index.php, class="settings-section") is now sitewide —
   any page's <details class="collapsible-section" data-category="X">
   gets the same open/closed persistence, keyed on its own data-category
   so a staff member's preference for one section never leaks into
   another's. The storage key keeps the original "settings-section-open:"
   prefix (an internal namespace, invisible to users) rather than
   resetting a Settings user's stored preference for a rename with no
   visible benefit. Inputs inside a closed <details> still submit
   normally, so collapsing a section never drops its values. */
document.querySelectorAll('.collapsible-section[data-category]').forEach(function (details) {
  var storageKey = 'settings-section-open:' + details.dataset.category;
  try {
    var stored = localStorage.getItem(storageKey);
    if (stored !== null) { details.open = stored === '1'; }
  } catch (e) { /* ignore — storage unavailable, keep the default open state */ }
  details.addEventListener('toggle', function () {
    try { localStorage.setItem(storageKey, details.open ? '1' : '0'); } catch (e) { /* ignore */ }
  });
});
</script>
</body>
</html>
