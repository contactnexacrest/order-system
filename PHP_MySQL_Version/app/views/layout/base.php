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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Export Operations</title>
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/app.js" defer></script>
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">NEXACREST <span>INTERNATIONAL</span></div>
  <?php if ($current): ?>
    <input type="checkbox" id="nav-toggle" class="nav-toggle-checkbox">
    <label for="nav-toggle" class="nav-toggle-label" aria-label="Toggle navigation">&#9776;</label>
  <?php endif; ?>
  <nav class="topbar-nav">
    <a href="/">Dashboard</a>
    <?php if ($current): ?>
      <a href="/reference-docs">Reference Library</a>
      <a href="/reviews">My Reviews</a>
      <?php if ($canViewProducts): ?><a href="/products">Products</a><?php endif; ?>
    <?php endif; ?>
    <?php if ($opsGroupVisible): ?>
      <details class="nav-group">
        <summary>Operations</summary>
        <div class="nav-dropdown">
          <a href="/clients">Clients</a>
          <a href="/orders">Orders</a>
          <?php if ($canViewArchivedOrders): ?><a href="/orders/archived">Archived Orders</a><?php endif; ?>
          <a href="/client-intake">Client Requests</a>
          <a href="/pi-intake-review">PI Intake Review</a>
          <a href="/disputes">Disputes</a>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($insightsGroupVisible): ?>
      <details class="nav-group">
        <summary>Insights</summary>
        <div class="nav-dropdown">
          <?php if ($canReports): ?><a href="/reports">Reports</a><?php endif; ?>
          <?php if ($canAudit): ?><a href="/audit-log">Audit Log</a><?php endif; ?>
          <?php if ($canApproveEmail): ?><a href="/email-approvals">Email Approvals</a><?php endif; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($adminGroupVisible): ?>
      <details class="nav-group">
        <summary>Admin</summary>
        <div class="nav-dropdown">
          <?php if ($canSettings): ?><a href="/settings">Company Settings</a><?php endif; ?>
          <?php if ($canSettings): ?><a href="/holidays">Holiday Calendar</a><?php endif; ?>
          <?php if ($canAssets): ?><a href="/company-assets">Assets</a><?php endif; ?>
          <?php if ($canSignatories): ?><a href="/signatories">Signatories</a><?php endif; ?>
          <?php if ($canPermissions): ?><a href="/admin/permissions">Permissions</a><?php endif; ?>
          <?php if ($canUsers): ?><a href="/users">Users</a><?php endif; ?>
          <?php if ($canFieldProtection): ?><a href="/admin/field-protection">Field Protection</a><?php endif; ?>
          <?php if ($canOverrides): ?><a href="/admin/overrides">Admin Overrides</a><?php endif; ?>
          <?php if ($canSampleData): ?><a href="/sample-data">Sample Data</a><?php endif; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($isEffectiveSuperAdmin): ?>
      <a href="/super-admin">Super Admin</a>
      <a href="/test-mode">Test Mode<?= $testModeEnabled ? ' (ON)' : '' ?></a>
    <?php endif; ?>
  </nav>
  <div class="topbar-user">
    <?php if ($current): ?>
      <a href="/notifications">Notifications<?= $unreadCount > 0 ? ' (' . (int) $unreadCount . ')' : '' ?></a>
      <span><?= htmlspecialchars($current['name']) ?></span>
      <a href="/logout" class="logout-link">Log out</a>
    <?php endif; ?>
  </div>
</header>
<?php if ($testModeEnabled): ?>
  <div class="test-mode-banner">TEST MODE ACTIVE — client access suspended, all outbound business mail is redirected, admin panel settings are locked. Any order/client/document created now is test data.</div>
<?php endif; ?>
<main class="page">
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
