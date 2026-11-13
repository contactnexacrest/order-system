<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AdminOverrideController;
use App\Controllers\AmendmentController;
use App\Controllers\AnnexureController;
use App\Controllers\AssetController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\BuyerActionController;
use App\Controllers\PaymentActionController;
use App\Controllers\VendorDetailsController;
use App\Controllers\SupplierActionController;
use App\Controllers\CaController;
use App\Controllers\OrderFinancialsController;
use App\Controllers\IncentiveController;
use App\Controllers\OrderExpenseController;
use App\Controllers\VendorBillController;
use App\Controllers\ClientController;
use App\Controllers\DataExportController;
use App\Controllers\BackupController;
use App\Controllers\AssociateAdminController;
use App\Controllers\SelfApprovalController;
use App\Controllers\AssociatePortalController;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\ClientPortalController;
use App\Controllers\PiIntakeController;
use App\Controllers\PiIntakeReviewController;
use App\Controllers\DashboardController;
use App\Controllers\ComplaintController;
use App\Controllers\DisputeController;
use App\Controllers\DocumentController;
use App\Controllers\FileStoreController;
use App\Controllers\GalleryController;
use App\Controllers\EmailDispatchController;
use App\Controllers\FieldProtectionController;
use App\Controllers\HolidayController;
use App\Controllers\HsCodeController;
use App\Controllers\ComplianceTaskTypeController;
use App\Controllers\LogisticsPartnerController;
use App\Controllers\SupplierController;
use App\Controllers\PortController;
use App\Controllers\PortRouteController;
use App\Controllers\DropdownOptionController;
use App\Controllers\PaymentPresetController;
use App\Controllers\WatermarkController;
use App\Controllers\ReferenceDocController;
use App\Controllers\SopController;
use App\Controllers\GettingStartedController;
use App\Controllers\NotificationController;
use App\Controllers\AccountController;
use App\Controllers\EmailTemplateController;
use App\Controllers\UiTemplateController;
use App\Controllers\OrderCommentController;
use App\Controllers\OrderController;
use App\Controllers\ReorderRequestController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\SampleDataController;
use App\Controllers\SettingsController;
use App\Controllers\SignatoryController;
use App\Controllers\PermissionAdminController;
use App\Controllers\ProductController;
use App\Controllers\PricingController;
use App\Controllers\EditPriceController;
use App\Controllers\SuperAdminController;
use App\Middleware\SuperAdminOnly;
use App\Middleware\TestModeGate;
use App\Controllers\TestModeController;
use App\Controllers\UserController;
use App\Helpers\Router;
use App\Middleware\AssociateAuth;
use App\Middleware\ClientAuth;
use App\Middleware\CsrfCheck;
use App\Middleware\OrderLockGuard;
use App\Middleware\PermissionCheck;
use App\Middleware\SessionAuth;

$router = new Router();

// QA Round 6, R6-N04: for a signed-in staff member, a page or action of an
// order, document or order-linked record that does not exist answers 404 —
// checked after the route's own sign-in / permission / CSRF checks, so a
// user without the permission is refused as before and never learns
// whether the record exists.
$router->beforeHandler(static function (string $path): bool {
    if (\App\Services\AuthService::currentUser() === null) {
        return true;
    }
    $missing = \App\Services\OrderAccessService::missingRecordForPath($path);
    if ($missing === null) {
        return true;
    }
    http_response_code(404);
    echo $missing;
    return false;
});

$auth = new AuthController();
$dashboard = new DashboardController();
$settings = new SettingsController();
$assets = new AssetController();
$signatories = new SignatoryController();
$superAdmin = new SuperAdminController();
$permissionAdmin = new PermissionAdminController();
$departments = new \App\Controllers\DepartmentController();
$departmentAdmin = new \App\Controllers\DepartmentAdminController();
$divisions = new \App\Controllers\DivisionController(); // D-257
$domestic = new \App\Controllers\DomesticOrderController(); // D-257 Phase 2
$imports = new \App\Controllers\ImportController(); // D-257 Phase 3
$merchant = new \App\Controllers\MerchantTradeController(); // D-257 Phase 4
$gstRates = new \App\Controllers\GstRateController(); // D-257 Phase 2
$purchasing = new \App\Controllers\PurchasingController();
$coordination = new \App\Controllers\CoordinationController();
$milestones = new \App\Controllers\PaymentMilestoneController();
$productCategories = new \App\Controllers\ProductCategoryController();
$clients = new ClientController();
$clientIntake = new ClientIntakeController();
$clientIntakeReview = new ClientIntakeReviewController();
$piIntake = new PiIntakeController();
$piIntakeReview = new PiIntakeReviewController();
$buyerAction = new BuyerActionController();
$paymentAction = new PaymentActionController();
$vendorDetails = new VendorDetailsController();
$supplierAction = new SupplierActionController();
$reorderRequests = new ReorderRequestController();
$clientPortal = new ClientPortalController();
$orders = new OrderController();
$orderComments = new OrderCommentController();
$emailTemplates = new EmailTemplateController();
$uiTemplates = new UiTemplateController();
$account = new AccountController();
$annexure = new AnnexureController();
$documents = new DocumentController();
$fileStore = new FileStoreController();
$gallery = new GalleryController();
$reviews = new ReviewController();
$emailDispatch = new EmailDispatchController();
$amendments = new AmendmentController();
$disputes = new DisputeController();
$complaints = new ComplaintController();
$holidays = new HolidayController();
$hsCodes = new HsCodeController();
$logisticsPartners = new LogisticsPartnerController();
$suppliersDirectory = new SupplierController();
$ports = new PortController();
$portRoutes = new PortRouteController();
$dropdownOptions = new DropdownOptionController();
$paymentPresets = new PaymentPresetController();
$complianceTaskTypes = new ComplianceTaskTypeController();
$watermarks = new WatermarkController();
$referenceDocs = new ReferenceDocController();
$sop = new SopController();
$gettingStarted = new GettingStartedController();
$help = new \App\Controllers\HelpController();
$ca = new CaController();
$orderFinancials = new OrderFinancialsController();
$incentives = new IncentiveController();
$associateAdmin = new AssociateAdminController();
$selfApproval = new SelfApprovalController();
$associatePortal = new AssociatePortalController();
$orderExpenses = new OrderExpenseController();
$vendorBills = new VendorBillController();
$auditLog = new AuditLogController();
$notifications = new NotificationController();
$reports = new ReportController();
$adminOverrides = new AdminOverrideController();
$fieldProtection = new FieldProtectionController();
$users = new UserController();
$sampleData = new SampleDataController();
$dataExport = new DataExportController();
$backups = new BackupController();
$products = new ProductController();
$productConfigure = new \App\Controllers\ProductConfigureController(); // D-361
$pricing = new PricingController();
$editPrice = new EditPriceController();
$testMode = new TestModeController();

// --- Public (unauthenticated) routes ---
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login'], [CsrfCheck::verify()]);
$router->get('/2fa', [$auth, 'show2fa']);
$router->post('/2fa', [$auth, 'verify2fa'], [CsrfCheck::verify()]);
$router->post('/2fa/resend', [$auth, 'resend2fa'], [CsrfCheck::verify()]);   // D-265
$router->post('/2fa/backup', [$auth, 'verify2faBackup'], [CsrfCheck::verify()]); // D-265
// QA-5 AUTH-15: was a bare GET with no CSRF check — a plain
// <img src="…/logout"> on any page the victim's browser loaded would
// silently end their session (logout CSRF), usable to repeatedly force out
// a coworker mid-approval as harassment. Now POST + CsrfCheck, like every
// other state-changing action.
$router->post('/logout', [$auth, 'logout'], [CsrfCheck::verify()]);
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
// Owner decision 1 Nov 2026 (AS-5): an associate's one-off link, and the buyer's e-mail code + "X introduced us".
$router->get('/invite/{token}', [$clientIntake, 'invite']);
// Owner decision D-253: the public associate registration form (one-use link from the MD).
$router->get('/associate-register/{token}', [new \App\Controllers\AssociateRegistrationController(), 'show']);
$router->post('/associate-register/{token}', [new \App\Controllers\AssociateRegistrationController(), 'submit'], [CsrfCheck::verify()]);
$router->get('/quotation-details/verify', [$clientIntake, 'showVerify']);
$router->post('/quotation-details/verify', [$clientIntake, 'verify'], [CsrfCheck::verify()]);
$router->get('/quotation-details/edit/{token}', [$clientIntake, 'showEdit']);
$router->post('/quotation-details/edit/{token}', [$clientIntake, 'updateSubmission'], [CsrfCheck::verify()]);

// PI-stage intake — a SEPARATE public form from the Quotation-stage one
// above, per the business's Client_Forms.xlsx spec (schema.sql Section
// AA). Staff generate the per-order link (see OrderController's
// generatePiFormLink); the client never reaches this without one.
$router->get('/pi-details/{token}', [$piIntake, 'show']);
$router->post('/pi-details/{token}', [$piIntake, 'submit'], [CsrfCheck::verify()]);

// 90-item backlog #75 — public, no-login counterpart to the client-portal
// OC-acknowledgment and Buyer-PO-upload actions. Staff generate the
// per-order link (see OrderController::generateBuyerActionLink()); the
// buyer never reaches this without one. See BuyerActionController's
// docblock and docs/schema.sql Section BG.
// Owner request (2026-10-26, t4): no-login Supplier Action Link (Step 7c).
$router->get('/supplier-action/{token}', [$supplierAction, 'show']);
$router->get('/supplier-action/{token}/po-document', [$supplierAction, 'downloadSupplierPo']);
$router->post('/supplier-action/{token}/acknowledgment', [$supplierAction, 'uploadAcknowledgment'], [CsrfCheck::verify()]);
$router->get('/buyer-action/{token}', [$buyerAction, 'show']);
$router->post('/buyer-action/{token}/acknowledge-oc', [$buyerAction, 'acknowledgeOc'], [CsrfCheck::verify()]);
$router->get('/buyer-action/{token}/buyer-po-document', [$buyerAction, 'downloadBuyerPo']);
$router->get('/buyer-action/{token}/proforma-invoice', [$buyerAction, 'downloadProformaInvoice']);
$router->get('/buyer-action/{token}/order-confirmation', [$buyerAction, 'downloadOrderConfirmation']);
$router->post('/buyer-action/{token}/buyer-po', [$buyerAction, 'uploadBuyerPo'], [CsrfCheck::verify()]);
$router->post('/buyer-action/{token}/report-payment', [$buyerAction, 'reportPayment'], [CsrfCheck::verify()]);
// Owner request (2026-10-27): no-login Payment Action Link — payment details only.
$router->get('/payment-action/{token}', [$paymentAction, 'show']);
$router->get('/payment-action/{token}/document', [$paymentAction, 'downloadDocument']);
$router->post('/payment-action/{token}/report', [$paymentAction, 'report'], [CsrfCheck::verify()]);
// Owner request (2026-10-27): no-login Vendor Details Link — a supplier / logistics partner fills in their company and bank details.
$router->get('/vendor-details/{token}', [$vendorDetails, 'show']);
$router->post('/vendor-details/{token}', [$vendorDetails, 'submit'], [CsrfCheck::verify()]);
// Owner decisions 11 Nov 2026 (D-294): the supplier / logistics partner confirms new bank details (link to the registered e-mail).
$vendorBankChange = new \App\Controllers\VendorBankChangeController();
$router->get('/vendor-bank-confirm/{token}', [$vendorBankChange, 'show']);
$router->post('/vendor-bank-confirm/{token}', [$vendorBankChange, 'confirm'], [CsrfCheck::verify()]);
$router->post('/vendor-bank-confirm/{token}/not-us', [$vendorBankChange, 'notUs'], [CsrfCheck::verify()]);

