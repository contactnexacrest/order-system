<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AdminOverrideController;
use App\Controllers\AmendmentController;
use App\Controllers\AnnexureController;
use App\Controllers\AssetController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\ClientPortalController;
use App\Controllers\PiIntakeController;
use App\Controllers\PiIntakeReviewController;
use App\Controllers\DashboardController;
use App\Controllers\DisputeController;
use App\Controllers\DocumentController;
use App\Controllers\FileStoreController;
use App\Controllers\EmailDispatchController;
use App\Controllers\FieldProtectionController;
use App\Controllers\HolidayController;
use App\Controllers\HsCodeController;
use App\Controllers\WatermarkController;
use App\Controllers\ReferenceDocController;
use App\Controllers\NotificationController;
use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\SampleDataController;
use App\Controllers\SettingsController;
use App\Controllers\SignatoryController;
use App\Controllers\PermissionAdminController;
use App\Controllers\ProductController;
use App\Controllers\SuperAdminController;
use App\Middleware\SuperAdminOnly;
use App\Middleware\TestModeGate;
use App\Controllers\TestModeController;
use App\Controllers\UserController;
use App\Helpers\Router;
use App\Middleware\ClientAuth;
use App\Middleware\CsrfCheck;
use App\Middleware\PermissionCheck;
use App\Middleware\SessionAuth;

$router = new Router();

$auth = new AuthController();
$dashboard = new DashboardController();
$settings = new SettingsController();
$assets = new AssetController();
$signatories = new SignatoryController();
$superAdmin = new SuperAdminController();
$permissionAdmin = new PermissionAdminController();
$clients = new ClientController();
$clientIntake = new ClientIntakeController();
$clientIntakeReview = new ClientIntakeReviewController();
$piIntake = new PiIntakeController();
$piIntakeReview = new PiIntakeReviewController();
$clientPortal = new ClientPortalController();
$orders = new OrderController();
$annexure = new AnnexureController();
$documents = new DocumentController();
$fileStore = new FileStoreController();
$reviews = new ReviewController();
$emailDispatch = new EmailDispatchController();
$amendments = new AmendmentController();
$disputes = new DisputeController();
$holidays = new HolidayController();
$hsCodes = new HsCodeController();
$watermarks = new WatermarkController();
$referenceDocs = new ReferenceDocController();
$auditLog = new AuditLogController();
$notifications = new NotificationController();
$reports = new ReportController();
$adminOverrides = new AdminOverrideController();
$fieldProtection = new FieldProtectionController();
$users = new UserController();
$sampleData = new SampleDataController();
$products = new ProductController();
$testMode = new TestModeController();

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

// Public quotation-stage intake form — the actual entry point into the
// system for a Zoho-qualified prospect. No auth: staff sends this link
// directly. Submitting only ever creates a client_intake_submissions row,
// never a client or order (see ClientIntakeController's docblock).
$router->get('/quotation-details', [$clientIntake, 'show']);
$router->post('/quotation-details/submit', [$clientIntake, 'submit'], [CsrfCheck::verify()]);
$router->get('/quotation-details/edit/{token}', [$clientIntake, 'showEdit']);
$router->post('/quotation-details/edit/{token}', [$clientIntake, 'updateSubmission'], [CsrfCheck::verify()]);

// PI-stage intake — a SEPARATE public form from the Quotation-stage one
// above, per the business's Client_Forms.xlsx spec (schema.sql Section
// AA). Staff generate the per-order link (see OrderController's
// generatePiFormLink); the client never reaches this without one.
$router->get('/pi-details/{token}', [$piIntake, 'show']);
$router->post('/pi-details/{token}', [$piIntake, 'submit'], [CsrfCheck::verify()]);

// Client portal login/set-password — public (unauthenticated) by nature,
// gated instead by the client_logins row provisioned at the Stage 3
// advance-cleared gate (ClientPortalService::provisionIfNeeded).
$router->get('/client/login', [$clientPortal, 'showLogin']);
$router->post('/client/login', [$clientPortal, 'login'], [CsrfCheck::verify()]);
$router->get('/client/logout', [$clientPortal, 'logout']);
$router->get('/client/set-password/{token}', [$clientPortal, 'showSetPassword']);
$router->post('/client/set-password/{token}', [$clientPortal, 'setPassword'], [CsrfCheck::verify()]);

