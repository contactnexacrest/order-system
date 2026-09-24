<?php use App\Helpers\Flash; use App\Repositories\NotificationRepository; use App\Services\AuthService; use App\Services\PermissionService; use App\Services\SuperAdminService; use App\Services\TestModeService;
$current = AuthService::currentUser();
$testModeEnabled = TestModeService::isEnabled();
$unreadCount = $current ? NotificationRepository::unreadCountForUser((int) $current['id']) : 0;
$isEffectiveSuperAdmin = $current && SuperAdminService::isEffective((int) $current['id']);
$roleId = $current && $current['role_id'] !== null ? (int) $current['role_id'] : null;
$can = static fn(string $perm): bool => $current && PermissionService::can((int) $current['id'], $roleId, $perm);

// Task #18 — the flat nav below used to list every one of these ~20 links
// side by side; grouped into "Operations" / "Insights" / "Admin" dropdowns
// (see .nav-group in app.css) so it's readable at all, on desktop or
// mobile. A group's <details> only renders once at least one of its links
// is actually visible to this user's permissions.
$canOrders = $can('manage_orders');
$canSettings = $can('manage_company_settings');
$canManageHsCodes = $can('manage_hs_codes');
$canManageEmailTemplates = $can('manage_email_templates');
$canAssets = $can('manage_assets');
$canSignatories = $can('manage_signatories');
$canPermissions = $can('manage_permissions');
$canApproveEmail = $can('approve_email_send');
$canAudit = $can('view_audit_log');
$canReports = $can('view_reports');
$canOverrides = $can('edit_locked_data');
$canFieldProtection = $can('manage_field_protection');
$canUsers = $can('manage_users');
$canSampleData = $can('manage_sample_data');
$canViewProducts = $can('view_product_catalog');
$canViewArchivedOrders = $can('view_archived_orders');

$opsGroupVisible = $canOrders || $canViewArchivedOrders;
$insightsGroupVisible = $canReports || $canAudit || $canApproveEmail;
$adminGroupVisible = $canSettings || $canAssets || $canSignatories || $canPermissions
    || $canUsers || $canFieldProtection || $canOverrides || $canSampleData;

// Sidebar redesign (2026-09-23, "cover all" UI pass) — highlights the
// current section so a fresher can see at a glance where they are, and
// keeps a group's disclosure open automatically when they're inside it
// rather than making them re-discover which dropdown their own page lives
// under every time they navigate.
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$isActive = static function (string $path) use ($currentPath): bool {
    return $path === '/' ? $currentPath === '/' : str_starts_with($currentPath, $path);
};
$opsGroupActive = $isActive('/clients') || $isActive('/orders') || $isActive('/client-intake') || $isActive('/pi-intake-review') || $isActive('/disputes');
$insightsGroupActive = $isActive('/reports') || $isActive('/audit-log') || $isActive('/email-approvals');
$adminGroupActive = $isActive('/settings') || $isActive('/holidays') || $isActive('/company-assets') || $isActive('/signatories')
    || $isActive('/admin') || $isActive('/users') || $isActive('/sample-data') || $isActive('/hs-codes') || $isActive('/watermarks') || $isActive('/email-templates');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Export Operations</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/app.js" defer></script>