// Client portal login/set-password — public (unauthenticated) by nature,
// gated instead by the client_logins row provisioned at the Stage 3
// advance-cleared gate (ClientPortalService::provisionIfNeeded).
$router->get('/client/login', [$clientPortal, 'showLogin']);
$router->post('/client/login', [$clientPortal, 'login'], [CsrfCheck::verify()]);
$router->get('/client/login/code', [$clientPortal, 'showLoginCode']);                                  // D-265
$router->post('/client/login/code', [$clientPortal, 'verifyLoginCode'], [CsrfCheck::verify()]);      // D-265
$router->post('/client/login/code/resend', [$clientPortal, 'resendLoginCode'], [CsrfCheck::verify()]); // D-265
$router->post('/client/logout', [$clientPortal, 'logout'], [CsrfCheck::verify()]);
$router->post('/client/end-impersonation', [$clientPortal, 'endImpersonation'], [CsrfCheck::verify()]);
$router->get('/client/set-password/{token}', [$clientPortal, 'showSetPassword']);
$router->post('/client/set-password/{token}', [$clientPortal, 'setPassword'], [CsrfCheck::verify()]);
$router->get('/client/forgot-password', [$clientPortal, 'showForgotPassword']);
$router->post('/client/forgot-password', [$clientPortal, 'forgotPassword'], [CsrfCheck::verify()]);

// --- Authenticated routes ---
$router->get('/force-password-change', [$auth, 'showForcePasswordChange'], [SessionAuth::required()]);
$router->post('/force-password-change', [$auth, 'forcePasswordChange'], [SessionAuth::required(), CsrfCheck::verify()]);

$router->get('/', [$dashboard, 'index'], [SessionAuth::required()]);

