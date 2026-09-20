<?php use App\Helpers\Flash; use App\Repositories\NotificationRepository; use App\Services\AuthService; use App\Services\PermissionService; use App\Services\SuperAdminService;
$current = AuthService::currentUser();
$unreadCount = $current ? NotificationRepository::unreadCountForUser((int) $current['id']) : 0;
$isEffectiveSuperAdmin = $current && SuperAdminService::isEffective((int) $current['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NexaCrest International — Export Operations</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">NEXACREST <span>INTERNATIONAL</span></div>
  <nav class="topbar-nav">
    <a href="/">Dashboard</a>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_orders')): ?>
      <a href="/clients">Clients</a>
      <a href="/orders">Orders</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_company_settings')): ?>
      <a href="/settings">Company Settings</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_assets')): ?>
      <a href="/company-assets">Assets</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_signatories')): ?>
      <a href="/signatories">Signatories</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_permissions')): ?>
      <a href="/admin/permissions">Permissions</a>
    <?php endif; ?>
    <?php if ($isEffectiveSuperAdmin): ?>
      <a href="/super-admin">Super Admin</a>
    <?php endif; ?>
    <?php if ($current): ?>
      <a href="/reviews">My Reviews</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'approve_email_send')): ?>
      <a href="/email-approvals">Email Approvals</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_orders')): ?>
      <a href="/disputes">Disputes</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'view_audit_log')): ?>
      <a href="/audit-log">Audit Log</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'view_reports')): ?>
      <a href="/reports">Reports</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'edit_locked_data')): ?>
      <a href="/admin/overrides">Admin Overrides</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_field_protection')): ?>
      <a href="/admin/field-protection">Field Protection</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_users')): ?>
      <a href="/users">Users</a>
    <?php endif; ?>
    <?php if ($current && PermissionService::can((int)$current['id'], $current['role_id'] !== null ? (int)$current['role_id'] : null, 'manage_sample_data')): ?>
      <a href="/sample-data">Sample Data</a>
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
<main class="page">
  <?php foreach (Flash::pull() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