// --- Authenticated routes ---
$router->get('/force-password-change', [$auth, 'showForcePasswordChange'], [SessionAuth::required()]);
$router->post('/force-password-change', [$auth, 'forcePasswordChange'], [SessionAuth::required(), CsrfCheck::verify()]);

$router->get('/', [$dashboard, 'index'], [SessionAuth::required()]);

$router->get('/settings', [$settings, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/update', [$settings, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/holidays', [$holidays, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/holidays', [$holidays, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/holidays/{id}/update', [$holidays, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/holidays/{id}/delete', [$holidays, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

$router->get('/hs-codes', [$hsCodes, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes')]);
$router->post('/hs-codes', [$hsCodes, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/{id}/update', [$hsCodes, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/{id}/toggle', [$hsCodes, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/{id}/delete', [$hsCodes, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);

$router->get('/watermarks', [$watermarks, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/watermarks/{which}', [$watermarks, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/reference-docs', [$referenceDocs, 'index'], [SessionAuth::required()]);
$router->get('/reference-docs/custom/create', [$referenceDocs, 'customCreateForm'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/reference-docs/custom', [$referenceDocs, 'customCreate'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/reference-docs/custom/{id}', [$referenceDocs, 'customShow'], [SessionAuth::required()]);
$router->get('/reference-docs/custom/{id}/edit', [$referenceDocs, 'customEditForm'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/reference-docs/custom/{id}/update', [$referenceDocs, 'customUpdate'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/reference-docs/custom/{id}/delete', [$referenceDocs, 'customDelete'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/reference-docs/custom/{id}/download', [$referenceDocs, 'customDownload'], [SessionAuth::required()]);
$router->get('/reference-docs/{code}', [$referenceDocs, 'show'], [SessionAuth::required()]);
$router->get('/reference-docs/{code}/edit', [$referenceDocs, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/reference-docs/{code}', [$referenceDocs, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

// NOTE: deliberately NOT "/assets" — that path collides with the static
// public_html/assets/ folder (css/js/img). On Apache, .htaccess's -d check
// would see that real directory and never reach index.php at all, so this
// route would 404 in production exactly like it does in local testing.
$router->get('/company-assets', [$assets, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_assets')]);
$router->get('/company-assets/preview', [$assets, 'preview'], [SessionAuth::required()]);
$router->post('/company-assets/replace', [$assets, 'replace'], [SessionAuth::required(), PermissionCheck::requires('manage_assets'), CsrfCheck::verify()]);
$router->post('/company-assets/{id}/delete', [$assets, 'delete'], [SessionAuth::required(), PermissionCheck::requires('delete_assets'), CsrfCheck::verify()]);

$router->get('/signatories', [$signatories, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories')]);
$router->post('/signatories/designations', [$signatories, 'createDesignation'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/designations/{id}/toggle', [$signatories, 'toggleDesignation'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/designations/{id}/update', [$signatories, 'updateDesignation'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/designations/{id}/delete', [$signatories, 'deleteDesignation'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/users/{id}/eligibility', [$signatories, 'setEligibility'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/users/{id}/upload', [$signatories, 'uploadUserAsset'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/user-assets/{id}/deactivate', [$signatories, 'deactivateUserAsset'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->get('/signatories/user-assets/{id}/preview', [$signatories, 'previewUserAsset'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories')]);
$router->post('/signatories/global-default', [$signatories, 'setGlobalDefault'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);
$router->post('/signatories/document-types/{id}', [$signatories, 'setDocumentTypeDefault'], [SessionAuth::required(), PermissionCheck::requires('manage_signatories'), CsrfCheck::verify()]);

$router->get('/super-admin', [$superAdmin, 'index'], [SessionAuth::required(), SuperAdminOnly::required()]);
$router->post('/super-admin/delegations', [$superAdmin, 'grantDelegation'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);
$router->post('/super-admin/delegations/{id}/revoke', [$superAdmin, 'revokeDelegation'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);
$router->post('/super-admin/set-permanent', [$superAdmin, 'setPermanent'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);

// Test Mode (docs/schema.sql Section V) — Super Admin only, same tier as
// /super-admin itself, given how broadly it affects the whole system
// (client access, every business email, every admin settings screen).
$router->get('/test-mode', [$testMode, 'index'], [SessionAuth::required(), SuperAdminOnly::required()]);
$router->post('/test-mode/enable', [$testMode, 'enable'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);
$router->post('/test-mode/disable', [$testMode, 'disable'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);
$router->post('/test-mode/test-email', [$testMode, 'updateTestEmail'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);
$router->post('/test-mode/delete-test-data', [$testMode, 'deleteTestData'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);

$router->get('/admin/permissions', [$permissionAdmin, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions')]);
$router->post('/admin/permissions/grant', [$permissionAdmin, 'grantOverride'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/permissions/{id}/remove', [$permissionAdmin, 'removeOverride'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/roles', [$permissionAdmin, 'roleCreate'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->get('/admin/roles/{id}/edit', [$permissionAdmin, 'roleEditForm'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions')]);
$router->post('/admin/roles/{id}/update', [$permissionAdmin, 'roleUpdate'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/roles/{id}/delete', [$permissionAdmin, 'roleDelete'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->get('/admin/roles/{id}/permissions', [$permissionAdmin, 'rolePermissionsForm'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions')]);
$router->post('/admin/roles/{id}/permissions', [$permissionAdmin, 'rolePermissionsUpdate'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/permission-definitions', [$permissionAdmin, 'permissionCreate'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->get('/admin/permission-definitions/{id}/edit', [$permissionAdmin, 'permissionEditForm'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions')]);
$router->post('/admin/permission-definitions/{id}/update', [$permissionAdmin, 'permissionUpdate'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/permission-definitions/{id}/delete', [$permissionAdmin, 'permissionDelete'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);

// --- Phase B: clients / orders / stage gates / document generation ---
$router->get('/clients', [$clients, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/clients/inactive', [$clients, 'inactiveIndex'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/clients/create', [$clients, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients', [$clients, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/clients/{id}', [$clients, 'show'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/clients/{id}/edit', [$clients, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients/{id}/update', [$clients, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/clients/{id}/toggle-active', [$clients, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// Staff review queue for public quotation-details submissions.
$router->get('/client-intake', [$clientIntakeReview, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/client-intake/{id}/accept', [$clientIntakeReview, 'accept'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/client-intake/{id}/reject', [$clientIntakeReview, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->get('/pi-intake-review', [$piIntakeReview, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/pi-intake-review/{id}/accept', [$piIntakeReview, 'accept'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/pi-intake-review/{id}/reject', [$piIntakeReview, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->get('/orders', [$orders, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/orders/archived', [$orders, 'archivedIndex'], [SessionAuth::required(), PermissionCheck::requires('view_archived_orders')]);
$router->get('/orders/create', [$orders, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders', [$orders, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/orders/{id}', [$orders, 'show'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/archive', [$orders, 'archive'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/unarchive', [$orders, 'unarchive'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-po', [$orders, 'recordBuyerPo'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-po/documents', [$orders, 'uploadBuyerPoDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance', [$orders, 'recordAdvancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment-reports/{reportId}/reviewed', [$orders, 'markPaymentReportReviewed'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance/clear', [$orders, 'clearAdvancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/production-status', [$orders, 'updateProductionStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// Annexure A — Product Technical Specifications (schema tables shipped
// with no screen ever built against them; this is that missing piece).
$router->get('/orders/{id}/annexure', [$annexure, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/annexure/toggle', [$annexure, 'toggleInclude'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products', [$annexure, 'createProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products/{productId}', [$annexure, 'updateProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products/{productId}/delete', [$annexure, 'deleteProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products/{productId}/images', [$annexure, 'uploadImage'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/images/{imageId}/remove', [$annexure, 'removeImage'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// --- Phase C: Stages 4-9 ---
$router->post('/orders/{id}/buyer-acknowledged', [$orders, 'confirmBuyerAcknowledged'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->post('/orders/{id}/suppliers', [$orders, 'createSupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po', [$orders, 'saveSupplierPo'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/signed', [$orders, 'confirmSupplierSigned'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/documents', [$orders, 'uploadSupplierPoDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

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
$router->get('/file-store/{id}/download', [$fileStore, 'download'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);

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
// No PermissionCheck wrapper: a Level-2 approver may cancel any row, but a
// plain requester may also cancel their own — that ownership check can only
// happen once the row is loaded, so it lives in EmailDispatchService::cancelSend().
$router->post('/email-log/{emailLogId}/cancel', [$emailDispatch, 'cancel'], [SessionAuth::required(), CsrfCheck::verify()]);

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
$router->get('/orders/{id}/audit-log', [$auditLog, 'forOrder'], [SessionAuth::required(), PermissionCheck::requires('view_audit_log')]);
$router->get('/orders/{id}/dossier', [$orders, 'downloadDossier'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/pi-form-link', [$orders, 'generatePiFormLink'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

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
$router->post('/reports/saved/{reportId}/update', [$reports, 'updateDefinition'], [SessionAuth::required(), PermissionCheck::requires('manage_report_definitions'), CsrfCheck::verify()]);
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
$router->get('/users/{id}/edit', [$users, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_users')]);
$router->post('/users/{id}/update', [$users, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/toggle-active', [$users, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/force-reset-password', [$users, 'forceResetPassword'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);

// --- Client portal (authenticated, structurally separate from staff /
// SessionAuth) — gated by ClientAuth::required(), a different session key. ---
$router->get('/client', [$clientPortal, 'dashboard'], [ClientAuth::required()]);
$router->get('/client/account', [$clientPortal, 'showAccount'], [ClientAuth::required()]);
$router->post('/client/account/password', [$clientPortal, 'changePassword'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->get('/client/orders/{id}', [$clientPortal, 'showOrder'], [ClientAuth::required()]);
$router->post('/client/orders/{id}/report-payment', [$clientPortal, 'reportPayment'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->get('/client/documents/{id}/download', [$clientPortal, 'downloadDocument'], [ClientAuth::required()]);

$router->get('/sample-data', [$sampleData, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_sample_data')]);
$router->post('/sample-data/load', [$sampleData, 'load'], [SessionAuth::required(), PermissionCheck::requires('manage_sample_data'), CsrfCheck::verify()]);
$router->post('/sample-data/clear', [$sampleData, 'clear'], [SessionAuth::required(), PermissionCheck::requires('manage_sample_data'), CsrfCheck::verify()]);

// --- Product Interface (internal product catalog) — Section U. Fully
// independent of the order pipeline; internal staff reference only,
// never shown to clients/buyers. See ProductController's docblock for
// the browse_product_catalog / view_product_pricing server-side gating.
$router->get('/products', [$products, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_product_catalog')]);
$router->get('/products/create', [$products, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
$router->post('/products/create', [$products, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->get('/products/{id}', [$products, 'show'], [SessionAuth::required(), PermissionCheck::requires('view_product_catalog')]);
$router->get('/products/{id}/edit', [$products, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
$router->post('/products/{id}/update', [$products, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/delete', [$products, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/images/upload', [$products, 'uploadImage'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/images/{imageId}/delete', [$products, 'deleteImage'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
// Streams the actual image bytes (storage lives outside the web root, exactly like AssetController::preview()) — gated on plain view access, not manage.
$router->get('/products/images/{imageId}/view', [$products, 'viewImage'], [SessionAuth::required(), PermissionCheck::requires('view_product_catalog')]);
$router->post('/products/{id}/suppliers/add', [$products, 'addSupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/update', [$products, 'updateSupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/delete', [$products, 'deleteSupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/set-primary', [$products, 'setPrimarySupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/misc-charges/add', [$products, 'addMiscCharge'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/misc-charges/{chargeId}/delete', [$products, 'deleteMiscCharge'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);

// Test Mode's two access gates (docs/schema.sql Section V) run before
// dispatch: the client-facing block must intercept public routes that
// never reach SessionAuth, and the admin-settings freeze must apply
// regardless of role, so neither can be expressed as per-route middleware
// alongside PermissionCheck (the Router has no global "before every
// route" concept — see TestModeGate's docblock).
$requestPath = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/', '/') ?: '/';
TestModeGate::blockClientFacingInTestMode($requestPath);
TestModeGate::blockAdminWritesInTestMode($_SERVER['REQUEST_METHOD'], $requestPath);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
