<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AdminOverrideController;
use App\Controllers\AmendmentController;
use App\Controllers\AssetController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\DisputeController;
use App\Controllers\DocumentController;
use App\Controllers\EmailDispatchController;
use App\Controllers\FieldProtectionController;
use App\Controllers\NotificationController;
use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\SampleDataController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Helpers\Router;
use App\Middleware\CsrfCheck;
use App\Middleware\PermissionCheck;
use App\Middleware\SessionAuth;

$router = new Router();

$auth = new AuthController();
$dashboard = new DashboardController();
$settings = new SettingsController();
$assets = new AssetController();
$clients = new ClientController();
$orders = new OrderController();
$documents = new DocumentController();
$reviews = new ReviewController();
$emailDispatch = new EmailDispatchController();
$amendments = new AmendmentController();
$disputes = new DisputeController();
$auditLog = new AuditLogController();
$notifications = new NotificationController();
$reports = new ReportController();
$adminOverrides = new AdminOverrideController();
$fieldProtection = new FieldProtectionController();
$users = new UserController();
$sampleData = new SampleDataController();

// --- Public (unauthenticated) routes ---
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login'], [CsrfCheck::verify()]);
$router->get('/2fa', [$auth, 'show2fa']);
$router->post('/2fa', [$auth, 'verify2fa'], [CsrfCheck::verify()]);
$router->get('/logout', [$auth, 'logout']);
$router->get('/forgot-password', [$auth, 'showForgotPassword']);
$router->post('/forgot-password', [$auth, 'forgotPassword'], [CsrfCheck::verify()]);
$router->get('/reset-password/{token}', [$auth, 'showResetPassword']);
$router->post('/reset-password/{token}', [$auth, 'resetPassword'], [CsrfCheck::verify()]);

// --- Authenticated routes ---
$router->get('/force-password-change', [$auth, 'showForcePasswordChange'], [SessionAuth::required()]);
$router->post('/force-password-change', [$auth, 'forcePasswordChange'], [SessionAuth::required(), CsrfCheck::verify()]);

$router->get('/', [$dashboard, 'index'], [SessionAuth::required()]);