</head>
<body>
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
  <nav class="sidebar-nav">
    <a href="/" class="<?= $isActive('/') && $currentPath === '/' ? 'active' : '' ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg></span>Dashboard</a>
    <?php if ($current): ?>
      <a href="/reference-docs" class="<?= $isActive('/reference-docs') ? 'active' : '' ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></span>Reference Library</a>
      <a href="/reviews" class="<?= $isActive('/reviews') ? 'active' : '' ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span>My Reviews</a>
      <?php if ($canViewProducts): ?><a href="/products" class="<?= $isActive('/products') ? 'active' : '' ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></span>Products</a><?php endif; ?>
    <?php endif; ?>
    <?php if ($opsGroupVisible): ?>
      <details class="nav-group"<?= $opsGroupActive ? ' open' : '' ?>>
        <summary><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg></span>Operations</summary>
        <div class="nav-dropdown">
          <a href="/clients" class="<?= $isActive('/clients') ? 'active' : '' ?>">Clients</a>
          <a href="/orders" class="<?= $currentPath === '/orders' || (str_starts_with($currentPath, '/orders/') && !str_starts_with($currentPath, '/orders/archived')) ? 'active' : '' ?>">Orders</a>
          <?php if ($canViewArchivedOrders): ?><a href="/orders/archived" class="<?= $isActive('/orders/archived') ? 'active' : '' ?>">Archived Orders</a><?php endif; ?>
          <a href="/client-intake" class="<?= $isActive('/client-intake') ? 'active' : '' ?>">Quotation Intake Review</a>
          <a href="/pi-intake-review" class="<?= $isActive('/pi-intake-review') ? 'active' : '' ?>">PI Intake Review</a>
          <a href="/disputes" class="<?= $isActive('/disputes') ? 'active' : '' ?>">Disputes</a>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($insightsGroupVisible): ?>
      <details class="nav-group"<?= $insightsGroupActive ? ' open' : '' ?>>
        <summary><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg></span>Insights</summary>
        <div class="nav-dropdown">
          <?php if ($canReports): ?><a href="/reports" class="<?= $isActive('/reports') ? 'active' : '' ?>">Reports</a><?php endif; ?>
          <?php if ($canAudit): ?><a href="/audit-log" class="<?= $isActive('/audit-log') ? 'active' : '' ?>">Audit Log</a><?php endif; ?>
          <?php if ($canApproveEmail): ?><a href="/email-approvals" class="<?= $isActive('/email-approvals') ? 'active' : '' ?>">Email Approvals</a><?php endif; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($adminGroupVisible): ?>
      <details class="nav-group"<?= $adminGroupActive ? ' open' : '' ?>>
        <summary><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></span>Admin</summary>
        <div class="nav-dropdown">
          <?php if ($canSettings): ?><a href="/settings" class="<?= $isActive('/settings') ? 'active' : '' ?>">Company Settings</a><?php endif; ?>
          <?php if ($canSettings): ?><a href="/holidays" class="<?= $isActive('/holidays') ? 'active' : '' ?>">Holiday Calendar</a><?php endif; ?>
          <?php if ($canManageHsCodes): ?><a href="/hs-codes" class="<?= $isActive('/hs-codes') ? 'active' : '' ?>">HS Codes</a><?php endif; ?>
          <?php if ($canManageEmailTemplates): ?><a href="/email-templates" class="<?= $isActive('/email-templates') ? 'active' : '' ?>">Email Templates</a><?php endif; ?>
          <?php if ($canSettings): ?><a href="/watermarks" class="<?= $isActive('/watermarks') ? 'active' : '' ?>">Watermarks</a><?php endif; ?>
          <?php if ($canAssets): ?><a href="/company-assets" class="<?= $isActive('/company-assets') ? 'active' : '' ?>">Assets</a><?php endif; ?>
          <?php if ($canSignatories): ?><a href="/signatories" class="<?= $isActive('/signatories') ? 'active' : '' ?>">Signatories</a><?php endif; ?>
          <?php if ($canPermissions): ?><a href="/admin/permissions" class="<?= $isActive('/admin/permissions') ? 'active' : '' ?>">Permissions</a><?php endif; ?>
          <?php if ($canUsers): ?><a href="/users" class="<?= $isActive('/users') ? 'active' : '' ?>">Users</a><?php endif; ?>
          <?php if ($canFieldProtection): ?><a href="/admin/field-protection" class="<?= $isActive('/admin/field-protection') ? 'active' : '' ?>">Field Protection</a><?php endif; ?>
          <?php if ($canOverrides): ?><a href="/admin/overrides" class="<?= $isActive('/admin/overrides') ? 'active' : '' ?>">Admin Overrides</a><?php endif; ?>
          <?php if ($canSampleData): ?><a href="/sample-data" class="<?= $isActive('/sample-data') ? 'active' : '' ?>">Sample Data</a><?php endif; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($isEffectiveSuperAdmin): ?>
      <a href="/super-admin" class="<?= $isActive('/super-admin') ? 'active' : '' ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg></span>Super Admin</a>
      <a href="/test-mode" class="<?= $isActive('/test-mode') ? 'active' : '' ?>"><span class="nav-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6L4 20a1 1 0 0 0 1 2h14a1 1 0 0 0 1-2L15 8V2"></path><path d="M8.5 14h7"></path></svg></span>Test Mode<?= $testModeEnabled ? ' <span class="nav-live-dot" aria-hidden="true"></span>' : '' ?></a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-user">
    <?php if ($current): ?>
      <a href="/notifications" class="sidebar-user-notif <?= $isActive('/notifications') ? 'active' : '' ?>" aria-label="Notifications<?= $unreadCount > 0 ? ' (' . (int) $unreadCount . ' unread)' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        Notifications<?= $unreadCount > 0 ? ' <span class="nav-badge">' . (int) $unreadCount . '</span>' : '' ?>
      </a>
      <a href="/account" class="sidebar-user-card">
        <span class="sidebar-user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($current['name'], 0, 1))) ?></span>
        <span class="sidebar-user-name"><?= htmlspecialchars($current['name']) ?></span>
      </a>
      <a href="/logout" class="sidebar-logout">Log out</a>
    <?php endif; ?>
  </div>
</header>
<div class="app-main">
  <?php if ($testModeEnabled): ?>
    <div class="test-mode-banner">TEST MODE ACTIVE — client access suspended, all outbound business mail is redirected, admin panel settings are locked. Any order/client/document created now is test data.</div>
  <?php endif; ?>
  <main class="page">
    <?php foreach (Flash::pull() as $f): ?>
      <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</div>
</body>
</html>