// Owner decisions D-280 / D-281 (11 Nov 2026): Admin › E-mail settings.
$emailSettings = new \App\Controllers\EmailSettingsController();
$router->get('/admin/email-settings', [$emailSettings, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
// Owner decision D-312 (point 4 B): Admin › Setup checklist.
$setupChecklist = new \App\Controllers\SetupChecklistController();
$router->get('/admin/setup-checklist', [$setupChecklist, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/admin/setup-checklist/tick', [$setupChecklist, 'tick'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/admin/setup-checklist/apply-recommended', [$setupChecklist, 'applyRecommended'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/admin/setup-checklist/national-holidays', [$setupChecklist, 'nationalHolidays'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/admin/email-settings', [$emailSettings, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
// D-283: "Stay logged in" (the 2-minute warning in app.js) — one per signed-in area.
$sessionKeepAlive = new \App\Controllers\SessionController();
$router->post('/keep-alive', [$sessionKeepAlive, 'staffKeepAlive'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/client/keep-alive', [$sessionKeepAlive, 'clientKeepAlive'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/keep-alive', [$sessionKeepAlive, 'associateKeepAlive'], [AssociateAuth::required(), CsrfCheck::verify()]);
// D-282: staff send a client a new set-password link (Client portal login block).
$router->post('/clients/{id}/portal/send-link', [$clients, 'sendPortalLink'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
// D-283: forget every computer remembered for the login code — one user (Admin) or yourself.
$router->post('/users/{id}/forget-computers', [$users, 'forgetComputers'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/account/forget-computers', [$account, 'forgetComputers'], [SessionAuth::required(), CsrfCheck::verify()]);

$router->get('/settings', [$settings, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/update', [$settings, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
// D-349: MD name / title change — "Apply it to:" open orders and draft documents.
$router->get('/settings/md-change', [$settings, 'mdChangeForm'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/md-change', [$settings, 'mdChangeSave'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/settings/test-zoho-email', [$settings, 'testZohoEmail'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/settings/test-zoho-books', [$settings, 'testZohoBooks'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/settings/test-smtp-email', [$settings, 'testSmtpEmail'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
// Owner points 5/8/9 (2 Nov 2026): Document copies clean-up (drafts, never-sent superseded copies, old CAFIN).
$documentCleanup = new \App\Controllers\DocumentCleanupController();
$router->get('/settings/document-cleanup', [$documentCleanup, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/document-cleanup', [$documentCleanup, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/settings/document-cleanup/run', [$documentCleanup, 'run'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
// Owner decision D-266 (7 Nov 2026): who sees which SOP chapter.
$sopAccess = new \App\Controllers\SopAccessController();
$router->get('/admin/sop-access', [$sopAccess, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/admin/sop-access/library', [$sopAccess, 'saveLibrary'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/admin/sop-access/chapter', [$sopAccess, 'saveChapter'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/admin/sop-access/role', [$sopAccess, 'saveRole'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/users/{id}/sop-chapters', [$sopAccess, 'addUserExtra'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/users/{id}/sop-chapters/remove', [$sopAccess, 'removeUserExtra'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
// Owner follow-up on point 18 (2 Nov 2026): the "Why is it different?" reasons on buyer remittance forms.
// Owner decision 2 Nov 2026 (D-235): TDS on associate commission — settings and the quarterly TDS register.
$associateTds = new \App\Controllers\AssociateTdsController();
$router->get('/settings/associate-tds', [$associateTds, 'settings'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/associate-tds', [$associateTds, 'saveSettings'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/associates/tds', [$associateTds, 'register'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_incentives', 'pay_incentives', 'ca_module_view'])]);
$router->post('/associates/tds/{payoutId}/challan', [$associateTds, 'saveChallan'], [SessionAuth::required(), PermissionCheck::requires('pay_incentives'), CsrfCheck::verify()]);
$remittanceReasons = new \App\Controllers\RemittanceReasonController();
$router->get('/settings/remittance-reasons', [$remittanceReasons, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/settings/remittance-reasons', [$remittanceReasons, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/settings/remittance-reasons/{id}/update', [$remittanceReasons, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/settings/remittance-reasons/{id}/toggle', [$remittanceReasons, 'toggle'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/holidays', [$holidays, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/holidays', [$holidays, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/holidays/{id}/update', [$holidays, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/holidays/{id}/delete', [$holidays, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

$router->get('/hs-codes', [$hsCodes, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes')]);
$router->post('/hs-codes', [$hsCodes, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/{id}/update', [$hsCodes, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/{id}/toggle', [$hsCodes, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/{id}/delete', [$hsCodes, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/bulk-import', [$hsCodes, 'bulkImport'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/product-guide', [$hsCodes, 'importProductGuide'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);
$router->post('/hs-codes/product-guide/{id}/delete', [$hsCodes, 'deleteProductGuideEntry'], [SessionAuth::required(), PermissionCheck::requires('manage_hs_codes'), CsrfCheck::verify()]);

$router->get('/logistics-partners', [$logisticsPartners, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners')]);
$router->get('/logistics-partners/create', [$logisticsPartners, 'createForm'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners')]);
$router->post('/logistics-partners', [$logisticsPartners, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners'), CsrfCheck::verify()]);
$router->get('/logistics-partners/{id}/edit', [$logisticsPartners, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners')]);
$router->post('/logistics-partners/{id}/update', [$logisticsPartners, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners'), CsrfCheck::verify()]);
$router->post('/logistics-partners/{id}/toggle', [$logisticsPartners, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners'), CsrfCheck::verify()]);
$router->post('/logistics-partners/{id}/delete', [$logisticsPartners, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners'), CsrfCheck::verify()]);
$router->post('/logistics-partners/{id}/details-link', [$logisticsPartners, 'sendDetailsLink'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners'), CsrfCheck::verify()]);
// D-294: verify / first check / reject a bank details change, or e-mail the confirmation link again.
foreach (['verify' => 'partnerVerify', 'check' => 'partnerCheck', 'reject' => 'partnerReject', 'resend' => 'partnerResend'] as $__a => $__m) {
    $router->post('/logistics-partners/{id}/bank-changes/{changeId}/' . $__a, [$vendorBankChange, $__m], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_partners'), CsrfCheck::verify()]);
}
// Suppliers directory (docs/schema.sql Section BZ) — same shape as Logistics Partners.
$router->get('/suppliers', [$suppliersDirectory, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers')]);
$router->get('/suppliers/create', [$suppliersDirectory, 'createForm'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers')]);
$router->post('/suppliers', [$suppliersDirectory, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
$router->get('/suppliers/{id}/edit', [$suppliersDirectory, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers')]);
$router->post('/suppliers/{id}/update', [$suppliersDirectory, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
$router->post('/suppliers/{id}/toggle', [$suppliersDirectory, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
$router->post('/suppliers/{id}/delete', [$suppliersDirectory, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
$router->post('/suppliers/{id}/details-link', [$suppliersDirectory, 'sendDetailsLink'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
// D-294: verify / first check / reject a bank details change, or e-mail the confirmation link again.
foreach (['verify' => 'supplierVerify', 'check' => 'supplierCheck', 'reject' => 'supplierReject', 'resend' => 'supplierResend'] as $__a => $__m) {
    $router->post('/suppliers/{id}/bank-changes/{changeId}/' . $__a, [$vendorBankChange, $__m], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
}
// D-303 (point 15): "+ Add a new supplier (quick)" from a product price (before /suppliers/{id}/…).
$router->post('/suppliers/quick-add', [$suppliersDirectory, 'quickAdd'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'manage_suppliers']), CsrfCheck::verify()]);
// D-360: products on the Suppliers pages — "+ Add product" (existing or new product) and the per-offer switch.
$router->post('/suppliers/offers/{offerId}/active', [$suppliersDirectory, 'offerActive'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->get('/suppliers/{id}/add-product', [$suppliersDirectory, 'addProductForm'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), PermissionCheck::requires('record_market_prices')]);
$router->post('/suppliers/{id}/add-product', [$suppliersDirectory, 'addProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->post('/suppliers/{id}/factory-addresses', [$suppliersDirectory, 'addFactoryAddress'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
$router->post('/suppliers/{id}/factory-addresses/{addressId}/delete', [$suppliersDirectory, 'deleteFactoryAddress'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);

$router->get('/ports', [$ports, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_ports')]);
$router->get('/ports/create', [$ports, 'createForm'], [SessionAuth::required(), PermissionCheck::requires('manage_ports')]);
$router->post('/ports', [$ports, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);
$router->get('/ports/{id}/edit', [$ports, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_ports')]);
$router->post('/ports/{id}/update', [$ports, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);
$router->post('/ports/{id}/toggle', [$ports, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);
$router->post('/ports/{id}/set-default', [$ports, 'setDefault'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);

$router->get('/port-routes', [$portRoutes, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_ports')]);
$router->get('/port-routes/create', [$portRoutes, 'createForm'], [SessionAuth::required(), PermissionCheck::requires('manage_ports')]);
$router->post('/port-routes', [$portRoutes, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);
$router->get('/port-routes/{id}/edit', [$portRoutes, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_ports')]);
$router->post('/port-routes/{id}/update', [$portRoutes, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);
$router->post('/port-routes/{id}/toggle', [$portRoutes, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_ports'), CsrfCheck::verify()]);
$router->get('/port-routes/lookup', [$portRoutes, 'lookup'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);

$router->get('/admin/dropdown-options', [$dropdownOptions, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_dropdown_options')]);
$router->post('/admin/dropdown-options/{listKey}/create', [$dropdownOptions, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_dropdown_options'), CsrfCheck::verify()]);
$router->post('/admin/dropdown-options/{listKey}/update', [$dropdownOptions, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_dropdown_options'), CsrfCheck::verify()]);

$router->get('/payment-presets', [$paymentPresets, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_payment_presets')]);
$router->get('/payment-presets/create', [$paymentPresets, 'createForm'], [SessionAuth::required(), PermissionCheck::requires('manage_payment_presets')]);
$router->post('/payment-presets', [$paymentPresets, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_payment_presets'), CsrfCheck::verify()]);
$router->get('/payment-presets/{id}/edit', [$paymentPresets, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_payment_presets')]);
$router->post('/payment-presets/{id}/update', [$paymentPresets, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_payment_presets'), CsrfCheck::verify()]);
$router->post('/payment-presets/{id}/toggle', [$paymentPresets, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_payment_presets'), CsrfCheck::verify()]);

$router->get('/compliance-task-types', [$complianceTaskTypes, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types')]);
$router->get('/compliance-task-types/create', [$complianceTaskTypes, 'createForm'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types')]);
$router->post('/compliance-task-types', [$complianceTaskTypes, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types'), CsrfCheck::verify()]);
$router->get('/compliance-task-types/{id}/edit', [$complianceTaskTypes, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types')]);
$router->post('/compliance-task-types/{id}/update', [$complianceTaskTypes, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types'), CsrfCheck::verify()]);
$router->post('/compliance-task-types/{id}/toggle', [$complianceTaskTypes, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types'), CsrfCheck::verify()]);
$router->post('/compliance-task-types/{id}/delete', [$complianceTaskTypes, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_task_types'), CsrfCheck::verify()]);

// docs/schema.sql Section AI — email template CRUD (add/edit, never delete).
$router->get('/email-templates', [$emailTemplates, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_email_templates')]);
$router->get('/email-templates/create', [$emailTemplates, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_email_templates')]);
$router->post('/email-templates', [$emailTemplates, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_email_templates'), CsrfCheck::verify()]);
$router->get('/email-templates/{id}/edit', [$emailTemplates, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_email_templates')]);
$router->post('/email-templates/{id}/update', [$emailTemplates, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_email_templates'), CsrfCheck::verify()]);
$router->post('/email-templates/{id}/toggle-active', [$emailTemplates, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_email_templates'), CsrfCheck::verify()]);

$router->get('/ui-templates', [$uiTemplates, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/ui-templates/{id}/toggle-active', [$uiTemplates, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

$router->get('/account', [$account, 'edit'], [SessionAuth::required()]);
$router->post('/account/signature', [$account, 'updateSignature'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/account/notifications', [$account, 'updateNotificationPreference'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/account/ui-template', [$account, 'updateUiTemplate'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/account/password', [$account, 'updatePassword'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/account/backup-codes', [$account, 'generateBackupCodes'], [SessionAuth::required(), CsrfCheck::verify()]); // D-265

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
// docs/schema.sql Section AY — registered before the /reference-docs/{code} catch-all below so "categories" is never captured as a code.
$router->get('/reference-docs/categories', [$referenceDocs, 'categoriesIndex'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/reference-docs/categories', [$referenceDocs, 'categoryCreate'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/reference-docs/categories/{id}/update', [$referenceDocs, 'categoryUpdate'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/reference-docs/categories/{id}/delete', [$referenceDocs, 'categoryDelete'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->get('/reference-docs/{code}', [$referenceDocs, 'show'], [SessionAuth::required()]);
$router->get('/reference-docs/{code}/edit', [$referenceDocs, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->post('/reference-docs/{code}', [$referenceDocs, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

// SOP viewer — reads docs/SOP/*.md live off disk, any logged-in staff member can view.
$router->get('/getting-started', [$gettingStarted, 'show'], [SessionAuth::required()]);
// Owner request (2 Nov 2026): illustrated supplier / logistics partner bill + payment guide.
$router->get('/help/supplier-logistics-payments', [$help, 'supplierLogisticsPayments'], [SessionAuth::required()]);
$router->get('/sop', [$sop, 'index'], [SessionAuth::required()]);
$router->get('/sop-assets/{file}', [$sop, 'asset'], [SessionAuth::required()]);
$router->get('/sop/{chapter}', [$sop, 'show'], [SessionAuth::required()]);

// Gallery — reads docs/gallery/** live off disk, any logged-in staff member
// can view. No upload form: files are added directly on the server's
// filesystem (e.g. via FTP); folders double as categories with no code
// change needed.
$router->get('/gallery', [$gallery, 'index'], [SessionAuth::required()]);
$router->get('/gallery/file', [$gallery, 'file'], [SessionAuth::required()]);

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
// Owner decision 1 Nov 2026 (D-221): which roles may approve their own work (payment terms, document review, e-mail send).
$router->get('/admin/self-approval', [$selfApproval, 'index'], [SessionAuth::required(), SuperAdminOnly::required()]);
$router->post('/admin/self-approval', [$selfApproval, 'save'], [SessionAuth::required(), SuperAdminOnly::required(), CsrfCheck::verify()]);
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

// Owner decision D-214, phase 4: the system coordinates — My tasks, owners per department, the cockpit, task due days.
$router->get('/my-tasks', [$coordination, 'myTasks'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_orders'])]);
$router->get('/cockpit', [$coordination, 'cockpit'], [SessionAuth::required(), PermissionCheck::requires('view_cockpit')]);
$router->get('/admin/task-types', [$coordination, 'taskTypes'], [SessionAuth::required(), PermissionCheck::requires('manage_task_types')]);
$router->post('/admin/task-types', [$coordination, 'updateTaskTypes'], [SessionAuth::required(), PermissionCheck::requires('manage_task_types'), CsrfCheck::verify()]);
$router->post('/orders/{id}/department-owners', [$coordination, 'setOwner'], [SessionAuth::required(), PermissionCheck::requires('assign_department_owner'), OrderLockGuard::check(), CsrfCheck::verify()]);

// Owner decision D-214, phase 3: Procurement — requirements raised by Sales, quotes, award, approval, receipt and quality check.
$router->post('/orders/{id}/purchase-requirements', [$purchasing, 'raise'], [SessionAuth::required(), PermissionCheck::requires('raise_purchase_requirements'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/procurement', [$purchasing, 'queue'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_purchasing', 'approve_purchases'])]);
$router->get('/procurement/suppliers', [$purchasing, 'suppliers'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing')]);
$router->get('/procurement/{id}', [$purchasing, 'show'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_purchasing', 'approve_purchases'])]);
$router->post('/procurement/{id}/quotes', [$purchasing, 'addQuote'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/procurement/{id}/award', [$purchasing, 'award'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/procurement/{id}/approve', [$purchasing, 'approve'], [SessionAuth::required(), PermissionCheck::requires('approve_purchases'), CsrfCheck::verify()]);
$router->post('/procurement/{id}/receive', [$purchasing, 'receive'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/procurement/{id}/cancel', [$purchasing, 'cancel'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);

// Owner decision D-214: "Log in as" a department, switching, Admin "Preview as", and Admin → Departments.
$router->get('/choose-department', [$departments, 'chooseForm'], [SessionAuth::required()]);
$router->post('/choose-department', [$departments, 'choose'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/switch-department', [$departments, 'switch'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/admin/preview-as', [$departments, 'previewStart'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/preview/exit', [$departments, 'previewEnd'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->get('/admin/departments', [$departmentAdmin, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions')]);
$router->post('/admin/departments', [$departmentAdmin, 'createDepartment'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/departments/{id}/update', [$departmentAdmin, 'updateDepartment'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/designations', [$departmentAdmin, 'createDesignation'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/designations/{id}/update', [$departmentAdmin, 'updateDesignation'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/users/{id}/memberships', [$departmentAdmin, 'addMembership'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/memberships/{membershipId}/end', [$departmentAdmin, 'endMembership'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
// Owner decision D-257 (5 Nov 2026): divisions.
$router->post('/divisions/choose', [$divisions, 'choose'], [SessionAuth::required(), PermissionCheck::requires('see_all_divisions'), CsrfCheck::verify()]);
$router->get('/admin/divisions', [$divisions, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions')]);
$router->get('/admin/divisions/{id}', [$divisions, 'show'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions')]);
$router->post('/admin/divisions/{id}/modules', [$divisions, 'saveModules'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/divisions/{id}/settings', [$divisions, 'saveSetting'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/divisions/{id}/settings/delete', [$divisions, 'deleteSetting'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/divisions/{id}/activate', [$divisions, 'activate'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/divisions/{id}/deactivate', [$divisions, 'deactivate'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/users/{id}/division', [$divisions, 'setUserDivision'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/admin/departments/{id}/division', [$divisions, 'setDepartmentDivision'], [SessionAuth::required(), PermissionCheck::requires('manage_permissions'), CsrfCheck::verify()]);
$router->post('/admin/divisions/{id}/starter-departments', [$divisions, 'starterDepartments'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/divisions/{id}/staff', [$divisions, 'addStaff'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
// Owner decision D-257 Phase 2 (5 Nov 2026): Domestic Trade orders and the GST rates.
$router->get('/admin/gst-rates', [$gstRates, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions')]);
$router->post('/admin/gst-rates', [$gstRates, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/gst-rates/{id}/toggle', [$gstRates, 'toggle'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->get('/domestic/orders/create', [$domestic, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/domestic/orders', [$domestic, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/party', [$domestic, 'saveParty'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/lines', [$domestic, 'addLine'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/lines/{lineId}/delete', [$domestic, 'removeLine'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/purchase', [$domestic, 'savePurchase'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/dispatch', [$domestic, 'saveDispatch'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/invoice-sent', [$domestic, 'invoiceSent'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/advance', [$domestic, 'advance'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/payments', [$domestic, 'recordPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/documents', [$domestic, 'generate'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->post('/clients/{id}/gst', [$domestic, 'saveClientGst'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/domestic/orders/{id}/zoho', [$domestic, 'pushZoho'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->get('/domestic/orders/{id}/zoho-check', [$domestic, 'zohoCheck'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage')]);
// Owner decision D-257 Phase 3 (5 Nov 2026): Import & Distribution — imports, landed cost, stock, duty rates.
// /import/… belongs to the module "Import, stock and landed cost" (on for IMP only).
$router->get('/import', [$imports, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->get('/import/create', [$imports, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing')]);
$router->get('/import/reports', [$imports, 'reports'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->post('/import', [$imports, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->get('/import/{id}', [$imports, 'show'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->post('/import/{id}/po', [$imports, 'saveHeader'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/po-document', [$imports, 'poDocument'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices'), CsrfCheck::verify()]);
$router->post('/import/{id}/lines', [$imports, 'addLine'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/lines/{lineId}/delete', [$imports, 'removeLine'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/lc', [$imports, 'saveLc'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/bl', [$imports, 'saveShipment'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/clearance', [$imports, 'saveClearance'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/igst-credit', [$imports, 'igstCredit'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/payments', [$imports, 'recordPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), CsrfCheck::verify()]);
$router->post('/import/{id}/advance', [$imports, 'advance'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/import/{id}/duty-payment', [$imports, 'dutyPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), CsrfCheck::verify()]);
$router->post('/import/{id}/zoho', [$imports, 'pushZoho'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->get('/import/{id}/zoho-check', [$imports, 'zohoCheck'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage')]);
$router->get('/admin/import-duty-rates', [$imports, 'dutyRates'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions')]);
$router->post('/admin/import-duty-rates', [$imports, 'storeDutyRate'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
$router->post('/admin/import-duty-rates/{id}/toggle', [$imports, 'toggleDutyRate'], [SessionAuth::required(), PermissionCheck::requires('manage_divisions'), CsrfCheck::verify()]);
// Owner decision D-257 Phase 4 (5 Nov 2026): Merchant Trade — deals, both legs, BL / switch BL, RBI checks.
// /merchant/… belongs to the module "Merchant trade" (on for MTT only).
$router->get('/merchant', [$merchant, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->get('/merchant/create', [$merchant, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing')]);
$router->get('/merchant/reports', [$merchant, 'reports'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->post('/merchant', [$merchant, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->get('/merchant/{id}', [$merchant, 'show'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->post('/merchant/{id}/deal', [$merchant, 'saveHeader'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/lines', [$merchant, 'addLine'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/lines/{lineId}/delete', [$merchant, 'removeLine'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/backing', [$merchant, 'saveBacking'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/bl', [$merchant, 'saveShipment'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/bank-documents', [$merchant, 'saveDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/payments', [$merchant, 'recordPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/advance', [$merchant, 'advance'], [SessionAuth::required(), PermissionCheck::requires('manage_purchasing'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/document', [$merchant, 'document'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices'), CsrfCheck::verify()]);
$router->post('/merchant/{id}/zoho', [$merchant, 'pushZoho'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->get('/merchant/{id}/zoho-check', [$merchant, 'zohoCheck'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage')]);

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
// docs/schema.sql Section AY — view_clients admits a read-only visitor alongside manage_orders;
// every mutating route below stays manage_orders-only.
$router->get('/clients', [$clients, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_clients'])]);
$router->get('/clients/inactive', [$clients, 'inactiveIndex'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_clients'])]);
$router->get('/clients/create', [$clients, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients', [$clients, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/clients/{id}/duplicate', [$clients, 'duplicateForm'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients/{id}/duplicate', [$clients, 'duplicateStore'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/clients/{id}', [$clients, 'show'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_clients'])]);
$router->get('/clients/{id}/edit', [$clients, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients/{id}/update', [$clients, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
// Batch 3 #12 — separate endpoint, deliberately NOT gated by the data-lock check inside update() above.
$router->post('/clients/{id}/agreement-footer', [$clients, 'updateAgreementFooter'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
// Item 2 — agreement file upload + expiry + force-expire + renew (schema.sql Section BE).
$router->post('/clients/{id}/agreement/upload', [$clients, 'uploadAgreement'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/clients/{id}/agreement/force-expire', [$clients, 'forceExpireAgreement'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/clients/{id}/agreement/renew', [$clients, 'renewAgreement'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/clients/{id}/agreement/download', [$clients, 'downloadAgreement'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/clients/{id}/toggle-active', [$clients, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
// Batch 3 #13b — free-form extra documents attached to this client (schema.sql Section AZ).
$router->post('/clients/{id}/additional-documents', [$clients, 'uploadAdditionalDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/clients/{id}/additional-documents/{fileId}/delete', [$clients, 'deleteAdditionalDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/clients/{id}/additional-documents/removed', [$clients, 'showRemovedAdditionalDocuments'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
// docs/schema.sql Section AV — the per-client flag is a manage_company_settings action (same tier as other
// global/client-config toggles); the impersonation action itself re-checks that flag plus the global switch
// inside ClientController::impersonate(), on top of its own impersonate_client permission gate here.
$router->post('/clients/{id}/impersonation-allowed', [$clients, 'setImpersonationAllowed'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/clients/{id}/order-history-visibility', [$clients, 'setOrderHistoryVisibilityOverride'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/clients/{id}/document-downloads-visibility', [$clients, 'setDocumentDownloadsVisibilityOverride'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);
$router->post('/clients/{id}/impersonate', [$clients, 'impersonate'], [SessionAuth::required(), PermissionCheck::requires('impersonate_client'), CsrfCheck::verify()]);
$router->post('/clients/{id}/reset-password', [$clients, 'resetPortalPassword'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), CsrfCheck::verify()]);

// Staff review queue for public quotation-details submissions.
$router->get('/client-intake', [$clientIntakeReview, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/client-intake/{id}/accept', [$clientIntakeReview, 'accept'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/client-intake/{id}/reject', [$clientIntakeReview, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/client-intake/{id}/resend-link', [$clientIntakeReview, 'resendLink'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

$router->get('/pi-intake-review', [$piIntakeReview, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/pi-intake-review/{id}/accept', [$piIntakeReview, 'accept'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/pi-intake-review/{id}/reject', [$piIntakeReview, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/reorder-requests', [$reorderRequests, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->get('/reorder-requests/{id}', [$reorderRequests, 'show'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/reorder-requests/{id}/approve', [$reorderRequests, 'approve'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/reorder-requests/{id}/reject', [$reorderRequests, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// docs/schema.sql Section AY — view_orders admits a read-only visitor alongside manage_orders;
// every mutating route below stays manage_orders-only (or its existing finer siblings).
$router->get('/orders', [$orders, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_orders'])]);
$router->get('/orders/archived', [$orders, 'archivedIndex'], [SessionAuth::required(), PermissionCheck::requires('view_archived_orders')]);
$router->get('/orders/create', [$orders, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders', [$orders, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/orders/{id}', [$orders, 'show'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_orders'])]);
$router->post('/orders/{id}/assignees', [$orders, 'addAssignee'], [SessionAuth::required(), PermissionCheck::requires('assign_order_owner'), CsrfCheck::verify()]);
$router->post('/orders/{id}/assignees/{userId}/remove', [$orders, 'removeAssignee'], [SessionAuth::required(), PermissionCheck::requires('assign_order_owner'), CsrfCheck::verify()]);
$router->get('/orders/{id}/edit', [$orders, 'editDetails'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/edit', [$orders, 'updateDetails'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/archive', [$orders, 'archive'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/unarchive', [$orders, 'unarchive'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/duplicate', [$orders, 'duplicateOrder'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-po', [$orders, 'recordBuyerPo'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-po/received-date', [$orders, 'correctBuyerPoReceivedDate'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-po/documents', [$orders, 'uploadBuyerPoDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Batch 3 #13b — free-form extra documents attached to this order (schema.sql Section AZ).
$router->post('/orders/{id}/additional-documents', [$orders, 'uploadAdditionalDocument'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'upload_order_documents']), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/additional-documents/{fileId}/delete', [$orders, 'deleteAdditionalDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/orders/{id}/additional-documents/removed', [$orders, 'showRemovedAdditionalDocuments'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/products', [$orders, 'addProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/quality-documents', [new \App\Controllers\OrderQualityDocumentController(), 'save'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'manage_purchasing']), OrderLockGuard::check(), CsrfCheck::verify()]); // D-257 0B
$router->post('/orders/{id}/products/{productId}', [$orders, 'updateProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/products/{productId}/delete', [$orders, 'deleteProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decision D-245 P2: a line below the floor goes to the MD; only the MD approves it (never below the minimum price).
$router->post('/orders/{id}/products/{productId}/price-approval-request', [$orders, 'requestLinePriceApproval'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/orders/{id}/products/{productId}/price-approval', [$orders, 'linePriceApproval'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing')]);
$router->post('/orders/{id}/products/{productId}/price-approval', [$orders, 'approveLinePrice'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/products/{productId}/duplicate', [$orders, 'duplicateProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/ca', [$ca, 'index'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->get('/ca/cafin-export', [$ca, 'cafinExport'], [SessionAuth::required(), PermissionCheck::requires('view_cafin')]);
$router->get('/ca/reports', [$ca, 'reports'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->get('/ca/zoho-sync', [$ca, 'zohoSync'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage')]);
$router->post('/ca/zoho-sync/run', [$ca, 'runZohoSync'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
// Owner decision 2 Nov 2026 (D-238): send every client and product to Zoho Books again.
$router->post('/ca/zoho-sync/repush-master-data', [$ca, 'repushMasterData'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->get('/orders/{id}/zoho-check', [$ca, 'orderZohoCheck'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage')]);
// Owner 2 Nov 2026 (point 16): sync one order's records to Zoho Books from the order page.
$router->post('/orders/{id}/zoho-sync', [$ca, 'syncOrderToZoho'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->get('/ca/expenses', [$ca, 'expenses'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->get('/ca/tds-summary', [$ca, 'tdsSummary'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->post('/ca/expenses/{id}/tds', [$ca, 'setExpenseTds'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/expenses/{id}/link-order', [$ca, 'linkExpenseToOrder'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/orders/{id}/ca-internal-doc/toggle', [$ca, 'toggleInternalDoc'], [SessionAuth::required(), PermissionCheck::requires('ca_internal_doc_manage'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/ca-internal-doc/generate', [$ca, 'generateInternalDoc'], [SessionAuth::required(), PermissionCheck::requires('ca_internal_doc_manage'), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits', [$orderFinancials, 'addExportBenefit'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_order_financials', 'manage_export_benefit_claims']), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits/{benefitId}/received', [$orderFinancials, 'markExportBenefitReceived'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/financials/costs-confirmed', [$orderFinancials, 'confirmCosts'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/financials/complete', [$orderFinancials, 'completeFinancials'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits/save-all', [$orderFinancials, 'saveExportBenefits'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits/skip', [$orderFinancials, 'skipExportBenefitClaim'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits/{benefitId}/correct', [$orderFinancials, 'correctExportBenefit'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits/{benefitId}/delete', [$orderFinancials, 'deleteExportBenefit'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/financials/skip', [$orderFinancials, 'skipFinancials'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/financials/skip/undo', [$orderFinancials, 'undoSkipFinancials'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefit-schemes', [$orderFinancials, 'setExportBenefitSchemeStatus'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_order_financials', 'manage_export_benefit_claims']), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decision (2 Nov 2026, gap-list Q-6): ECGC buyer limit + shipment declaration, claim tracking, Missing eBRC / FIRC report.
$complianceTracking = new \App\Controllers\ComplianceTrackingController();
$router->post('/clients/{id}/ecgc', [$complianceTracking, 'saveClientEcgc'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_export_benefit_claims', 'ca_module_manage']), CsrfCheck::verify()]);
$router->post('/orders/{id}/ecgc-declaration', [$complianceTracking, 'declareShipment'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_export_benefit_claims', 'ca_module_manage']), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/export-benefits/{benefitId}/tracking', [$complianceTracking, 'saveClaimTracking'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_export_benefit_claims', 'manage_order_financials']), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/reports/missing-ebrc', [$complianceTracking, 'missingEbrcReport'], [SessionAuth::required(), PermissionCheck::requiresAny(['ca_module_view', 'manage_export_benefit_claims', 'manage_order_financials'])]);
$router->post('/orders/{id}/lc-details', [$orderFinancials, 'saveLcDetails'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/lc-received', [$orderFinancials, 'recordLcReceived'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/lc/firc', [$orderFinancials, 'recordLcFirc'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/inspection-fee', [$orderFinancials, 'saveInspectionFee'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/travel-expenses', [$orderFinancials, 'saveTravelExpenses'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Team incentives and associates (owner decision 2026-10-30).
$router->get('/incentives/settings', [$incentives, 'settings'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives')]);
$router->post('/incentives/settings', [$incentives, 'saveSettings'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
// Associates — staff side (owner decision 1 Nov 2026, "AS all as recommended").
$router->get('/associates', [$associateAdmin, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_associates', 'manage_associates', 'manage_associate_bank'])]);
$router->get('/associates/new', [$associateAdmin, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
// Owner decision D-255 (5 Nov 2026): associate price requests (queue, manual pricing) and an associate's price access.
$priceRequests = new \App\Controllers\PriceRequestController();
$router->get('/associates/price-requests', [$priceRequests, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_price_buildup', 'manage_pricing'])]);
$router->post('/associates/price-requests/{id}/price', [$priceRequests, 'price'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->get('/associates/{id}/price-access', [$priceRequests, 'access'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing')]);
$router->post('/associates/{id}/price-access', [$priceRequests, 'saveAccess'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/associates', [$associateAdmin, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
// Owner decision D-253 (4 Nov 2026, A1): associate self-registration through the MD's one-use link.
$associateRegistration = new \App\Controllers\AssociateRegistrationController();
$router->get('/associates/registrations', [$associateRegistration, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
$router->post('/associates/registrations/links', [$associateRegistration, 'createLink'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/registrations/links/{id}/cancel', [$associateRegistration, 'cancelLink'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/registrations/settings', [$associateRegistration, 'saveSettings'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/registrations/{id}/accept', [$associateRegistration, 'accept'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/registrations/{id}/reject', [$associateRegistration, 'reject'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
// D-261 (owner approval 6 Nov 2026): associate portal access levels — only the MD changes them (checked in the service).
$associateAccess = new \App\Controllers\AssociateAccessController();
$router->get('/associates/access-levels', [$associateAccess, 'levels'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_associates', 'manage_associates'])]);
$router->post('/associates/access-levels', [$associateAccess, 'saveLevels'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/access', [$associateAccess, 'setLevel'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/access/not-yet', [$associateAccess, 'notYet'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->get('/associates/ladder', [$associateAdmin, 'ladder'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
$router->post('/associates/ladder', [$associateAdmin, 'saveLadder'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->get('/reports/associates', [$associateAdmin, 'report'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
$router->get('/associates/{id}', [$associateAdmin, 'show'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_associates', 'manage_associates', 'manage_associate_bank'])]);
$router->get('/associates/{id}/edit', [$associateAdmin, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
$router->post('/associates/{id}/update', [$associateAdmin, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/status', [$associateAdmin, 'changeStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/portal/invite', [$associateAdmin, 'invitePortal'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/portal/disable', [$associateAdmin, 'disablePortal'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/code', [$associateAdmin, 'setCode'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->get('/associates/{id}/view-as', [$associateAdmin, 'viewAs'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
$router->get('/associates/{id}/agreement', [$associateAdmin, 'downloadAgreement'], [SessionAuth::required(), PermissionCheck::requires('manage_associates')]);
$router->post('/associates/{id}/clients', [$associateAdmin, 'linkClient'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/clients/{clientId}/unlink', [$associateAdmin, 'unlinkClient'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/{id}/bank', [$associateAdmin, 'addBank'], [SessionAuth::required(), PermissionCheck::requires('manage_associate_bank'), CsrfCheck::verify()]);
$router->post('/associates/{id}/bank/{bankId}/check', [$associateAdmin, 'firstCheck'], [SessionAuth::required(), PermissionCheck::requires('manage_associate_bank'), CsrfCheck::verify()]);
$router->post('/associates/{id}/bank/{bankId}/verify', [$associateAdmin, 'verifyBank'], [SessionAuth::required(), PermissionCheck::requires('manage_associate_bank'), CsrfCheck::verify()]);
$router->post('/associates/{id}/bank/{bankId}/reject', [$associateAdmin, 'rejectBank'], [SessionAuth::required(), PermissionCheck::requires('manage_associate_bank'), CsrfCheck::verify()]);
// Owner decisions 11 Nov 2026 (D-291, D-293): remind the associate to confirm new bank details; the MD sets the commission rule.
$router->post('/associates/{id}/bank/{bankId}/remind', [$associateAdmin, 'remindBank'], [SessionAuth::required(), PermissionCheck::requires('manage_associate_bank'), CsrfCheck::verify()]);
$router->get('/associates/{id}/commission-rule', [$associateAdmin, 'commissionRule'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing')]);
$router->post('/associates/{id}/commission-rule', [$associateAdmin, 'saveCommissionRule'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->get('/associates/{id}/bank/{bankId}/number', [$associateAdmin, 'bankNumber'], [SessionAuth::required(), PermissionCheck::requires('manage_associate_bank')]);
$router->post('/associates/{id}/documents', [$associateAdmin, 'uploadDocument'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_associates', 'manage_associate_bank']), CsrfCheck::verify()]);
// Owner decision D-252 (A3): accept / reject one KYC document.
$router->post('/associates/{id}/documents/{docId}/review', [$associateAdmin, 'reviewDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->get('/associates/{id}/documents/{docId}', [$associateAdmin, 'downloadDocument'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_associates', 'manage_associate_bank'])]);
$router->post('/associates/{id}/messages', [$associateAdmin, 'reply'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/payouts/{payoutId}/hold', [$associateAdmin, 'holdPayout'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/payouts/{payoutId}/release', [$associateAdmin, 'releasePayout'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
$router->post('/associates/payouts/{payoutId}/clawback', [$associateAdmin, 'clawback'], [SessionAuth::required(), PermissionCheck::requires('manage_associates'), CsrfCheck::verify()]);
// Owner decision 2 Nov 2026 (point 4): the Agreements register.
$agreements = new \App\Controllers\AgreementController();
$router->get('/agreements', [$agreements, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_agreements', 'manage_agreements'])]);
$router->get('/agreements/new', [$agreements, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_agreements')]);
$router->post('/agreements', [$agreements, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_agreements'), CsrfCheck::verify()]);
$router->get('/agreements/{id}/edit', [$agreements, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_agreements')]);
$router->post('/agreements/{id}/update', [$agreements, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_agreements'), CsrfCheck::verify()]);
$router->post('/agreements/{id}/terminate', [$agreements, 'terminate'], [SessionAuth::required(), PermissionCheck::requires('manage_agreements'), CsrfCheck::verify()]);
$router->get('/agreements/{id}/file', [$agreements, 'download'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_agreements', 'manage_agreements'])]);
$router->post('/orders/{id}/incentive', [$incentives, 'saveOrder'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
$router->post('/orders/{id}/incentive/people', [$incentives, 'saveOrderPeople'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
$router->post('/orders/{id}/incentive/recalculate', [$incentives, 'recalculate'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
// Owner decision D-245 P4: the MD locks an order's incentive (nothing locks by itself), one or all ready; unlock (above) needs a reason.
$router->post('/orders/{id}/incentive/lock', [$incentives, 'lock'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
// D-264: the MD corrects a fixed associate commission before it is paid (reason, audited, associate told).
$router->post('/orders/{id}/incentive/associate-commission', [$incentives, 'changeAssociateCommission'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
$router->get('/incentives/ready', [$incentives, 'ready'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives')]);
$router->post('/incentives/ready/lock-all', [$incentives, 'lockAll'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
$router->post('/incentives/roles/{key}/delete', [$incentives, 'deleteRole'], [SessionAuth::required(), PermissionCheck::requires('manage_incentives'), CsrfCheck::verify()]);
$router->get('/my-incentives', [$incentives, 'mine'], [SessionAuth::required(), PermissionCheck::requires('view_own_incentives')]);
$router->get('/incentives/payouts', [$incentives, 'payouts'], [SessionAuth::required(), PermissionCheck::requires('pay_incentives')]);
$router->post('/incentives/payouts/paid-all', [$incentives, 'markPaid'], [SessionAuth::required(), PermissionCheck::requires('pay_incentives'), CsrfCheck::verify()]);
$router->post('/incentives/payouts/{payoutId}/paid', [$incentives, 'markPaid'], [SessionAuth::required(), PermissionCheck::requires('pay_incentives'), CsrfCheck::verify()]);
$router->post('/orders/{id}/cost-entries', [$orderFinancials, 'addCostEntry'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/cost-entries/{entryId}/delete', [$orderFinancials, 'deleteCostEntry'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/ca/bank-statement', [$ca, 'bankStatement'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->post('/ca/bank-statement/upload', [$ca, 'uploadBankStatement'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/bank-statement/{id}/match-revenue', [$ca, 'matchBankLineToRevenue'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/bank-statement/{id}/match-expense', [$ca, 'matchBankLineToExpense'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/bank-statement/{id}/unmatch', [$ca, 'unmatchBankLine'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->get('/ca/reconciliation', [$ca, 'reconciliation'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->get('/ca/export-benefits', [$ca, 'exportBenefits'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->post('/ca/export-benefits', [$ca, 'recordExportBenefit'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/export-benefits/{id}/mark-received', [$ca, 'markExportBenefitReceived'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
// Owner decision 2026-10-31 (option A): GST refunds under LUT — claims per period, bank credits, Zoho journals.
$gstRefunds = new \App\Controllers\GstRefundController();
$router->get('/ca/gst-refunds', [$gstRefunds, 'index'], [SessionAuth::required(), PermissionCheck::requires('ca_module_view')]);
$router->post('/ca/gst-refunds', [$gstRefunds, 'recordClaim'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/gst-refunds/{id}/credits', [$gstRefunds, 'recordCredit'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->post('/ca/gst-refunds/credits/{creditId}/delete', [$gstRefunds, 'deleteCredit'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), CsrfCheck::verify()]);
$router->get('/ca/fy-locks', [$ca, 'fyLocks'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage')]);
$router->post('/ca/fy-locks/lock', [$ca, 'lockFinancialYear'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->post('/ca/fy-locks/unlock', [$ca, 'unlockFinancialYear'], [SessionAuth::required(), PermissionCheck::requires('ca_module_manage'), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance/exchange-rate', [$orders, 'recordAdvanceExchangeRate'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/balance/exchange-rate', [$orders, 'recordBalanceExchangeRate'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight/exchange-rate', [$orders, 'recordFreightExchangeRate'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance/firc', [$orders, 'recordAdvanceFirc'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/balance/firc', [$orders, 'recordBalanceFirc'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight/firc', [$orders, 'recordFreightFirc'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance/inr-actual', [$orders, 'recordAdvanceInrActual'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/advance/inr-actual/delete', [$orders, 'deleteAdvanceInrActual'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_delete'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/balance/inr-actual', [$orders, 'recordBalanceInrActual'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/balance/inr-actual/delete', [$orders, 'deleteBalanceInrActual'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_delete'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight/inr-actual', [$orders, 'recordFreightInrActual'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_edit'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight/inr-actual/delete', [$orders, 'deleteFreightInrActual'], [SessionAuth::required(), PermissionCheck::requires('inr_actual_delete'), OrderLockGuard::check(), CsrfCheck::verify()]);
// QA-5 RBAC-03/04: payment record/clear is Accounts' duty, split out of
// manage_orders so a Logistics-only user can no longer mark a buyer
// payment as cleared (see docs/seed.sql's manage_payments permission).
$router->post('/orders/{id}/payment/advance', [$orders, 'recordAdvancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment-reports/{reportId}/reviewed', [$orders, 'markPaymentReportReviewed'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// External QA report Q-ROLE-1: clearing a payment (confirming the bank
// actually received it) is split out of manage_payments (recording it)
// so the same person can't do both — see docs/migrations/
// 2026-10-23d-split-payment-and-supplier-payment-permissions.sql.
$router->post('/orders/{id}/payment/advance/clear', [$orders, 'clearAdvancePayment'], [SessionAuth::required(), PermissionCheck::requires('clear_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Step 5 LC leg (order.payment_method = 'LC') — same manage_payments/
// clear_payments split as the cash-advance leg above, since recording
// vs. confirming-usable is the same record/clear duty split either way.
$router->post('/orders/{id}/payment/lc', [$orders, 'recordLcDetails'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/lc/confirm-usable', [$orders, 'confirmLcUsable'], [SessionAuth::required(), PermissionCheck::requires('clear_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Gap list G-4 (owner, 1 Nov 2026): the LC's dates and its amendments.
$router->post('/orders/{id}/lc-dates', [$orders, 'saveLcDates'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/lc-amendments', [$orders, 'addLcAmendment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// D-262: the MD's "Refresh company details on this order" (bank, LUT, IEC, address …), with a reason.
$router->post('/orders/{id}/company-details/refresh', [$orders, 'refreshCompanyDetails'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment-terms/approve', [$orders, 'approvePaymentTerms'], [SessionAuth::required(), PermissionCheck::requires('approve_payment_terms'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/production-status', [$orders, 'updateProductionStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_po'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/dispute-visibility', [$orders, 'setDisputeButtonVisible'], [SessionAuth::required(), PermissionCheck::requires('manage_disputes'), OrderLockGuard::check(), CsrfCheck::verify()]);

// docs/schema.sql Section AI — order progress chat.
$router->post('/orders/{id}/comments', [$orderComments, 'post'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
// Step 16 — staff recording feedback the client gave over the phone/in person rather than via the portal. No OrderLockGuard: feedback is only reachable once the order is shipped_docs_sent/complete, which is already past the point of being "locked" in the ordinary editing sense.
$router->post('/orders/{id}/feedback', [$orders, 'recordFeedback'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);
$router->get('/orders/{id}/comment-attachments/{fileId}/download', [$orderComments, 'downloadAttachment'], [SessionAuth::required(), PermissionCheck::requires('download_pdf')]);

// Annexure A — Product Technical Specifications (schema tables shipped
// with no screen ever built against them; this is that missing piece).
$router->get('/orders/{id}/annexure', [$annexure, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/annexure/toggle', [$annexure, 'toggleInclude'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/mode', [$annexure, 'updateMode'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/terms', [$annexure, 'updateTerms'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
// D-336: Annexure B (as shipped) — "Copy everything from Annexure A".
$router->post('/orders/{id}/annexure/copy-from-a', [$annexure, 'copyFromA'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
// D-341: one "Save all changes" for the whole annexure page (mode, entries, new images, Additional Terms).
$router->post('/orders/{id}/annexure/save-all', [$annexure, 'saveAll'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products', [$annexure, 'createProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products/{productId}', [$annexure, 'updateProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products/{productId}/delete', [$annexure, 'deleteProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/products/{productId}/images', [$annexure, 'uploadImage'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/annexure/images/{imageId}/remove', [$annexure, 'removeImage'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);

// --- Phase C: Stages 4-9 ---
$router->post('/orders/{id}/oc-acknowledgment', [$orders, 'recordOcAcknowledgment'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Gap list G-6 (owner, 1 Nov 2026): with e-mail off, the buyer's 48 hours start when the OC is marked as sent.
$router->post('/orders/{id}/oc-sent-to-buyer', [$orders, 'markOcSentToBuyer'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);

$router->post('/orders/{id}/supplier-po', [$orders, 'saveSupplierPo'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_po'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/signed', [$orders, 'confirmSupplierSigned'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_po'), OrderLockGuard::check(), CsrfCheck::verify()]);
// External QA report Q-ROLE-3: split out of manage_order_financials so
// Accounts can confirm supplier payments without gaining the broader
// financials permission's access to cost entries/export benefits/the
// profitability report — see docs/migrations/
// 2026-10-23d-split-payment-and-supplier-payment-permissions.sql.
$router->post('/orders/{id}/supplier-po/advance-paid', [$orders, 'markSupplierAdvancePaid'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/balance-paid', [$orders, 'markSupplierBalancePaid'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decisions (2026-10-27): bill first, then pay — the supplier's bills
// (7e / 10a) and the logistics partners' bills (CHA, transport, forwarder).
$router->post('/orders/{id}/vendor-bills/supplier', [$vendorBills, 'addSupplierBill'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decision (2 Nov 2026): Logistics enters the logistics partners' bills (manage_logistics_bills); paying stays with manage_supplier_payments.
$router->post('/orders/{id}/vendor-bills/logistics', [$vendorBills, 'addLogisticsBill'], [SessionAuth::required(), PermissionCheck::requires('manage_logistics_bills'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/vendor-bills/{billId}/pay', [$vendorBills, 'payBill'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/vendor-bills/{billId}/delete', [$vendorBills, 'deleteBill'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_supplier_payments', 'manage_logistics_bills']), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decisions (2026-10-27): the order's actual expenses with receipts (Step 12a).
$router->post('/orders/{id}/expenses', [$orderExpenses, 'add'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/expenses/{expenseId}/delete', [$orderExpenses, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/documents', [$orders, 'uploadSupplierPoDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_po'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/supplier-link', [$orders, 'generateSupplierActionLink'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_po'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/supplier-po/ack-skip', [$orders, 'skipSupplierPoAck'], [SessionAuth::required(), PermissionCheck::requires('manage_supplier_po'), OrderLockGuard::check(), CsrfCheck::verify()]);

// QA-5 RBAC-04: freight/packing/shipping/BL/closure are Logistics' duty,
// split out of manage_orders so an Accounts-only user can no longer
// record a BL or close an order (see docs/seed.sql's manage_shipping /
// close_orders permissions).
$router->post('/orders/{id}/freight-terms', [$orders, 'saveFreightTerms'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight', [$orders, 'recordFreightPayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/freight/clear', [$orders, 'clearFreightPayment'], [SessionAuth::required(), PermissionCheck::requires('clear_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);

$router->post('/orders/{id}/packing', [$orders, 'savePacking'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/shipping', [$orders, 'saveShipping'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/draft-bl', [$orders, 'uploadDraftBl'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/draft-bl/{roundId}/review', [$orders, 'reviewDraftBl'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-issued', [$orders, 'recordBlIssued'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Gap list G-3 (owner, 1 Nov 2026): the pre-shipment inspection's result and report.
$router->post('/orders/{id}/inspection-result', [$orders, 'recordInspectionResult'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-copy', [$orders, 'attachBlCopy'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/shipping-bill', [$orders, 'recordShippingBill'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/scanned-bl-sent', [$orders, 'recordScannedBlSent'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);

$router->post('/orders/{id}/payment/balance', [$orders, 'recordBalancePayment'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/balance/clear', [$orders, 'clearBalancePayment'], [SessionAuth::required(), PermissionCheck::requires('clear_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decision D-215 (5a): the order's own payment schedule, and each milestone between the advance and the balance as its own payment.
$router->post('/orders/{id}/payment-schedule', [$milestones, 'saveSchedule'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/milestones/{milestoneId}', [$milestones, 'record'], [SessionAuth::required(), PermissionCheck::requires('manage_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment/milestones/{milestoneId}/clear', [$milestones, 'clear'], [SessionAuth::required(), PermissionCheck::requires('clear_payments'), OrderLockGuard::check(), CsrfCheck::verify()]);

$router->post('/orders/{id}/bl-originals-received', [$orders, 'recordBlOriginalsReceived'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-endorsed', [$orders, 'recordBlEndorsed'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-endorsement', [$orders, 'saveBlEndorsement'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/bl-endorsement/generate', [$orders, 'generateBlEndorsement'], [SessionAuth::required(), PermissionCheck::requires('manage_shipping'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/close', [$orders, 'closeOrder'], [SessionAuth::required(), PermissionCheck::requires('close_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/mark-fully-settled', [$orders, 'markFullySettled'], [SessionAuth::required(), PermissionCheck::requires('settle_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/mark-lost', [$orders, 'markLost'], [SessionAuth::required(), PermissionCheck::requires('close_orders'), CsrfCheck::verify()]);
// External QA report M-09: formal cancellation workflow, distinct from mark-lost above.
$router->post('/orders/{id}/cancellation/request', [$orders, 'requestCancellation'], [SessionAuth::required(), PermissionCheck::requires('close_orders'), CsrfCheck::verify()]);
$router->post('/orders/{id}/cancellation/{cancellationId}/approve', [$orders, 'approveCancellation'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/orders/{id}/cancellation/{cancellationId}/reject', [$orders, 'rejectCancellation'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/orders/{id}/cancellation/{cancellationId}/complete-refund', [$orders, 'completeCancellationRefund'], [SessionAuth::required(), PermissionCheck::requires('close_orders'), CsrfCheck::verify()]);
// Owner decision D-337 (12 Nov 2026): the order's buyer details follow the client — at once while nothing is issued, by approved request after.
$buyerDetails = new \App\Controllers\BuyerDetailsController();
$router->post('/orders/{id}/buyer-details/refresh', [$buyerDetails, 'refresh'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-details/request', [$buyerDetails, 'request'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-details/{requestId}/approve', [$buyerDetails, 'approve'], [SessionAuth::required(), PermissionCheck::requires('approve_buyer_details_change'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-details/{requestId}/refuse', [$buyerDetails, 'refuse'], [SessionAuth::required(), PermissionCheck::requires('approve_buyer_details_change'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/compliance-tasks', [$orders, 'updateComplianceTask'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_checklist'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/compliance-tasks/mark-all-na', [$orders, 'markAllComplianceTasksNotApplicable'], [SessionAuth::required(), PermissionCheck::requires('manage_compliance_checklist'), OrderLockGuard::check(), CsrfCheck::verify()]);

$router->post('/orders/{id}/documents/generate', [$documents, 'generate'], [SessionAuth::required(), PermissionCheck::requiresAny(['generate_documents', 'generate_compliance_documents']), CsrfCheck::verify()]);
$router->post('/orders/{id}/documents/{documentId}/delete', [$documents, 'delete'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->get('/documents/{documentId}/download', [$documents, 'download'], [SessionAuth::required(), PermissionCheck::requiresAny(['download_pdf', 'view_cafin'])]);
// Batch 3 #13b/#13a — widened so a view-only visitor (view_orders/view_clients) can open what they can already see listed on the order/client page.
$router->get('/file-store/{id}/download', [$fileStore, 'download'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_orders', 'view_orders', 'view_clients'])]);

// --- Phase D: review/approval, deferred send, amendments, disputes, audit log ---

// Review & cross-verification (Section 9). Ownership of a specific
// document_reviews row (only the assigned reviewer may act on it) is
// enforced inside ReviewWorkflowService, not by a permission gate here —
// that's a per-row authorization check, not a per-route one.
$router->get('/reviews', [$reviews, 'queue'], [SessionAuth::required()]);
// Owner decision D-310 (point 27): one Approvals page — each row is shown only to someone who may act on it (the rules are checked per row, as on /reviews).
$router->get('/approvals', [new \App\Controllers\ApprovalsController(), 'index'], [SessionAuth::required()]);
$router->post('/documents/{documentId}/reviewers', [$reviews, 'assign'], [SessionAuth::required(), PermissionCheck::requires('approve_documents'), CsrfCheck::verify()]);
$router->post('/reviews/{reviewId}/approve', [$reviews, 'approve'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/reviews/{reviewId}/reject', [$reviews, 'reject'], [SessionAuth::required(), CsrfCheck::verify()]);
$router->post('/documents/{documentId}/cross-verify', [$reviews, 'crossVerify'], [SessionAuth::required(), PermissionCheck::requires('cross_verify_documents'), CsrfCheck::verify()]);

// Deferred email send (Section 10), 2-level approval.
$router->get('/orders/{id}/email/compose', [$emailDispatch, 'composeGeneric'], [SessionAuth::required(), PermissionCheck::requires('generate_documents')]);
$router->post('/orders/{id}/email/send', [$emailDispatch, 'requestSendGeneric'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->get('/orders/{id}/documents/{documentId}/send', [$emailDispatch, 'compose'], [SessionAuth::required(), PermissionCheck::requires('generate_documents')]);
$router->post('/orders/{id}/documents/{documentId}/send', [$emailDispatch, 'requestSend'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->get('/email-approvals', [$emailDispatch, 'approvalQueue'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send')]);
$router->post('/email-log/{emailLogId}/approve', [$emailDispatch, 'approve'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send'), CsrfCheck::verify()]);
$router->post('/email-log/{emailLogId}/reject', [$emailDispatch, 'reject'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send'), CsrfCheck::verify()]);
$router->post('/email-log/{emailLogId}/send-now', [$emailDispatch, 'sendNow'], [SessionAuth::required(), PermissionCheck::requires('approve_email_send'), CsrfCheck::verify()]);
// No PermissionCheck wrapper: a Level-2 approver may cancel any row, but a
// plain requester may also cancel their own — that ownership check can only
// happen once the row is loaded, so it lives in EmailDispatchService::cancelSend().
$router->post('/email-log/{emailLogId}/cancel', [$emailDispatch, 'cancel'], [SessionAuth::required(), CsrfCheck::verify()]);

// Payment Terms Amendments (Section 8).
$router->get('/orders/{id}/amendments', [$amendments, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/amendments', [$amendments, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
// Owner decision D-342: the "Products & prices" amendment and the re-issue checklist's Buyer PO tick.
$router->get('/orders/{id}/amendments/products/new', [$amendments, 'newProducts'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/amendments/products', [$amendments, 'createProducts'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/amendments/{amendmentId}/buyer-po-amended', [$amendments, 'buyerPoAmended'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/md-approve', [$amendments, 'mdApprove'], [SessionAuth::required(), PermissionCheck::requires('approve_documents'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/reject', [$amendments, 'reject'], [SessionAuth::required(), PermissionCheck::requires('approve_documents'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/generate-document', [$amendments, 'generateDocument'], [SessionAuth::required(), PermissionCheck::requires('generate_documents'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/signed-copy', [$amendments, 'uploadSignedCopy'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), CsrfCheck::verify()]);

// Dispute management (Section 16). manage_disputes replaces manage_orders
// here (docs/schema.sql Section AO) — view/raise/status/evidence/enable
// are privileged-only by default; respond_to_disputes (below) is the
// separate, more broadly grantable permission for posting a reply.
$router->get('/disputes', [$disputes, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_disputes', 'respond_to_disputes'])]);
$router->get('/orders/{id}/disputes', [$disputes, 'forOrder'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_disputes', 'respond_to_disputes'])]);
$router->post('/orders/{id}/disputes', [$disputes, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_disputes'), CsrfCheck::verify()]);
$router->post('/disputes/{disputeId}/status', [$disputes, 'updateStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_disputes'), CsrfCheck::verify()]);
$router->post('/disputes/{disputeId}/documents', [$disputes, 'uploadDocument'], [SessionAuth::required(), PermissionCheck::requires('manage_disputes'), CsrfCheck::verify()]);
$router->post('/disputes/{disputeId}/replies', [$disputes, 'postReply'], [SessionAuth::required(), PermissionCheck::requires('respond_to_disputes'), CsrfCheck::verify()]);

// Complaint / Process Issue management (docs/schema.sql Section BX) — a
// separate feature from Disputes: always available on any order (never
// gated by dispute_button_visible_to_client), and a two-way conversation
// rather than a staff-only resolution log.
$router->get('/complaints', [$complaints, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_complaints', 'respond_to_complaints'])]);
$router->get('/orders/{id}/complaints', [$complaints, 'forOrder'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_complaints', 'respond_to_complaints'])]);
$router->post('/orders/{id}/complaints', [$complaints, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_complaints'), CsrfCheck::verify()]);
$router->post('/complaints/{complaintId}/status', [$complaints, 'updateStatus'], [SessionAuth::required(), PermissionCheck::requires('manage_complaints'), CsrfCheck::verify()]);
$router->post('/complaints/{complaintId}/replies', [$complaints, 'postReply'], [SessionAuth::required(), PermissionCheck::requires('respond_to_complaints'), CsrfCheck::verify()]);

// Audit log viewer (Section 14/17) — read-only, no delete route exists anywhere.
$router->get('/audit-log', [$auditLog, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_audit_log')]);
$router->get('/orders/{id}/audit-log', [$auditLog, 'forOrder'], [SessionAuth::required(), PermissionCheck::requires('view_audit_log')]);
$router->get('/orders/{id}/dossier', [$orders, 'downloadDossier'], [SessionAuth::required(), PermissionCheck::requires('manage_orders')]);
$router->post('/orders/{id}/pi-form-link', [$orders, 'generatePiFormLink'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/pi-form/skip', [$orders, 'skipPiForm'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/buyer-action-link', [$orders, 'generateBuyerActionLink'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);
$router->post('/orders/{id}/payment-action-link', [$orders, 'generatePaymentActionLink'], [SessionAuth::required(), PermissionCheck::requires('manage_orders'), OrderLockGuard::check(), CsrfCheck::verify()]);

// Notifications bell.
$router->get('/notifications', [$notifications, 'index'], [SessionAuth::required()]);

// --- Phase E: dashboard/reports (Section 16), gated on view_reports; saving/deleting a
// saved report additionally requires manage_report_definitions. ---
$router->get('/reports', [$reports, 'index'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/client/{clientId}', [$reports, 'client'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/order/{orderId}', [$reports, 'order'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/aggregate', [$reports, 'aggregate'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/queues', [$reports, 'queues'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/payments', [$reports, 'payments'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/todays-payments', [$reports, 'todaysPayments'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/disputes', [$reports, 'disputes'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/amendments', [$reports, 'amendments'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/trends', [$reports, 'trends'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/staff', [$reports, 'staff'], [SessionAuth::required(), PermissionCheck::requires('view_staff_reports')]);
$router->get('/reports/ageing', [$reports, 'ageing'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
// Owner decision 1 Nov 2026 (A-7 / A-9): each of these two reports needs only the money it shows.
$router->get('/reports/freight-cost', [$reports, 'freightCost'], [SessionAuth::required(), PermissionCheck::requires('view_logistics_costs')]);
$router->get('/reports/products', [$reports, 'products'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/suppliers', [$reports, 'suppliers'], [SessionAuth::required(), PermissionCheck::requires('view_purchase_prices')]);
$router->get('/reports/order-profitability', [$reports, 'profitability'], [SessionAuth::required(), PermissionCheck::requires('manage_order_financials')]);
// Owner decision D-245 P5: incentive & commission reports (MD / ED / Accounts — whoever manages or pays incentives or sees the price build-up).
$incentiveReports = new \App\Controllers\IncentiveReportController();
$incentiveReportGate = [SessionAuth::required(), PermissionCheck::requiresAny(['manage_incentives', 'pay_incentives', 'view_price_buildup'])];
$router->get('/reports/incentives', [$incentiveReports, 'register'], $incentiveReportGate);
$router->get('/reports/incentives/to-pay', [$incentiveReports, 'toPay'], $incentiveReportGate);
$router->get('/reports/incentives/owed', [$incentiveReports, 'owed'], $incentiveReportGate);
$router->get('/reports/incentives/statement', [$incentiveReports, 'statement'], $incentiveReportGate);
$router->get('/reports/conversion', [$reports, 'conversion'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
$router->get('/reports/performance', [$reports, 'performance'], [SessionAuth::required(), PermissionCheck::requires('view_reports')]);
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

$router->get('/admin/data-export', [$dataExport, 'index'], [SessionAuth::required(), PermissionCheck::requires('data_export_run')]);
// D-260: Admin › Backups — read-only status of the nightly backup jobs.
$router->get('/admin/backups', [$backups, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_company_settings')]);
$router->get('/admin/data-export/schema', [$dataExport, 'downloadSchema'], [SessionAuth::required(), PermissionCheck::requires('data_export_run')]);
$router->get('/admin/data-export/data', [$dataExport, 'downloadData'], [SessionAuth::required(), PermissionCheck::requires('data_export_run')]);
$router->post('/clients/{id}/override-unique-number', [$clients, 'overrideUniqueNumber'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/orders/{id}/override-status-lock', [$orders, 'overrideStatusLock'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);
$router->post('/amendments/{amendmentId}/override-reference', [$amendments, 'overrideReference'], [SessionAuth::required(), PermissionCheck::requires('edit_locked_data'), CsrfCheck::verify()]);

// --- Phase E follow-up: admin user management (Section 14 gap) ---
$router->get('/users', [$users, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_users')]);
$router->post('/users/create', [$users, 'create'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->get('/users/{id}/edit', [$users, 'editForm'], [SessionAuth::required(), PermissionCheck::requires('manage_users')]);
$router->post('/users/{id}/update', [$users, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/toggle-active', [$users, 'toggleActive'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/archive', [$users, 'archive'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/unarchive', [$users, 'unarchive'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/delete', [$users, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);
$router->post('/users/{id}/force-reset-password', [$users, 'forceResetPassword'], [SessionAuth::required(), PermissionCheck::requires('manage_users'), CsrfCheck::verify()]);

// --- Associate portal (owner decision 1 Nov 2026, AS-1): its own login (password + e-mailed code), separate from staff and buyers. ---
$router->get('/associate/login', [$associatePortal, 'showLogin']);
$router->post('/associate/login', [$associatePortal, 'login'], [CsrfCheck::verify()]);
$router->get('/associate/login/code', [$associatePortal, 'showCode']);
$router->post('/associate/login/code', [$associatePortal, 'verifyCode'], [CsrfCheck::verify()]);
$router->get('/associate/forgot-password', [$associatePortal, 'showForgotPassword']);                 // D-282
$router->post('/associate/forgot-password', [$associatePortal, 'forgotPassword'], [CsrfCheck::verify()]); // D-282
$router->get('/associate/set-password/{token}', [$associatePortal, 'showSetPassword']);
$router->post('/associate/set-password/{token}', [$associatePortal, 'setPassword'], [CsrfCheck::verify()]);
$router->get('/associate/logout', [$associatePortal, 'logout']);
$router->post('/associate/logout', [$associatePortal, 'logout'], [CsrfCheck::verify()]);
$router->get('/associate', [$associatePortal, 'home'], [AssociateAuth::required()]);
$router->get('/associate/clients', [$associatePortal, 'clients'], [AssociateAuth::required()]);
$router->get('/associate/clients/{id}/certificate', [$associatePortal, 'certificate'], [AssociateAuth::required()]);
$router->post('/associate/invitations', [$associatePortal, 'createInvitation'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/invitations/{id}/cancel', [$associatePortal, 'cancelInvitation'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/code/propose', [$associatePortal, 'proposeCode'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/code', [$associatePortal, 'chooseCode'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->get('/associate/orders', [$associatePortal, 'orders'], [AssociateAuth::required()]);
$router->get('/associate/orders/{id}', [$associatePortal, 'order'], [AssociateAuth::required()]);
$router->get('/associate/prices', [$associatePortal, 'prices'], [AssociateAuth::required()]);
// Owner decision D-255 (5 Nov 2026): catalogue with no prices, get a price for chosen options (replaces P3's price range).
$router->get('/associate/catalogue', [$associatePortal, 'catalogue'], [AssociateAuth::required()]);
$router->get('/associate/catalogue/{id}', [$associatePortal, 'getPriceForm'], [AssociateAuth::required()]);
$router->post('/associate/catalogue/{id}/price', [$associatePortal, 'requestPrice'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->get('/associate/price-requests/{id}', [$associatePortal, 'priceRequest'], [AssociateAuth::required()]);
$router->get('/associate/commission', [$associatePortal, 'commission'], [AssociateAuth::required()]);
$router->post('/associate/commission/{id}/invoice', [$associatePortal, 'uploadInvoice'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->get('/associate/profile', [$associatePortal, 'profile'], [AssociateAuth::required()]);
$router->post('/associate/profile', [$associatePortal, 'saveProfile'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/documents', [$associatePortal, 'uploadDocument'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->get('/associate/documents/{id}', [$associatePortal, 'downloadDocument'], [AssociateAuth::required()]);
$router->post('/associate/bank', [$associatePortal, 'requestBank'], [AssociateAuth::required(), CsrfCheck::verify()]);
// D-293: the associate confirms new bank details (last 4 digits + e-mailed code), or "This is not me — cancel it".
$router->get('/associate/bank/confirm', [$associatePortal, 'showBankConfirm'], [AssociateAuth::required()]);
$router->post('/associate/bank/confirm/code', [$associatePortal, 'sendBankCode'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/bank/confirm', [$associatePortal, 'confirmBank'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->post('/associate/bank/not-me', [$associatePortal, 'bankNotMe'], [AssociateAuth::required(), CsrfCheck::verify()]);
$router->get('/associate/agreement', [$associatePortal, 'downloadAgreement'], [AssociateAuth::required()]);
$router->get('/associate/messages', [$associatePortal, 'messages'], [AssociateAuth::required()]);
$router->post('/associate/messages', [$associatePortal, 'sendMessage'], [AssociateAuth::required(), CsrfCheck::verify()]);

// --- Client portal (authenticated, structurally separate from staff /
// SessionAuth) — gated by ClientAuth::required(), a different session key. ---
$router->get('/client', [$clientPortal, 'dashboard'], [ClientAuth::required()]);
$router->get('/client/account', [$clientPortal, 'showAccount'], [ClientAuth::required()]);
$router->post('/client/account/password', [$clientPortal, 'changePassword'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->get('/client/orders/{id}', [$clientPortal, 'showOrder'], [ClientAuth::required()]);
$router->get('/client/orders/{id}/reorder', [$clientPortal, 'showReorderForm'], [ClientAuth::required()]);
$router->post('/client/orders/{id}/reorder', [$clientPortal, 'submitReorder'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/report-payment', [$clientPortal, 'reportPayment'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/buyer-po', [$clientPortal, 'uploadBuyerPo'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/acknowledge-oc', [$clientPortal, 'acknowledgeOc'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/disputes', [$clientPortal, 'raiseDispute'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/complaints', [$clientPortal, 'raiseComplaint'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/complaints/{complaintId}/replies', [$clientPortal, 'postComplaintReply'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/feedback', [$clientPortal, 'submitFeedback'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->post('/client/orders/{id}/comments', [$clientPortal, 'postComment'], [ClientAuth::required(), CsrfCheck::verify()]);
$router->get('/client/orders/{id}/comment-attachments/{fileId}/download', [$clientPortal, 'downloadCommentAttachment'], [ClientAuth::required()]);
$router->get('/client/orders/{id}/payment-reports/{reportId}/screenshot', [$clientPortal, 'downloadPaymentScreenshot'], [ClientAuth::required()]);
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
// Owner decision D-215 (5a): product categories and their attributes (before /products/{id}, which would match first).
$router->get('/products/categories', [$productCategories, 'index'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
$router->post('/products/categories', [$productCategories, 'store'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/categories/{id}', [$productCategories, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/categories/{id}/settings', [$productCategories, 'settings'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
// Owner decisions D-300..D-306 (11 Nov 2026): one page per category (tabs), its price rules, and the one-time clean-up pages (before /products/{id}).
$catalogueMaster = new \App\Controllers\CatalogueMasterController();
$router->get('/products/categories/{id}', [$productCategories, 'show'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_product_catalog', 'manage_pricing', 'view_price_buildup'])]);
$router->post('/products/categories/{id}/price-rules', [$productCategories, 'saveRules'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->get('/products/price-rules', [$productCategories, 'priceRules'], [SessionAuth::required(), PermissionCheck::requiresAny(['manage_pricing', 'view_price_buildup'])]);
$router->get('/products/fix-hs-codes', [$catalogueMaster, 'fixHsCodes'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
$router->post('/products/{id}/hs-code', [$catalogueMaster, 'saveHsCode'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->get('/products/link-suppliers', [$catalogueMaster, 'linkSuppliers'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'manage_suppliers'])]);
$router->post('/products/link-suppliers', [$catalogueMaster, 'saveLinks'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'manage_suppliers']), CsrfCheck::verify()]);
// Owner decision D-361 (13 Nov 2026): Configure / Edit (manage_product_catalog only — owner decision 2), "Link a supplier"
// for a product with no supplier yet (decision 1) and "Link these prices" for legacy supplier prices (decision 3).
$router->get('/products/link-prices', [$productConfigure, 'linkPricesForm'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'manage_suppliers'])]);
$router->post('/products/link-prices', [$productConfigure, 'linkPrices'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'manage_suppliers']), CsrfCheck::verify()]);
$router->get('/products/{id}/configure', [$productConfigure, 'form'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
$router->post('/products/{id}/configure', [$productConfigure, 'save'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/configure/reset', [$productConfigure, 'reset'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/configure/use-price', [$productConfigure, 'usePrice'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->get('/products/{id}/link-supplier', [$productConfigure, 'linkSupplierForm'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), PermissionCheck::requires('record_market_prices')]);
$router->post('/products/{id}/link-supplier', [$productConfigure, 'linkSupplier'], [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->get('/products/{id}', [$products, 'show'], [SessionAuth::required(), PermissionCheck::requires('view_product_catalog')]);
$router->get('/products/{id}/edit', [$products, 'edit'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
// Owner decision D-358 (13 Nov 2026): duplicate a product — confirm screen, then the copy (Inactive).
$router->get('/products/{id}/duplicate', [$products, 'duplicateForm'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog')]);
$router->post('/products/{id}/duplicate', [$products, 'duplicate'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/update', [$products, 'update'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/delete', [$products, 'delete'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
// Owner (12 Nov 2026): deactivate / reactivate a product (a used product is never deleted).
$router->post('/products/{id}/deactivate', [$products, 'deactivate'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
$router->post('/products/{id}/reactivate', [$products, 'reactivate'], [SessionAuth::required(), PermissionCheck::requires('manage_product_catalog'), CsrfCheck::verify()]);
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
// Owner decision D-245 (4 Nov 2026), P1: pricing settings, commission levels and ladder, product pricing overrides, market prices.
$router->get('/pricing/settings', [$pricing, 'settings'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_price_buildup', 'manage_pricing'])]);
$router->post('/pricing/settings', [$pricing, 'saveSettings'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->get('/pricing/commission-levels', [$pricing, 'levels'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_price_buildup', 'manage_pricing'])]);
$router->post('/pricing/commission-levels', [$pricing, 'addLevel'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/commission-levels/{id}', [$pricing, 'updateLevel'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/commission-levels/{id}/active', [$pricing, 'toggleLevel'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/commission-levels/{id}/delete', [$pricing, 'deleteLevel'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/commission-ladder', [$pricing, 'saveLadder'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/products/{id}/pricing', [$pricing, 'saveProductOverrides'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->get('/products/{id}/market-prices', [$pricing, 'marketPrices'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'view_price_buildup'])]);
$router->post('/products/{id}/market-prices', [$pricing, 'addMarketPrice'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/use-for-pricing', [$pricing, 'useForPricing'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/market-price', [$pricing, 'updateMarketDates'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
// D-300 (point 12): one supplier price — every thickness, CHA + transport, history. D-301 (point 4): the exchange rate.
$router->get('/products/suppliers/{supplierId}/price', [$pricing, 'editPrice'], [SessionAuth::required(), PermissionCheck::requiresAny(['record_market_prices', 'view_price_buildup'])]);
$router->post('/products/suppliers/{supplierId}/price', [$pricing, 'savePrice'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
// D-359 (plan rule 8): the simple "Edit price" form — one supplier's offer, one Save, Undo, live preview.
$router->get('/products/suppliers/{supplierId}/edit-price', [$editPrice, 'form'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices')]);
$router->get('/products/suppliers/{supplierId}/edit-price/preview', [$editPrice, 'preview'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices')]);
$router->post('/products/suppliers/{supplierId}/edit-price', [$editPrice, 'save'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/edit-price/undo', [$editPrice, 'undo'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
// D-335: "Add / update a price" (one thickness typed, the ticked ones worked out) and the BASE for orders.
$router->post('/products/suppliers/{supplierId}/thickness-price', [$pricing, 'addThicknessPrice'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->post('/products/suppliers/{supplierId}/base', [$pricing, 'setBase'], [SessionAuth::required(), PermissionCheck::requires('record_market_prices'), CsrfCheck::verify()]);
$router->post('/pricing/fx-rates/fetch', [$pricing, 'fetchFxRate'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/fx-rates/{id}/decide', [$pricing, 'decideFxRate'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
// Owner decision D-255 (5 Nov 2026): specifications that change the price — per category (manage_pricing) and per product (manage_product_specifications).
$specs = new \App\Controllers\SpecController();
$router->get('/pricing/specifications', [$specs, 'index'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_price_buildup', 'manage_pricing'])]);
$router->post('/pricing/specifications', [$specs, 'addSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/specifications/{id}', [$specs, 'updateSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/specifications/{id}/active', [$specs, 'toggleSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/specifications/{id}/delete', [$specs, 'deleteSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/specifications/{id}/options', [$specs, 'addOption'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/spec-options/{id}', [$specs, 'updateOption'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/spec-options/{id}/active', [$specs, 'toggleOption'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->post('/pricing/spec-options/{id}/delete', [$specs, 'deleteOption'], [SessionAuth::required(), PermissionCheck::requires('manage_pricing'), CsrfCheck::verify()]);
$router->get('/products/{id}/specifications', [$specs, 'product'], [SessionAuth::required(), PermissionCheck::requiresAny(['view_price_buildup', 'manage_product_specifications', 'manage_pricing'])]);
$router->post('/products/{id}/specifications/settings', [$specs, 'saveProductFields'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/add', [$specs, 'addProductSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/reset', [$specs, 'resetProduct'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/{specId}', [$specs, 'saveProductSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/{specId}/hide', [$specs, 'hideProductSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/{specId}/show', [$specs, 'showProductSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/{specId}/reset', [$specs, 'resetProductSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{id}/specifications/{specId}/options', [$specs, 'addProductOption'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{pid}/own-specs/{id}', [$specs, 'updateSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{pid}/own-specs/{id}/delete', [$specs, 'deleteSpec'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
$router->post('/products/{pid}/spec-options/{id}/delete', [$specs, 'deleteOption'], [SessionAuth::required(), PermissionCheck::requires('manage_product_specifications'), CsrfCheck::verify()]);
// The order form's live quote for a product with chosen options (selling-price visibility is checked inside; never cost).
$router->get('/pricing/line-quote', [$priceRequests, 'lineQuote'], [SessionAuth::required()]);

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