$router->get('/settings', [$settings, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/update', [$settings, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

// NOTE: deliberately NOT "/assets" — that path collides with the static
// public_html/assets/ folder (css/js/img). On Apache, .htaccess's -d check
// would see that real directory and never reach index.php at all, so this
// route would 404 in production exactly like it does in local testing.
$router->get('/company-assets', [$assets, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_assets')]);
$router->get('/company-assets/preview', [$assets, 'preview'], [SessionAuth::required()]);
$router->post('/company-assets/replace', [$assets, 'replace'], [SessionAuth::required(), PermissionCheck::requires('manage_assets'), CsrfCheck::verify()]);

// --- Phase B: clients / orders / stage gates / document generation ---
$router->get('/clients', [$clients, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/clients/create', [$clients, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients', [$clients, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/clients/{id}', [$clients, 'show'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);

$router->get('/orders', [$orders, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/orders/create', [$orders, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders', [$orders, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/orders/{id}', [$orders, 'show'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/buyer-po', [$orders, 'recordBuyerPo'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance', [$orders, 'recordAdvancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance/clear', [$orders, 'clearAdvancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/production-status', [$orders, 'updateProductionStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// --- Phase C: Stages 4-9 ---
$router->post('/orders/{id}/buyer-acknowledged', [$orders, 'confirmBuyerAcknowledged'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/suppliers', [$orders, 'createSupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po', [$orders, 'saveSupplierPo'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/signed', [$orders, 'confirmSupplierSigned'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/freight-terms', [$orders, 'saveFreightTerms'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight', [$orders, 'recordFreightPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight/clear', [$orders, 'clearFreightPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/packing', [$orders, 'savePacking'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/shipping', [$orders, 'saveShipping'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-issued', [$orders, 'recordBlIssued'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/scanned-bl-sent', [$orders, 'recordScannedBlSent'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/payment/balance', [$orders, 'recordBalancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/balance/clear', [$orders, 'clearBalancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/bl-originals-received', [$orders, 'recordBlOriginalsReceived'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-endorsed', [$orders, 'recordBlEndorsed'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/close', [$orders, 'closeOrder'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/mark-lost', [$orders, 'markLost'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/documents/generate', [$documents, 'generate'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->get('/documents/{documentId}/download', [$documents, 'download'], [SessionAuth::required(), PermissionCheck::requires('download_pdf')]);

// --- Phase D: review/approval, deferred send, amendments, disputes, audit log ---

// Review & cross-verification (Section 9). Ownership of a specific
// document_reviews row (only the assigned reviewer may act on it) is
// enforced inside ReviewWorkflowService, not by a permission gate here —
// that's a per-row authorization check, not a per-route one.
$router->get('/reviews', [$reviews, 'queue'], [SessionAuth::required()]);
$router->post('/documents/{documentId}/reviewers', [$reviews, 'assign'], [SessionAuth::required(), PermissionCheck::requires('approve_documents'), CsrfCheck::verify()]);
$router->post('/reviews/{reviewId}/approve', [$reviews, 'approve'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/reviews/{reviewId}/reject', [$reviews, 'reject'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/documents/{documentId}/cross-verify', [$reviews, 'crossVerify'], [SessionAuth::required(), PermissionCheck::requires('cross_verify_documents'), CsrfCheck::verify()]);

// Deferred email send (Section 10), 2-level approval.
$router->get('/orders/{id}/documents/{documentId}/send', [$emailDispatch, 'compose'], [SessionAuth::required(), PermissionCheck::requires('generate_documents')]);
$router->post('/orders/{id}/documents/{documentId}/send', [$emailDispatch, 'requestSend'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->get('/email-approvals', [$emailDispatch, 'approvalQueue'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send')]);
$router->post('/email-log/{emailLogId}/approve', [$emailDispatch, 'approve'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send'), CsrfCheck::verify()]);
$router->post('/email-log/{emailLogId}/reject', [$emailDispatch, 'reject'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send'), CsrfCheck::verify()]);

// Payment Terms Amendments (Section 8).
$router->get('/orders/{id}/amendments', [$amendments, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/amendments', [$amendments, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/md-approve', [$amendments, 'mdApprove'], [SessionAuth::required(), PermissionCheck::requires('approve_documents'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/reject', [$amendments, 'reject'], [SessionAuth::required(), PermissionCheck::requires('approve_documents'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/generate-document', [$amendments, 'generateDocument'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/signed-copy', [$amendments, 'uploadSignedCopy'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// Dispute management (Section 16).
$router->get('/disputes', [$disputes, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/orders/{id}/disputes', [$disputes, 'forOrder'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/disputes', [$disputes, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/disputes/{disputeId}/status', [$disputes, 'updateStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/disputes/{disputeId}/documents', [$disputes, 'uploadDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// Audit log viewer (Section 14/17) — read-only, no delete route exists anywhere.
$router->get('/audit-log', [$auditLog, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_audit_log')]);

// Notifications bell.
$router->get('/notifications', [$notifications, 'index'], [SessionAuth::required()]);

// --- Phase E: dashboard/reports (Section 16), gated on view_reports; saving/deleting a
// saved report additionally requires manage_report_definitions. ---
$router->get('/reports', [$reports, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/client/{clientId}', [$reports, 'client'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/order/{orderId}', [$reports, 'order'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/aggregate', [$reports, 'aggregate'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/queues', [$reports, 'queues'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->post('/reports/save', [$reports, 'saveDefinition'], [SessionAuth::required(), PermissionCheck::requires('manage_report_definitions'), CsrfCheck::verify()]);
$router->get('/reports/saved/{reportId}/run', [$reports, 'runDefinition'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->post('/reports/saved/{reportId}/delete', [$reports, 'deleteDefinition'], [SessionAuth::required(), PermissionCheck::requires('manage_report_definitions'), CsrfCheck::verify()]);

// --- Phase E: admin field-override system (Section 13) — every route requires
// edit_locked_data (Admin/MD by default via the seeded wildcard grant), a mandatory
// reason (enforced server-side in each controller action, not just the UI), and CSRF. ---
$router->get('/admin/overrides', [$adminOverrides, 'index'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data')]);
$router->post('/admin/overrides/document-types', [$adminOverrides, 'updateDocumentTypes'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/admin/overrides/tc-clauses', [$adminOverrides, 'updateTcClauses'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/admin/overrides/payment-presets', [$adminOverrides, 'updatePaymentPresets'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);

$router->get('/admin/field-protection', [$fieldProtection, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_field_protection')]);
$router->post('/admin/field-protection/request', [$fieldProtection, 'createRequest'], [SessionAuth::required(), PermissionCheck::requires('manage_field_protection'), CsrfCheck::verify()]);
$router->post('/admin/field-protection/{requestId}/approve', [$fieldProtection, 'approve'], [SessionAuth::required(), PermissionCheck::requires('manage_field_protection'), CsrfCheck::verify()]);
$router->post('/admin/field-protection/{requestId}/reject', [$fieldProtection, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_field_protection'), CsrfCheck::verify()]);
$router->post('/clients/{id}/override-unique-number', [$clients, 'overrideUniqueNumber'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/orders/{id}/override-status-lock', [$orders, 'overrideStatusLock'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/override-reference', [$amendments, 'overrideReference'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);

// --- Phase E follow-up: admin user management (Section 14 gap) ---
$router->get('/users', [$users, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_users')]);
$router->post('/users/create', [$users, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/toggle-active', [$users, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/force-reset-password', [$users, 'forceResetPassword'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);

$router->get('/sample-data', [$sampleData, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_sample_data')]);
$router->post('/sample-data/load', [$sampleData, 'load'], [SessionAuth::required(), PermissionCheck::requires('manage_sample_data'), CsrfCheck::verify()]);
$router->post('/sample-data/clear', [$sampleData, 'clear'], [SessionAuth::required(), PermissionCheck::requires('manage_sample_data'), CsrfCheck::verify()]);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
